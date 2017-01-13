<?php
namespace ffan\php\cache;

use ffan\php\utils\Config as FFanConfig;

require_once '../vendor/autoload.php';

FFanConfig::addArray(
    array(
        'ffan-cache:main' => array(
            'category' => 'main',
            'class' => 'apcs'
        ),
        'ffan-logger:web' => array(
            'file' => 'test',
            'path' => 'test'
        ),
        'runtime_path' => __DIR__ . DIRECTORY_SEPARATOR,
        'env' => 'dev'
    )
);
$apc = CacheFactory::get('main');
print_r($apc);