<?php

namespace FFan\Std\Cache;

use FFan\Std\Common\InvalidConfigException;
use FFan\Std\Console\Debug;

/**
 * Class Memcached
 * @package FFan\Std\Cache
 */
class Memcached extends CacheBase implements CacheInterface
{
    /**
     * server 不可用
     */
    const MEMCACHED_SERVER_MARKED_DEAD = 35;

    /**
     * 错误代码 34
     */
    const MEMCACHED_INVALID_HOST_PROTOCOL = 34;

    /**
     * 本地内存不足
     */
    const MEMCACHED_MEMORY_ALLOCATION_FAILURE = 17;

    /**
     * 服务器内存不足
     */
    const MEMCACHED_SERVER_MEMORY_ALLOCATION_FAILURE = 48;

    /**
     * key不正确
     */
    const MEMCACHED_BAD_KEY_PROVIDED = 33;

    /**
     * 数据太大
     */
    const MEMCACHED_E2BIG = 37;

    /**
     * 键名太长
     */
    const MEMCACHED_KEY_TOO_BIG = 38;

    /**
     * 连接对象
     * @var \Memcached
     */
    private $cache_handle;

    /**
     * 键名前缀
     * @var string
     */
    private $key_category = '';

    /**
     * @var bool 是否初始化好了
     */
    private $is_init = false;

    /**
     * @var bool 是否是retry
     */
    private $is_retry_flag = false;

    /**
     * @var bool 缓存是否已经不可用了（缓存不可用时，系统继续）
     */
    private $is_disabled = false;

    /**
     * 存储token值的数组
     * @var array
     */
    private $cas_token_arr;

    /**
     * Memcached constructor.
     * @param $config_name
     * @param array $config_set
     */
    public function __construct($config_name, array $config_set)
    {
        parent::__construct($config_name, $config_set, 'memcached');
    }

    /**
     * 初始化
     */
    private function init()
    {
        if ($this->is_init) {
            return;
        }
        $this->is_init = true;
        if (null == $this->getConfig('server')) {
            throw new InvalidConfigException('Memcache config key is not exist!');
        }
        $this->key_category = $this->conf_name;
        $this->connect();
    }

    /**
     * 连接
     */
    private function connect()
    {
        $cache_obj = new \Memcached($this->conf_name);
        $server_conf = $this->getConfig('server');
        $current_list = $cache_obj->getServerList();
        $need_add = true;
        //检查当前连接的服务器, 是不是配置的服务器
        if (!empty($current_list)) {
            //单台服务器
            if (isset($server_conf['host'])) {
                $tmp_server = $current_list[0];
                if (1 == count($current_list) && $tmp_server['host'] === $server_conf['host'] && $tmp_server['port'] == $server_conf['port']) {
                    $need_add = false;
                }
            } //多台服务器, 但完全一样
            elseif (serialize($server_conf) === serialize($current_list)) {
                $need_add = false;
            }
            //关闭所有已经打开的链接
            if ($need_add) {
                //切换服务器
                $this->logMsg('reset server', 'server list');
                $cache_obj->resetServerList();
            }
        }
        if ($need_add) {
            $cache_obj->setOption(\Memcached::OPT_RECV_TIMEOUT, 1000);
            $cache_obj->setOption(\Memcached::OPT_SEND_TIMEOUT, 1000);
            $cache_obj->setOption(\Memcached::OPT_TCP_NODELAY, true);
            $cache_obj->setOption(\Memcached::OPT_SERVER_FAILURE_LIMIT, 50);
            $cache_obj->setOption(\Memcached::OPT_CONNECT_TIMEOUT, 500);
            $cache_obj->setOption(\Memcached::OPT_RETRY_TIMEOUT, 300);
            $cache_obj->setOption(\Memcached::OPT_DISTRIBUTION, \Memcached::DISTRIBUTION_CONSISTENT);
            $cache_obj->setOption(\Memcached::OPT_REMOVE_FAILED_SERVERS, true);
            $cache_obj->setOption(\Memcached::OPT_LIBKETAMA_COMPATIBLE, true);
            //单台服务器
            if (isset($server_conf['host'])) {
                $ret = $cache_obj->addServer($server_conf['host'], $server_conf['port']);
            } else {
                $ret = $cache_obj->addServers($server_conf);
            }
            $this->logMsg('Add server', 'server', $ret, $server_conf);
            //如果添加服务器失败，表示系统已经不可用
            if (false === $ret) {
                $this->is_disabled = true;
                $this->logResultMessage($cache_obj->getResultCode());
            }
        }
        $this->cache_handle = $cache_obj;
    }

    /**
     * 重连
     */
    private function reconnect()
    {
        $this->logMsg('reconnect');
        $this->cache_handle->resetServerList();
        $this->connect();
    }

