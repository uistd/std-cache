<?php
namespace ffan\php\cache;

require_once '../vendor/autoload.php';
require_once 'config.php';

/** @var Memcached $cache */
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

$cache->set('test_1', 'test string 1');
$cache->set('test_2', 'test string 2');
$cache->set('test_3', 'test string 3');
$cache->set('test_4', 'test string 4');
$cache->set('test_5', 'test string 5');
$cache->set('test_6', 'test string 6');
$cache->commit();

$result = $cache->getMultiple(array(
    'test',
    'test_1',
    'test_2',
    'test_3',
    'test_4',
    'test_5',
));

var_dump($result);

$cache->cleanup();

$result = $cache->getMultiple(array(
    'test',
    'test_1',
    'test_2',
    'test_3',
    'test_4',
    'test_5',
));

var_dump($result);