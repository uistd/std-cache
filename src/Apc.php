<?php

namespace FFan\Std\Cache;

/**
 * Class Apc
 * @package FFan\Std\Cache
 */
class Apc extends CacheBase implements CacheInterface
{
    /**
     * @var string 配置名
     */
    protected $conf_name;

    /**
     * @var array 值列表 用于cas 校验
     */
    private $apc_value_arr;

    /**
     * Apc constructor.
     * @param string $conf_name
     * @param array $conf_arr
     */
    public function __construct($conf_name, array $conf_arr)
    {
        parent::__construct($conf_name, $conf_arr, 'apc');
        if (!function_exists('apcu_fetch')) {
            throw new \RuntimeException('Apc extension needed!');
        }
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
        if (null === $ttl || $ttl < 0) {
            return 1800;
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
        $re = apcu_fetch($real_key, $is_ok);
        $this->logMsg('get', $key, $is_ok, null, $re);
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
        $re = apcu_store($real_key, $value, $ttl);
        $this->logMsg('set', $key, $re, null, $value);
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
            $this->logMsg('cas_get_from_local_var', $key, true, null, $re);
        } else {
            $real_key = $this->keyName($key);
            $re = apcu_fetch($real_key, $is_ok);
            $this->logMsg('cas_get', $key, $is_ok, null, $re);
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
        $re = apcu_cas($real_key, $old_value, $value);
        if ($re) {
            $this->apc_value_arr[$key] = $value;
        }
        $this->logMsg('cas_set', $key, $re, null, $value);
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
        $re = apcu_delete($real_key);
        $this->logMsg('delete', $key, $re);
        return true;
    }

    /**
     * 清除整个缓存
     * @return bool
     */
    public function clear()
    {
        $re = apcu_clear_cache();
        $this->logMsg('clear', 'all_keys', $re);
        return true;
    }

    /**
     * 获取多个缓存.
     * @param array $keys 缓存键名列表
     * @return array
     */
    public function getMultiple(array $keys)
    {
        $all_keys = array();
        foreach ($keys as $key) {
            $all_keys[$this->keyName($key)] = $key;
        }
        /** @var array $re */
        $re = apcu_fetch(array_keys($all_keys), $is_ok);
        $result = array();
        if ($is_ok) {
            foreach ($re as $real_key => $value) {
                $result[$all_keys[$real_key]] = $value;
            }
        }
        $this->logMsg('get_multi', join(',', $keys), $is_ok, null, $result);
        return $result;
    }

    /**
     * 批量设置缓存
     * @param array $values 一个key => value 数组
     * @param null|int $ttl
     * @return bool
     */
    public function setMultiple(array $values, $ttl = null)
    {
        $new_values = array();
        foreach ($values as $key => $value) {
            $new_values[$this->keyName($key)] = $value;
        }
        $re = apcu_store($new_values, null, $ttl);
        //这里返回 出错的key的数组, 所以空数组 就是完全正确
        $re = empty($re);
        $this->logMsg('set_multi', join(',', array_keys($values)), $re, null, $values);
        return $re;
    }

    /**
     * 批量删除
     * @param array $keys 需要删除的keys
     * @return bool
     */
    public function deleteMultiple(array $keys)
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
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
        $re = apcu_exists($real_key);
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
        $re = apcu_add($key_name, $value, $ttl);
        $this->logMsg('add', $key, $re, null, $value);
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
        $re = apcu_inc($key_name, $step, $is_ok);
        $this->logMsg('increase', $key, $is_ok, null, $re);
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
        $re = apcu_dec($key_name, $step, $is_ok);
        $this->logMsg('decrease', $key, $is_ok, null, $re);
        return $re;
    }
}
