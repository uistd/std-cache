<?php
namespace UiStd\Cache;

require_once '../vendor/autoload.php';
require_once 'config.php';

/** @var File $cache */
$cache = CacheFactory::get('file');

print_r($cache->get('test', 'no value'));

$arr = array();
for ($i = 0; $i < mt_rand(2, 100); ++$i) {
    $arr[] = $i;
}
$cache->set('test', $arr);

$re = $cache->add('test', 11);

var_dump($re);

$key_name = '张三星';

$re = $cache->has($key_name);

if ($re) {
    echo $key_name, 'has exist', PHP_EOL;
    var_dump($cache->delete($key_name));
}
$re = $cache->add($key_name, $_SERVER);

var_dump($re);

print_r($cache->get($key_name, 'not found'));
echo PHP_EOL;

var_dump($cache->get('test_expire'));

$cache->set('test_expire', 'This is never expire string', 0);
