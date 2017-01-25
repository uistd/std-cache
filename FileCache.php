<?php
namespace ffan\php\cache;

use ffan\php\utils\Transaction;
use ffan\php\utils\Utils as FFanUtils;

/**
 * Class FileCache 文件缓存
 * @package ffan\php\cache
 */
class FileCache extends Transaction implements CacheInterface
{
    /** 判断是否过期的key */
    const EXPIRE_KEY = '__expire__';
     
    /** 缓存值的key */
    const VALUE_KEY = '__value__';
    
    /** 用于检验的token */
    const CAS_KEY = '__token__';
    
    /**
     * @var array 已经打开的缓存
     */
    private $cache_arr;

    /**
     * @var array 待保存的缓存
     */
    private $cache_save;

    /**
     * @var string 文件路径
     */
    private $file_path;

    /**
     * @var int 默认的过期时间
     */
    private $default_ttl;
    
    /**
     * FileCache constructor.
     * @param string $config_name 配置名称
     * @param array $config_set 配置参数列表
     */
    public function __construct($config_name, array $config_set)
    {
        parent::__construct();
    }

    /**
     * 退出前确保所有缓存写入
     */
    public function __exit()
    {
        $this->commit();
    }

    /**
     * 获取一个缓存.
     * @param string $key 缓存键值
     * @param mixed $default 如果缓存不存在，返回的默认值
     * @return mixed
     */
    public function get($key, $default = null)
    {
        //如果缓存里已经有了
        if (isset($this->cache_arr[$key])) {
            return $this->cache_arr[$key];
        }
        $file_name = $this->makeFileName($key);
        //文件不存在
        if (!is_file($file_name)) {
            return $default;
        }
        /** @noinspection PhpIncludeInspection */
        $tmp_arr = include($file_name);
        //如果不是数组，或者不存在expire_key或者 value_key
        if (!is_array($tmp_arr) || !isset($tmp_arr[self::EXPIRE_KEY], $tmp_arr[self::VALUE_KEY])) {
            return $default;
        }
        $exp = (int)$tmp_arr[self::EXPIRE_KEY];
        //过期了
        if ($exp < time()) {
            return $default;
        }
        $value = $tmp_arr[self::VALUE_KEY];
        $this->cache_arr[$key] = $value;
        return $value;
    }

    /**
     * 生成文件名
     * @param string $key
     * @return string
     */
    private function makeFileName($key){
        if (!preg_match('/^[a-zA-Z_][a-zA-Z_0-9]*$/', $key)) {
            $key = md5($key);
        }
        if (null === $this->file_path) {
            $this->init();
        }
        return $this->file_path . $key .'.php';
    }

    /**
     * 初始化
     */
    private function init()
    {
        $base_path = defined('FFAN_BASE') ? FFAN_BASE : str_replace('vendor/ffan/php/cache', '', __DIR__);
        $base_dir = isset($conf_arr['cache_dir']) ? trim($conf_arr['cache_dir']) : 'file_cache';
        if (!DIRECTORY_SEPARATOR === $base_dir[0]) {
            $base_dir = FFanUtils::joinPath($base_path, $base_dir);
        }
        $this->file_path = $base_dir;
        //是否有可写权限
        FFanUtils::pathWriteCheck($base_dir);
    }

    /**
     * 生成过期时间
     * @param int $ttl 过期时间
     * @return int
     */
    private function ttl($ttl)
    {
        if (null === $this->default_ttl) {
            $def_ttl = isset($config_set['default_ttl']) ? (int)$config_set['default_ttl'] : 0;
            $this->default_ttl = $def_ttl > 0 ? $def_ttl : 86400;
        }
        if (null === $ttl || $ttl < 0) {
            return $this->default_ttl;
        }
        return $ttl;
    }
    
    /**
     * 设置一个缓存
     * @param string $key 缓存键名
     * @param mixed $value 值
     * @param null|int $ttl 过期时间
     * @return bool
     */
    public function set($key, $value, $ttl = null)
    {
        $ttl = $this->ttl($ttl);
        $this->cache_save[$key] = array($value, $ttl);
    }

    /**
     * 获取一个值，同时将它的token值存起来
     * @param string $key
     * @param null $default
     * @return mixed
     */
    public function casGet($key, $default = null)
    {
        // TODO: Implement casGet() method.
    }

    /**
     * 先比较cas，再做缓存更新（如果未找到cas值，将更新失败）
     * @param string $key 缓存键名
     * @param mixed $value 值
     * @param null|int $ttl 过期时间
     * @return bool
     */
    public function casSet($key, $value, $ttl = null)
    {
        // TODO: Implement casSet() method.
    }

    /**
     * 从缓存中删除一个键
     * @param string $key 缓存名
     * @return bool
     */
    public function delete($key)
    {
        // TODO: Implement delete() method.
    }

    /**
     * 清除整个缓存
     * @return bool
     */
    public function clear()
    {
        // TODO: Implement clear() method.
    }

    /**
     * 获取多个缓存.
     * @param array $keys 缓存键名列表
     * @param mixed $default 当缓存不存在时的默认值
     * @return array 如果值不存在的key会以default填充
     */
    public function getMultiple(array $keys, $default = null)
    {
        // TODO: Implement getMultiple() method.
    }

    /**
     * 批量设置缓存
     * @param array $values 一个key => value 数组
     * @param null|int $ttl
     * @return bool
     */
    public function setMultiple(array $values, $ttl = null)
    {
        // TODO: Implement setMultiple() method.
    }

    /**
     * 批量删除
     * @param array $keys 需要删除的keys
     * @return bool
     */
    public function deleteMultiple(array $keys)
    {
        // TODO: Implement deleteMultiple() method.
    }

    /**
     * 判断缓存中是否有某个值
     * @param string $key 缓存键名
     * @return bool
     */
    public function has($key)
    {
        // TODO: Implement has() method.
    }

    /**
     * 添加一个缓存（必须保证缓存中没有值时才能插入成功）
     * @param string $key 缓存键名
     * @param mixed $value 值
     * @param null|int $ttl 过期时间
     * @return bool
     */
    public function add($key, $value, $ttl = null)
    {
        // TODO: Implement add() method.
    }

    /**
     * 在一个键上做自增
     * @param string $key 键名
     * @param int $step 步长
     * @return bool|int 如果值不存在，将返回false 其它情况元素的值
     */
    public function increase($key, $step = 1)
    {
        // TODO: Implement increase() method.
    }

    /**
     * 在一个键上做自减
     * @param string $key 键名
     * @param int $step 步长
     * @return bool|int 如果值不存在，将返回false
     */
    public function decrease($key, $step = 1)
    {
        // TODO: Implement decrease() method.
    }

    /**
     * 设置一个缓存的过期时间（精确时间）
     * @param string $key 缓存
     * @param int|null $time 时间
     * @return bool
     */
    public function expiresAt($key, $time)
    {
        // TODO: Implement expiresAt() method.
    }

    /**
     * 设置一个缓存有效时间
     * @param string $key 缓存
     * @param int|null $time 时间
     * @return bool
     */
    public function expiresAfter($key, $time)
    {
        // TODO: Implement expiresAfter() method.
    }
}