<?php

namespace FFan\Std\Cache;

use FFan\Std\Common\InvalidConfigException;
use FFan\Std\Console\Debug;

/**
 * Class ClusterRedis
 * @package FFan\Std\Cache
 */
class ClusterRedis extends CacheBase implements CacheInterface
{
    /**
     * 判断数据是否被压缩的标志
     */
    const ZIP_FLAG_STR = 'zip:';

    /**
     * flag字符串长度
     */
    const ZIP_FLAG_LEN = 4;

    /**
     * 数据压缩阀值
     */
    const ZIP_DATA_LEN = 2048;

    /**
     * 默认的缓存过期时间
     */
    const DEFAULT_TTL = 1800;

    /**
     * @var bool 系统是否不可用了
     */
    private $is_disable = false;

    /**
     * @var int 设定每一个服务器的节点数
     * 数量越多，宕机时服务器负载就会分布得越平均，但也增大数据查找消耗。
     */
    private $slot_size = 24;

    /**
     * @var array 当前服务器组的结点列表
     */
    private $server_nodes = array();

    /**
     * ClusterRedis constructor.
     * @param string $conf_name
     * @param array $conf_arr
     * @throws InvalidConfigException
     */
    public function __construct($conf_name, array $conf_arr)
    {
        parent::__construct($conf_name, $conf_arr, 'cluster_redis');
        $servers = $this->getConfig('server');
        if (!is_array($servers)) {
            $this->errorMsg('server config error');
            throw new InvalidConfigException('Server config error');
        }
        $this->addServers($servers);
    }

    /**
     * 获取一个缓存.
     * @param string $key 缓存键值
     * @param mixed $default 如果缓存不存在，返回的默认值
     * @return mixed
     */
    public function get($key, $default = null)
    {
        if ($this->is_disable) {
            return $default;
        }
        Debug::timerStart();
        $save_key = $this->makeKey($key);
        $redis_fd = $this->getRedis($save_key);
        if (!$redis_fd) {
            return $default;
        }
        $result = $redis_fd->get($save_key);
        if (false !== $result) {
            $result = $this->unpack($result);
        }
        $use_time = Debug::timerStop();
        $this->logMsg('get', $key, false !== $result, $use_time, $result);
        return $result;
    }

    /**
     * 设置一个缓存
     * @param string $key 缓存键名
     * @param mixed $value 值
     * @param null|int $ttl 过期时间
     * @return bool
     */
    public function set($key, $value, $ttl = self::DEFAULT_TTL)
    {
        if ($this->is_disable) {
            return false;
        }
        Debug::timerStart();
        $save_key = $this->makeKey($key);
        $redis_fd = $this->getRedis($save_key);
        if (!$redis_fd) {
            return false;
        }
        $save_str = $this->pack($value);
        //如果是永久的值
        if ($ttl <= 0) {
            $result = $redis_fd->set($save_key, $save_str);
        } else {
            $result = $redis_fd->setEx($save_key, $ttl, $save_str);
        }
        $use_time = Debug::timerStop();
        $this->logMsg('set', $key, $result, $use_time, $value);
        return $result;
    }

    /**
     * 获取一个值，同时将它的token值存起来
     * @param string $key
     * @param null $default
     * @return mixed
     */
    public function casGet($key, $default = null)
    {
        return $this->get($key, $default);
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
        return $this->set($key, $value);
    }

    /**
     * 从缓存中删除一个键
     * @param string $key 缓存名
     * @return bool
     */
    public function delete($key)
    {
        if ($this->is_disable) {
            return false;
        }
        Debug::timerStart();
        $save_key = $this->makeKey($key);
        $redis_fd = $this->getRedis($save_key);
        if (!$redis_fd) {
            return false;
        }
        $result = $redis_fd->del($save_key);
        $use_time = Debug::timerStop();
        $this->logMsg('del', $key, $use_time, $result);
        return $result;
    }

    /**
     * 清除整个缓存
     * @return bool
     */
    public function clear()
    {
        //不支持清除redis
        return false;
    }

