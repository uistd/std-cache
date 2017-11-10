<?php

namespace FFan\Std\Cache;

use FFan\Std\Common\Env;
use FFan\Std\Console\Debug;
use FFan\Std\Logger\LogHelper;
use FFan\Std\Logger\LogRouter;

/**
 * Class Apc
 * @package FFan\Std\Cache
 */
class Apc implements CacheInterface
{
    /**
     * @var string 配置名
     */
    private $conf_name;

    /**
     * @var int 默认的过期时间
     */
    private $default_ttl;

    /**
     * @var LogRouter
     */
    private $logger;

    /**
     * @var bool 是否调试模式
     */
    private $is_debug = false;

    /**
     * @var array 值列表 用于cas 校验
     */
    private $apc_value_arr;

    /**
     * @var self[]
     */
    private static $instance_arr;

    /**
     * Memcached constructor.
     * @param $config_name
     */
    public function __construct($config_name)
    {
        if (!extension_loaded('apc')) {
            throw new \RuntimeException('Apc(u) extension needed!');
        }
        $this->logger = LogHelper::getLogRouter();
        $this->conf_name = $config_name;
        $this->is_debug = Env::isDev() || Env::isSit();
    }

    /**
     * 生成缓存key
     * @param string $key
     * @return string
     */
    private function keyName($key)
    {
        return $this->conf_name . '_' . $key;
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
            $this->default_ttl = $def_ttl > 0 ? $def_ttl : 1800;
        }
        if (null === $ttl || $ttl < 0) {
            return $this->default_ttl;
        }
        return $ttl;
    }

    /**
     * 获取一个缓存.
     * @param string $key 缓存键值
     * @param mixed $default 如果缓存不存在，返回的默认值
     * @return mixed
     */
    public function get($key, $default = null)
    {
        $real_key = $this->keyName($key);
        $re = apc_fetch($real_key, $is_ok);
        $this->logMsg('get', $key, $is_ok, $re);
        if (false === $is_ok) {
            return $default;
        }
        return $re;
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
        $real_key = $this->keyName($key);
        $re = apc_store($real_key, $value, $ttl);
        $this->logMsg('set', $key, $re, $value);
        //使用  set 方法更新后, 要把apc_value_arr[$key]清理, 之后就不能再用cas_set更新了
        unset($this->apc_value_arr[$key]);
        return true;
    }

    /**
     * 获取一个值，同时将它的token值存起来
     * @param string $key
     * @param null $default
     * @return mixed
     */
    public function casGet($key, $default = null)
    {
        //如果有本地缓存了,直接返回本地的
        if (isset($this->apc_value_arr[$key])) {
            $re = $this->apc_value_arr[$key];
            $this->logMsg('cas_get_from_local_var', $key, true, $re);
        } else {
            $real_key = $this->keyName($key);
            $re = apc_fetch($real_key, $is_ok);
            $this->logMsg('cas_get', $key, $is_ok, $re);
            if ($is_ok) {
                $this->apc_value_arr[$key] = $re;
            } else {
                $re = $default;
            }
        }
        return $re;
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
        //必须使用casGet之后, 才能调用casSet
        if (!isset($this->apc_value_arr[$key])) {
            return false;
        }
        $real_key = $this->keyName($key);
        $old_value = $this->apc_value_arr[$key];
        $re = apc_cas($real_key, $old_value, $value);
        if ($re) {
            $this->apc_value_arr[$key] = $value;
        }
        $this->logMsg('cas_set', $key, $re, $value);
        return $re;
    }

    /**
     * 从缓存中删除一个键
     * @param string $key 缓存名
     * @return bool
     */
    public function delete($key)
    {
        unset($this->apc_value_arr[$key]);
        $real_key = $this->keyName($key);
        $re = apc_delete($real_key);
        $this->logMsg('delete', $key, $re);
        return true;
    }

    /**
     * 清除整个缓存
     * @return bool
     */
    public function clear()
    {
        $re = apc_clear_cache('user');
        $this->logMsg('clear', 'all_keys', $re);
        return true;
    }

    /**
     * 获取多个缓存.
     * @param array $keys 缓存键名列表
     * @param mixed $default 当缓存不存在时的默认值
     * @return array 如果值不存在的key会以default填充
     */
    public function getMultiple(array $keys, $default = null)
    {
        //todo
        return array();
    }

    /**
     * 批量设置缓存
     * @param array $values 一个key => value 数组
     * @param null|int $ttl
     * @return bool
     */
    public function setMultiple(array $values, $ttl = null)
    {
        //todo
        return true;
    }

    /**
     * 批量删除
     * @param array $keys 需要删除的keys
     * @return bool
     */
    public function deleteMultiple(array $keys)
    {
        //todo
        return true;
    }

    /**
     * 判断缓存中是否有某个值
     * @param string $key 缓存键名
     * @return bool
     */
    public function has($key)
    {
        $real_key = $this->keyName($key);
        $re = apc_exists($real_key);
        $this->logMsg('has', $key, $re);
        return $re;
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
        $key_name = $this->keyName($key);
        $ttl = $this->ttl($ttl);
        $re = apc_add($key_name, $value, $ttl);
        $this->logMsg('add', $key, $re, $value);
        return $re;
    }

    /**
     * 在一个键上做自增
     * @param string $key 键名
     * @param int $step 步长
     * @return bool|int 如果值不存在，将返回false 其它情况元素的值
     */
    public function increase($key, $step = 1)
    {
        $key_name = $this->keyName($key);
        $re = apc_inc($key_name, $step, $is_ok);
        $this->logMsg('increase', $key, $is_ok, $re);
        return $re;
    }

    /**
     * 在一个键上做自减
     * @param string $key 键名
     * @param int $step 步长
     * @return bool|int 如果值不存在，将返回false
     */
    public function decrease($key, $step = 1)
    {
        $key_name = $this->keyName($key);
        $re = apc_dec($key_name, $step, $is_ok);
        $this->logMsg('decrease', $key, $is_ok, $re);
        return $re;
    }

    /**
     * 日志消息
     * @param string $action 操作类型
     * @param string|array $key 键名
     * @param bool $is_success 结果
     * @param mixed $result
     */
    private function logMsg($action, $key, $is_success, $result = null)
    {
        $str = Debug::getIoStepStr() . '[Apc ' . $this->conf_name . '][' . $action . ']';
        if (!empty($key)) {
            $str .= $key;
        }
        $str .= $is_success ? ' success' : ' failed';
        $this->logger->info($str);
        if (null !== $result && $this->is_debug) {
            $this->logger->info('[apc result]' . Debug::varFormat($result));
        }
        Debug::addIoStep();
    }

    /**
     * 获取实例
     * @param string $name
     * @return self
     */
    public static function getInstance($name)
    {
        if (!isset(self::$instance_arr[$name])) {
            self::$instance_arr[$name] = new Apc($name);
        }
        return self::$instance_arr[$name];
    }
}
