<?php
/**
 * @author CraftPulse
 * @since 4.0.0
 */

namespace percipiolondon\typesense\services;

use craft\base\MemoizableArray;
use craft\db\Query;
use Craft;

use craft\errors\MissingComponentException;
use Http\Client\Exception;
use percipiolondon\typesense\models\CollectionModel as Collection;

use percipiolondon\typesense\Typesense;
use Throwable;
use Typesense\Exceptions\TypesenseClientError;
use yii\base\Component;

class CollectionService extends Component
{
    /**
     * @var string
     */
    public const CONFIG_COLLECTIONS_KEY = 'collections';

    /**
     * @throws Exception
     * @throws TypesenseClientError
     * @throws MissingComponentException
     */
    public function getCollectionByCollectionRetrieve(string $indexName): ?array
    {
        $collections = null;
        if ($this->_verifyClient()) {
            $collections = Typesense::$plugin->getClient()->client()->collections->retrieve();
        }

        $retrievedCollection = [];

        if ($collections) {
            foreach ($collections as $collection) {
                if ($collection['name'] === $indexName) {
                    $retrievedCollection = $collection;
                }
            }
        }

        return $retrievedCollection;
    }

    public function saveCollections(): void
    {
        $indexes = Typesense::$plugin->getSettings()->collections;

        if ($this->_verifyClient()) {
            foreach ($indexes as $index) {
                if (!$this->getCollectionByCollectionRetrieve($index->indexName)) {
                    Typesense::$plugin->getClient()->client()->collections->create($index->schema);
                }
            }
        }
    }


    private function _verifyClient(): bool
    {
        $client = Typesense::$plugin->getClient()->client();
        if (!$client) {
            return false;
        }

        return true;
    }
}