    /**
     * 获取多个缓存.
     * @param array $keys 缓存键名列表
     * @return array
     */
    public function getMultiple(array $keys)
    {
        if ($this->is_disable) {
            return [];
        }
        Debug::timerStart();
        $key_map = array();
        //将keys先hash到各自的节点
        $group_arr = $this->keysGroup($keys, false, $key_map);
        $result = array();
        foreach ($group_arr as $each_group) {
            /** @var \Redis $redis_fd */
            $redis_fd = $each_group['fd'];
            $keys_arr = $each_group['arr'];
            $tmp_result = $redis_fd->mget($keys_arr);
            foreach ($tmp_result as $i => $tmp_value) {
                $save_key = $keys_arr[$i];
                $raw_key = $key_map[$save_key];
                $result[$raw_key] = $tmp_value;
            }
        }
        //数据需要解包
        foreach ($result as $key => &$value) {
            if (false !== $value) {
                $value = $this->unpack($value);
            } else {
                unset($result[$key]);
            }
        }
        $cost_time = Debug::timerStop();
        $this->logMsg('getMultiple', join(',', $keys), true, $cost_time, $result);
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
        if ($this->is_disable) {
            return false;
        }
        Debug::timerStart();
        //将keys先hash到各自的节点
        $group_arr = $this->keysGroup($values, true);
        $result = true;
        foreach ($group_arr as $each_group) {
            /** @var \Redis $redis_fd */
            $redis_fd = $each_group['fd'];
            $pair_arr = $each_group['arr'];
            $pipe = $redis_fd->multi(\Redis::PIPELINE);
            foreach ($pair_arr as $save_key => $save_value) {
                if (is_array($save_value) && isset($save_value['value'], $save_value['ttl'])) {
                    $value = $save_value['value'];
                    $tmp_ttl = (int)$save_value['ttl'];
                } else {
                    $tmp_ttl = $ttl;
                    $value = $save_value;
                }
                $value = $this->pack($value);
                if ($tmp_ttl <= 0) {
                    $tmp_ttl = $ttl;
                }
                $pipe->setex($save_key, $tmp_ttl, $value);
            }
            $pipe->exec();
        }
        $const_time = Debug::timerStop();
        $this->logMsg('setMultiple', join(',', array_keys($values)), $result, $const_time);
        return $result;
    }

    /**
     * 批量删除
     * @param array $keys 需要删除的keys
     * @return bool
     */
    public function deleteMultiple(array $keys)
    {
        if ($this->is_disable) {
            return false;
        }
        Debug::timerStart();
        //将keys先hash到各自的节点
        $group_arr = $this->keysGroup($keys);
        $result = 0;
        foreach ($group_arr as $each_group) {
            /** @var \Redis $redis_fd */
            $redis_fd = $each_group['fd'];
            $keys_arr = $each_group['arr'];
            $redis_fd->del($keys_arr);
        }
        $use_time = Debug::timerStop();
        $this->logMsg('deleteMultiple', join(',', $keys), true, $use_time);
        return $result;
    }

    /**
     * 判断缓存中是否有某个值
     * @param string $key 缓存键名
     * @return bool
     */
    public function has($key)
    {
        if ($this->is_disable) {
            return false;
        }
        Debug::timerStart();
        $save_key = $this->makeKey($key);
        $redis_fd = $this->getRedis($save_key);
        if (!$redis_fd) {
            return false;
        }
        $result = $redis_fd->exists($save_key);
        $cost_time = Debug::timerStop();
        $this->logMsg('exists', $key, $result, $cost_time);
        return $result;
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
        if ($this->is_disable) {
            return false;
        }
        Debug::timerStart();
        $save_key = $this->makeKey($key);
        $redis_fd = $this->getRedis($save_key);
        if (!$redis_fd) {
            return false;
        }
        $result = $redis_fd->sAdd($save_key, $value);
        if ($result && $ttl > 0) {
            $redis_fd->expire($save_key, $ttl);
        }
        $cost_time = Debug::timerStop();
        $this->logMsg('add', $key, $result, $cost_time);
        return $result;
    }

    /**
     * 在一个键上做自增
     * @param string $key 键名
     * @param int $step 步长
     * @return bool|int 如果值不存在，将返回false 其它情况元素的值
     */
    public function increase($key, $step = 1)
    {
        if ($this->is_disable) {
            return false;
        }
        Debug::timerStart();
        $save_key = $this->makeKey($key);
        $redis_fd = $this->getRedis($save_key);
        if (!$redis_fd) {
            return false;
        }
        $result = $redis_fd->incrBy($save_key, $step);
        $cost_time = Debug::timerStop();
        $this->logMsg('increase', $key, true, $cost_time, $result);
        return $result;
    }

    /**
     * 在一个键上做自减
     * @param string $key 键名
     * @param int $step 步长
     * @return bool|int 如果值不存在，将返回false
     */
    public function decrease($key, $step = 1)
    {
        if ($this->is_disable) {
            return false;
        }
        Debug::timerStart();
        $save_key = $this->makeKey($key);
        $redis_fd = $this->getRedis($save_key);
        if (!$redis_fd) {
            return false;
        }
        $result = $redis_fd->decrBy($save_key, $step);
        $cost_time = Debug::timerStop();
        $this->logMsg('decrease', $key, true, $cost_time, $result);
        return $result;
    }

