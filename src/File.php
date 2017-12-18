<?php

namespace UiStd\Cache;

use UiStd\Event\EventManager;
use UiStd\Common\Utils as UisUtils;
use UiStd\Common\Env as UisEnv;

/**
 * Class File 文件缓存
 * @package UiStd\Cache
 */
class File extends CacheBase implements CacheInterface
{
    /** 判断是否过期的key */
    const EXPIRE_KEY = '__expire__';

    /** 缓存值的key */
    const VALUE_KEY = '__value__';

    /** 用于检验的token */
    const CAS_KEY = '__token__';

    /** 文件名前缀 */
    const FILE_PREFIX = '_tmp_';

    /**
     * @var array 已经打开的缓存
     */
    private $cache_arr;

    /**
     * @var array 待保存的缓存
     */
    private $cache_save;

    /**
     * @var string 文件路径
     */
    private $file_path;

    /**
     * FileCache constructor.
     * @param string $config_name 配置名称
     * @param array $config_set 配置参数列表
     */
    public function __construct($config_name, $config_set)
    {
        parent::__construct($config_name, $config_set, 'file');
        EventManager::instance()->attach(EventManager::SHUTDOWN_EVENT, [$this, 'commit']);
    }

    /**
     * 获取一个缓存.
     * @param string $key 缓存键值
     * @param mixed $default 如果缓存不存在，返回的默认值
     * @return mixed
     */
    public function get($key, $default = null)
    {
        //如果缓存里已经有了
        if (isset($this->cache_arr[$key])) {
            return $this->cache_arr[$key];
        }
        $file_name = $this->makeFileName($key);
        //文件不存在
        if (!is_file($file_name)) {
            return $default;
        }
        /** @noinspection PhpIncludeInspection */
        $tmp_arr = require($file_name);
        //如果不是数组，或者不存在expire_key或者 value_key
        if (!is_array($tmp_arr) || !isset($tmp_arr[self::EXPIRE_KEY], $tmp_arr[self::VALUE_KEY])) {
            return $default;
        }
        $exp = (int)$tmp_arr[self::EXPIRE_KEY];
        //过期了
        if (0 !== $exp && $exp < time()) {
            return $default;
        }
        $value = $tmp_arr[self::VALUE_KEY];
        $this->cache_arr[$key] = $value;
        return $value;
    }

    /**
     * 生成文件名
     * @param string $key
     * @return string
     */
    private function makeFileName($key)
    {
        //如果key名不满足文件命名，就md5它
        if (strlen($key) > 32 || !preg_match('/^[a-zA-Z_][a-zA-Z_0-9.]*$/', $key)) {
            $key = md5($key);
        }
        if (null === $this->file_path) {
            $this->init();
        }
        return $this->file_path . self::FILE_PREFIX . $key . '.php';
    }

    /**
     * 初始化
     */
    private function init()
    {
        $base_dir = $this->getConfigString('cache_dir', 'file_cache');
        if (DIRECTORY_SEPARATOR !== $base_dir[0]) {
            $base_dir = UisUtils::joinPath(UisEnv::getRuntimePath(), $base_dir);
        }
        $this->file_path = $base_dir;
        //是否有可写权限
        UisUtils::pathWriteCheck($base_dir);
    }

    /**
     * 生成过期时间
     * @param int $ttl 过期时间
     * @return int
     */
    private function ttl($ttl)
    {
        if (null === $ttl || $ttl < 0) {
            return 86400;
        }
        return $ttl;
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
        $this->cache_save[$key] = array($value, $ttl);
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
        trigger_error('FileCache do not support `casGet` method');
        return false;
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
        trigger_error('FileCache do not support `casSet` method');
        return false;
    }

    /**
     * 从缓存中删除一个键
     * @param string $key 缓存名
     * @return bool
     */
    public function delete($key)
    {
        unset($this->cache_arr[$key], $this->cache_save[$key]);
        $file_name = $this->makeFileName($key);
        if (!is_file($file_name)) {
            return true;
        }
        return unlink($file_name);
    }

    /**
     * 清除整个缓存
     * @return bool
     */
    public function clear()
    {
        if (null === $this->file_path) {
            $this->init();
        }
        $handle = opendir($this->file_path);
        if (!$handle) {
            return false;
        }
        while (false !== ($item = readdir($handle))) {
            //如果不是以 FILE_PREFIX 开始的，不处理
            if (0 !== strpos($item, self::FILE_PREFIX)) {
                continue;
            }
            $file = $this->file_path . $item;
            if (is_dir($file)) {
                continue;
            }
            //删除失败，可能没有权限
            if (!unlink($file)) {
                return false;
            }
        }
        closedir($handle);
        return true;
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
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
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
        foreach ($values as $key => $value) {
            $this->set($key, $values, $ttl);
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
        $result = true;
        foreach ($keys as $key) {
            $re = $this->delete($key);
            if (false === $re) {
                $result = false;
            }
        }
        return $result;
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
        if ($this->has($key)) {
            return false;
        }
        $file_name = $this->makeFileName($key);
        $ttl = $this->ttl($ttl);
        $file_handle = fopen($file_name, 'a+');
        if (!$file_handle) {
            return false;
        }
        return $this->writeFile($file_handle, $value, $ttl);
    }

    /**
     * 生成内容
     * @param resource $file_handle
     * @param mixed $value 值
     * @param int $ttl 有效时间
     * @return bool
     */
    private function writeFile($file_handle, $value, $ttl)
    {
        $arr = array(
            self::VALUE_KEY => $value,
        );
        if ($ttl > 0) {
            $arr[self::EXPIRE_KEY] = $ttl + time();
        } else {
            //0表示不过期
            $arr[self::EXPIRE_KEY] = 0;
        }
        $content = '<?php' . PHP_EOL . 'return ' . var_export($arr, true) . ';';
        if (!flock($file_handle, LOCK_EX)) {
            fclose($file_handle);
            return false;
        }
        //清空内容
        ftruncate($file_handle, 0);
        //重置文件指针位置
        rewind($file_handle);
        $re = fwrite($file_handle, $content);
        flock($file_handle, LOCK_UN);
        fclose($file_handle);
        return false !== $re;
    }

    /**
     * 在一个键上做自增
     * @param string $key 键名
     * @param int $step 步长
     * @return bool|int 如果值不存在，将返回false 其它情况元素的值
     */
    public function increase($key, $step = 1)
    {
        trigger_error('FileCache do not support `increase` method');
        return false;
    }

    /**
     * 在一个键上做自减
     * @param string $key 键名
     * @param int $step 步长
     * @return bool|int 如果值不存在，将返回false
     */
    public function decrease($key, $step = 1)
    {
        trigger_error('FileCache do not support `decrease` method');
        return false;
    }

    /**
     * 写入缓存
     */
    public function commit()
    {
        if (empty($this->cache_save)) {
            return;
        }
        foreach ($this->cache_save as $key => $tmp) {
            $file = $this->makeFileName($key);
            $file_handle = fopen($file, 'a+');
            $this->writeFile($file_handle, $tmp[0], $tmp[1]);
        }
        $this->cache_save = null;
    }
}
