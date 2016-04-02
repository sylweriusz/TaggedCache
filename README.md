TaggedCache
==============
Cache with tags, fast delete

### Installation with composer
It is possible to install library via composer
```
composer require sylweriusz/taggedcache
```

### Initialization

```php
$cache = new \TaggedCache\Memcache('127.0.0.1'); //memcache server address
//or
$cache = new \TaggedCache\Redis('192.168.1.34'); //redis server address
```

###Usage 

```php
$key  = md5("item name and unique params ".json_encode($params));
$tags = ['tag_for_delete'];

//try from cache, and save if not exists
if (!$result = $cache->load($key, $tags)){
    $result = \Get_Data::result($params); // just fill $result with computed data
    $cache->save($result, $key, $tags, 1200); //remember this for 1200 sec
}
```

###Cache Clean 

```php
//clean all items    
$cache->clean('all');

//if one of $params get changed do somethink like this
$cache->clean('matchingAnyTag',['tag_for_delete', 'or_another_tag']);
```
