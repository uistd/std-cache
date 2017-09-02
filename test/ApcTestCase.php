<?php
namespace FFan\Std\Cache;

require_once '../vendor/autoload.php';
require_once 'config.php';

/** @var Apc $apc */
$apc = CacheFactory::get('apc');
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


$apc->set('test_1', 'test string 1');
$apc->set('test_2', 'test string 2');
$apc->set('test_3', 'test string 3');
$apc->set('test_4', 'test string 4');
$apc->set('test_5', 'test string 5');
$apc->set('test_6', 'test string 6');
$apc->commit();

$result = $apc->getMultiple(array(
    'test',
    'test_1',
    'test_2',
    'test_3',
    'test_4',
    'test_5',
));

var_dump('get-multi', $result);

$apc->cleanup();

$result = $apc->getMultiple(array(
    'test',
    'test_1',
    'test_2',
    'test_3',
    'test_4',
    'test_5',
));

var_dump($result);

$apc->setMultiple(
    array(
        'test_7' => 'test string 7',
        'test_8' => 'test string 8',
        'test_9' => 'test string 9',
        'test_10' => 'test string 10',
        'test_11' => 'test string 11'
    ), 300
);

$apc->set('test_12', 'test string 12', 500);
$apc->set('test_13', 'test string 13', 500);
$apc->set('test_14', 'test string 14', 600);
