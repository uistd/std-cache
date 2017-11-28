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
            'host' => '10.213.33.156',
            'port' => 10401
        ),
        'ffan-cache:cluster' => array(
            'type' => 'clusterRedis',
            'server' => array(
                '10.213.33.156:10401',
                '10.213.33.156:10616',
                '10.213.33.156:10596',
                '10.213.33.156:10652',
            )
        ),
        'env' => 'dev'
    )
);