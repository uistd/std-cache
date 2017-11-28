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
        'env' => 'dev'
    )
);