    /**
     * 键名包装，加统一前缀防冲突
     * @param string $key 键名
     * @return string
     */
    private function makeKey($key)
    {
        if (!$this->is_init) {
            $this->init();
        }
        if (empty($this->key_category)) {
            return $key;
        }
        return $this->key_category . '.' . $key;
    }

    /**
     * 获取连接句柄
     * @return \Memcached
     */
    private function getCacheHandle()
    {
        if (!$this->is_init) {
            $this->init();
        }
        return $this->cache_handle;
    }

    /**
     * 解出真实的key名
     * @param string $name
     * @return string
     */
    private function unpackKey($name)
    {
        if (empty($this->key_category)) {
            return $name;
        }
        $prefix = $this->key_category . '.';
        return substr($name, strlen($prefix));
    }

    /**
     * 获取一个缓存.
     * @param string $key 缓存键值
     * @param mixed $default 如果缓存不存在，返回的默认值
     * @return mixed
     */
    public function get($key, $default = null)
    {
        //系统已经不可用了
        if ($this->is_disabled) {
            return $default;
        }
        $cache_handle = $this->getCacheHandle();
        //save_key必须在getCacheHandle之后
        $save_key = $this->makeKey($key);
        Debug::timerStart();
        $ret = $cache_handle->get($save_key);
        $this->logMsg('Get', $key, $ret, Debug::timerStop(), $ret);
        $result_code = 0;
        if (false === $ret) {
            $result_code = $cache_handle->getResultCode();
            if ($result_code > 0) {
                $this->logResultMessage($result_code);
                //服务器不可用，重试
                if (self::MEMCACHED_SERVER_MARKED_DEAD === $result_code) {
                    return $this->retry('get', func_get_args());
                }
            }
        }
        //如果ret是false 并且 result_code 不为0, 表示 false 不是缓存值，而是失败的返回值
        if (false === $ret && $result_code > 0) {
            $ret = $default;
        }
        return $ret;
    }

    /**
     * 当缓存服务器不可用时，自动重试
     * @param string $func_name 方法名
     * @param array $args
     * @return mixed
     */
    private function retry($func_name, $args)
    {
        $func = array($this, $func_name);
        //如果重试后，又调用了retry函数，表示服务已经不可用了
        if ($this->is_retry_flag) {
            $this->errorMsg('server down');
            $this->is_disabled = true;
        }//重连服务器
        else {
            $this->is_retry_flag = true;
            $this->reconnect();
        }
        $ret = call_user_func_array($func, $args);
        $this->is_retry_flag = false;
        return $ret;
    }

    /**
     * 返回生成时间
     * @param null|int $ttl 过期时间
     * @return int
     */
    private function ttl($ttl)
    {
        $ttl = (int)$ttl;
        if ($ttl < 1) {
            $ttl = 1800;
        }
        return $ttl;
    }

    /**
     * 设置一个缓存，并不立即和服务器交互
     * @param string $key 缓存键名
     * @param mixed $value 值
     * @param null|int $ttl 过期时间
     * @return bool
     */
    public function set($key, $value, $ttl = null)
    {
        if ($this->is_disabled) {
            return false;
        }
        //如果之前是通过casGet获取到的值，更新的时候也要按casSet方式更新
        if (isset($this->cas_token_arr[$key])) {
            return $this->casSet($key, $value, $ttl);
        }
        $ttl = $this->ttl($ttl);
        $cache_handle = $this->getCacheHandle();
        Debug::timerStart();
        $ret = $cache_handle->set($key, $value, $ttl);
        $this->logMsg('set', $key, $ret, Debug::timerStop(), $value);
        return $ret;
    }

    /**
     * 从缓存中删除一个键
     * @param string $key 缓存键名
     * @return bool
     */
    public function delete($key)
    {
        if ($this->is_disabled) {
            return true;
        }
        $cache_handle = $this->getCacheHandle();
        $save_key = $this->makeKey($key);
        Debug::timerStart();
        $ret = $cache_handle->delete($save_key);
        $this->logMsg('Delete', $key, $ret, Debug::timerStop());
        if (false === $ret) {
            $result_code = $cache_handle->getResultCode();
            $this->logResultMessage($result_code);
            if (self::MEMCACHED_SERVER_MARKED_DEAD === $result_code) {
                return $this->retry('remove', array($key));
            }
        }
        return $ret;
    }

    /**
     * 清除整个缓存
     * @return bool
     */
    public function clear()
    {
        if ($this->is_disabled) {
            return true;
        }
        $cache_handle = $this->getCacheHandle();
        $ret = $cache_handle->flush();
        $this->logMsg('Flush');
        if (false === $ret) {
            $result_code = $cache_handle->getResultCode();
            $this->logResultMessage($result_code);
            if (self::MEMCACHED_SERVER_MARKED_DEAD === $result_code) {
                return $this->retry('clear', null);
            }
        }
        return $ret;
    }