    /**
     * 打包数据
     * @param mixed $data
     * @return string
     */
    private function pack($data)
    {
        $result = igbinary_serialize($data);
        //如果数据长度超过
        if (strlen($result) > self::ZIP_DATA_LEN) {
            $gz_str = gzcompress($result);
            $result = self::ZIP_FLAG_STR . $gz_str;
        }
        return $result;
    }

    /**
     * 解包数据
     * @param string $data
     * @return mixed
     */
    private function unpack($data)
    {
        if (!is_string($data)) {
            return $data;
        }
        //如果带压缩标志
        if (0 === strpos($data, self::ZIP_FLAG_STR)) {
            $gz_data = substr($data, self::ZIP_FLAG_LEN);
            $data = gzuncompress($gz_data);
        }
        $result = igbinary_unserialize($data);
        return $result;
    }

    /**
     * @param string $key
     * @return \Redis
     */
    private function getRedis($key)
    {
        $server = $this->getServer($key);
        return $server->getRedisFd();
    }

    /**
     * 计算一个数据的哈希值，用以确定位置
     * @param string $key
     * @return int
     */
    private function makeHash($key)
    {
        return crc32($key);
    }

    /**
     * 遍历当前服务器组的节点列表，确定需要存储/查找的服务器
     * @param string $key
     * @return ClusterRedisNode
     */
    private function getServer($key)
    {
        if (empty($this->server_nodes)) {
            if (!$this->is_disable) {
                $this->is_disable = true;
                $this->errorMsg('SERVER_NODE_EMPTY');
            }
            return null;
        }
        $hash = $this->makeHash($key);
        /**
         * @var string $key
         * @var ClusterRedisNode $val
         */
        foreach ($this->server_nodes as $key => $val) {
            if ($hash <= $key) {
                $result = $val;
                break;
            }
        }
        if (!isset($result)) {
            /** @var ClusterRedisNode $val */
            $result = $val;
        }
        $redis_fd = $result->getRedisFd();
        //如果无法连接，移除服务器
        if (null === $redis_fd) {
            $this->removeServer($result);
            return $this->getServer($key);
        }
        return $result;
    }

    /**
     * 添加一个服务器，将其结点添加到服务器组的节点列表内。
     * @param array $node_conf
     */
    private function addServer($node_conf)
    {
        $server_node = new ClusterRedisNode($this->conf_name, $node_conf);
        for ($i = 0; $i < $this->slot_size; ++$i) {
            $key = $server_node->makeServerKey($i);
            $hash = $this->makeHash($key);
            $this->server_nodes[$hash] = $server_node;
        }
        ksort($this->server_nodes);
    }

    /**
     * 添加服务器列表
     * @param array $servers 单台服务器配置 host:port
     * @throws InvalidConfigException
     */
    private function addServers(array $servers)
    {
        foreach ($servers as $node_conf) {
            $colon_pos = strpos($node_conf, ':');
            if (false === $colon_pos) {
                throw new InvalidConfigException($node_conf);
            }
            $host = trim(substr($node_conf, 0, $colon_pos));
            $port = (int)trim(substr($node_conf, $colon_pos + 1));
            $this->addServer(array('host' => $host, 'port' => $port));
        }
    }

    /**
     * 移除一台服务器信息
     * @param ClusterRedisNode $server_node
     */
    private function removeServer($server_node)
    {
        for ($i = 0; $i < $this->slot_size; ++$i) {
            $key = $server_node->makeServerKey($i);
            $hash = $this->makeHash($key);
            unset($this->server_nodes[$hash]);
        }
    }

    /**
     * 将key分组到每个节点
     * @param array $keys
     * @param bool $is_assoc_arr 是否是关联数组
     * @param array $key_map 新旧key对照数组
     * @return array
     */
    private function keysGroup($keys, $is_assoc_arr = false, &$key_map = array())
    {
        $result = array();
        foreach ($keys as $key => $value) {
            //如果是 只有key的情况，$key变量是数组的索引，$value是键名
            if ($is_assoc_arr) {
                $tmp_key = $this->makeKey($key);
                $key_map[$tmp_key] = $key;
            } else {
                $tmp_key = $this->makeKey($value);
                $key_map[$tmp_key] = $value;
            }
            $tmp_key = $this->makeKey($is_assoc_arr ? $key : $value);
            $tmp_server = $this->getServer($tmp_key);
            $id = $tmp_server->getId();
            if (!isset($result[$id])) {
                $result[$id] = array('fd' => $tmp_server->getRedisFd(), 'arr' => array());
            }
            if ($is_assoc_arr) {
                $result[$id]['arr'][$tmp_key] = $value;
            } else {
                $result[$id]['arr'][] = $tmp_key;
            }
        }
        return $result;
    }
}