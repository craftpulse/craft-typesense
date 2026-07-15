<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\helpers;

/**
 * Locale helpers for the Typesense boundary.
 *
 * Typesense's field-level and stopword-set `locale` is an ISO language code
 * (`en`, `nl`, `de`, `ja`; see https://typesense.org/docs/30.2/api/collections.html
 * and https://typesense.org/docs/30.2/api/stopwords.html), while Craft's language
 * menu yields richer locale ids (`en-GB`, `pt-BR`). This normalises a Craft locale
 * id down to the language subtag Typesense expects.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class Locale
{
    // Static Methods
    // =========================================================================

    /**
     * Normalises a Craft locale id to the ISO language subtag Typesense expects
     * (the part before the first hyphen or underscore, lowercased). An empty
     * value returns null so the caller can omit the locale entirely.
     *
     * @param string|null $localeId
     * @return string|null
     * @author CraftPulse
     */
    public static function toTypesense(?string $localeId): ?string
    {
        $localeId = trim((string)$localeId);

        if ($localeId === '') {
            return null;
        }

        $language = preg_split('/[-_]/', $localeId)[0] ?? $localeId;

        return strtolower($language);
    }
}
