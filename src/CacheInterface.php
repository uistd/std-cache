<?php

namespace UiStd\Cache;

/**
 * Interface CatchInterface simpleCache改良接口
 * @package UiStd\Cache
 */
interface CacheInterface
{
    /**
     * 获取一个缓存.
     * @param string $key 缓存键值
     * @param mixed $default 如果缓存不存在，返回的默认值
     * @return mixed
     */
    public function get($key, $default = null);

    /**
     * 设置一个缓存
     * @param string $key 缓存键名
     * @param mixed $value 值
     * @param null|int $ttl 过期时间
     * @return bool
     */
    public function set($key, $value, $ttl = null);

    /**
     * 获取一个值，同时将它的token值存起来
     * @param string $key
     * @param null $default
     * @return mixed
     */
    public function casGet($key, $default = null);

    /**
     * 先比较cas，再做缓存更新（如果未找到cas值，将更新失败）
     * @param string $key 缓存键名
     * @param mixed $value 值
     * @param null|int $ttl 过期时间
     * @return bool
     */
    public function casSet($key, $value, $ttl = null);

    /**
     * 从缓存中删除一个键
     * @param string $key 缓存名
     * @return bool
     */
    public function delete($key);

    /**
     * 清除整个缓存
     * @return bool
     */
    public function clear();

    /**
     * 获取多个缓存.
     * @param array $keys 缓存键名列表
     * @return array 如果值不存在的key会以default填充
     */
    public function getMultiple(array $keys);

    /**
     * 批量设置缓存
     * @param array $values 一个key => value 数组
     * @param null|int $ttl
     * @return bool
     */
    public function setMultiple(array $values, $ttl = null);

    /**
     * 批量删除
     * @param array $keys 需要删除的keys
     * @return bool
     */
    public function deleteMultiple(array $keys);

    /**
     * 判断缓存中是否有某个值
     * @param string $key 缓存键名
     * @return bool
     */
    public function has($key);

    /**
     * 添加一个缓存（必须保证缓存中没有值时才能插入成功）
     * @param string $key 缓存键名
     * @param mixed $value 值
     * @param null|int $ttl 过期时间
     * @return bool
     */
    public function add($key, $value, $ttl = null);

    /**
     * 在一个键上做自增
     * @param string $key 键名
     * @param int $step 步长
     * @return bool|int 如果值不存在，将返回false 其它情况元素的值
     */
    public function increase($key, $step = 1);

    /**
     * 在一个键上做自减
     * @param string $key 键名
     * @param int $step 步长
     * @return bool|int 如果值不存在，将返回false
     */
    public function decrease($key, $step = 1);
}
