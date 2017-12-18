<?php

namespace UiStd\Cache;

use UiStd\Common\InvalidConfigException;
use UiStd\Console\Debug;

class Redis extends CacheBase implements CacheInterface
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
     * @var bool 是否可用
     */
    private $is_available = false;

    /**
     * @var \Redis Redis连接句柄
     */
    private $redis_fd;

    /**
     * Redis constructor.
     * @param string $conf_name
     * @param array $conf_arr
     */
    public function __construct($conf_name, array $conf_arr)
    {
        parent::__construct($conf_name, $conf_arr, 'redis');
        $this->initRedis();
    }


    /**
     * 初始化redis连接
     */
    private function initRedis()
    {
        $host = $this->getConfig('host');
        $port = $this->getConfigInt('port');
        if (!is_string($host) || empty($host)) {
            $this->errorMsg('config error');
            throw new InvalidConfigException('Redis host error');
        }
        $redis = new \Redis();
        Debug::timerStart();
        $ret = $redis->pconnect($host, $port, 1);
        $cost_time = Debug::timerStop();
        $this->logMsg('Connect', $host .':'. $port, $ret, $cost_time);
        if ($ret) {
            $this->redis_fd = $redis;
            $this->is_available = true;
        } else {
            $this->errorMsg('CAN_NOT_CONNECT_REDIS '. $host .':'. $port);
        }
    }

    /**
     * 获取一个缓存.
     * @param string $key 缓存键值
     * @param mixed $default 如果缓存不存在，返回的默认值
     * @return mixed
     */
    public function get($key, $default = null)
    {
        if (!$this->is_available) {
            return $default;
        }
        Debug::timerStart();
        $result = $this->redis_fd->get($key);
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
        if (!$this->is_available) {
            return false;
        }
        Debug::timerStart();
        $save_str = $this->pack($value);
        //如果是永久的值
        if ($ttl <= 0) {
            $result = $this->redis_fd->set($key, $save_str);
        } else {
            $result = $this->redis_fd->setEx($key, $ttl, $save_str);
        }
        $cost_time = Debug::timerStop();
        $this->logMsg('set', $key, $result, $cost_time, $value);
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
        //redis 没有 cas 机制 @todo 替换方案
        return $this->get($key, $default);
    }

    /**
     * 先比较cas，再做缓存更新（如果未找到cas值，将更新失败）
     * @param string $key 缓存键名
     * @param mixed $value 值
     * @param null|int $ttl 过期时间
     * @return bool
     */
    public function casSet($key, $value, $ttl = self::DEFAULT_TTL)
    {
        //redis 没有 cas 机制 @todo 替换方案
        return $this->set($key, $value, $ttl);
    }

    /**
     * 从缓存中删除一个键
     * @param string $key 缓存名
     * @return bool
     */
    public function delete($key)
    {
        if (!$this->is_available) {
            return 0;
        }
        Debug::timerStart();
        $result = $this->redis_fd->del($key);
        $const_time = Debug::timerStop();
        $this->logMsg('del', $key, $result, $const_time);
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
     * @return array 如果值不存在的key会以default填充
     */
    public function getMultiple(array $keys)
    {
        if (!$this->is_available) {
            return array();
        }
        $result = array();
        Debug::timerStart();
        $tmp_result = $this->redis_fd->mget($keys);
        //数据需要解包
        foreach ($tmp_result as $index => $value) {
            if (false !== $value) {
                $value = $this->unpack($value);
                $result[$keys[$index]] = $value;
            }
        }
        $use_time = Debug::timerStop();
        $this->logMsg('getMultiple', join(',', $keys), !empty($result), $use_time, $result);
        return $result;
    }

    /**
     * 批量设置缓存
     * @param array $values 一个key => value 数组
     * @param null|int $ttl
     * @return bool
     */
    public function setMultiple(array $values, $ttl = self::DEFAULT_TTL)
    {
        if (!$this->is_available) {
            return false;
        }
        Debug::timerStart();
        /** @var \Redis $pipe_fd */
        $pipe_fd = $this->redis_fd->multi(\Redis::PIPELINE);
        foreach ($values as $save_key => $save_value) {
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
            $pipe_fd->setex($save_key, $tmp_ttl, $value);
        }
        $pipe_fd->exec();
        $cost_time = Debug::timerStop();
        $result = true;
        $this->logMsg('setMultiple', join(',', array_keys($values)), $result, $cost_time, $values);
        return $result;
    }

    /**
     * 批量删除
     * @param array $keys 需要删除的keys
     * @return bool
     */
    public function deleteMultiple(array $keys)
    {
        if (!$this->is_available) {
            return false;
        }
        Debug::timerStart();
        /** @var \Redis $pipe_fd */
        $pipe_fd = $this->redis_fd->multi(\Redis::PIPELINE);
        foreach ($keys as $key) {
            /** @var \Redis $redis_fd */
            $pipe_fd->del($key);
        }
        $result = true;
        $pipe_fd->exec();
        $cost_time = Debug::timerStop();
        $this->logMsg('deleteMultiple', join(',', $keys), $result, $cost_time);
        return $result;
    }

    /**
     * 判断缓存中是否有某个值
     * @param string $key 缓存键名
     * @return bool
     */
    public function has($key)
    {
        if (!$this->is_available) {
            return false;
        }
        Debug::timerStart();
        $result = $this->redis_fd->exists($key);
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
    public function add($key, $value, $ttl = self::DEFAULT_TTL)
    {
        if (!$this->is_available) {
            return false;
        }
        Debug::timerStart();
        $ret = $this->redis_fd->sAdd($key, $value);
        //成功后, 设置key 过期时间
        if ($ret && $ttl > 0) {
            $this->redis_fd->expire($key, $ttl);
        }
        $cost_time = Debug::timerStop();
        $this->logMsg('add', $key, $ret, $cost_time, $value);
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
        if (!$this->is_available) {
            return false;
        }
        Debug::timerStart();
        $ret = $this->redis_fd->incrBy($key, $step);
        $cost_time = Debug::timerStop();
        $this->logMsg('increase', $key, true, $cost_time, $ret);
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
        if (!$this->is_available) {
            return false;
        }
        Debug::timerStart();
        $ret = $this->redis_fd->decrBy($key, $step);
        $cost_time = Debug::timerStop();
        $this->logMsg('decrease', $key, true, $cost_time, $ret);
        return $ret;
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
}