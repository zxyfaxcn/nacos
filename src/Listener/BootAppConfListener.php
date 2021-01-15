<?php

namespace Hyperf\Nacos\Listener;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BootApplication;
use Hyperf\Framework\Event\MainWorkerStart;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Nacos\Lib\NacosInstance;
use Hyperf\Nacos\Lib\NacosService;
use Hyperf\Nacos\Model\ServiceModel;
use Hyperf\Nacos\ThisInstance;
use Hyperf\Nacos\Util\RemoteConfig;
use Hyperf\Process\ProcessManager;
use Hyperf\Server\Event\MainCoroutineServerStart;
use Hyperf\Utils\Coordinator\CoordinatorManager;

class BootAppConfListener implements ListenerInterface
{
    public function listen(): array
    {
        return [
            MainWorkerStart::class,
            MainCoroutineServerStart::class
        ];
    }

    public function process(object $event)
    {
        if (!config('nacos')) {
            return;
        }
        if (!config('nacos.enable')) {
            return;
        }
        $logger = container(LoggerFactory::class)->get('nacos');

        try {
            /** @var NacosService $nacos_service */
            $nacos_service = container(NacosService::class);
            /** @var ServiceModel $service */
            $service = make(ServiceModel::class, ['config' => config('nacos.service')]);
            $exist = $nacos_service->detail($service);
            if (!$exist && !$nacos_service->create($service)) {
                throw new \Exception("nacos register service fail: {$service}");
            } else {
                $logger->info('nacos register service success!', compact('service'));
            }

            /** @var ThisInstance $instance */
            $instance = make(ThisInstance::class);
            /** @var NacosInstance $nacos_instance */
            $nacos_instance = make(NacosInstance::class);
            foreach (['enabled', 'ephemeral', 'healthy'] as $field) {
                if (is_bool($instance->{$field})) {
                    $instance->{$field} = $instance->{$field} ? 'true' : 'false';
                }
            }
            if (!$nacos_instance->register($instance)) {
                throw new \Exception("nacos register instance fail: {$instance}");
            } else {
                $logger->info('nacos register instance success!', compact('instance'));
            }

            $this->refreshConfig();

            if ($event instanceof MainCoroutineServerStart) {
                $interval = (int)config('nacos.config_reload_interval', 3);
                // Create a coroutine, repeat infinitely (while true) to do an event, and try again through retry (INF unlimited)
                \Hyperf\Utils\Coroutine::create(function () use ($interval) {
                    sleep($interval);
                    // Unlimited retries (prevents from jumping out after getting configuration exception `Throwable`)
                    retry(INF, function () use ($interval) {
                        $prevConfig = [];
                        while (ProcessManager::isRunning()) {
                            // CoordinatorManager is used to direct the coroutine to wait for events to occur
                            // Wake up after WORKER_EXIT event callback is completed (channel + observer mode)
                            $coordinator = CoordinatorManager::until(\Hyperf\Utils\Coordinator\Constants::WORKER_EXIT);
                            // Block and wait for the `workerExited` event for 3s, if it is true, end, if not, continue to do configuration update operations
                            $workerExited = $coordinator->yield($interval);
                            if ($workerExited) {
                                break;
                            }
                            $prevConfig = $this->refreshConfig($prevConfig);
                        }
                    }, $interval * 1000);
                });
                \Hyperf\Utils\Coroutine::create(function () use ($interval) {
                    sleep($interval);
                    retry(INF, function () use ($interval) {
                        while (ProcessManager::isRunning()) {
                            $coordinator = CoordinatorManager::until(\Hyperf\Utils\Coordinator\Constants::WORKER_EXIT);
                            $workerExited = $coordinator->yield($interval);
                            if ($workerExited) {
                                break;
                            }
                            $this->refreshVendorServices();
                        }
                    }, $interval * 1000);
                });
            }

        } catch (\Throwable $exception) {
            $logger->critical((string)$exception);
        }
    }

    private function refreshConfig(array $prevConfig = []): array
    {
        // Dynamically update configuration information
        $remote_config = RemoteConfig::get();
        if ($remote_config === $prevConfig) {
            return $remote_config;
        }
        /** @var \Hyperf\Config\Config $config */
        $config = container(ConfigInterface::class);
        $append_node = $config->get('nacos.configAppendNode');
        foreach ($remote_config as $key => $conf) {
            $config->set($append_node ? $append_node . '.' . $key : $key, $conf);
        }
        return $remote_config;
    }

    private function refreshVendorServices()
    {
        /** @var \Hyperf\Config\Config $config */
        $config = container(ConfigInterface::class);
        // Dynamically update dependent service node information
        foreach ($config->get('nacos.vendorServices', []) as $serviceName => $point) {
            $serviceModel = new ServiceModel([
                'serviceName' => $serviceName,
                'groupName' => $config->get('nacos.service.groupName'),
                'namespaceId' => $config->get('nacos.service.namespaceId'),
            ]);
            $pointRemote = (new NacosInstance())->list($serviceModel);
            if ($point['checksum'] !== $pointRemote['checksum']) {
                $config->set('nacos.vendorServices.' . $serviceName, $pointRemote);
            }
        }
    }
}
