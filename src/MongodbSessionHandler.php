<?php

namespace Solital\MongodbAdapter;

use MongoDB\BSON\UTCDateTime;
use Solital\Core\Session\Exception\SessionConfigException;

/**
 * A class that implements SessionHandlerInterface and can be used to store
 * sessions as structured data in MongoDB
 */
class MongodbSessionHandler implements \SessionHandlerInterface
{
    protected const BOT_PATTERN = '/bot|crawl|slurp|spider|mediapartners/i';

    protected const MAX_LIFETIME = 2592000;  // 30 days in seconds

    protected const BOT_LIFETIME = 30;  // 30 seconds for bots
    
    /**
     * @var int
     */
    protected int $reads;

    /**
     * @var mixed
     */
    private mixed $collection_instance;

    /**
     * @var string
     */
    private string $collection_name = 'solital_mongodb_session';

    /**
     * Class constructor
     */
    public function __construct()
    {
        $yaml = app_get_yaml('session.yaml');

        $user = $yaml['cache_user'] ?? '';
        $pass = $yaml['cache_pass'] ?? '';

        $database = $yaml['session_mongodb_database'] ?? throw new SessionConfigException(
            'You must define `session_mongodb_database` in `session.yaml`'
        );

        $mongodb_adapter = MongodbAdapter::configuration($yaml['save_path'], $user, $pass);
        $mongodb_adapter->setDatabase($database);

        $collections = $mongodb_adapter->getCollections($database);

        if (!in_array($this->collection_name, $collections)) {
            $create = $mongodb_adapter->getDatabase();
            $create->createCollection($this->collection_name);
        }

        $mongodb_adapter->setTable($this->collection_name);
        $this->collection_instance = $mongodb_adapter->getCollectionInstance();
    }

    /**
     * Read session data and update read stats
     *
     * @param  string  $id
     * @return array
     */
    public function read($id): string|false
    {
        $result = $this->collection_instance->findOneAndUpdate(
            ['_id' => $id],
            [
                '$set' => ['last_read_at' => new UTCDateTime],
                '$inc' => ['reads' => 1],
            ],
            [
                'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
                'upsert' => true,
            ]
        );

        // If session was destroyed, return empty array
        if (isset($result['_destroyed']) && $result['_destroyed'] === true) {
            $this->reads = 1;

            return '';
        }

        // Store number of reads for later when we write to calculate lifetime
        $this->reads = $result['reads'] ?? 1;

        // Return the session data
        return $result['data'] ?? '';
    }

    /**
     * Calculate session lifetime based on number of reads and user agent
     */
    protected function _calculateLifetime(int $reads, string $userAgent): int
    {
        if (preg_match(self::BOT_PATTERN, $userAgent)) {
            return self::BOT_LIFETIME;
        }

        $lifetime = $reads > 100 ? self::MAX_LIFETIME : pow($reads, 3) * 30;

        return (int) min($lifetime, self::MAX_LIFETIME);
    }

    /**
     * Process session data into MongoDB updates
     *
     * @param  string  $id
     * @param  array  $data
     * @return bool
     */
    public function write($id, $data): bool
    {
        if (empty($data)) return true;

        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $lifetime = $this->_calculateLifetime($this->reads ?? 0, $userAgent);
        $updates = $this->_processSessionData($data);

        // Add metadata updates
        $updates['$set']['updated_at'] = new UTCDateTime(time() * 1000);
        $updates['$set']['lifetime'] = $lifetime;
        $updates['$set']['user_agent'] = $userAgent;

        // Not an upsert so we do not resurrect a destroyed session
        $result = $this->collection_instance->updateOne(
            ['_id' => $id],
            $updates
        );

        return $result->isAcknowledged();
    }

    /**
     * Process session data into MongoDB update operations
     *
     * @param  array  $sessionData
     * @return array
     */
    protected function _processSessionData($sessionData)
    {
        $updates = [];
        $sets = [];
        $unsets = [];

        foreach ($sessionData as $namespace => $operations) {
            // Non-namespaced data is written directly
            if (str_starts_with($namespace, '_'))
                $sets['data.' . $namespace] = $operations;

            // Ignore namespaces that do not conform to the expected format
            if (!isset($operations['__operations'])) continue;

            foreach ($operations['__operations'] as $operation) {
                $key = 'data.' . $namespace . '.' . $operation['key'];

                if ($operation['type'] === 'set') {
                    $sets[$key] = $operation['value'];
                } elseif ($operation['type'] === 'unset') {
                    unset($sets[$key]);
                    $unsets[$key] = 1;
                }
            }
        }

        if (!empty($sets)) $updates['$set'] = $sets;
        if (!empty($unsets)) $updates['$unset'] = $unsets;

        return $updates;
    }

    /**
     * Mark session as destroyed
     *
     * @param  string  $id
     * @return bool
     */
    public function destroy($id): bool
    {
        $result = $this->collection_instance->updateOne(
            ['_id' => $id],
            [
                '$set' => [
                    '_destroyed' => true,
                    'destroyed_at' => new UTCDateTime,
                ],
            ]
        );

        return $result->isAcknowledged();
    }

    /**
     * Garbage collection
     *
     * @param  int  $max_lifetime
     * @return bool
     */
    public function gc($max_lifetime): int|false
    {
        $now = time();

        // Delete sessions that are either:
        // 1. Destroyed and older than their calculated lifetime
        // 2. Not accessed for longer than their calculated lifetime
        $result = $this->collection_instance->deleteMany([
            '$or' => [
                [
                    '_destroyed' => true,
                    'destroyed_at' => [
                        '$lt' => new UTCDateTime(($now - $max_lifetime) * 1000),
                    ],
                ],
                [
                    '$expr' => [
                        '$lt' => [
                            '$last_read_at',
                            new UTCDateTime(($now - '$lifetime') * 1000),
                        ],
                    ],
                ],
            ],
        ]);

        return $result->isAcknowledged();
    }

    public function open($path, $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }
}
