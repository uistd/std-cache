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
    private $_is_disabled = false;

    /**
     * 存储token值的数组
     * @var array
     */
    private $_token_arr;

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
                $ret = $cache_obj->addServer($server_conf['host'], $server_conf['port']);
            } else {
                $ret = $cache_obj->addServers($server_conf);
            }
            //如果添加服务器失败，表示系统已经不可用
            if (false === $ret) {
                $this->logResultMessage($cache_obj->getResultCode(), 'Add server, ret:' . $ret, $server_conf);
                $this->_is_disabled = true;
            }
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
        if ($this->_is_init) {
            $this->init();
        }
        if (empty($this->_key_category)) {
            return $key;
        }
        return $this->_key_category . '.' . $key;
    }

    /**
     * 获取连接句柄
     * @return \Memcached
     */
    private function getCacheHandle()
    {
        if (!$this->_is_init) {
            $this->init();
        }
        return $this->_cache_handle;
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
        return $this->doGet($key, $default);
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
            $this->_is_disabled = true;
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
        if (isset($this->del_arr[$key])) {
            unset($this->del_arr[$key]);
        }
        $this->cache_arr[$key] = $value;
        $this->cache_save[$key] = array($value, $ttl);
    }

    /**
     * 从缓存中删除一个键
     * @param string $key 缓存键名
     * @return bool
     */
    public function delete($key)
    {
        if (isset($this->cache_arr[$key])) {
            unset($this->cache_arr[$key]);
        }
        if (isset($this->cache_save[$key])) {
            unset($this->cache_save[$key]);
        }
        if (isset($this->_token_arr[$key])) {
            unset($this->_token_arr[$key]);
        }
        //如果已经在add列表中，要真实删除
        if (isset($this->cache_add[$key])) {
            $re = $this->remove($key);
        } else {
            $this->del_arr[$key] = true;
            $re = true;
        }
        return $re;
    }

    /**
     * 从缓存真实删除一个key
     * @param string $key 缓存键名
     * @return bool
     */
    private function remove($key)
    {
        if (isset($this->del_arr[$key])) {
            unset($this->del_arr[$key]);
        }
        if ($this->_is_disabled) {
            return true;
        }
        $cache_handle = $this->getCacheHandle();
        $save_key = $this->make_key($key);
        $ret = $cache_handle->delete($save_key);
        $this->_logger->info($this->logMsg('Delete', $key));
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
        if ($this->_is_disabled) {
            return true;
        }
        $cache_handle = $this->getCacheHandle();
        $this->cleanup();
        $ret = $cache_handle->flush();
        $this->_logger->notice($this->logMsg('Flush'));
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
    public function getMultiple($keys, $default = null)
    {
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
        elseif ($this->_is_disabled) {
            foreach ($keys as $name) {
                $result[$name] = $default;
            }
            return $result;
        } else {
            $cas_arr = array();
            $cache_handle = $this->getCacheHandle();
            $new_keys = array();
            foreach ($keys as $name) {
                $new_keys[] = $this->make_key($name);
            }
            $result_list = $cache_handle->getMulti($new_keys, $cas_arr, \Memcached::GET_PRESERVE_ORDER);
            $this->_logger->info($this->logMsg('GetMultiple', $keys, $result_list));
            if (false === $result_list) {
                $result_code = $cache_handle->getResultCode();
                $this->logResultMessage($result_code, 'Get', $new_keys);
                //服务器已经不可用
                if (self::MEMCACHED_SERVER_MARKED_DEAD === $result_code) {
                    return $this->retry(array($this, 'getMultiple'), func_get_args());
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
    public function add($key, $value, $ttl)
    {
        //已经有了这个key了
        if (isset($this->cache_arr[$key])) {
            return false;
        }
        //如果服务器不可用了，直接返回false
        if (!$this->_is_disabled) {
            return false;
        }
        //如果在待删除列表中，表示服务器端可能有这个key，要立刻、立即、马上做真实删除操作，不然肯定add不成功
        if (isset($this->del_arr[$key])) {
            $this->remove($key);
        }
        $cache_handle = $this->getCacheHandle();
        if (null === $this->cache_add) {
            $this->cache_add = [];
        }
        $save_key = $this->make_key($key);
        $ret = $cache_handle->add($save_key, $value, $ttl);
        $this->_logger->info($this->logMsg('Add', $key, $ret));
        if (false === $ret) {
            $result_code = $cache_handle->getResultCode();
            $this->logResultMessage($result_code, 'Add', $key);
            //如果是断开连接了, 自动重连
            if (self::MEMCACHED_SERVER_MARKED_DEAD === $result_code) {
                return $this->retry('add', func_get_args());
            }
        }
        $this->cache_arr[$key] = $value;
        $this->cache_add[$key] = true;
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
        if ($this->_is_disabled) {
            return false;
        }
        $cache_handle = $this->getCacheHandle();
        $save_key = $this->make_key($key);
        $ret = $cache_handle->increment($save_key, $step);
        $this->_logger->info($this->logMsg('Increment', $key, $ret));
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
    public function decrement($key, $step = 1)
    {
        if ($this->_is_disabled) {
            return false;
        }
        $cache_handle = $this->getCacheHandle();
        $save_key = $this->make_key($key);
        $ret = $cache_handle->decrement($save_key, $step);
        $this->_logger->info($this->logMsg('Decrement', $key, $ret));
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
        if ($this->_is_disabled) {
            return false;
        }
        //如果在待存列表中，缓存起来异步操作
        if (isset($this->cache_save[$key])) {
            $this->cache_save[$key][1] = $time;
            $ret = true;
        } else {
            $cache_handle = $this->getCacheHandle();
            $save_key = $this->make_key($key);
            $ret = $cache_handle->touch($save_key, $time);
            $this->_logger->info($this->logMsg('expiresAt:' . $time, $key, $ret));
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
        $this->commitSet();
        $this->commitDelete();
        $this->cleanup();
    }

    /**
     * 提交set部分
     * @return bool
     */
    private function commitSet()
    {
        if (empty($this->cache_save) || $this->_is_disabled) {
            return true;
        }
        $new_arr = array();
        //把所有的值，把ttl分组，相同的ttl就可以指更新
        foreach ($this->cache_save as $key => $arg) {
            $ttl = $arg[1];
            $value = $arg[0];
            $name = $this->make_key($key);
            if (!isset($new_arr[$ttl])) {
                $new_arr[$ttl] = array($name => $value);
            } else {
                $new_arr[$ttl][$name] = $value;
            }
        }
        $cache_handle = $this->getCacheHandle();
        $ret = true;
        foreach ($new_arr as $ttl => $value_arr) {
            //如果有多个，批量更新
            if (count($value_arr) > 1) {
                $ret = $cache_handle->setMulti($value_arr, $ttl);
                $this->_logger->debug($this->logMsg('setMulti', 'multipleKeys', $value_arr));
            } else {
                $key = key($value_arr);
                $value = $value_arr[$key];
                $ret = $cache_handle->set($key, $value, $ttl);
                $this->_logger->debug($this->logMsg('setMulti', 'multipleKeys', $value_arr));
            }
            if (false === $ret) {
                $result_code = $cache_handle->getResultCode();
                $this->logResultMessage($result_code, 'set/setMulti', 'multipleKeys', $value_arr);
                if (self::MEMCACHED_SERVER_MARKED_DEAD === $result_code) {
                    return $this->retry('commitSet', null);
                }
            }
        }
        return $ret;
    }

    /**
     * 提交删除部分
     * @return bool
     */
    private function commitDelete()
    {
        if (empty($this->del_arr) || $this->_is_disabled) {
            return true;
        }
        $keys = array();
        foreach ($this->del_arr as $key => $v) {
            $keys[] = $this->make_key($key);
        }
        $cache_handle = $this->getCacheHandle();
        $ret = $cache_handle->deleteMulti($keys);
        $this->_logger->debug($this->logMsg('deleteMulti', $keys, $ret));
        if (false === $ret) {
            $result_code = $cache_handle->getResultCode();
            $this->logResultMessage($result_code, 'deleteMulti', $keys);
            if (self::MEMCACHED_SERVER_MARKED_DEAD === $result_code) {
                return $this->retry('commitDelete', null);
            }
        }
        return $ret;
    }

    /**
     * 回滚
     * @return void
     */
    public function rollback()
    {
        $this->cleanup();
    }

    /**
     * 清理内存
     * @return void
     */
    public function cleanup()
    {
        $this->cache_save = $this->_token_arr = $this->del_arr = $this->cache_add = null;
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
        $msg = ' resultCode:' . $ret_code . ' resultMessage:' . $this->_cache_handle->getResultMessage();
        $this->_logger->warning($this->logMsg($action, $key, $msg, $ext_msg));
    }

    /**
     * 获取一个值，同时将它的token值存起来
     * @param string $key 缓存键名
     * @param null $default 默认值
     * @param mixed $token token值
     * @return mixed
     */
    public function casGet($key, $default = null, &$token = '')
    {
        $re = $this->doGet($key, $default, true);
        if (isset($this->_token_arr[$key])) {
            $token = $this->_token_arr[$key];
        }
        return $re;
    }

    /**
     * 获取一个缓存.
     * @param string $key 缓存键值
     * @param mixed $default 如果缓存不存在，返回的默认值
     * @param bool $need_token 是否需要token值
     * @return mixed
     */
    private function doGet($key, $default, $need_token = false)
    {
        //在缓存里已经有值，如果需要token，必须事先有token值，因为通过getMultiple方法拿不到token
        if (($need_token && isset($this->_token_arr[$key]))
            || (!$need_token && isset($this->cache_arr[$key]))
        ) {
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
        //系统已经不可用了
        if ($this->_is_disabled) {
            return $default;
        }
        $cache_handle = $this->getCacheHandle();
        //save_key必须在getCacheHandle之后
        $save_key = $this->make_key($key);
        $token = null;
        $ret = $cache_handle->get($save_key, null, $token);
        $this->_logger->debug($this->logMsg('Get', $key, $ret));
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
        if (null !== $token) {
            $this->_token_arr[$key] = $token;
        }
        //缓存起来
        $this->cache_arr[$key] = $ret;
        //如果ret是false 并且 result_code 不为0, 表示 false 不是缓存值，而是失败的返回值
        if (false === $ret && $result_code > 0) {
            $ret = $default;
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
        if ($this->_is_disabled) {
            return false;
        }
        //之前没有获取过token值，无法进行下去
        if (null === $token) {
            if (!isset($this->_token_arr[$key])){
                return false;
            }
            $token = $this->_token_arr[$key];
        }
        $cache_handle = $this->getCacheHandle();
        $save_key = $this->make_key($key);
        $ttl = $this->ttl($ttl);
        $ret = $cache_handle->cas($token, $save_key, $value, $ttl);
        $this->_logger->debug($this->logMsg('CasSet', $key, $value, 'ret:' . $ret));
        if (false === $ret) {
            $result_code = $cache_handle->getResultCode();
            $this->logResultMessage($result_code, 'CasSet', $key);
            //服务器不可用，重试
            if (self::MEMCACHED_SERVER_MARKED_DEAD === $result_code) {
                return $this->retry(array($this, 'casSet'), func_get_args());
            }
        } else {
            unset($this->_token_arr[$key], $this->cache_save[$key]);
            $this->cache_arr[$key] = $value;
        }
        return $ret;
    }
}
