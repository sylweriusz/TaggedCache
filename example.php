<?php

$params = [
    'id'   => 242342,
    'date' => '2010-12-22 04:09:00',
    'text' => 'lorem ipsum'
];

$cache = new \TaggedCache\Memcache();
//or
$cache = new \TaggedCache\Redis('192.168.1.34'); //redis server address

//key for cache should be unique for given params
$key  = md5("item name and unique params " . json_encode($params));
$tags = ['tag_for_delete'];
//try from cache, if not exists - fill cache
if (!$result = $cache->load($key, $tags))
{
    $result = json_encode($params); // anything time consuming what You want to do with $params 
    $cache->save($result, $key, $tags, 1200); //remember this for 1200 sec
}


//if one of $params get changed do somethink like this
$cache->clean('matchingTag', ['tag_for_delete']);
