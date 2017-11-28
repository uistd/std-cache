<?php

namespace FFan\Std\Cache;

use FFan\Std\Common\ConfigBase;
use FFan\Std\Common\Env;
use FFan\Std\Console\Debug;
use FFan\Std\Logger\LogHelper;
use FFan\Std\Logger\LogRouter;

/**
 * Class CacheBase
 * @package FFan\Std\Cache
 */
class CacheBase extends ConfigBase
{
    /**
     * @var string 配置名称
     */
    protected $conf_name;

    /**
     * @var string 缓存类型
     */
    protected $cache_type;

    /**
     * @var array
     */
    protected $conf_arr;

    /**
     * @var LogRouter
     */
    protected $logger;

    /**
     * @var array 是否是调试模式
     */
    private $is_debug;

    /**
     * CacheBase constructor.
     * @param string $conf_name 配置名称
     * @param array $conf_arr 配置项
     * @param string $cache_type 缓存类型
     */
    public function __construct($conf_name, $conf_arr, $cache_type)
    {
        $this->initConfig($conf_arr);
        $this->cache_type = strtoupper($cache_type);
        $this->conf_name = $conf_name;
        $this->logger = LogHelper::getLogRouter();
        if (!Env::isProduct()) {
            $this->is_debug = true;
        } else {
            $this->is_debug = Debug::isDebugIO();
        }
    }

    /**
     * 日志消息
     * @param string $action 操作类型
     * @param string|null $key 键名
     * @param bool $is_success 结果
     * @param null|string $cost_time
     * @param mixed $data
     */
    protected function logMsg($action, $key = null, $is_success = true, $cost_time = null, $data = null)
    {
        $str = Debug::getIoStepStr() . '[' . $this->cache_type . ' ' . $this->conf_name . '][' . $action . ']';
        if (!empty($key)) {
            $str .= $key;
        }
        $str .= $is_success ? ' success' : ' failed';
        if (!empty($cost_time)) {
            $str .= '[' . $cost_time . ']';
        }
        $this->logger->info($str);
        if ($this->is_debug && null !== $data) {
            $this->logger->debug(Debug::varFormat($data));
        }
        Debug::addIoStep();
    }

    /**
     * 错误消息
     * @param string $msg
     */
    protected function errorMsg($msg)
    {
        $str = '[' . $this->cache_type . ' ' . $this->conf_name . ']' . $msg;
        $this->logger->error($str);
    }
}
