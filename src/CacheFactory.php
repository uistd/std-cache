<?php

namespace UiStd\Cache;

use UiStd\Common\Factory;
use UiStd\Common\InvalidConfigException;

/**
 * Class CacheFactory
 * @package UiStd\Cache
 */
class CacheFactory extends Factory
{
    /**
     * @var string 配置组名
     */
    protected static $config_group = 'uis-cache';

    /**
     * @var array 别名
     */
    protected static $class_type = array(
        'apc' => 'UiStd\Cache\Apc',
        'memcached' => 'UiStd\Cache\Memcached',
        'file' => 'UiStd\Cache\File',
        'redis' => 'UiStd\Cache\Redis',
        'clusterRedis' => 'UiStd\Cache\ClusterRedis',
    );

    /**
     * 获取一个缓存实例
     * @param string $config_name
     * @return CacheInterface
     * @throws InvalidConfigException
     */
    public static function get($config_name = 'main')
    {
        $obj = self::getInstance($config_name);
        if (!($obj instanceof CacheInterface)) {
            throw new InvalidConfigException(self::$config_group . ':' . $config_name . '.class', 'class is not implements of MysqlInterface');
        }
        return $obj;
    }

    /**
     * 默认的缓存类
     * @param string $config_name
     * @param array $conf_arr
     * @return CacheInterface
     * @throws InvalidConfigException
     */
    protected static function defaultInstance($config_name, $conf_arr)
    {
        $cache_type = isset($conf_arr['type']) ? $conf_arr['type'] : $config_name;
        if (!isset(self::$class_type[$cache_type])) {
            throw new InvalidConfigException(self::configGroupName($config_name), 'unknown cache type:' . $cache_type);
        }
        return new self::$class_type[$cache_type]($config_name, $conf_arr);
    }
}
