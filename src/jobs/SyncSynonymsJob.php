<?php

namespace percipiolondon\typesense\jobs;

use Craft;
use craft\queue\BaseJob;
use percipiolondon\typesense\Typesense;

class SyncSynonymsJob extends BaseJob
{
    public array $criteria = [];

    public function execute($queue): void
    {
        $data = $this->criteria['data'] ?? [];
        $client = Typesense::$plugin->getClient()->client();

        $typesenseSynonymIds = collect(Typesense::$plugin->synonyms->getTypesenseSynonyms($this->criteria['index']))
            ->map(function ($synonym) {
                return $synonym['id'];
            });

        if ($data['synonyms'] && $client) {
            $totalEntries = count($data['synonyms']);

            foreach ($data['synonyms'] as $i => $synonym) {
                Typesense::$plugin->synonyms->saveTypesenseSynonym($this->criteria['index'], $synonym);

                $typesenseSynonymIds = $typesenseSynonymIds->filter(function ($id) use ($synonym) {
                    return $id !== $synonym['id'];
                })->values();

                $this->setProgress(
                    $queue,
                    $i / $totalEntries,
                    Craft::t('app', '{step, number} of {total, number}', [
                        'step' => $i + 1,
                        'total' => $totalEntries,
                    ])
                );
            }
        }

        if (count($typesenseSynonymIds) > 0) {
            foreach ($typesenseSynonymIds as $id) {
                Typesense::$plugin->synonyms->deleteTypesenseSynonym($this->criteria['index'], $id);
            }
        }
    }

    protected function defaultDescription(): string
    {
        return Craft::t('typesense', 'Upsert synonyms for ' . $this->criteria['index']);
    }
}
