<?php
namespace FFan\Std\Cache;

use FFan\Std\Common\Factory as FFanFactory;
use FFan\Std\Common\InvalidConfigException;

/**
 * Class CacheFactory
 * @package FFan\Std\Cache
 */
class CacheFactory extends FFanFactory
{
    /**
     * @var string 配置组名
     */
    protected static $config_group = 'ffan-cache';

    /**
     * @var array 别名
     */
    protected static $class_alias = array(
        'apc' => 'FFan\Std\Cache\Apc',
        'file' => 'FFan\Std\Cache\FileCache'
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
     * @return CacheInterface
     */
    protected static function defaultInstance($config_name, $conf_arr)
    {
        if (isset(self::$class_alias[$config_name])) {
            return new self::$class_alias[$config_name]($config_name, $conf_arr);
        } else {
            return new Memcached($config_name, $conf_arr);
        }
    }
}
