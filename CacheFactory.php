<?php
namespace ffan\php\cache;

use ffan\php\utils\Factory as FFanFactory;
use ffan\php\utils\InvalidConfigException;

/**
 * Class CacheFactory
 * @package ffan\php\cache
 */
class CacheFactory extends FFanFactory
{
    /**
     * 配置组名
     */
    const CONFIG_GROUP = 'ffan-cache';

    /**
     * @var array 别名
     */
    protected static $class_alias = array(
        'apc' => '\ffan\php\cache\Apc',
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
            throw new InvalidConfigException(self::$config_group . ':' . $config_name . '.class', 'class is not implements of CacheInterface');
        }
        return $obj;
    }

    /**
     * 默认的缓存类
     * @param string $config_name
     * @param array $conf_arr
     * @return Memcached
     */
    protected static function defaultInstance($config_name, $conf_arr)
    {
        return new Memcached($config_name, $conf_arr);
    }
}
