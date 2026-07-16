<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\models;

use craft\base\ElementInterface;
use craft\base\FieldLayoutProviderInterface;
use craft\base\Model;
use craft\elements\Entry;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;

/**
 * One member source of a union collection: an element type and source, with its
 * own mapping field layout. It is a field-layout provider in its own right, so
 * the mapping-palette events scope a member's designer to that member's source
 * (each member is mapped independently, visually separated). Members are stored
 * inline on the owning [[CollectionDefinition]]'s `members` array; this model is
 * the hydrated, layout-carrying view of one entry.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class CollectionMember extends Model implements FieldLayoutProviderInterface
{
    // Private Properties
    // =========================================================================

    /**
     * @var FieldLayout|null The member's mapping field layout, lazily created.
     */
    private ?FieldLayout $_fieldLayout = null;

    // Public Properties
    // =========================================================================

    /**
     * @var string The member handle (unique within the collection, the
     * `_elementType` discriminator value).
     */
    public string $handle = '';

    /**
     * @var class-string<ElementInterface> The element type this member indexes.
     */
    public string $elementType = Entry::class;

    /**
     * @var string|null The element source handle (a section, group, volume, or
     * entry type), or null for every source of the type.
     */
    public ?string $source = null;

    /**
     * @var string The source shape (a CollectionDefinition::SOURCE_TYPE_* value).
     */
    public string $sourceType = CollectionDefinition::SOURCE_TYPE_SECTION;

    /**
     * @var string|null The member mapping field layout's UID.
     */
    public ?string $fieldLayoutUid = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function getFieldLayout(): FieldLayout
    {
        if ($this->_fieldLayout === null) {
            $this->fieldLayoutUid ??= StringHelper::UUID();
            $this->_fieldLayout = new FieldLayout();
            $this->_fieldLayout->type = $this->elementType;
            $this->_fieldLayout->uid = $this->fieldLayoutUid;
            $this->_fieldLayout->provider = $this;
        }

        return $this->_fieldLayout;
    }

    /**
     * @inheritdoc
     */
    public function getHandle(): ?string
    {
        return $this->handle !== '' ? $this->handle : null;
    }

    /**
     * Sets the member's mapping field layout and binds it to this member as its
     * provider so the palette events scope to it.
     *
     * @param FieldLayout $fieldLayout
     * @return void
     * @author CraftPulse
     */
    public function setFieldLayout(FieldLayout $fieldLayout): void
    {
        $fieldLayout->type = $this->elementType;
        $fieldLayout->provider = $this;
        $this->fieldLayoutUid = $fieldLayout->uid ?? null;
        $this->_fieldLayout = $fieldLayout;
    }
}
