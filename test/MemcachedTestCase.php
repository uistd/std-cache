<?php
namespace ffan\php\cache;

require_once '../vendor/autoload.php';
require_once 'config.php';

$cache = CacheFactory::get('main');

$cache->clear();

$cache->set('test', 'This is test memcached string');

$re = $cache->get('test');

var_dump($re);

$re = $cache->casGet('test', null);

$re = $cache->casSet('test', 'Cas set value');

var_dump($re);

$re = $cache->get('test');

var_dump($re);
