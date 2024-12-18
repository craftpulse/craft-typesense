<?php
namespace percipiolondon\typesense\services;

use Craft;
use craft\db\Query;
use craft\helpers\Queue;
use Illuminate\Support\Collection;
use percipiolondon\typesense\jobs\SyncSynonymsJob;
use percipiolondon\typesense\db\Table;
use percipiolondon\typesense\models\SynonymModel;
use percipiolondon\typesense\Typesense;
use yii\base\Component;
use yii\db\Exception;

/**
 *
 */
class SynonymService extends Component
{
    /**
     * Fetch the list of synonyms from a collection
     * @param string $collection
     * @return array|null
     * @throws \Http\Client\Exception
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \craft\errors\MissingComponentException
     */
    public function getTypesenseSynonyms(string $collection): ?array
    {
        // Retrieve the synonyms from Typesense
        $query = Collection::make(Typesense::$plugin->getClient()->client()->collections[$collection]->synonyms->retrieve());

        // Check if synonyms exist and have records
        if ($query->get('synonyms') && $query->count() > 0) {
            // Get the synonyms data
            $synonyms = $query->get('synonyms');

            // Transform the synonyms into a comma-separated string
            $synonyms = collect($synonyms)->map(function ($item) {
                // Ensure 'synonyms' is an array, and then join it into a string
                if (isset($item['synonyms']) && is_array($item['synonyms'])) {
                    $item['synonyms'] = implode(', ', $item['synonyms']);
                }
                return $item;
            });
        }

        // Return the transformed synonyms array
        return $synonyms->toArray();
    }

    /**
     * Save a synonym to the typesense collection. Based on the setting, it will be a one-way or multi-way synonym
     * @param string $index
     * @param array $data
     * @return bool
     * @throws \Http\Client\Exception
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \craft\errors\MissingComponentException
     */
    public function saveTypesenseSynonym(string $index, array $data): bool
    {
        if (Typesense::$plugin->getSettings()->synonymsDirection == 'one-way') {
            $synonyms = $this->createOneWaySynonyms($data);
        } else {
            $synonyms = $this->createMultiWaySynonyms($data);
        }


        try {
            Typesense::$plugin->getClient()->client()->collections[$index]->synonyms->upsert($data['id'], $synonyms);
        } catch(Exception $exception) {
            Craft::error($exception->getMessage(), __METHOD__);
            return false;
        }

        return true;
    }

    /**
     * Delete a synonym from a collection
     * @param string $index
     * @param string $id
     * @return void
     * @throws \Http\Client\Exception
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \craft\errors\MissingComponentException
     */
    public function deleteTypesenseSynonym(string $index, string $id): void
    {
        Typesense::$plugin->getClient()->client()->collections[$index]->synonyms[$id]->delete();
    }

    /**
     * Get the synonym list from a specific collection in our database
     * @param string $index
     * @return array|null
     */
    public function getSynonymDataByIndex(string $index): ?array
    {
        return (new Query())
            ->from(Table::SYNONYMS)
            ->where(['index' => $index])
            ->one();
    }

    /**
     * Get the synonyms data only from a specific collection in our database
     * @param string $index
     * @return array|null
     */
    public function getSynonymsByIndex(string $index): ?array
    {
        $synonyms = $this->getSynonymDataByIndex($index);

        if(!empty($synonyms)) {
            return json_decode($synonyms['synonyms'], true);
        }

        return null;
    }

    /**
     * Save synonyms in our database and start a queue job to sync with Typesense
     * @param string $index
     * @param array $synonyms
     * @return bool
     * @throws Exception
     */
    public function saveSynonyms(string $index, array $synonyms): bool
    {
        // Validate the model
        $synonymData = new SynonymModel(['synonyms' => $synonyms]);

        // Make sure our array is JSON encoded.
        $data = $synonymData->getAttributes();
        $data['index'] = $index;

        // Check if the index exists
        $indexWithSynonyms = $this->getSynonymDataByIndex($index);

        $isNew = $indexWithSynonyms == null;

        if (!$isNew) {
            // Update the existing record
            Craft::$app->getDb()->createCommand()->update(
                Table::SYNONYMS,
                $data,
                [
                    'id' => $indexWithSynonyms['id']
                ]
            )->execute();
        } else {
            try {
                Craft::$app->getDb()->createCommand()->insert(
                    Table::SYNONYMS,
                    $data,
                )->execute();
            } catch(Exception $exception) {
                Craft::error($exception->getMessage(), __METHOD__);
                return false;
            }
        }

        // upsert the synonyms
        Queue::push(new SyncSynonymsJob([
            'criteria' => [
                'index' => $index,
                'data' => $data,
            ],
        ]));

        return true;
    }


    /**
     * Prepare the multi-way synonyms to save to Typesense
     * @param array $data
     * @return array
     */
    public function createMultiWaySynonyms(array $data): array
    {
        $arrSynonyms = $data;
        $arrSynonyms['synonyms'] = explode(",", $data['synonyms']);
        array_push($arrSynonyms['synonyms'], $data['root']);

        $typesenseModel = [];
        $typesenseModel['synonyms'] = $arrSynonyms['synonyms'];

        return $typesenseModel;
    }

    /**
     * Prepare the one-way synonyms to save to Typesense
     * @param array $data
     * @return array
     */
    public function createOneWaySynonyms(array $data): array
    {
        $arrSynonyms = explode(",", $data['synonyms']);

        $typesenseModel = [];
        $typesenseModel['root'] = $data['root'];
        $typesenseModel['synonyms'] = $arrSynonyms;

        return $typesenseModel;
    }
}
