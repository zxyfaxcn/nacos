<?php
declare(strict_types=1);

namespace Hyperf\Nacos\Config;

use Hyperf\Nacos\Util\RemoteConfig;
use Hyperf\Process\AbstractProcess;
use Hyperf\Process\ProcessCollector;
use Hyperf\Process\ProcessManager;
use Swoole\Coroutine\Server as CoServer;
use Swoole\Server;

class FetchConfigProcess extends AbstractProcess
{
    public $name = 'nacos-fetch-config';

    /**
     * @var CoServer|Server
     */
    private $server;

    public function bind($server): void
    {
        $this->server = $server;
        parent::bind($server);
    }

    public function handle(): void
    {
        $workerCount = $this->server->setting['worker_num'] + $this->server->setting['task_worker_num'] - 1;
        $cache = [];
        while (ProcessManager::isRunning()) {
            $remote_config = RemoteConfig::get();
            if ($remote_config != $cache) {
                $pipe_message = new PipeMessage($remote_config);
                for ($workerId = 0; $workerId <= $workerCount; ++$workerId) {
                    $this->server->sendMessage($pipe_message, $workerId);
                }

                $string = serialize($pipe_message);

                $processes = ProcessCollector::all();
                /** @var \Swoole\Process $process */
                foreach ($processes as $process) {
                    $process->exportSocket()->send($string);
                }

                $cache = $remote_config;
            }
            sleep(config('nacos.configReloadInterval', 3));
        }
    }

    public function isEnable($server): bool
    {
        return $server instanceof Server && (bool)config('nacos.configReloadEnable', false);
    }
}
