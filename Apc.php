<?php
namespace ffan\php\cache;

use ffan\php\logger\LoggerFactory;
use ffan\php\utils\Transaction;
use Psr\Log\LoggerInterface;
use ffan\php\utils\Debug as FFanDebug;

/**
 * Class Apc
 * @package ffan\php\cache
 */
class Apc extends Transaction implements CacheInterface
{
    /**
     * @var array 已经获取过的缓存
     */
    private $cache_arr;

    /**
     * @var array 待保存的缓存
     */
    private $cache_save;

    /**
     * @var string key分组
     */
    private $category;

    /**
     * @var string 配置名
     */
    private $conf_name;

    /**
     * @var array 配置项
     */
    private $config_set;

    /**
     * @var int 默认的过期时间
     */
    private $default_ttl;

    /**
     * @var LoggerInterface 日志对象
     */
    private $logger;

    /**
     * Memcached constructor.
     * @param $config_name
     * @param array $config_set
     */
    public function __construct($config_name, array $config_set)
    {
        parent::__construct();
        if (!function_exists('apc_add')) {
            throw new \RuntimeException('Apc(u) extension needed!');
        }
        $this->conf_name = $config_name;
        $this->config_set = $config_set;
        $this->category = isset($config_set['category']) ? $config_set['category'] : $config_name;
    }

    /**
     * 析构
     */
    public function __destruct()
    {
        parent::__destruct();
        $this->cleanup();
    }

    /**
     * 生成缓存key
     * @param string $key
     * @return string
     */
    private function keyName($key)
    {
        return $this->category . '_' . $key;
    }

    /**
     * 获取日志对象
     * @return LoggerInterface
     */
    private function getLogger()
    {
        if (null === $this->logger) {
            if (isset($this->config_set['logger_name'])) {
                $this->logger = LoggerFactory::get($this->config_set['logger_name']);
            } else {
                $this->logger = LoggerFactory::get();
            }
        }
        return $this->logger;
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
        return $this->doGet($key, $default);
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
        $this->cache_save[$key] = [$value, $ttl];
        $msg = $this->logMsg('SET', $key, $value, 'to be commit');
        $this->getLogger()->info($msg);
        return true;
    }

    /**
     * 获取一个缓存.
     * @param string $key 缓存键值
     * @param mixed $default 如果缓存不存在，返回的默认值
     * @param string $get_type 获取方式
     * @return mixed
     */
    private function doGet($key, $default = null, $get_type = 'GET')
    {
        //已经获取过了
        if (isset($this->cache_arr[$key])) {
            $ext_msg = 'from $this->cache_arr';
            $re = $this->cache_arr[$key];
        } //还未写入的
        elseif (isset($this->cache_save[$key])) {
            $ext_msg = 'from $this->cache_save';
            $re = $this->cache_save[$key][0];
        } else {
            $re = apc_fetch($key, $is_ok);
            if (!$is_ok) {
                $ext_msg = 'Not exist';
                $re = $default;
            } else {
                $ext_msg = 'from APC';
                $this->cache_arr[$key] = $re;
            }
        }
        $msg = $this->logMsg($get_type, $key, $re, $ext_msg);
        $this->getLogger()->info($msg);
        return $re;
    }

