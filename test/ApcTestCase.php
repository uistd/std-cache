<?php
namespace ffan\php\cache;

use ffan\php\utils\Config as FFanConfig;

require_once '../vendor/autoload.php';

FFanConfig::addArray(
    array(
        'ffan-cache:main' => array(
            'category' => 'main',
            'class' => 'apc'
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
$apc->set('test', 'test apc string');
$re = $apc->get('test');
var_dump($re);

$re = $apc->has('test');

var_dump($re);

$re = $apc->set('test2', 1);

var_dump($re);

$re = $apc->casSet('test2', 2);

var_dump($re);

$re = $apc->casSet('test2', 3);

var_dump($re);