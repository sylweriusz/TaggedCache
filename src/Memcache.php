<?php

namespace TaggedCache;

class Memcache implements BasicCache
{
    const CLEANING_MODE_ALL = 'all';
    const CLEANING_MODE_MATCHING_TAG = 'matchingTag';
    const CLEANING_MODE_MATCHING_ANY_TAG = 'matchingAnyTag';

    private $memcached = false;
    private $namespace = false;
    private $server = '';
    private $prefix = '';

    public function __construct($server = '127.0.0.1')
    {
        $this->connect();
        $this->server    = $server;
        $this->namespace = $this->memcached->get("TCM:namespace");
        if ($this->namespace === false)
        {
            $this->namespace = rand(1, 10000);
            $this->memcached->set("TCM:namespace", $this->namespace);
        }
    }

    private function connect()
    {

        if (!$this->memcached)
        {
            $this->memcached = new Memcached('pool');
            $this->memcached->setOption(Memcached::OPT_COMPRESSION, true);
            if (count($this->memcached->getServerList()) < 1)
            {
                $this->memcached->addServer($this->server, 11211, 33);
            }
        }
    }

    public function save($data, $key, $tags = [], $timeout = 3600)
    {
        if ($this->memcached)
        {
            $key = $this->genkey($key, $tags);

            return $this->memcached->set($key, $data, $timeout);
        }
    }

    public function load($key, $tags = [])
    {
        if ($this->memcached)
        {
            $key = $this->genkey($key, $tags);

            return $this->memcached->get($key);
        }
    }

    public function clean($mode, $tags = [])
    {
        switch ($mode)
        {
            case self::CLEANING_MODE_ALL:
                $this->memcached->increment("TCM:namespace");
                $this->namespace = $this->memcached->get("TCM:namespace");
                break;
            case self::CLEANING_MODE_MATCHING_TAG:
            case self::CLEANING_MODE_MATCHING_ANY_TAG:
                if (count($tags))
                {
                    foreach ($tags as $tag)
                    {
                        $this->incrementTag($tag);
                    }
                }
                break;
        }
    }

    private function genkey($string, $tags = [])
    {
        $tags_str = '_';
        $tags_val = 0;
        if (count($tags))
        {
            asort($tags);
            foreach ($tags as $tag)
            {
                $tags_str = $tags_str . '_' . $tag;
                $tags_val = $tags_val . '_' . $this->getTagValue($tag);
            }
        }

        $hash_this = $this->prefix . '_keys_' . $string . '_' . $tags_str . '_' . $tags_val;

        return 'TCM:' . $this->namespace . ':' . rtrim(strtr(base64_encode(hash('sha256', $hash_this, true)), '+/', '-_'), '=');
    }

    private function incrementTag($tag)
    {
        return $this->memcached->increment("TCM:key:" . $tag);
    }


    private function getTagValue($tag)
    {
        $thistag = $this->memcached->get("TCM:key:" . $tag);
        if (!$thistag)
        {
            $this->memcached->set("TCM:key:" . $tag, 1, 90000);
            $thistag = 1;
        }

        return $thistag;
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
}
