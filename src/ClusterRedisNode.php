<?php

namespace UiStd\Cache;

use UiStd\Common\InvalidConfigException;
use UiStd\Console\Debug;

/**
 * Class ClusterRedisNode
 * @package UiStd\Cache
 */
class ClusterRedisNode extends CacheBase
{
    /**
     * @var string
     */
    private $host;

    /**
     * @var int
     */
    private $port;

    /**
     * @var \Redis Redis连接句柄
     */
    private $redis_fd;

    /**
     * @var int 节点Id，用于批量处理时为key分组
     */
    private $id;

    /**
     * @var int
     */
    private static $_id;

    /**
     * ClusterRedisNode constructor.
     * @param string $conf_name
     * @param array $conf_arr
     * @throws InvalidConfigException
     */
    public function __construct($conf_name, $conf_arr)
    {
        parent::__construct($conf_name, $conf_arr, 'cluster_redis');
        $this->id = ++self::$_id;
        $this->host = $this->getConfig('host');
        $this->port = $this->getConfigInt('port');
        if (!is_string($this->host) || empty($this->host)) {
            $this->errorMsg('Node config array');
            throw new InvalidConfigException('Redis host error');
        }
    }

    /**
     * 获取Redis连接句柄
     * @return \Redis
     * @throws InvalidConfigException
     */
    public function getRedisFd()
    {
        if (null !== $this->redis_fd) {
            return $this->redis_fd;
        }
        $redis = new \Redis();
        Debug::timerStart();
        $ret = $redis->pconnect($this->host, $this->port, 1);
        $use_time = Debug::timerStop();
        $this->logMsg('connect', $this->host . ':' . $this->port, $ret, $use_time);
        if ($ret) {
            $this->redis_fd = $redis;
        } else {
            $this->errorMsg('CAN_NOT_CONNECT_CLUSTER_REDIS ' . $this->host . ':' . $this->port);
        }
        return $this->redis_fd;
    }

    /**
     * 生成唯一key
     * @param int $slot
     * @return string
     */
    public function makeServerKey($slot)
    {
        return $this->host . ':' . $this->port . '_' . $slot;
    }

    /**
     * 获取节点ID
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }
}