    /**
     * 获取多个缓存.
     * @param array $keys 缓存键名列表
     * @param mixed $default 当缓存不存在时的默认值
     * @return array 如果值不存在的key会以default填充
     */
    public function getMultiple(array $keys, $default = null)
    {
        $result = array();
        //已经不需要和服务器交互了，最理想的情况
        if (empty($keys)) {
            return $result;
        }//服务器已经不可用了
        elseif ($this->is_disabled) {
            foreach ($keys as $name) {
                $result[$name] = $default;
            }
            return $result;
        } else {
            $cache_handle = $this->getCacheHandle();
            $new_keys = array();
            foreach ($keys as $name) {
                $new_keys[] = $this->makeKey($name);
            }
            Debug::timerStart();
            $result_list = $cache_handle->getMulti($new_keys, $cas_arr, \Memcached::GET_PRESERVE_ORDER);
            $this->logMsg('GetMultiple', join(',', $keys), Debug::timerStop(), $result_list);
            if (false === $result_list) {
                $result_code = $cache_handle->getResultCode();
                $this->logResultMessage($result_code);
                //服务器已经不可用
                if (self::MEMCACHED_SERVER_MARKED_DEAD === $result_code) {
                    return $this->retry('getMultiple', func_get_args());
                }
                $result_list = array();
            }
            foreach ($result_list as $name => $value) {
                //这里要还原真实的key
                $name = $this->unpackKey($name);
                //表示不存在，设置成false，为了isset
                if (null === $value) {
                    $result[$name] = $default;
                } else {
                    $result[$name] = $value;
                }
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
        if ($this->is_disabled) {
            return false;
        }
        $cache_handle = $this->getCacheHandle();
        $ttl = $this->ttl($ttl);
        $new_values = array();
        foreach ($values as $key => $value) {
            $new_values[$this->makeKey($key)] = $value;
        }
        Debug::timerStart();
        $ret = $cache_handle->setMulti($new_values, $ttl);
        $this->logMsg('setMultiple', array_keys($values), $ret, Debug::timerStop());
        return $ret;
    }

    /**
     * 批量删除
     * @param array $keys 需要删除的keys
     * @return bool
     */
    public function deleteMultiple(array $keys)
    {
        if ($this->is_disabled) {
            return false;
        }
        $cache_handle = $this->getCacheHandle();
        $new_keys = array();
        foreach ($keys as $name) {
            $new_keys[] = $this->makeKey($name);
        }
        Debug::timerStart();
        $ret = $cache_handle->deleteMulti($new_keys);
        $this->logMsg('deleteMultiple', join(',', $keys), $ret, Debug::timerStop());
        if (false === $ret) {
            $result_code = $cache_handle->getResultCode();
            $this->logResultMessage($result_code);
            if (self::MEMCACHED_SERVER_MARKED_DEAD === $result_code) {
                return $this->retry('deleteMultiple', array($keys));
            }
        }
        return $ret;
    }

    /**
     * 判断缓存中是否有某个值
     * @param string $key 缓存键名
     * @return bool
     */
    public function has($key)
    {
        return null !== $this->get($key, null);
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
        //如果服务器不可用了，直接返回false
        if ($this->is_disabled) {
            return false;
        }
        $ttl = $this->ttl($ttl);
        $cache_handle = $this->getCacheHandle();
        $save_key = $this->makeKey($key);
        Debug::timerStart();
        $ret = $cache_handle->add($save_key, $value, $ttl);
        $this->logMsg('Add', $key, $ret, Debug::timerStop(), $value);
        if (false === $ret) {
            $result_code = $cache_handle->getResultCode();
            $this->logResultMessage($result_code);
            //如果是断开连接了, 自动重连
            if (self::MEMCACHED_SERVER_MARKED_DEAD === $result_code) {
                return $this->retry('add', func_get_args());
            }
        }
        return $ret;
    }

    /**
     * 在一个键上做自增
     * @param string $key 键名
     * @param int $step 步长
     * @return bool|int 如果值不存在，将返回false 其它情况元素的值
     */
    public function increase($key, $step = 1)
    {
        if ($this->is_disabled) {
            return false;
        }
        $cache_handle = $this->getCacheHandle();
        $save_key = $this->makeKey($key);
        Debug::timerStart();
        $ret = $cache_handle->increment($save_key, $step);
        $this->logMsg('Increment', $key, $ret, Debug::timerStop(), $ret);
        if (false === $ret) {
            $result_code = $cache_handle->getResultCode();
            $this->logResultMessage($result_code);
            if (self::MEMCACHED_SERVER_MARKED_DEAD === $result_code) {
                return $this->retry('increment', func_get_args());
            }
        }
        return $ret;
    }

    /**
     * 在一个键上做自减
     * @param string $key 键名
     * @param int $step 步长
     * @return bool|int 如果值不存在，将返回false
     */
    public function decrease($key, $step = 1)
    {
        if ($this->is_disabled) {
            return false;
        }
        $cache_handle = $this->getCacheHandle();
        $save_key = $this->makeKey($key);
        Debug::timerStart();
        $ret = $cache_handle->decrement($save_key, $step);
        $this->logMsg('Decrement', $key, $ret, Debug::timerStop(), $ret);
        if (false === $ret) {
            $result_code = $cache_handle->getResultCode();
            $this->logResultMessage($result_code);
            if (self::MEMCACHED_SERVER_MARKED_DEAD === $result_code) {
                return $this->retry('decrement', func_get_args());
            }
        }
        return $ret;
    }

    /**
     * 记录memcached返回消息
     * @param int $ret_code
     */
    public function logResultMessage($ret_code)
    {
        static $code_arr = array(
            \Memcached::RES_NO_SERVERS => true,
            \Memcached::RES_TIMEOUT => true,
            \Memcached::RES_CONNECTION_SOCKET_CREATE_FAILURE => true,
            \Memcached::RES_PAYLOAD_FAILURE => true,
            \Memcached::RES_UNKNOWN_READ_FAILURE => true,
            \Memcached::RES_PROTOCOL_ERROR => true,
            \Memcached::RES_SERVER_ERROR => true,
            \Memcached::RES_CLIENT_ERROR => true,
            //以下的并不是预定义变量
            self::MEMCACHED_SERVER_MARKED_DEAD => true,
            self::MEMCACHED_INVALID_HOST_PROTOCOL => true,
            self::MEMCACHED_MEMORY_ALLOCATION_FAILURE => true,
            self::MEMCACHED_SERVER_MEMORY_ALLOCATION_FAILURE => true,
            self::MEMCACHED_BAD_KEY_PROVIDED => true,
            self::MEMCACHED_E2BIG => true,
            self::MEMCACHED_KEY_TOO_BIG => true
        );
        //不在需要记录的列表里
        if (!isset($code_arr[$ret_code])) {
            return;
        }
        $msg = ' resultCode:' . $ret_code . ' resultMessage:' . $this->cache_handle->getResultMessage();
        $this->logMsg('Ret_code', $msg);
    }

    /**
     * 获取一个值，同时将它的token值存起来
     * @param string $key 缓存键名
     * @param null $default 默认值
     * @return mixed
     */
    public function casGet($key, $default = null)
    {
        //系统已经不可用了
        if ($this->is_disabled) {
            return $default;
        }
        $cache_handle = $this->getCacheHandle();
        //save_key必须在getCacheHandle之后
        $save_key = $this->makeKey($key);
        Debug::timerStart();
        $ret = $cache_handle->get($save_key, null, $token);
        $this->logMsg('casGet', $key, $ret, Debug::timerStop(), $ret);
        $result_code = 0;
        if (false === $ret) {
            $result_code = $cache_handle->getResultCode();
            if ($result_code > 0) {
                $this->logResultMessage($result_code);
                //服务器不可用，重试
                if (self::MEMCACHED_SERVER_MARKED_DEAD === $result_code) {
                    return $this->retry('casGet', func_get_args());
                }
            }
        }
        //如果ret是false 并且 result_code 不为0, 表示 false 不是缓存值，而是失败的返回值
        if (false === $ret && $result_code > 0) {
            $ret = $default;
        } else {
            $this->cas_token_arr[$key] = $token;
        }
        return $ret;
    }

    /**
     * 先比较cas，再做缓存更新（如果未找到cas值，将更新失败）
     * @param string $key 缓存键名
     * @param mixed $value 值
     * @param null|int $ttl 过期时间
     * @param null|float $token token值
     * @return bool
     */
    public function casSet($key, $value, $ttl = null, $token = null)
    {
        if ($this->is_disabled) {
            return false;
        }
        //之前没有获取过token值，无法进行下去
        if (null === $token) {
            if (!isset($this->cas_token_arr[$key])) {
                return false;
            }
            $token = $this->cas_token_arr[$key];
        }
        $cache_handle = $this->getCacheHandle();
        $save_key = $this->makeKey($key);
        $ttl = $this->ttl($ttl);
        Debug::timerStart();
        $ret = $cache_handle->cas($token, $save_key, $value, $ttl);
        $this->logMsg('CasSet', $key, $ret,Debug::timerStop(), $value);
        if (false === $ret) {
            $result_code = $cache_handle->getResultCode();
            $this->logResultMessage($result_code);
            //服务器不可用，重试
            if (self::MEMCACHED_SERVER_MARKED_DEAD === $result_code) {
                return $this->retry('casSet', func_get_args());
            }
        } else {
            unset($this->cas_token_arr[$key]);
        }
        return $ret;
    }
}
