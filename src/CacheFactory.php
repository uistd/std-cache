<?php

namespace FFan\Std\Cache;

use FFan\Std\Common\Factory;
use FFan\Std\Common\InvalidConfigException;

/**
 * Class CacheFactory
 * @package FFan\Std\Cache
 */
class CacheFactory extends Factory
{
    /**
     * @var string 配置组名
     */
    protected static $config_group = 'ffan-cache';

    /**
     * @var array 别名
     */
    protected static $class_type = array(
        'apc' => 'FFan\Std\Cache\Apc',
        'memcached' => 'FFan\Std\Cache\Memcached',
        'file' => 'FFan\Std\Cache\File',
        'redis' => 'FFan\Std\Cache\Redis',
        'clusterRedis' => 'FFan\Std\Cache\ClusterRedis',
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
