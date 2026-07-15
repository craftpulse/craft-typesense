<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\services;

use Carbon\Carbon;
use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craftpulse\typesense\db\Table;
use Throwable;

/**
 * Maintains the element-to-curation-rule lookup table.
 *
 * A curation rule pins documents (its includes) at positions. To let the entry
 * sidebar answer "which rules pin this element" without scanning every override,
 * the plugin projects each rule's includes into this table on every curation
 * write, keyed and indexed by element id. The document id a rule pins maps back
 * to an element id (the leading segment of a bare or composite document id).
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class CurationIndex extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Removes every lookup row for a collection.
     *
     * @param string $collectionHandle
     * @return void
     * @author CraftPulse
     */
    public function clearForCollection(string $collectionHandle): void
    {
        Db::delete(Table::CURATION_INDEX, ['collectionHandle' => $collectionHandle]);
    }

    /**
     * Returns the rules that pin an element, across every collection.
     *
     * @param int $elementId
     * @return array<int, array<string, mixed>>
     * @author CraftPulse
     */
    public function forElement(int $elementId): array
    {
        return (new Query())
            ->select(['collectionHandle', 'ruleId', 'documentId', 'position'])
            ->from(Table::CURATION_INDEX)
            ->where(['elementId' => $elementId])
            ->orderBy(['collectionHandle' => SORT_ASC, 'position' => SORT_ASC])
            ->all();
    }

    /**
     * Rebuilds a collection's lookup rows from its current rules. Each rule's
     * includes become one row per pinned document, with the element id resolved
     * from the document id.
     *
     * @param string $collectionHandle
     * @param array<int, array<string, mixed>> $rules
     * @return void
     * @throws Throwable
     * @author CraftPulse
     */
    public function rebuildForCollection(string $collectionHandle, array $rules): void
    {
        $now = Db::prepareDateForDb(Carbon::now('UTC'));
        $rows = [];

        foreach ($rules as $rule) {
            $ruleId = (string)($rule['id'] ?? '');

            if ($ruleId === '') {
                continue;
            }

            foreach ($rule['includes'] ?? [] as $include) {
                $documentId = (string)($include['id'] ?? '');
                $elementId = $this->_elementId($documentId);

                if ($elementId === null) {
                    continue;
                }

                $rows[] = [
                    $collectionHandle,
                    $ruleId,
                    $documentId,
                    $elementId,
                    isset($include['position']) ? (int)$include['position'] : null,
                    $now,
                    $now,
                    StringHelper::UUID(),
                ];
            }
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $this->clearForCollection($collectionHandle);

            if ($rows !== []) {
                Craft::$app->getDb()->createCommand()->batchInsert(
                    Table::CURATION_INDEX,
                    ['collectionHandle', 'ruleId', 'documentId', 'elementId', 'position', 'dateCreated', 'dateUpdated', 'uid'],
                    $rows,
                )->execute();
            }

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Resolves the element id a pinned document id refers to: the leading
     * segment of a bare (`42`) or composite (`42-1`) id.
     *
     * @param string $documentId
     * @return int|null
     * @author CraftPulse
     */
    private function _elementId(string $documentId): ?int
    {
        if ($documentId === '') {
            return null;
        }

        $leading = explode('-', $documentId, 2)[0];

        return is_numeric($leading) ? (int)$leading : null;
    }
}
