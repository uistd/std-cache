<?php
namespace ffan\php\cache;

require_once '../vendor/autoload.php';
require_once 'config.php';

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