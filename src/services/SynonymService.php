<?php
namespace percipiolondon\typesense\services;

use Craft;
use craft\db\Query;
use Illuminate\Support\Collection;
use percipiolondon\typesense\db\Table;
use percipiolondon\typesense\models\SynonymModel;
use percipiolondon\typesense\Typesense;
use yii\base\Component;
use yii\db\Exception;

class SynonymService extends Component
{
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

    public function getSynonym(string $index): ?array
    {
        return (new Query())
            ->from(Table::SYNONYMS)
            ->where(['index' => $index])
            ->one();
    }

    public function getSynonyms(string $index): ?array
    {
        $synonym = (new Query())
            ->from(Table::SYNONYMS)
            ->where(['index' => $index])
            ->one();

        if(!empty($synonym)) {
            return json_decode($synonym['synonyms'], true);
        }

        return null;
    }

    public function saveSynonyms(string $index, array $synonyms): bool
    {
        // Validate the model
        $synonymData = new SynonymModel(['synonyms' => $synonyms]);

        // Make sure our array is JSON encoded.
        $data = $synonymData->getAttributes();
        $data['index'] = $index;

        // Check if the index exists
        $indexWithSynonyms = $this->getSynonym($index);

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

        return true;
    }
}
