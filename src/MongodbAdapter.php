<?php

namespace Solital\MongodbAdapter;

use Katrina\Katrina;

class MongodbAdapter
{
    /**
     * @var \MongoDB\Client
     */
    private static mixed $client;

    /**
     * @var mixed
     */
    private mixed $database;

    /**
     * @var mixed
     */
    private mixed $collection;

    /**
     * @var mixed
     */
    private mixed $inserted_id;

    /**
     * @var array
     */
    public static array $filter_for_mongodb = [];

    /**
     * Configuration MongoDB connection
     *
     * @param string $host
     * @param string|null $user
     * @param string|null $pass
     *
     * @return static
     */
    public static function configuration(string $host, ?string $user = null, ?string $pass = null): static
    {
        try {
            ($user != '' && $pass != '')
                ? $config = 'mongodb://' . $user . ':' . $pass . '@' . $host
                : $config = 'mongodb://' . $host;

            self::$client = new \MongoDB\Client($config);
        } catch (\Exception $e) {
            echo $e->getMessage();
        }

        return new static;
    }

    /**
     * Get a MongoDB\Collection class
     *
     * @return mixed
     */
    public function getCollectionInstance(): mixed
    {
        return $this->collection;
    }

    /**
     * Get all collections from database
     *
     * @param string $database
     *
     * @return array
     */
    public function getCollections(string $database): array
    {
        $all_collection = [];
        $db = self::$client->selectDatabase($database);
        $collections = $db->listCollections();

        foreach ($collections as $collection) {
            $all_collection[] = $collection->getName();
        }

        return $all_collection;
    }

    /**
     * Get database on the server
     *
     * @return mixed
     */
    public function getDatabase(): mixed
    {
        return $this->database;
    }

    /**
     * Selects a database on the server
     *
     * @param string $database
     *
     * @return self
     */
    public function setDatabase(string $database): self
    {
        $this->database = self::$client->selectDatabase($database);
        return $this;
    }

    /**
     * Set table
     *
     * @param string $table
     *
     * @return self
     */
    public function setTable(string $table): self
    {
        $this->collection = $this->database->selectCollection($table);
        return $this;
    }

    /**
     * Describe a MongoDB collection
     *
     * @param string $collection
     *
     * @return mixed
     */
    public function describeCollection(string $collection): mixed
    {
        $stats = $this->database->command(['collStats' => $collection])->toArray()[0];

        return [
            'collection_name' => $stats['ns'],
            'document_count' => $stats['count'],
            'storage_size' => $stats['storageSize'] . ' bytes',
            'indexes' => json_encode($stats['indexSizes'], JSON_PRETTY_PRINT)
        ];
    }

    /**
     * Count the number of documents
     *
     * @param array $where The filter criteria that specifies the documents to count
     *
     * @return int
     */
    public function count(): int
    {
        return $this->collection->countDocuments();
    }

    /**
     * Finds documents
     *
     * @param array|null $where The filter criteria that specifies the documents to query
     * @param array|null $options An array specifying the desired options
     *
     * @return mixed
     */
    public function select(bool $all = false): mixed
    {
        $sort = [];
        //dump_die(self::$filter_for_mongodb);

        try {
            if (Katrina::$latest_mongodb == true) {
                $sort = ['_id' => -1];
                Katrina::$limit = 1;
            }

            $options_mongodb = [
                'typeMap' => ['root' => 'object', 'document' => 'object'],
                'limit' => Katrina::$limit,
                'sort' => $sort
            ];

            /* if (!is_null(Katrina::$group)) {
                dump_die(Katrina::$columns);
            } */

            return ($all == null)
                ? $this->collection->findOne(self::$filter_for_mongodb, $options_mongodb)
                : $this->collection->find(self::$filter_for_mongodb, $options_mongodb)->toArray();
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Insert one document
     *
     * @param array $data If array is recursive, insert multiple documents
     *
     * @return mixed
     */
    public function insert(array $data): mixed
    {
        if (self::isArrayRecursive($data) == false) {
            $inserted_id = $this->collection->insertOne($data);
            $this->inserted_id = $inserted_id;

            return $inserted_id->getInsertedId();
        }

        $inserted_id = $this->collection->insertMany($data);
        $this->inserted_id = $inserted_id;

        return $inserted_id->getInsertedIds();
    }

    /**
     * Get the last insert id in document
     *
     * @return mixed
     */
    public function lastInsertId(): mixed
    {
        return $this->inserted_id;
    }

    /**
     * Update all documents that match the filter criteria
     *
     * @param array $data The filter criteria that specifies the documents to update
     * @param array $where Specifies the field and value combinations to update and any relevant update operators
     *
     * @return mixed
     */
    public function update(array $data, array $where): mixed
    {
        if (array_key_exists('_id', $where)) {
            $id = new \MongoDB\BSON\ObjectId($where['_id']);
            $where = ['_id' => $id];
        }

        $result = $this->collection->updateMany($where, ['$set' => $data]);

        return [
            'matched_count' => $result->getMatchedCount(),
            'modified_count' => $result->getModifiedCount()
        ];
    }

    /**
     * Deletes all documents that match the filter criteria
     *
     * @param array $data The filter criteria that specifies the documents to delete
     *
     * @return mixed
     */
    public function delete(array $data): mixed
    {
        if (array_key_exists('id', $data)) {
            $id = new \MongoDB\BSON\ObjectId($data['id']);
            $data = ['_id' => $id];
        }

        $result = $this->collection->deleteMany($data);
        return $result->getDeletedCount();
    }

    /* Para "substituir" a chave description por descrição, por exemplo, bastaria fazer
    array_replace_key($task, 'description', 'descrição'). Caso a chave descrição já existir
    no array ele será sobrescrito; se esse não for o comportamento desejado, você pode passar
    o último argumento como falso que o valor existente será mantido inalterado,
    array_replace_key($task, 'description', 'descrição', false). A função retornará um booleano
    indicando se houve ou não a "substituição" da chave.

    Vale lembrar que como é feita a cópia do valor de uma chave para outra, dependendo do que
    é esse valor, poderá ter problemas com memória, pois durante a execução da função você terá dois
    objetos iguais. */
    private static function isArrayRecursive(array $array): bool
    {
        return (count($array) == count($array, COUNT_RECURSIVE)) ? false : true;
    }
}
