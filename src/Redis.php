<?php

namespace TaggedCache;

/**
 * Class TaggedRedisCache
 */
class Redis implements BasicCache
{
    const CLEANING_MODE_ALL = 'all';
    const CLEANING_MODE_MATCHING_TAG = 'matchingTag';
    const CLEANING_MODE_MATCHING_ANY_TAG = 'matchingAnyTag';

    private $cache = false;
    private $connected = false;
    private $namespace = false;
    private $server = '';
    private $prefix = '';
    private $delayedKeys = ['element', 'elements', 'layout', 'thumb'];
    private $delayedKeysTtl = 200;

    /**
     * TaggedRedisCache constructor.
     *
     * @param string $server // ip of a redis server
     */
    public function __construct($server = '127.0.0.1')
    {
        $this->server = $server;
        $this->connect();
        if ($this->connected) {
            $this->namespace = $this->cache->get("RKC:NAMESPACE");
            if (!$this->namespace) {
                $this->namespace = random_int(1, 10000);
                $this->cache->set("RKC:NAMESPACE", $this->namespace);
            }
        }
    }

    private function connect()
    {
        if (!$this->connected) {
            if (class_exists("\\Redis")) {
                try {
                    if (is_array($this->server) && count($this->server)) {
                        $this->cache = new \RedisArray($this->server, ["lazy_connect" => true]);
                        $this->connected = true;
                    } else {
                        $this->cache = new \Redis();
                        $this->connected = $this->cache->connect($this->server, 6379);
//                    $this->cache->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);
                    }
                    $this->cache->select(1);
                } catch (\RedisException $e) {
                    $this->connected = false;
                }
            } else {
                if (is_array($this->server) && count($this->server)) {
                    foreach ($this->server as $item) {
                        $server[] = 'tcp://' . $item . ':6379&database=1';
                    }
                } else {
                    $server = 'tcp://' . $this->server . ':6379&database=1';
                }
                try {
                    $this->cache = new \Predis\Client($server);
                    $this->connected = true;
                } catch (\Predis\CommunicationException $e) {
                    $this->connected = false;
                }
            }

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
        if ($this->connected) {
            $key = $this->genkey($key, $tags);
            $compressed = gzcompress(json_encode($data, JSON_UNESCAPED_UNICODE), 9);

            return $this->cache->setex($key, $timeout, $compressed);
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
        if ($this->connected) {
            $key = $this->genkey($key, $tags);
            $dane = $this->cache->get($key);

            if ($dane) {
                return json_decode(gzuncompress($dane), true);
            } else {
                return false;
            }
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
        if ($this->connected) {
            switch ($mode) {
                case self::CLEANING_MODE_ALL:
                    $this->cache->incr("RKC:NAMESPACE");
                    $this->namespace = $this->cache->get("RKC:NAMESPACE");
                    break;
                case self::CLEANING_MODE_MATCHING_TAG:
                case self::CLEANING_MODE_MATCHING_ANY_TAG:
                    if (count($tags)) {
                        foreach ($tags as $tag) {
                            $this->incrementTag($tag);
                            if (in_array($tag, $this->delayedKeys)) {
                                $this->cache->setex("RKC:D:" . $tag, $this->delayedKeysTtl, 1);
                            }
                        }
                    }
                    break;
            }
        }
    }

    private function genkey($string, $tags = null)
    {
        $tags_str = '_';
        $tags_val = 0;
        if (is_array($tags) && count($tags)) {
            asort($tags);
            foreach ($tags as $tag) {
                if (in_array($tag, $this->delayedKeys)) {
                    if ($this->cache->get("RKC:D:" . $tag)) {
                        if (!$this->cache->get("RKC:T:" . $tag)) {
                            $this->cache->setex("RKC:T:" . $tag, 49, 1);
                            $this->incrementTag($tag);
                        }
                    }
                }
                $tags_str = $tags_str . '_' . $tag;
                $tags_val = $tags_val . '_' . $this->getTagValue($tag);
            }
        }

        $hash_this = $this->prefix . '_keys_' . $string . '_' . $tags_str . '_' . $tags_val;

        $key = 'RKC:' . $this->namespace . ':' . hash('tiger192,3', $hash_this);
        return $key;
    }

    private function incrementTag($tag)
    {
        $tag = $this->prepare_tag($tag);

        return $this->cache->incr("RKC:TAGS:" . $tag);
    }


    private function getTagValue($tag)
    {
        $tag = $this->prepare_tag($tag);

        if (!$newval = $this->cache->get("RKC:TAGS:" . $tag)) {
            $this->cache->setex("RKC:TAGS:" . $tag, 199999, 1);
            $newval = 1;
        } else {
            $this->cache->expire("RKC:TAGS:" . $tag, 199999);
        }

        return $newval;
    }

    private function prepare_tag($tag)
    {
        return str_replace(":", ".", $tag);
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
        if ($this->connected) {
            return $this->cache;
        } else {
            return false;
        }

    }
}

