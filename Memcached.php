<?php
namespace ffan\php\cache;

use ffan\php\logger\LoggerFactory;
use ffan\php\utils\Debug as FFanDebug;
use ffan\php\utils\Config as FFanConfig;
use ffan\php\utils\InvalidConfigException;
use Psr\log\LoggerInterface;

/**
 * Class Memcached
 * @package ffan\php\cache
 */
class Memcached implements CatchInterface
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
     * 已经获取的缓存
     * @var array
     */
    private $cache_arr;

    /**
     * 待保存的缓存
     * @var array
     */
    private $cache_save;

    /**
     * 新增加的cache
     * @var array
     */
    private $cache_add;

    /**
     * 准备删除的键
     * @var array
     */
    private $del_arr;

    /**
     * 连接对象
     * @var \Memcached
     */
    private $_cache_handle;

    /**
     * 配置的缓存类
     * @var string
     */
    private $_conf_name;

    /**
     * 是否需要提交
     * @var bool
     */
    private $need_commit = false;

    /**
     * 键名前缀
     * @var string
     */
    private $_key_category = '';

    /**
     * @var bool 是否初始化好了
     */
    private $_is_init = false;

    /**
     * @var LoggerInterface 日志
     */
    private $_logger;

    /**
     * @var int 默认的缓存生存时间
     */
    private $_default_ttl = 7200;

    /**
     * @var bool 是否是retry
     */
    private $_is_retry = false;

    /**
     * @var bool 缓存是否已经不可用了（缓存不可用时，系统继续）
     */
    private $_is_disable = false;

    /**
     * Memcached constructor.
     * @param string $conf_name 配置名称
     */
    public function __construct($conf_name)
    {
        $this->_conf_name = $conf_name;
    }

    /**
     * 初始化
     */
    private function init()
    {
        if ($this->_is_init) {
            return;
        }
        $this->_is_init = true;
        $config_key = 'cache.' . $this->_conf_name;
        $conf_arr = FFanConfig::get($config_key);
        if (!is_array($conf_arr) || !isset($conf_arr['server'])) {
            throw new InvalidConfigException($config_key);
        }
        //如果设置了分类，所有的键名前都会加上分类前缀
        if (isset($conf_arr['category'])) {
            $this->_key_category = $conf_arr['category'];
        }
        //默认的缓存生存期
        if (isset($conf_arr['default_ttl'])) {
            $default_ttl = (int)$conf_arr['default_ttl'];
            if ($default_ttl > 0) {
                $this->_default_ttl = $default_ttl;
            }
        }
        $this->_logger = LoggerFactory::get(isset($conf_arr['log_conf']) ? $conf_arr['log_conf'] : null);
        $this->connect();
    }

    /**
     * 连接
     */
    private function connect()
    {
        $cache_obj = new \Memcached($this->_conf_name);
        $conf_arr = FFanConfig::get('cache.' . $this->_conf_name);
        $server_conf = $conf_arr['server'];
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
                $this->_logger->notice($this->logMsg('remove server', 'server list', $current_list));
                $this->_logger->notice($this->logMsg('add server', 'new server list', $server_conf));
                $cache_obj->resetServerList();
            }
        }
        if ($need_add) {
            $this->_logger->info($this->logMsg('Add server', '', $server_conf));
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
                $cache_obj->addServer($server_conf['host'], $server_conf['port']);
            } else {
                $cache_obj->addServers($server_conf);
            }
            $this->logResultMessage($cache_obj->getResultCode(), 'Add server', $server_conf);
        }
        $this->_cache_handle = $cache_obj;
    }

    /**
     * 重连
     */
    private function reconnect()
    {
        $this->_logger->notice($this->logMsg('reconnect'));
        $this->_cache_handle->resetServerList();
        $this->connect();
    }

    /**
     * 键名包装，加统一前缀防冲突
     * @param string $key 键名
     * @return string
     */
    private function make_key($key)
    {
        if (empty($this->_key_category)) {
            return $key;
        }
        return $this->_key_category . '.' . $key;
    }

    /**
     * 解出真实的key名
     * @param string $name
     * @return string
     */
    private function unpackKey($name)
    {
        if (empty($this->_key_category)) {
            return $name;
        }
        $prefix = $this->_key_category . '.';
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
        if (null === $this->cache_arr) {
            $this->cache_arr = [];
        }
        //在缓存里已经有值
        if (isset($this->cache_arr[$key])) {
            $ret = $this->cache_arr[$key];
            if (false === $ret) {
                $ret = $default;
            }
            return $ret;
        }
        //已经删除了
        if (isset($this->del_arr[$key])) {
            return $default;
        }
        if (!$this->$this->_is_init) {
            $this->init();
        }
        $save_key = $this->make_key($key);
        //系统已经不可用了
        if ($this->_is_disable) {
            return $default;
        }
        $ret = $this->_cache_handle->get($save_key);
        $result_code = $this->_cache_handle->getResultCode();
        $this->logResultMessage($result_code, 'Get', $key);
        //未找到，将ret置false
        if (\Memcached::RES_NOTFOUND === $result_code) {
            $ret = false;
        }//服务器不可用，重试
        else if (self::MEMCACHED_SERVER_MARKED_DEAD === $result_code) {
            return $this->retry(array($this, 'get'), func_get_args());
        }
        $this->_logger->debug($this->logMsg('Get', $key, $ret));
        //缓存起来
        $this->cache_arr[$key] = $ret;
        if (false === $ret) {
            $ret = null;
        }
        return $ret;
    }

    /**
     * 当缓存服务器不可用时，自动重试
     * @param callable $func 方法
     * @param array $args
     * @return mixed
     */
    private function retry($func, $args)
    {
        //如果重试后，又调用了retry函数，表示服务已经不可用了
        if ($this->_is_retry) {
            $this->_logger->error('Server down!!!');
            $this->_is_disable = true;
        }//重连服务器
        else {
            $this->_is_retry = true;
            $this->reconnect();
        }
        $ret = call_user_func_array($func, $args);
        $this->_is_retry = false;
        return $ret;
    }

    /**
     * 返回生成时间
     * @param null|int $ttl 过期时间
     * @return int
     */
    private function ttl($ttl)
    {
        if (null === $ttl) {
            return $this->_default_ttl;
        } else {
            $ttl = (int)$ttl;
            if ($ttl < 1) {
                $ttl = $this->_default_ttl;
            }
            return $ttl;
        }
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
        $ttl = $this->ttl($ttl);
        if (null === $this->cache_arr) {
            $this->cache_arr = [];
        }
        if (null === $this->cache_save) {
            $this->cache_save = [];
        }
        $this->cache_arr[$key] = $value;
        $this->need_commit = true;
        $this->cache_save[$key] = array($value, $ttl);
    }

    /**
     * 从缓存中删除一个键
     * @param string $key The unique cache key of the item to delete.
     * @return bool
     */
    public function delete($key)
    {
        if (null === $this->del_arr) {
            $this->del_arr = [];
        }
        $this->del_arr[$key] = true;
        return true;
    }

    /**
     * 清除整个缓存
     * @return bool
     */
    public function clear()
    {
        $this->_logger->notice($this->logMsg('Flush'));
        $this->cleanup();
        if (!$this->_is_init) {
            $this->init();
        }
        $this->_cache_handle->flush();
    }

    /**
     * 获取多个缓存.
     * @param array $keys 缓存键名列表
     * @param mixed $default 当缓存不存在时的默认值
     * @return array 如果值不存在的key会以default填充
     */
    public function getMultiple($keys, $default = null)
    {
        if (!$this->_is_init) {
            $this->init();
        }
        if (null === $this->cache_arr) {
            $this->cache_arr = [];
        }
        $result = array();
        //先检查在本地数组里有没能， 或者有没有被删除
        if (!empty($this->cache_arr) || !empty($this->del_arr)) {
            foreach ($keys as $i => $name) {
                if (isset($this->cache_arr[$name])) {
                    $result[$name] = false === $this->cache_arr[$name] ? $default : $this->cache_arr[$name];
                    unset($keys[$i]);
                } elseif (isset($this->del_arr[$name])) {
                    $result[$name] = $default;
                    unset($keys[$i]);
                }
            }
        }
        //已经不需要和服务器交互了，最理想的情况
        if (empty($keys)) {
            return $result;
        }//服务器已经不可用了
        elseif ($this->_is_disable) {
            foreach ($keys as $name) {
                $result[$name] = $default;
            }
            return $result;
        } else {
            $cas_arr = array();
            $new_keys = array();
            foreach ($keys as $name) {
                $new_keys[] = $this->make_key($name);
            }
            $result_list = $this->_cache_handle->getMulti($new_keys, $cas_arr, \Memcached::GET_PRESERVE_ORDER);
            $result_code = $this->_cache_handle->getResultCode();
            $this->logResultMessage($result_code, 'Get', $new_keys);
            //服务器已经不可用
            if (self::MEMCACHED_SERVER_MARKED_DEAD === $result_code) {
                return $this->retry(array($this, 'getMultiple'), func_get_args());
            }
            $this->_logger->info($this->logMsg('GetMultiple', $keys, $result_list));
            foreach ($result_list as $name => $value) {
                //这里要还原真实的key
                $name = $this->unpackKey($name);
                //表示不存在，设置成false，为了isset
                if (null === $value) {
                    $value = false;
                    $result[$name] = $default;
                } else {
                    $result[$name] = $value;
                }
                $this->cache_arr[$name] = $value;
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
    public function setMultiple($values, $ttl = null)
    {
        $ttl = $this->ttl($ttl);
        if (null === $this->cache_arr) {
            $this->cache_arr = [];
        }
        if (null === $this->cache_save) {
            $this->cache_save = [];
        }
        $this->need_commit = true;
        foreach ($values as $name => $value) {
            $this->cache_arr[$name] = $value;
            $this->cache_save[$name] = array($value, $ttl);
        }
        return true;
    }

    /**
     * 批量删除
     * @param array $keys 需要删除的keys
     * @return bool
     */
    public function deleteMultiple($keys)
    {
        if (null === $this->del_arr) {
            $this->del_arr = [];
        }
        foreach ($keys as $name) {
            $this->del_arr[$name] = true;
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
        return null !== $this->get($key, null);
    }

    /**
     * 添加一个缓存（必须保证缓存中没有值时才能插入成功）
     * @param string $key 缓存键名
     * @param mixed $value 值
     * @param null|int $ttl 过期时间
     * @return bool
     */
    public function add($key, $value, $ttl)
    {
        //已经有了这个key了
        if (isset($this->cache_arr[$key])) {
            return false;
        }
        //如果服务器不可用了，直接返回false
        if (!$this->_is_disable) {
            return false;
        }
        if (!$this->_is_init) {
            $this->init();
        }
        if (null === $this->cache_add) {
            $this->cache_add = [];
        }
        $save_key = $this->make_key($key);
        $ret = $this->_cache_handle->add($save_key, $value, $ttl);
        $result_code = $this->_cache_handle->getResultCode();
        $this->logResultMessage($result_code, 'Add', $key);
        //如果是断开连接了, 自动重连
        if (!$ret && self::MEMCACHED_SERVER_MARKED_DEAD === $result_code ) {
            return $this->retry('add', func_get_args());
        }
        $this->cache_arr[$key] = $value;
        $this->cache_add[] = $key;
        $this->_logger->info($this->logMsg('Add', $key, $ret));
        return $ret;
    }

    /**
     * 在一个键上做自增
     * @param string $key 键名
     * @param int $step 步长
     * @return bool|int 如果值不存在，将返回false 其它情况元素的值
     */
    public function increment($key, $step = 1)
    {
        if ($this->_is_disable) {
            return false;
        }
        if (!$this->_is_init) {
            $this->init();
        }
        $save_key = $this->make_key($key);
        $ret = $this->_cache_handle->increment($save_key, $step);
        $result_code = $this->_cache_handle->getResultCode();
        $this->logResultMessage($result_code, 'Increment', $key);
        if (false === $ret && self::MEMCACHED_SERVER_MARKED_DEAD === $result_code) {
            return $this->retry('increment', func_get_args());
        }
        $this->_logger->info($this->logMsg('Increment', $key, $ret));
        return $ret;
    }

    /**
     * 在一个键上做自减
     * @param string $key 键名
     * @param int $step 步长
     * @return bool 如果值不存在，将返回false
     */
    public function decrement($key, $step = 1)
    {
        if ($this->_is_disable) {
            return false;
        }
        if (!$this->_is_init) {
            $this->init();
        }
        $save_key = $this->make_key($key);
        $ret = $this->_cache_handle->decrement($save_key, $step);
        $result_code = $this->_cache_handle->getResultCode();
        $this->logResultMessage($result_code, 'Decrement', $key);
        if (false === $ret && self::MEMCACHED_SERVER_MARKED_DEAD === $result_code) {
            return $this->retry('decrement', func_get_args());
        }
        $this->_logger->info($this->logMsg('Decrement', $key, $ret));
        return $ret;
    }

    /**
     * 设置一个缓存的过期时间（精确时间）
     * @param int|\DateInterval|null $expiration
     * @return bool
     */
    public function expiresAt($expiration)
    {
        // TODO: Implement expiresAt() method.
    }

    /**
     * 设置一个缓存有效时间
     * @param int|\DateInterval|null $time
     * @return bool
     */
    public function expiresAfter($time)
    {
        // TODO: Implement expiresAfter() method.
    }

    /**
     * 提交
     * @return void
     */
    public function commit()
    {
        // TODO: Implement commit() method.
    }

    /**
     * 回滚
     * @return void
     */
    public function rollback()
    {
        // TODO: Implement rollback() method.
    }

    /**
     * 清理内存
     * @return void
     */
    public function cleanup()
    {
        // TODO: Implement cleanup() method.
    }

    /**
     * 生成日志信息
     * @param string $action
     * @param string|array $key
     * @param mixed $val
     * @return string
     */
    public function logMsg($action, $key = '', $val = null)
    {
        $str = '[Memcached ' . $this->_conf_name . ']' . $action;
        if (!empty($key)) {
            if (is_array($key)) {
                $key = join(',', $key);
            }
            $str .= ' Key:' . $key;
        }
        if (null !== $val) {
            $str .= ' Value:' . FFanDebug::varFormat($val);
        }
        return $str;
    }

    /**
     * 记录memcached返回消息
     * @param int $ret_code
     * @param string $action
     * @param string|array $key
     */
    public function logResultMessage($ret_code, $action, $key)
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
        if (!isset($code_arr[$ret_code])){
            return;
        }
        $msg = ' resultCode:' . $ret_code . ' resultMessage:' . $this->_cache_handle->getResultMessage();
        $this->_logger->warning($this->logMsg($action, $key, $msg));
    }

    /**
     * 获取一个值，同时将它的token值存起来
     * @param string $key
     * @param null $default
     * @return mixed
     */
    public function cas_get($key, $default = null)
    {
        // TODO: Implement cas_get() method.
    }

    /**
     * 先比较cas，再做缓存更新（如果未找到cas值，将更新失败）
     * @param float $cas_token token值
     * @param string $key 缓存键名
     * @param mixed $value 值
     * @param null|int $ttl 过期时间
     * @return bool
     */
    public function cas_set($cas_token, $key, $value, $ttl = null)
    {
        // TODO: Implement cas_set() method.
    }
}
