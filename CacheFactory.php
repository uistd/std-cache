<?php
namespace ffan\php\cache;
use ffan\php\event\EventManager;
use ffan\php\utils\InvalidConfigException;
use ffan\php\utils\Str as FFanStr;
use ffan\php\utils\Config as FFanConfig;

/**
 * Class CacheFactory
 * @package ffan\php\cache
 */
class CacheFactory
{
    /**
     * 配置组名
     */
    const CONFIG_GROUP = 'ffan-cache';

    /**
     * @var array 所有实例化对象列表
     */
    private static $object_arr;

    /**
     * @var bool 是否已经触发事件了
     */
    private static $is_trigger_event = false;

    /**
     * 获取一个缓存实例
     * @param string $config_name
     * @return CacheInterface
     * @throws InvalidConfigException
     */
    public static function get($config_name = 'main')
    {
        if (isset(self::$object_arr[$config_name])) {
            return self::$object_arr[$config_name];
        }
        if (!is_string($config_name)) {
            throw new \InvalidArgumentException('config_name is not string');
        }
        $conf_arr = FFanConfig::get(self::CONFIG_GROUP .':' . $config_name);
        if (!is_array($conf_arr)) {
            $conf_arr = [];
        }
        //如果指定了日志的类名，使用指定的类
        if (isset($conf_arr['class_name'])) {
            $conf_key = self::CONFIG_GROUP .':' . $config_name . '.class_name';
            if (!FFanStr::isValidClassName($conf_arr['class_name'])) {
                throw new InvalidConfigException($conf_key, 'invalid class name!');
            }
            $new_obj = new $conf_arr['class_name']($config_name, $conf_arr);
            if (!($new_obj instanceof CacheInterface)) {
                throw new InvalidConfigException($conf_key, 'class is not implements of CacheInterface');
            }
        }
        //其它情况根据 type 来判断
        else {
            
            $type = isset($conf_arr['type']) ? $conf_arr['type'] : 'memcached';
            switch ($type) {
                case 'apc':
                    $new_obj = new Apc($config_name, $conf_arr);
                    break;
                default:
                    $new_obj = new Memcached($config_name, $conf_arr);
                    break;
            }
        }
        self::$object_arr[$config_name] = $new_obj;
        //通过事件自动调用commit 和 rollback
        if (!self::$is_trigger_event) {
            self::$is_trigger_event = true;
            $eve_manager = EventManager::instance();
            $eve_manager->attach('commit', [__CLASS__, 'commit'], PHP_INT_MAX);
            $eve_manager->attach('rollback', [__CLASS__, 'rollback'], PHP_INT_MAX);
        }
        return $new_obj;
    }
    
    /**
     * 全部rollback
     */
    public static function rollback()
    {
        if (!self::$object_arr) {
            return;
        }

        /**
         * @var string $name
         * @var CacheInterface $mysql_obj
         */
        foreach (self::$object_arr as $name => $mysql_obj) {
            $mysql_obj->rollback();
        }
    }

    /**
     * 全部commit
     */
    public static function commit()
    {
        if (!self::$object_arr) {
            return;
        }

        /**
         * @var string $name
         * @var CacheInterface $mysql_obj
         */
        foreach (self::$object_arr as $name => $mysql_obj) {
            $mysql_obj->commit();
        }
    }
}