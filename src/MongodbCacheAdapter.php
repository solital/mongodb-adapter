<?php

namespace Solital\MongodbAdapter;

use Override;
use Solital\Core\Cache\Adapter\CacheAdapterInterface;
use Solital\Core\Cache\Exception\CacheAdapterException;

/**
 * Cache adapter for Solital Framework
 */
class MongodbCacheAdapter implements CacheAdapterInterface
{
    /**
     * @var mixed
     */
    private mixed $collection_instance;

    /**
     * @var string
     */
    private string $collection_name = 'solital_mongodb_cache';

    public function __construct()
    {
        $yaml = app_get_yaml('cache.yaml');
        
        $user = $yaml['cache_user'] ?? '';
        $pass = $yaml['cache_pass'] ?? '';

        $database = $yaml['cache_mongodb_database'] ?? throw new CacheAdapterException(
            'Database for MongoDB Cache not defined'
        );

        $mongodb_adapter = MongodbAdapter::configuration(
            $yaml['cache_host'],
            $user,
            $pass
        );
        $mongodb_adapter->setDatabase($database);

        $collections = $mongodb_adapter->getCollections($database);

        if (!in_array($this->collection_name, $collections)) {
            $create = $mongodb_adapter->getDatabase();
            $result = $create->createCollection($this->collection_name);
            // var_dump($result);
        }

        $mongodb_adapter->setTable($this->collection_name);
        $this->collection_instance = $mongodb_adapter->getCollectionInstance();
    }

    /**
     * @param string $key
     *
     * @return mixed
     */
    #[Override]
    public function get(string $key): mixed
    {
        $result = $this->collection_instance->findOne(
            ['key' => $key, 'expiry' => ['$gt' => new \MongoDB\BSON\UTCDateTime()]]
        );
        return $result ? $result['value'] : null;
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    #[Override]
    public function has(string $key): bool
    {
        $value = $this->get($key);
        return ($value) ? true : false;
    }

    /**
     * @param string $key
     *
     * @return mixed
     */
    #[Override]
    public function delete(string $key): mixed
    {
        return $this->collection_instance->deleteOne(['key' => $key]);
    }

    /**
     * @param string $key
     * @param mixed $data
     *
     * @return mixed
     */
    #[Override]
    public function save(string $key, mixed $data, int $expiration_time): mixed
    {
        $expiry = new \MongoDB\BSON\UTCDateTime($expiration_time);

        $this->collection_instance->updateOne(
            ['key' => $key],
            ['$set' => ['value' => $data, 'expiry' => $expiry]],
            ['upsert' => true]
        );

        return true;
    }
}
