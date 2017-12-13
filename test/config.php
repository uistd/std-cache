<?php
use FFan\Std\Common\Config as FFanConfig;

FFanConfig::addArray(
    array(
        'ffan-cache:apc' => array(
            'type' => 'apc',
        ),
        'ffan-cache:main' => array(
            'type' => 'memcached',
            'server' => array(
                'host' => '127.0.0.1',
                'port' => 11211
            )
        ),
        'ffan-cache:redis' => array(
            'host' => '127.0.0.1',
            'port' => 10401
        ),
        'ffan-cache:cluster' => array(
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