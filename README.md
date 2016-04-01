TaggedCache
==============
Cache with tags, fast delete

TaggedMemCache Class - cache based on MemCache


usage example: 

$cache = new TaggedMemCache();
//or
$cache = new TaggedRedisCache('192.168.1.34'); //redis server address
//key for cache should be unique for given params
$key  = md5("item name and unique params ".json_encode($params));
$tags = ['tag_for_delete'];
//try from cache, if not exists - fill cache
if (!$result = $cache->load($key, $tags)){
    $result = \Get_Data::result($params); // just fill $result with computed data
    $cache->save($result, $key, $tags, 1200); //remember this for 1200 sec
}
//if one of $params get changed do somethink like this
$cache->clean('matchingTag',['tag_for_delete']);
