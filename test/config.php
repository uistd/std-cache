<?php
use ffan\php\utils\Config as FFanConfig;

FFanConfig::addArray(
    array(
        'ffan-cache:apc' => array(
            'category' => 'main',
        ),
        'ffan-cache:main' => array(
            'server' => array(
                'host' => '127.0.0.1',
                'port' => 11211
            )
        ),
        'ffan-logger:web' => array(
            'file' => 'test',
            'path' => 'test'
        ),
        'runtime_path' => __DIR__ . DIRECTORY_SEPARATOR,
        'env' => 'dev'
    )
);