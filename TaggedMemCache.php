namespace awr;

class TaggedMemCache
{
    const CLEANING_MODE_ALL = 'all';
    const CLEANING_MODE_MATCHING_TAG = 'matchingTag';
    const CLEANING_MODE_MATCHING_ANY_TAG = 'matchingAnyTag';

    private $memcached = false;
    private $namespace = false;
    private $keys = array(); //keys version internal cache

    public function __construct()
    {
        $this->connect();
        $this->namespace = $this->memcached->get("keymemcache.namespace.key.beware");
        if ($this->namespace === false)
        {
            $this->namespace = rand(1, 10000);
            $this->memcached->set("keymemcache.namespace.key.beware", $this->namespace);
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
                $this->memcached->addServer('127.0.0.1', 11211, 33);
            }
        }
    }

    public function save($data, $key, $tags = null, $timeout = 3600)
    {
        if (count($tags))
        {
            $key = $this->genkey($key, $tags);
        }
        return $this->memcached->set($key, $data, $timeout);
    }

    public function load($key, $tags = null)
    {
        if (count($tags))
        {
            $key = $this->genkey($key, $tags);
        }
        return $this->memcached->get($key);
    }

    public function clean($mode, $tags = array())
    {
        switch ($mode)
        {
            case self::CLEANING_MODE_ALL:
                $this->memcached->increment("keymemcache.namespace.key.beware");
                $this->namespace = $this->memcached->get("keymemcache.namespace.key.beware");
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

    private function genkey($string, $tags = array())
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

        $hash_this = 'the3bushooxoobu4oo9Esah1chutak' . $string . '_' . $tags_str . '_' . $tags_val;
        return $this->namespace . '.' . rtrim(strtr(base64_encode(hash('sha256', $hash_this, true)), '+/', '-_'), '=');
    }

    private function incrementTag($tag)
    {
        $this->getTagValue($tag);
        $this->memcached->increment("keymemcache_key_" . $tag);
        return $this->keys[$tag] = $this->memcached->get("keymemcache_key_" . $tag);
    }


    private function getTagValue($tag)
    {
        if (!$this->keys[$tag])
        {
            $this->keys[$tag] = $this->memcached->get("keymemcache_key_" . $tag);
        }
        if (!$this->keys[$tag])
        {
            $this->memcached->set("keymemcache_key_" . $tag, 1, 90000);
            $this->keys[$tag] = 1;
        }
        return $this->keys[$tag];
    }

}