    /**
     * 获取一个值，同时将它的token值存起来
     * @param string $key
     * @param null $default
     * @return mixed
     */
    public function casGet($key, $default = null)
    {
        $re = $this->doGet($key, $default, 'CAS_GET');
        if (!is_int($re)) {
            $this->getLogger()->warning('CAS_GET ' . $key . ' value must be int');
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
        //必须先获取一次
        if (!isset($this->cache_arr[$key])) {
            $this->getLogger()->warning('CAS_SET ' . $key . ' must get old value first!');
            return false;
        }
        if (!is_int($value)) {
            throw new \InvalidArgumentException('Apc casSet value must be int');
        }
        $old_value = $this->cache_arr[$key];
        if (!is_int($old_value)) {
            return false;
        }
        $save_key = $this->keyName($key);
        $re = apc_cas($save_key, $old_value, $value);
        if ($re) {
            $this->cache_arr[$key] = $value;
        }
        return $re;
    }

    /**
     * 从缓存中删除一个键
     * @param string $key 缓存名
     * @return bool
     */
    public function delete($key)
    {
        $key_name = $this->keyName($key);
        unset($this->cache_save[$key], $this->cache_arr[$key]);
        apc_delete($key_name);
    }

    /**
     * 清除整个缓存
     * @return bool
     */
    public function clear()
    {
        $this->getLogger()->info($this->logMsg('CLEAR', ''));
        apc_clear_cache('user');
    }

    /**
     * 获取多个缓存.
     * @param array $keys 缓存键名列表
     * @param mixed $default 当缓存不存在时的默认值
     * @return array 如果值不存在的key会以default填充
     */
    public function getMultiple(array $keys, $default = null)
    {
        $log_keys = join(',', $keys);
        foreach ($keys as &$key) {
            $key = $this->keyName($key);
        }
        $result = apc_fetch($keys);
        $log_msg = $this->logMsg('GET_MULTI', $log_keys, $result);
        $this->getLogger()->info($log_msg);
        foreach ($result as $key => &$value) {
            if (null === $value) {
                $value = $default;
            }
        }
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
        $log_msg = $this->logMsg('SET_MULTI', join(',', array_keys($values)));
        $this->getLogger()->info($log_msg);
        foreach ($values as $key => $value) {
            $this->set($key, $values);
        }
        return true;
    }

    /**
     * 批量删除
     * @param array $keys 需要删除的keys
     * @return bool
     */
    public function deleteMultiple(array $keys)
    {
        $this->getLogger()->info('APC DELETE_MULTI ' . join(',', array_keys($keys)));
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
        if (isset($this->cache_save[$key]) || isset($this->cache_arr[$key])) {
            $has_cache = true;
        } else {
            $re = apc_fetch($this->keyName($key), $has_cache);
            if ($has_cache) {
                $this->cache_arr[$key] = $re;
            }
        }
        $this->getLogger()->info($this->logMsg('HAS', $key, $has_cache));
        return $has_cache;
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
        $this->getLogger()->info($this->logMsg('ADD', $key, $value, $re ? 'success' : 'failed'));
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
        $re = apc_inc($key_name, $step);
        $this->getLogger()->info($this->logMsg('INCREASE', $key, $re));
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
        $re = apc_dec($key_name, $step);
        $this->getLogger()->info($this->logMsg('DECREASE', $key, $re));
        return $re;
    }

    /**
     * 设置一个缓存的过期时间（精确时间）
     * @param string $key 缓存
     * @param int|null $time 时间
     * @return bool
     */
    public function expiresAt($key, $time)
    {
        return $this->expiresAfter($key, (int)$time - time());
    }

    /**
     * 设置一个缓存有效时间
     * @param string $key 缓存
     * @param int|null $time 时间
     * @return bool
     */
    public function expiresAfter($key, $time)
    {
        //不存在key
        if (!$this->has($key)) {
            return false;
        }
        $ttl = $this->ttl($time);
        $value = $this->get($key);
        $this->set($key, $value, $ttl);
        $this->getLogger()->info($this->logMsg('SET_TTL', $key, $value, 'new ttl:' . $ttl));
        return true;
    }

    /**
     * 提交
     * @return void
     */
    public function commit()
    {
        if (!$this->cache_save) {
            return;
        }
        $this->getLogger()->info($this->logMsg('COMMIT', ''));
        foreach ($this->cache_save as $name => $tmp) {
            $key = $this->keyName($name);
            apc_store($key, $tmp[0], $tmp[1]);
        }
        $this->cache_arr = null;
    }

    /**
     * 回滚
     * @return void
     */
    public function rollback()
    {
        $this->cache_save = null;
    }

    /**
     * 清理内存
     * @return void
     */
    public function cleanup()
    {
        $this->cache_arr = $this->cache_save = null;
    }

    /**
     * 日志消息
     * @param string $action 操作类型
     * @param string $key 键名
     * @param mixed $val 值
     * @param null|string $ext_msg 附加消息
     * @return string
     */
    private function logMsg($action, $key, $val = null, $ext_msg = null)
    {
        $str = '[Apc ' . $this->conf_name . ']' . $action;
        if (!empty($key)) {
            if (is_array($key)) {
                $key = join(',', $key);
            }
            $str .= ' Key:' . $key;
        }
        if (null !== $val) {
            $str .= ' Value:' . FFanDebug::varFormat($val);
        }
        if (null !== $ext_msg) {
            $str .= FFanDebug::varFormat($ext_msg);
        }
        return $str;
    }
}
