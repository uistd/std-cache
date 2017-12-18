<?php
use UiStd\Common\Config as UisConfig;

UisConfig::addArray(
    array(
        'uis-cache:apc' => array(
            'type' => 'apc',
        ),
        'uis-cache:main' => array(
            'type' => 'memcached',
            'server' => array(
                'host' => '127.0.0.1',
                'port' => 11211
            )
        ),
        'uis-cache:redis' => array(
            'host' => '127.0.0.1',
            'port' => 10401
        ),
        'uis-cache:cluster' => array(
            'type' => 'clusterRedis',
            'server' => array(
                '127.0.0.1:10401',
                '127.0.0.1:10616',
                '127.0.0.1:10596',
                '127.0.0.1:10652',
            )
        ),
        'env' => 'dev'
    )
);