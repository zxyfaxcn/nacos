<?php
/**
 * Nacos doc
 *
 * @link https://nacos.io/zh-cn/docs/open-api.html
 */

return [
    'enable' => true, // 是否开启自动注册 默认false
    'deleteServiceWhenShutdown' => true, // 是否开启自动注销 默认false
    'host' => '127.0.0.1',
    'port' => '8848',
    // 服务配置 serviceName, groupName, namespaceId
    // protectThreshold, metadata, selector
    'service' => [
        'serviceName' => 'hyperf',
        'groupName' => 'api',
        'namespaceId' => 'namespace_id',
        'protectThreshold' => 0.5,
    ],
    // 节点配置 serviceName, groupName, weight, enabled,
    // healthy, metadata, clusterName, namespaceId, ephemeral
    'client' => [
        'namespaceId' => 'namespace_id', // 注意此处必须和service保持一致
        'serviceName' => 'hyperf',
        'groupName' => 'DEFAULT',
        'weight' => 80,
        'enabled' => true,
        'healthy' => true,
        'cluster' => 'DEFAULT',
        'ephemeral' => true,
        'beatEnable' => true,
        'beatInterval' => 5,// s
    ],
    // 配置刷新间隔 s
    'configReloadInterval' => 3,
    // 远程配置合并节点, 默认 config 根节点
    'configAppendNode' => 'nacos_conf',
    'listenerConfig' => [
        // 配置项 dataId, group, tenant, type, content
        [
            'dataId' => 'hyperf-service-config',
            'group' => 'DEFAULT_GROUP',
        ],
        [
            'dataId' => 'hyperf-service-config-yml',
            'group' => 'DEFAULT_GROUP',
            'type' => 'yml',
        ],
    ],
];
