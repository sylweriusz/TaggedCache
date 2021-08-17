<?php

namespace TaggedCache;

/**
 * Class TaggedRedisCache
 */
class Redis implements BasicCache
{
    const CLEANING_MODE_ALL = 'all';
    const CLEANING_MODE_CLEAR = 'clear';
    const CLEANING_MODE_MATCHING_TAG = 'matchingTag';
    const CLEANING_MODE_MATCHING_ANY_TAG = 'matchingAnyTag';

    protected $delayedKeys = [];
    protected $cache = false;
    protected $connected = null;
    protected $namespace = false;
    protected $server ;
    protected $prefix = '';
    protected $delayedKeysTtl = 200;

    /**
     * TaggedRedisCache constructor.
     *
     * @param string $server // ip of a redis server
     */
    public function __construct($server = '127.0.0.1')
    {
        $this->server = $server;
        $this->connect();
        if ($this->connected)
        {
            $this->namespace = $this->cache->get('RKC:NAMESPACE');
            if (!$this->namespace)
            {
                $this->namespace = 1;
                $this->cache->set('RKC:NAMESPACE', $this->namespace);
            }
        }
    }

    protected function connect()
    {
        if (\is_array($this->server) && \count($this->server))
        {
            try
            {
                $this->cache = new \RedisArray($this->server,
                    [
                       'lazy_connect' => false,
                       'retry_timeout'   => 100,
                       'read_timeout'    => 1,
                       'connect_timeout' => 1,
                    ]
                );
                $this->connected = $this->cache->ping();
            } catch (\RedisException $e)
            {
                $this->connected = false;
                error_log('TaggedCache ERROR: '.$e->getMessage());
            }
        }
        else
        {
            try
            {
                $this->cache = new \Redis();
                $this->connected = $this->cache->connect($this->server, 6379, 0.5);
            } catch (\RedisException $e)
            {
                $this->connected = false;
                error_log('TaggedCache ERROR: '.$e->getMessage());
            }
        }
        if($this->connected)
            $this->cache->select(4);
    }

    /**
     * Save variable in cache
     *
     * @param mixed  $data    // variable to store
     * @param string $key     // unique key
     * @param array  $tags    // array of tags for simple delete of key groups
     * @param int    $timeout // timeout in seconds
     *
     * @return string "+OK\r\n" or "-Error message\r\n" etc
     */
    public function save($data, $key, $tags = [], $timeout = 3600)
    {
        if ($this->connected)
        {
            $key = $this->genKey($key, $tags);
            $compressed = gzcompress(json_encode($data, JSON_UNESCAPED_UNICODE), 9);
            try {
                return $this->cache->setex($key, $timeout, $compressed);
            } catch (\RedisException $e){
                return false;
            }
        }
    }

    /**
     * Try load Variable from Cache
     *
     * @param string $key  // unique key
     * @param array  $tags // array of tags for simple delete of key groups
     *
     * @return bool|mixed
     */
    public function load($key, $tags = [])
    {
        if ($this->connected)
        {
            $key = $this->genKey($key, $tags);
            $dane = $this->cache->get($key);

            if ($dane)
            {
                return json_decode(gzuncompress($dane), true);
            }
        return false;
        }
    }

    /**
     * Clean whole Cache or tag group
     *
     * @param       $mode // one of 'all', 'matchingTag', 'matchingAnyTag'
     * @param array $tags // tag or tags
     */
    public function clean($mode, $tags = [])
    {
        if ($this->connected)
        {
            switch ($mode)
            {
                case self::CLEANING_MODE_ALL:
                    $this->cache->incr('RKC:NAMESPACE');
                    $this->namespace = $this->cache->get('RKC:NAMESPACE');
                    break;
                case self::CLEANING_MODE_CLEAR:
                    $this->cache->flushdb();
                    break;
                case self::CLEANING_MODE_MATCHING_TAG:
                case self::CLEANING_MODE_MATCHING_ANY_TAG:
                    if (\count($tags))
                    {
                        foreach ($tags as $tag)
                        {
                            $this->incrementTag($tag);
                            if (\in_array($tag, $this->delayedKeys, false))
                            {
                                $this->cache->setex('RKC:D:' . $tag, $this->delayedKeysTtl, 1);
                            }
                        }
                    }
                    break;
            }
        }
    }

    protected function genKey($string, $tags = null)
    {
        if ($this->connected) {
            $tags_str = '_';
            $tags_val = 0;
            if (\is_array($tags) && \count($tags)) {
                asort($tags);
                foreach ($tags as $tag) {
                    if (\in_array($tag, $this->delayedKeys, false)) {
                        if ($this->cache->get('RKC:D:' . $tag)) {
                            if (!$this->cache->get('RKC:T:' . $tag)) {
                                $this->cache->setex('RKC:T:' . $tag, 49, 1);
                                $this->incrementTag($tag);
                            }
                        }
                    }
                    $tag_mget[] = 'RKC:TAGS:' . $this->prepareString($tag);
                    $tags_str   = $tags_str . '_' . $tag;
                }
                $tags_val = implode('_', $this->cache->mGet($tag_mget));
            }

            $hash_this = $this->prefix . '_keys_' . $string . '_' . $tags_str . '_' . $tags_val;

            return 'RKC:' . $this->namespace . ':' . hash('tiger192,3', $hash_this);
        }
    }

    protected function incrementTag($tag)
    {
        if ($this->connected) {
            return $this->cache->incr('RKC:TAGS:' . $this->prepareString($tag));
        }
    }


    protected function prepareString($string)
    {
        return preg_replace('/\W/', '', $string);
    }

    /**
     * Set prefix, for cache separation in some scenarios
     *
     * @param string $prefix
     */
    public function prefix($prefix)
    {
        $this->prefix = (string)$prefix;
    }

    public function getInstance()
    {
        if ($this->connected)
        {
            return $this->cache;
        }

        return false;
    }
}

