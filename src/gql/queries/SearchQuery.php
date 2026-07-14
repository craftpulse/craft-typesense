<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\gql\queries;

use craft\gql\base\Query;
use craft\helpers\Json;
use craftpulse\typesense\Typesense;
use GraphQL\Type\Definition\Type;

/**
 * A Typesense-backed GraphQL search query (Free, a developer tool).
 *
 * Mirrors the search layer's argument surface and returns the Typesense response
 * as a JSON string. The resolver runs entirely server-side through the search
 * layer (fail-soft), so no API key is ever exposed to the client.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class SearchQuery extends Query
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @return array<string, array<string, mixed>>
     */
    public static function getQueries(bool $checkToken = true): array
    {
        return [
            'typesenseSearch' => [
                'type' => Type::string(),
                'args' => [
                    'collection' => Type::nonNull(Type::string()),
                    'q' => Type::string(),
                    'queryBy' => Type::string(),
                    'filterBy' => Type::string(),
                    'sortBy' => Type::string(),
                    'facetBy' => Type::string(),
                    'preset' => Type::string(),
                    'page' => Type::int(),
                    'perPage' => Type::int(),
                ],
                'resolve' => static function($source, array $arguments): string {
                    $params = [];
                    $map = [
                        'q' => 'q',
                        'queryBy' => 'query_by',
                        'filterBy' => 'filter_by',
                        'sortBy' => 'sort_by',
                        'facetBy' => 'facet_by',
                        'preset' => 'preset',
                        'page' => 'page',
                        'perPage' => 'per_page',
                    ];

                    foreach ($map as $arg => $param) {
                        if (isset($arguments[$arg])) {
                            $params[$param] = $arguments[$arg];
                        }
                    }

                    return Json::encode(Typesense::$plugin->getSearch()->search($arguments['collection'], $params));
                },
            ],
        ];
    }
}
