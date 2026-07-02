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
            $this->namespace = $this->retry(function () {
                return $this->cache->get('RKC:NAMESPACE');
            });
            if (!$this->namespace)
            {
                $this->namespace = 1;
                $this->retry(function () {
                    return $this->cache->set('RKC:NAMESPACE', $this->namespace);
                });
            }
        }
    }

    protected function connect()
    {
        try
        {
            if (\is_array($this->server) && \count($this->server))
            {
                $this->cache = new \RedisArray($this->server,
                    [
                       'lazy_connect'    => true,
                       'retry_timeout'   => 100,
                       'read_timeout'    => 0.5,
                       'connect_timeout' => 0.3,
                    ]
                );
                $this->connected = $this->cache->ping();
            }
            else
            {
                $this->cache = new \Redis();
                $this->connected = $this->cache->connect($this->server, 6379, 0.5);
            }
            if ($this->connected)
                $this->cache->select(4);
        }
        // RedisArray::ping() / Redis::connect() raise \RedisException (NOT
        // \RedisArrayException) on a socket/read error. The old catch tested for
        // \RedisArrayException, so a reaped or failed-over connection surfaced as
        // an *uncaught* fatal right at the ping() line. Catch the real type and
        // degrade to "no cache" instead of 500-ing the whole request.
        catch (\RedisException $e)
        {
            $this->connected = false;
            file_put_contents('php://stderr', 'TaggedCache ERROR: ' . $e->getMessage() . "\n");
        }
    }

    /**
     * Run a redis op with a single reconnect+retry on a dropped connection.
     *
     * HAProxy fronts redis in L4 (tcp) mode: after a failover or an idle-reap it
     * does NOT re-forward an already-established connection — the socket just
     * dies and phpredis raises \RedisException on next use. HAProxy only picks
     * the live master on a NEW connection, so the cure is client-side: reconnect
     * and retry once. If it still fails, degrade gracefully (return $default)
     * rather than let an uncaught \RedisException take down the page.
     *
     * @param \Closure $op      the redis operation (uses $this->cache internally)
     * @param mixed    $default value to return if the op cannot be completed
     *
     * @return mixed
     */
    protected function retry(\Closure $op, $default = false)
    {
        if (!$this->connected)
            return $default;
        try
        {
            return $op();
        }
        catch (\RedisException $e)
        {
            try
            {
                $this->connect();               // rebuild RedisArray/Redis -> live master
                if ($this->connected)
                    return $op();
            }
            catch (\RedisException $ignored) {}
            $this->connected = false;
            file_put_contents('php://stderr', 'TaggedCache ERROR: ' . $e->getMessage() . "\n");
            return $default;
        }
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
            return $this->retry(function () use ($key, $timeout, $compressed) {
                return $this->cache->setex($key, $timeout, $compressed);
            });
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
            $dane = $this->retry(function () use ($key) {
                return $this->cache->get($key);
            });

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
                    $this->retry(function () {
                        $this->cache->incr('RKC:NAMESPACE');
                        $this->namespace = $this->cache->get('RKC:NAMESPACE');
                    });
                    break;
                case self::CLEANING_MODE_CLEAR:
                    $this->retry(function () {
                        $this->cache->flushdb();
                    });
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
                                $this->retry(function () use ($tag) {
                                    return $this->cache->setex('RKC:D:' . $tag, $this->delayedKeysTtl, 1);
                                });
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
                $tag_mget = [];
                foreach ($tags as $tag) {
                    if (\in_array($tag, $this->delayedKeys, false)) {
                        $delayed = $this->retry(function () use ($tag) {
                            return $this->cache->get('RKC:D:' . $tag);
                        });
                        if ($delayed) {
                            $seen = $this->retry(function () use ($tag) {
                                return $this->cache->get('RKC:T:' . $tag);
                            });
                            if (!$seen) {
                                $this->retry(function () use ($tag) {
                                    return $this->cache->setex('RKC:T:' . $tag, 49, 1);
                                });
                                $this->incrementTag($tag);
                            }
                        }
                    }
                    $tag_mget[] = 'RKC:TAGS:' . $this->prepareString($tag);
                    $tags_str   = $tags_str . '_' . $tag;
                }
                $mget = $this->retry(function () use ($tag_mget) {
                    return $this->cache->mGet($tag_mget);
                }, []);
                $tags_val = implode('_', \is_array($mget) ? $mget : []);
            }

            $hash_this = $this->prefix . '_keys_' . $string . '_' . $tags_str . '_' . $tags_val;

            return 'RKC:' . $this->namespace . ':' . hash('tiger192,3', $hash_this);
        }
    }

    protected function incrementTag($tag)
    {
        if ($this->connected) {
            return $this->retry(function () use ($tag) {
                return $this->cache->incr('RKC:TAGS:' . $this->prepareString($tag));
            });
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
