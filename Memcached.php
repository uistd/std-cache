<?php
namespace ffan\php\cache;

use ffan\php\logger\LoggerFactory;
use ffan\php\utils\Debug as FFanDebug;
use ffan\php\utils\InvalidConfigException;
use ffan\php\utils\Transaction;
use Psr\log\LoggerInterface;

/**
 * Class Memcached
 * @package ffan\php\cache
 */
class Memcached extends Transaction implements CacheInterface
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
     * 连接对象
     * @var \Memcached
     */
    private $cache_handle;

    /**
     * 配置的缓存类
     * @var string
     */
    private $conf_name;

    /**
     * @var array 配置
     */
    private $config_set;

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
     * @var LoggerInterface 日志
     */
    private $logger_handle;

    /**
     * @var int 默认的缓存生存时间
     */
    private $default_ttl = 7200;

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
        parent::__construct();
        $this->conf_name = $config_name;
        $this->config_set = $config_set;
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
        $conf_arr = $this->config_set;
        if (!isset($conf_arr['server'])) {
            throw new InvalidConfigException(CacheFactory::configGroupName($this->conf_name, 'server'), 'key is not exist!');
        }
        //如果设置了分类，所有的键名前都会加上分类前缀
        if (isset($conf_arr['category'])) {
            $this->key_category = $conf_arr['category'];
        }
        //默认的缓存生存期
        if (isset($conf_arr['default_ttl'])) {
            $default_ttl = (int)$conf_arr['default_ttl'];
            if ($default_ttl > 0) {
                $this->default_ttl = $default_ttl;
            }
        }
        //如果指定了日志对象
        if (isset($conf_arr['logger_name'])) {
            $this->logger_handle = LoggerFactory::get($conf_arr['logger_name']);
        } else {
            $this->logger_handle = LoggerFactory::get();
        }
        $this->connect();
    }

    /**
     * 连接
     */
    private function connect()
    {
        $cache_obj = new \Memcached($this->conf_name);
        $conf_arr = $this->config_set;
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
                $this->logger_handle->notice($this->logMsg('remove server', 'server list', $current_list));
                $this->logger_handle->notice($this->logMsg('add server', 'new server list', $server_conf));
                $cache_obj->resetServerList();
            }
        }
        if ($need_add) {
            $this->logger_handle->info($this->logMsg('Add server', '', $server_conf));
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
            //如果添加服务器失败，表示系统已经不可用
            if (false === $ret) {
                $this->logResultMessage($cache_obj->getResultCode(), 'Add server, ret:' . $ret, $server_conf);
                $this->is_disabled = true;
            }
        }
        $this->cache_handle = $cache_obj;
    }

    /**
     * 重连
     */
    private function reconnect()
    {
        $this->logger_handle->notice($this->logMsg('reconnect'));
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
        //在缓存里已经有值
        if (isset($this->cas_token_arr[$key])) {
            $ret = $this->cache_arr[$key];
            $this->logger_handle->debug($this->logMsg('Get', $key, $ret, 'from $this->cache_arr'));
            return $ret;
        }
        //系统已经不可用了
        if ($this->is_disabled) {
            return $default;
        }
        $cache_handle = $this->getCacheHandle();
        //save_key必须在getCacheHandle之后
        $save_key = $this->makeKey($key);
        $ret = $cache_handle->get($save_key);
        $this->logger_handle->debug($this->logMsg('Get', $key, $ret));
        $result_code = 0;
        if (false === $ret) {
            $result_code = $cache_handle->getResultCode();
            if ($result_code > 0) {
                $this->logResultMessage($result_code, 'Get', $key);
                //服务器不可用，重试
                if (self::MEMCACHED_SERVER_MARKED_DEAD === $result_code) {
                    return $this->retry(array($this, 'get'), func_get_args());
                }
            }
        }
        //如果ret是false 并且 result_code 不为0, 表示 false 不是缓存值，而是失败的返回值
        if (false === $ret && $result_code > 0) {
            $ret = $default;
        } else {
            $this->cache_arr[$key] = $ret;
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
            $this->logger_handle->error('Server down!!!');
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
        if (null === $ttl) {
            return $this->default_ttl;
        } else {
            $ttl = (int)$ttl;
            if ($ttl < 1) {
                $ttl = $this->default_ttl;
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
        //如果之前是通过casGet获取到的值，更新的时候也要按casSet方式更新
        if (isset($this->cas_token_arr[$key])) {
            return $this->casSet($key, $value, $ttl);
        }
        $ttl = $this->ttl($ttl);
        $this->cache_arr[$key] = $value;
        $this->cache_save[$key] = array($value, $ttl);
        return true;
    }

    /**
     * 从缓存中删除一个键
     * @param string $key 缓存键名
     * @return bool
     */
    public function delete($key)
    {
        unset($this->cache_arr[$key], $this->cache_save[$key], $this->cas_token_arr[$key]);
        if ($this->is_disabled) {
            return true;
        }
        $cache_handle = $this->getCacheHandle();
        $save_key = $this->makeKey($key);
        $ret = $cache_handle->delete($save_key);
        $this->logger_handle->info($this->logMsg('Delete', $key));
        if (false === $ret) {
            $result_code = $cache_handle->getResultCode();
            $this->logResultMessage($result_code, 'Delete', $key);
            if (self::MEMCACHED_SERVER_MARKED_DEAD === $result_code) {
                return $this->retry('remove', $key);
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
        $this->cleanup();
        $ret = $cache_handle->flush();
        $this->logger_handle->notice($this->logMsg('Flush'));
        if (false === $ret) {
            $result_code = $cache_handle->getResultCode();
            $this->logResultMessage($result_code, 'Flush', '');
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
        //先检查在本地数组里有没有
        if (!empty($this->cache_arr)) {
            $from_cache_arr = null;
            foreach ($keys as $i => $name) {
                //在缓存列表
                if (isset($this->cache_arr[$name])) {
                    $result[$name] = $this->cache_arr[$name];
                    $from_cache_arr[] = $name;
                    unset($keys[$i]);
                }
            }
            if ($from_cache_arr) {
                $log_msg = $this->logMsg('getMultiple', '[FROM $this->cache_arr]', join(',', $from_cache_arr));
                $this->logger_handle->debug($log_msg);
            }
        }
        //已经不需要和服务器交互了，最理想的情况
        if (empty($keys)) {
            $this->logger_handle->debug($this->logMsg('getMultiple final', '[all from cache]', $result));
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
            $result_list = $cache_handle->getMulti($new_keys, $cas_arr, \Memcached::GET_PRESERVE_ORDER);
            $this->logger_handle->info($this->logMsg('GetMultiple from server', $new_keys, $result_list));
            if (false === $result_list) {
                $result_code = $cache_handle->getResultCode();
                $this->logResultMessage($result_code, 'Get', $new_keys);
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
                    $value = false;
                    $result[$name] = $default;
                } else {
                    $result[$name] = $value;
                }
                $this->cache_arr[$name] = $value;
            }
        }
        $this->logger_handle->debug($this->logMsg('getMultiple final', $keys, $result));
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
        $ttl = $this->ttl($ttl);
        foreach ($values as $name => $value) {
            $this->cache_arr[$name] = $value;
            $this->cache_save[$name] = array($value, $ttl);
        }
        $this->logger_handle->debug($this->logMsg('setMultiple', array_keys($values), $values, ' commit later.'));
        return true;
    }

    /**
     * 批量删除
     * @param array $keys 需要删除的keys
     * @return bool
     */
    public function deleteMultiple(array $keys)
    {
        //其实是一个一个删除
        foreach ($keys as $name) {
            $this->delete($name);
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
    public function add($key, $value, $ttl = null)
    {
        //已经有了这个key了
        if (isset($this->cache_arr[$key])) {
            return false;
        }
        //如果服务器不可用了，直接返回false
        if ($this->is_disabled) {
            return false;
        }
        $ttl = $this->ttl($ttl);
        $cache_handle = $this->getCacheHandle();
        $save_key = $this->makeKey($key);
        $ret = $cache_handle->add($save_key, $value, $ttl);
        $this->logger_handle->info($this->logMsg('Add', $key, $ret));
        if (false === $ret) {
            $result_code = $cache_handle->getResultCode();
            $this->logResultMessage($result_code, 'Add', $key);
            //如果是断开连接了, 自动重连
            if (self::MEMCACHED_SERVER_MARKED_DEAD === $result_code) {
                return $this->retry('add', func_get_args());
            }
        }
        $this->cache_arr[$key] = $value;
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
        $ret = $cache_handle->increment($save_key, $step);
        $this->logger_handle->info($this->logMsg('Increment', $key, $ret));
        if (false === $ret) {
            $result_code = $cache_handle->getResultCode();
            $this->logResultMessage($result_code, 'Increment', $key);
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
        $ret = $cache_handle->decrement($save_key, $step);
        $this->logger_handle->info($this->logMsg('Decrement', $key, $ret));
        if (false === $ret) {
            $result_code = $cache_handle->getResultCode();
            $this->logResultMessage($result_code, 'Decrement', $key);
            if (self::MEMCACHED_SERVER_MARKED_DEAD === $result_code) {
                return $this->retry('decrement', func_get_args());
            }
        }
        return $ret;
    }

    /**
     * 设置一个缓存的过期时间（精确时间）
     * @param string $key 缓存
     * @param int|null $time 时间
     * @return bool
     */
    public function expiresAt($key, $time)
    {
        $time = $this->ttl($time);
        if ($this->is_disabled) {
            return false;
        }
        //如果在待存列表中，缓存起来异步操作
        if (isset($this->cache_save[$key])) {
            $this->cache_save[$key][1] = $time;
            $ret = true;
        } else {
            $cache_handle = $this->getCacheHandle();
            $save_key = $this->makeKey($key);
            $ret = $cache_handle->touch($save_key, $time);
            $this->logger_handle->info($this->logMsg('expiresAt:' . $time, $key, $ret));
            if (false === $ret) {
                $result_code = $cache_handle->getResultCode();
                $this->logResultMessage($result_code, 'expiresAt', $key);
                if (self::MEMCACHED_SERVER_MARKED_DEAD === $result_code) {
                    return $this->retry('expiresAt', func_get_args());
                }
            }
        }
        return $ret;
    }

    /**
     * 设置一个缓存有效时间
     * @param string $key 缓存
     * @param int|null $time 时间
     * @return bool
     */
    public function expiresAfter($key, $time)
    {
        //memcached内部会自动对时间处理
        return $this->expiresAt($key, $time);
    }

    /**
     * 提交
     * @return void
     */
    public function commit()
    {
        if (empty($this->cache_save) || $this->is_disabled) {
            return;
        }
        $new_arr = array();
        //把所有的值，把ttl分组，相同的ttl就可以指更新
        foreach ($this->cache_save as $key => $arg) {
            $ttl = $arg[1];
            $value = $arg[0];
            $name = $this->makeKey($key);
            if (!isset($new_arr[$ttl])) {
                $new_arr[$ttl] = array($name => $value);
            } else {
                $new_arr[$ttl][$name] = $value;
            }
        }
        $cache_handle = $this->getCacheHandle();
        $this->logger_handle->debug($this->logMsg('commit'));
        foreach ($new_arr as $ttl => $value_arr) {
            $ttl_str = ' TTL: '. $ttl;
            //如果有多个，批量更新
            if (count($value_arr) > 1) {
                $this->logger_handle->debug($this->logMsg('commit/setMulti', array_keys($value_arr), $value_arr, $ttl_str));
                $ret = $cache_handle->setMulti($value_arr, $ttl);
            } else {
                $key = key($value_arr);
                $value = $value_arr[$key];
                $this->logger_handle->debug($this->logMsg('commit/set', $key, $value, $ttl_str));
                $ret = $cache_handle->set($key, $value, $ttl);
            }
            if (false === $ret) {
                $result_code = $cache_handle->getResultCode();
                $this->logResultMessage($result_code, 'set/setMulti', 'multipleKeys', $value_arr);
                if (self::MEMCACHED_SERVER_MARKED_DEAD === $result_code) {
                    $this->retry('commitSet', null);
                    return;
                }
            }
        }
        $this->cleanup();
    }

    /**
     * 回滚
     * @return void
     */
    public function rollback()
    {
        $this->cache_save = $this->cas_token_arr = null;
    }

    /**
     * 清理内存
     * @return void
     */
    public function cleanup()
    {
        $this->cache_arr = $this->cache_save = $this->cas_token_arr = null;
    }

    /**
     * 生成日志信息
     * @param string $action
     * @param string|array $key
     * @param mixed $val
     * @param mixed $ext_msg 附加消息
     * @return string
     */
    public function logMsg($action, $key = '', $val = null, $ext_msg = null)
    {
        $str = '[Memcached ' . $this->conf_name . ']' . $action;
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

    /**
     * 记录memcached返回消息
     * @param int $ret_code
     * @param string $action
     * @param string|array $key
     * @param string $ext_msg
     */
    public function logResultMessage($ret_code, $action, $key, $ext_msg = '')
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
        $this->logger_handle->warning($this->logMsg($action, $key, $msg, $ext_msg));
    }

    /**
     * 获取一个值，同时将它的token值存起来
     * @param string $key 缓存键名
     * @param null $default 默认值
     * @return mixed
     */
    public function casGet($key, $default = null)
    {
        //在缓存里已经有值，如果需要token，必须事先有token值，因为通过getMultiple方法拿不到token
        if (isset($this->cas_token_arr[$key])) {
            $ret = $this->cache_arr[$key];
            $this->logger_handle->debug($this->logMsg('casGet', $key, $ret, 'from $this->cache_arr'));
            return $ret;
        }
        //系统已经不可用了
        if ($this->is_disabled) {
            return $default;
        }
        $cache_handle = $this->getCacheHandle();
        //save_key必须在getCacheHandle之后
        $save_key = $this->makeKey($key);
        //如果这个key在待存列表，立即写入
        if (isset($this->cache_save[$key])) {
            $tmp = $this->cache_save[$key];
            $cache_handle->set($key, $tmp[0], $tmp[1]);
            unset($this->cache_save[$key]);
        }
        $ret = $cache_handle->get($save_key, null, $token);
        $this->logger_handle->debug($this->logMsg('casGet', $key, $ret));
        $result_code = 0;
        if (false === $ret) {
            $result_code = $cache_handle->getResultCode();
            if ($result_code > 0) {
                $this->logResultMessage($result_code, 'casGet', $key);
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
            $this->cache_arr[$key] = $ret;
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
        $ret = $cache_handle->cas($token, $save_key, $value, $ttl);
        $this->logger_handle->debug($this->logMsg('CasSet', $key, $value, 'ret:' . $ret));
        if (false === $ret) {
            $result_code = $cache_handle->getResultCode();
            $this->logResultMessage($result_code, 'CasSet', $key);
            //服务器不可用，重试
            if (self::MEMCACHED_SERVER_MARKED_DEAD === $result_code) {
                return $this->retry('casSet', func_get_args());
            }
        } else {
            unset($this->cas_token_arr[$key], $this->cache_save[$key]);
            $this->cache_arr[$key] = $value;
        }
        return $ret;
    }
}
