<?php
namespace UiStd\Cache;

use UiStd\Logger\FileLogger;

require_once '../vendor/autoload.php';
require_once 'config.php';

/** @var Memcached $cache */
$cache = CacheFactory::get('main');
new FileLogger('logs');
$cache->clear();

$cache->set('test', 'This is test memcached string');

$re = $cache->get('test');

var_dump($re);
$re = $cache->casGet('test', null);

var_dump($re);
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

$result = $cache->getMultiple(array(
    'test',
    'test_1',
    'test_2',
    'test_3',
    'test_4',
    'test_5',
));

var_dump($result);

$cache->setMultiple(
    array(
        'test_7' => 'test string 7',
        'test_8' => 'test string 8',
        'test_9' => 'test string 9',
        'test_10' => 'test string 10',
        'test_11' => 'test string 11'
    ), 300
);

var_dump($cache->get('test_11'));

$cache->deleteMultiple(array('test_11', 'test_10'));

var_dump($cache->get('test_10'));

var_dump($cache->getMultiple(array('test_100', 'test_200')));
var_dump($cache->increase('total2'));
var_dump($cache->increase('total2'));
var_dump($cache->increase('total2', 10));
var_dump($cache->decrease('total2'));
var_dump($cache->decrease('total2'));
var_dump($cache->decrease('total2', 5));