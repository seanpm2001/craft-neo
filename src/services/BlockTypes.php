<?php

namespace benf\neo\services;

use benf\neo\assets\SettingsAsset;
use benf\neo\elements\Block;
use benf\neo\errors\BlockTypeNotFoundException;
use benf\neo\events\BlockTypeEvent;
use benf\neo\events\SetConditionElementTypesEvent;
use benf\neo\Field;
use benf\neo\helpers\Memoize;
use benf\neo\models\BlockType;
use benf\neo\models\BlockTypeGroup;
use benf\neo\Plugin as Neo;
use benf\neo\records\BlockType as BlockTypeRecord;
use benf\neo\records\BlockTypeGroup as BlockTypeGroupRecord;
use Craft;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Subscription;
use craft\commerce\elements\Variant;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Address;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use craft\elements\User;
use craft\events\ConfigEvent;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use yii\base\Component;
use yii\base\Event;
use yii\base\Exception;

/**
 * Class BlockTypes
 *
 * @package benf\neo\services
 * @author Spicy Web <plugins@spicyweb.com.au>
 * @author Benjamin Fleming
 * @since 2.0.0
 */
class BlockTypes extends Component
{
    /**
     * @event BlockTypeEvent The event that is triggered before saving a block type.
     * @since 2.3.0
     */
    public const EVENT_BEFORE_SAVE_BLOCK_TYPE = 'beforeSaveNeoBlockType';

    /**
     * @event BlockTypeEvent The event that is triggered after saving a block type.
     * @since 2.3.0
     */
    public const EVENT_AFTER_SAVE_BLOCK_TYPE = 'afterSaveNeoBlockType';

    /**
     * @event SetConditionElementTypesEvent The event that's triggered when setting the element types for setting
     * conditions on when block types can be used
     *
     * ```php
     * use benf\neo\events\SetConditionElementTypesEvent;
     * use benf\neo\services\BlockTypes;
     * use yii\base\Event;
     *
     * Event::on(
     *     BlockTypes::class,
     *     BlockTypes::EVENT_SET_CONDITION_ELEMENT_TYPES,
     *     function (SetConditionElementTypesEvent $event) {
     *         $event->elementTypes[] = \some\added\ElementType::class;
     *     }
     * );
     * ```
     *
     * @since 3.6.0
     */
    public const EVENT_SET_CONDITION_ELEMENT_TYPES = 'setConditionElementTypes';

    /**
     * @var string[]|null Supported element types for setting conditions on when block types can be used
     */
    private ?array $_conditionElementTypes = null;

    /**
     * Gets a Neo block type given its ID.
     *
     * @param int $id The block type ID to check.
     * @return BlockType|null
     */
    public function getById(int $id): ?BlockType
    {
        $blockType = null;

        if (isset(Memoize::$blockTypesById[$id])) {
            $blockType = Memoize::$blockTypesById[$id];
        } else {
            $result = $this->_createQuery()
                ->where(['id' => $id])
                ->one();

            if ($result) {
                $blockType = new BlockType($result);
                Memoize::$blockTypesById[$id] = $blockType;
                Memoize::$blockTypesByHandle[$blockType->handle] = $blockType;
            }
        }

        return $blockType;
    }

    /**
     * Gets a Neo block type, given its handle.
     *
     * @param $handle The block type handle to check.
     * @return BlockType
     * @throws BlockTypeNotFoundException if there is no Neo block type with the handle
     * @since 2.10.0
     */
    public function getByHandle(string $handle): BlockType
    {
        $blockType = null;

        if (isset(Memoize::$blockTypesByHandle[$handle])) {
            $blockType = Memoize::$blockTypesByHandle[$handle];
        } else {
            $result = $this->_createQuery()
                ->where(['handle' => $handle])
                ->one();

            if (!$result) {
                throw new BlockTypeNotFoundException('Neo block type with handle ' . $handle . ' not found');
            }

            $blockType = new BlockType($result);
            Memoize::$blockTypesById[$blockType->id] = $blockType;
            Memoize::$blockTypesByHandle[$handle] = $blockType;
        }

        return $blockType;
    }

    /**
     * Gets block types associated with a given field ID.
     *
     * @param int $fieldId The field ID to check for block types.
     * @return array The block types.
     */
    public function getByFieldId(int $fieldId): array
    {
        $blockTypes = [];

        if (isset(Memoize::$blockTypesByFieldId[$fieldId]) && !empty(Memoize::$blockTypesByFieldId[$fieldId])) {
            $blockTypes = Memoize::$blockTypesByFieldId[$fieldId];
        } else {
            $results = $this->_createQuery()
                ->where(['fieldId' => $fieldId])
                ->all();

            foreach ($results as $result) {
                $blockType = new BlockType($result);
                $blockTypes[] = $blockType;
                Memoize::$blockTypesById[$blockType->id] = $blockType;
                Memoize::$blockTypesByHandle[$blockType->handle] = $blockType;
            }

            Memoize::$blockTypesByFieldId[$fieldId] = $blockTypes;
        }

        return $blockTypes;
    }

    /**
     * Gets a block type group by its ID.
     *
     * @param int $id
     * @return BlockTypeGroup|null
     * @since 2.13.0
     */
    public function getGroupById(int $id): ?BlockTypeGroup
    {
        $group = null;

        if (isset(Memoize::$blockTypeGroupsById[$id])) {
            $group = Memoize::$blockTypeGroupsById[$id];
        } else {
            $result = $this->_createGroupQuery()
                ->where(['id' => $id])
                ->one();

            if ($result) {
                $group = new BlockTypeGroup($result);
                Memoize::$blockTypeGroupsById[$group->id] = $group;
            }
        }

        return $group;
    }

    /**
     * Gets block type groups associated with a given field ID.
     *
     * @param int $fieldId The field ID to check for block type groups.
     * @return array The block type groups.
     */
    public function getGroupsByFieldId(int $fieldId): array
    {
        $blockTypeGroups = [];

        if (isset(Memoize::$blockTypeGroupsByFieldId[$fieldId]) && !empty(Memoize::$blockTypeGroupsByFieldId[$fieldId])) {
            $blockTypeGroups = Memoize::$blockTypeGroupsByFieldId[$fieldId];
        } else {
            $results = $this->_createGroupQuery()
                ->where(['fieldId' => $fieldId])
                ->all();

            foreach ($results as $result) {
                $blockTypeGroup = new BlockTypeGroup($result);
                $blockTypeGroups[] = $blockTypeGroup;
                Memoize::$blockTypeGroupsById[$blockTypeGroup->id] = $blockTypeGroup;
            }

            Memoize::$blockTypeGroupsByFieldId[$fieldId] = $blockTypeGroups;
        }

        return $blockTypeGroups;
    }

    /**
     * Performs validation on a given Neo block type.
     *
     * @param BlockType $blockType The block type to perform validation on.
     * @return bool Whether validation was successful.
     */
    public function validate(BlockType $blockType): bool
    {
        $record = $this->_getRecord($blockType);

        $record->fieldId = $blockType->fieldId;
        $record->fieldLayoutId = $blockType->fieldLayoutId;
        $record->name = $blockType->name;
        $record->handle = $blockType->handle;
        $record->description = $blockType->description;
        $record->iconId = $blockType->iconId;
        $record->enabled = $blockType->enabled;
        $record->ignorePermissions = $blockType->ignorePermissions;
        $record->sortOrder = $blockType->sortOrder;
        $record->minBlocks = $blockType->minBlocks;
        $record->maxBlocks = $blockType->maxBlocks;
        $record->minSiblingBlocks = $blockType->maxSiblingBlocks;
        $record->maxSiblingBlocks = $blockType->maxSiblingBlocks;
        $record->minChildBlocks = $blockType->minChildBlocks;
        $record->maxChildBlocks = $blockType->maxChildBlocks;
        $record->groupChildBlockTypes = $blockType->groupChildBlockTypes;
        $record->childBlocks = $blockType->childBlocks;
        $record->topLevel = $blockType->topLevel;
        $record->groupId = $blockType->groupId;

        $isValid = (bool)$record->validate();

        if (!$isValid) {
            $blockType->addErrors($record->getErrors());
        }

        return $isValid;
    }

    /**
     * Saves a Neo block type.
     *
     * @param BlockType $blockType The block type to save.
     * @param bool $validate Whether to perform validation on the block type.
     * @return bool Whether saving the block type was successful.
     * @throws \Throwable
     */
    public function save(BlockType $blockType, bool $validate = true): bool
    {
        // Ensure that the block type passes validation or that validation is disabled
        if ($validate && !$this->validate($blockType)) {
            return false;
        }

        $projectConfigService = Craft::$app->getProjectConfig();
        $isNew = $blockType->getIsNew();

        if ($isNew) {
            $blockType->uid = StringHelper::UUID();
        }

        if ($blockType->uid === null) {
            $blockType->uid = Db::uidById('{{%neoblocktypes}}', $blockType->id);
        }

        $data = $blockType->getConfig();
        $event = new BlockTypeEvent([
            'blockType' => $blockType,
            'isNew' => $isNew,
        ]);

        $this->trigger(self::EVENT_BEFORE_SAVE_BLOCK_TYPE, $event);

        $path = 'neoBlockTypes.' . $blockType->uid;
        $projectConfigService->set($path, $data);

        return true;
    }

    /**
     * Saves a Neo block type group.
     *
     * @param BlockTypeGroup $blockTypeGroup The block type group to save.
     * @return bool Whether saving the block type group was successful.
     * @throws \Throwable
     */
    public function saveGroup(BlockTypeGroup $blockTypeGroup): bool
    {
        $projectConfigService = Craft::$app->getProjectConfig();

        if ($blockTypeGroup->getIsNew()) {
            $blockTypeGroup->uid = StringHelper::UUID();
        } elseif (!$blockTypeGroup->uid) {
            $blockTypeGroup->uid = Db::uidById('{{%neoblocktypegroups}}', $blockTypeGroup->id);
        }

        $path = 'neoBlockTypeGroups.' . $blockTypeGroup->uid;
        $projectConfigService->set($path, $blockTypeGroup->getConfig());

        if ($blockTypeGroup->getIsNew()) {
            $blockTypeGroup->id = Db::idByUid('{{%neoblocktypegroups}}', $blockTypeGroup->uid);
        }

        return true;
    }

    /**
     * Deletes a Neo block type and all associated Neo blocks.
     *
     * @param BlockType $blockType The block type to delete.
     * @return bool Whether deleting the block type was successful.
     * @throws \Throwable
     */
    public function delete(BlockType $blockType): bool
    {
        Craft::$app->getProjectConfig()->remove('neoBlockTypes.' . $blockType->uid);

        return true;
    }

    /**
     * Deletes a block type group.
     *
     * @since 2.8.3
     * @param BlockTypeGroup $blockTypeGroup
     * @return bool whether deletion was successful
     * @throws \Throwable
     */
    public function deleteGroup(BlockTypeGroup $blockTypeGroup): bool
    {
        Craft::$app->getProjectConfig()->remove('neoBlockTypeGroups.' . $blockTypeGroup->uid);

        return true;
    }

    /**
     * Deletes Neo block type groups associated with a given field ID.
     *
     * @param int $fieldId The field ID having its associated block type groups deleted.
     * @return bool Whether deleting the block type groups was successful.
     * @throws \Throwable
     */
    public function deleteGroupsByFieldId(int $fieldId): bool
    {
        $field = Craft::$app->getFields()->getFieldById($fieldId);
        $allGroups = $field->getGroups();

        foreach ($allGroups as $group) {
            $this->deleteGroup($group);
        }

        return true;
    }

    /**
     * Handles a Neo block type change.
     *
     * @param ConfigEvent $event
     * @throws \Throwable
     */
    public function handleChangedBlockType(ConfigEvent $event): void
    {
        $dbService = Craft::$app->getDb();
        $fieldsService = Craft::$app->getFields();
        $projectConfig = Craft::$app->getProjectConfig();
        $uid = $event->tokenMatches[0];
        $data = $event->newValue;

        // Make sure the fields have been synced
        ProjectConfigHelper::ensureAllFieldsProcessed();

        $fieldId = Db::idByUid('{{%fields}}', $data['field']);

        // Not much else we can do if the field doesn't actually exist
        if ($fieldId === null) {
            throw new Exception('Tried to save a Neo block type for a field with UID ' . $data['field'] . ', which was not found');
        }

        $groupId = isset($data['group']) ? Db::idByUid('{{%neoblocktypegroups}}', $data['group']) : null;

        $transaction = $dbService->beginTransaction();

        try {
            $record = $this->_getRecordByUid($uid);
            $fieldLayoutConfig = isset($data['fieldLayouts']) ? reset($data['fieldLayouts']) : null;
            $fieldLayout = null;
            $isNew = false;
            $blockType = null;
            $blockTypeConditions = $data['conditions'] ?? [];

            if (!isset($data['icon'])) {
                $blockTypeIcon = null;
            } elseif (is_string($data['icon'])) {
                $blockTypeIcon = Craft::$app->getElements()->getElementByUid($data['icon'], Asset::class);
            } else {
                $volumeId = Db::idByUid(Table::VOLUMES, $data['icon']['volume']);
                $folderId = (new Query())
                    ->select(['id'])
                    ->from(Table::VOLUMEFOLDERS)
                    ->where([
                        'volumeId' => $volumeId,
                        'path' => $data['icon']['folderPath'],
                    ])
                    ->scalar();
                $blockTypeIcon = Asset::find()
                    ->volumeId($volumeId)
                    ->folderId($folderId)
                    ->filename($data['icon']['filename'])
                    ->one();
            }

            if ($record->id !== null) {
                $result = $this->_createQuery()
                    ->where(['id' => $record->id])
                    ->one();

                $blockType = new BlockType($result);
            } else {
                $blockType = new BlockType();
                $isNew = true;
            }

            if ($fieldLayoutConfig === null && $record->id !== null && $blockType->fieldLayoutId !== null) {
                $fieldsService->deleteLayoutById($blockType->fieldLayoutId);
            }

            if ($fieldLayoutConfig !== null) {
                $fieldLayout = FieldLayout::createFromConfig($fieldLayoutConfig);
                $fieldLayout->id = $record->fieldLayoutId;
                $fieldLayout->type = Block::class;
                $fieldLayout->uid = key($data['fieldLayouts']);

                $fieldsService->saveLayout($fieldLayout);
            }

            $record->fieldId = $fieldId;
            $record->groupId = $groupId;
            $record->name = $data['name'];
            $record->handle = $data['handle'];
            $record->description = $data['description'] ?? '';
            $record->iconId = $blockTypeIcon?->id ?? null;
            $record->enabled = $data['enabled'] ?? true;
            $record->ignorePermissions = $data['ignorePermissions'] ?? true;
            $record->sortOrder = $data['sortOrder'];
            $record->minBlocks = $data['minBlocks'] ?? 0;
            $record->maxBlocks = $data['maxBlocks'];
            $record->minSiblingBlocks = $data['minSiblingBlocks'] ?? 0;
            $record->maxSiblingBlocks = $data['maxSiblingBlocks'] ?? 0;
            $record->minChildBlocks = $data['minChildBlocks'] ?? 0;
            $record->maxChildBlocks = $data['maxChildBlocks'];
            $record->groupChildBlockTypes = $data['groupChildBlockTypes'] ?? true;
            $record->childBlocks = $data['childBlocks'];
            $record->topLevel = $data['topLevel'];
            $record->conditions = Json::encode($blockTypeConditions);
            $record->uid = $uid;
            $record->fieldLayoutId = $fieldLayout?->id;
            $record->save(false);

            $blockType->id = $record->id;
            $blockType->fieldId = $fieldId;
            $blockType->groupId = $groupId;
            $blockType->name = $data['name'];
            $blockType->handle = $data['handle'];
            $blockType->description = $data['description'] ?? '';
            $blockType->enabled = $data['enabled'] ?? true;
            $blockType->ignorePermissions = $data['ignorePermissions'] ?? true;
            $blockType->sortOrder = $data['sortOrder'];
            $blockType->minBlocks = $data['minBlocks'] ?? 0;
            $blockType->maxBlocks = $data['maxBlocks'];
            $blockType->minSiblingBlocks = $data['minSiblingBlocks'] ?? 0;
            $blockType->maxSiblingBlocks = $data['maxSiblingBlocks'] ?? 0;
            $blockType->minChildBlocks = $data['minChildBlocks'] ?? 0;
            $blockType->maxChildBlocks = $data['maxChildBlocks'];
            $blockType->groupChildBlockTypes = $data['groupChildBlockTypes'] ?? true;
            $blockType->childBlocks = $data['childBlocks'];
            $blockType->topLevel = $data['topLevel'];
            $blockType->conditions = $blockTypeConditions;
            $blockType->uid = $uid;
            $blockType->fieldLayoutId = $fieldLayout?->id;

            $event = new BlockTypeEvent([
                'blockType' => $blockType,
                'isNew' => $isNew,
            ]);

            $this->trigger(self::EVENT_AFTER_SAVE_BLOCK_TYPE, $event);

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        Craft::$app->getElements()->invalidateCachesForElementType(Block::class);
    }

    /**
     * Handles deleting a Neo block type and all associated Neo blocks.
     *
     * @param ConfigEvent $event
     * @throws \Throwable
     */
    public function handleDeletedBlockType(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];
        $record = $this->_getRecordByUid($uid);

        if ($record->id === null) {
            return;
        }

        $dbService = Craft::$app->getDb();
        $transaction = $dbService->beginTransaction();

        try {
            $blockType = $this->getById($record->id);

            if ($blockType === null) {
                return;
            }

            $sitesService = Craft::$app->getSites();
            $elementsService = Craft::$app->getElements();
            $fieldsService = Craft::$app->getFields();

            // Delete all blocks of this type
            foreach ($sitesService->getAllSiteIds() as $siteId) {
                $blocks = Block::find()
                    ->siteId($siteId)
                    ->typeId($blockType->id)
                    ->inReverse()
                    ->all();

                foreach ($blocks as $block) {
                    $elementsService->deleteElement($block);
                }
            }

            // Delete the block type's field layout if it exists
            if ($blockType->fieldLayoutId !== null) {
                $fieldsService->deleteLayoutById($blockType->fieldLayoutId);
            }

            // Delete the block type
            $affectedRows = $dbService->createCommand()
                ->delete('{{%neoblocktypes}}', ['id' => $blockType->id])
                ->execute();

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        Craft::$app->getElements()->invalidateCachesForElementType(Block::class);
    }

    /**
     * Handles a Neo block type group change.
     *
     * @param ConfigEvent $event
     * @throws \Throwable
     */
    public function handleChangedBlockTypeGroup(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];

        $data = $event->newValue;
        $dbService = Craft::$app->getDb();
        $transaction = $dbService->beginTransaction();

        try {
            $record = BlockTypeGroupRecord::findOne(['uid' => $uid]);

            if ($record === null) {
                $record = new BlockTypeGroupRecord();
            }

            if ($record) {
                if ($data) {
                    $record->fieldId = Db::idByUid('{{%fields}}', $data['field']);
                    $record->name = $data['name'] ?? '';
                    $record->sortOrder = $data['sortOrder'];
                    // If the Craft install was upgraded from Craft 3 / Neo 2 and the project config doesn't have
                    // `alwaysShowDropdown` set, set it to null so it falls back to the global setting
                    $record->alwaysShowDropdown = $data['alwaysShowDropdown'] ?? null;
                    $record->uid = $uid;
                    $record->save(false);
                } else {
                    // if $data is unavailable then it
                    $record->delete();
                }
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }
    }

    /**
     * Handles deleting a Neo block type group.
     *
     * @param ConfigEvent $event
     * @throws \Throwable
     */
    public function handleDeletedBlockTypeGroup(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];
        $dbService = Craft::$app->getDb();
        $transaction = $dbService->beginTransaction();

        try {
            $affectedRows = $dbService->createCommand()
                ->delete('{{%neoblocktypegroups}}', ['uid' => $uid])
                ->execute();

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }
    }

    /**
     * Renders a Neo block type's settings.
     *
     * @param BlockType|null $blockType
     * @param string|null $baseNamespace A base namespace to use instead of `Craft::$app->getView()->getNamespace()`
     * @return array
     */
    public function renderBlockTypeSettings(?BlockType $blockType = null, ?string $baseNamespace = null): array
    {
        $view = Craft::$app->getView();
        $blockTypeId = $blockType?->id ?? '__NEOBLOCKTYPE_ID__';
        $oldNamespace = $view->getNamespace();
        $newNamespace = ($baseNamespace ?? $oldNamespace) . "[items][blockTypes][$blockTypeId]";
        $view->setNamespace($newNamespace);
        $view->startJsBuffer();

        $html = $view->namespaceInputs($view->renderTemplate('neo/block-type-settings', [
            'blockType' => $blockType,
            'conditions' => $this->_getConditions($blockType),
            'neoField' => $blockType?->getField(),
        ]));

        $js = $view->clearJsBuffer();
        $view->setNamespace($oldNamespace);

        return [$html, $js];
    }

    /**
     * Renders a field layout designer for a Neo block type.
     *
     * @param FieldLayout|null $fieldLayout
     * @return string
     */
    public function renderFieldLayoutDesigner(FieldLayout $fieldLayout): string
    {
        $view = Craft::$app->getView();

        // Render the field layout designer HTML, but disregard any JavaScript it outputs, as that'll be handled by Neo
        $view->startJsBuffer();
        $html = $view->renderTemplate('_includes/fieldlayoutdesigner', [
            'fieldLayout' => $fieldLayout,
            'customizableUi' => true,
        ]);
        $view->clearJsBuffer();

        return $html;
    }

    /**
     * Renders a Neo block type's settings.
     *
     * @param BlockTypeGroup|null $group
     * @param string|null $baseNamespace A base namespace to use instead of `Craft::$app->getView()->getNamespace()`
     * @return array
     */
    public function renderBlockTypeGroupSettings(?BlockTypeGroup $group = null, ?string $baseNamespace = null): array
    {
        $view = Craft::$app->getView();
        $groupId = $group?->id ?? '__NEOBLOCKTYPEGROUP_ID__';
        $oldNamespace = $view->getNamespace();
        $newNamespace = ($baseNamespace ?? $oldNamespace) . "[items][groups][$groupId]";
        $view->setNamespace($newNamespace);
        $view->startJsBuffer();

        $html = $view->namespaceInputs($view->renderTemplate('neo/block-type-group-settings', [
            'group' => $group,
        ]));

        $js = $view->clearJsBuffer();
        $view->setNamespace($oldNamespace);

        return [$html, $js];
    }

    /**
     * Returns all the block types.
     *
     * @return BlockType[]
     */
    public function getAllBlockTypes(): array
    {
        $results = $this->_createQuery()
            ->innerJoin(['f' => Table::FIELDS], '[[f.id]] = [[bt.fieldId]]')
            ->where(['f.type' => Field::class])
            ->all();

        foreach ($results as $key => $result) {
            $results[$key] = new BlockType($result);
        }

        return $results;
    }

    /**
     * Returns all block type groups belonging to all Neo fields.
     *
     * @return BlockTypeGroup[]
     * @since 2.9.0
     */
    public function getAllBlockTypeGroups(): array
    {
        $groups = [];

        foreach ($this->_createGroupQuery()->all() as $key => $result) {
            $groups[$key] = new BlockTypeGroup($result);
        }

        return $groups;
    }

    /**
     * Creates a basic Neo block type query.
     *
     * @return Query
     */
    private function _createQuery(): Query
    {
        $db = Craft::$app->getDb();
        $columns = [
            'id',
            'fieldId',
            'fieldLayoutId',
            'groupId',
            'name',
            'handle',
            'maxBlocks',
            'maxSiblingBlocks',
            'maxChildBlocks',
            'childBlocks',
            'topLevel',
            'sortOrder',
            'uid',
        ];

        // Columns that didn't exist in Neo 3.0.0
        $maybeColumns = [
            'description',
            'iconId',
            'enabled',
            'ignorePermissions',
            'minBlocks',
            'minChildBlocks',
            'minSiblingBlocks',
            'groupChildBlockTypes',
            'conditions',
        ];

        foreach ($maybeColumns as $column) {
            if ($db->columnExists('{{%neoblocktypes}}', $column)) {
                $columns[] = $column;
            }
        }

        return (new Query())
            ->select(array_map(fn($column) => "bt.$column", $columns))
            ->from(['bt' => '{{%neoblocktypes}}'])
            ->orderBy(['bt.sortOrder' => SORT_ASC]);
    }

    /**
     * Creates a basic Neo block type group query.
     *
     * @return Query
     */
    private function _createGroupQuery(): Query
    {
        return (new Query())
            ->select([
                'id',
                'fieldId',
                'name',
                'sortOrder',
                'alwaysShowDropdown',
                'uid',
            ])
            ->from(['{{%neoblocktypegroups}}'])
            ->orderBy(['sortOrder' => SORT_ASC]);
    }

    /**
     * Gets the block type record associated with the given block type.
     *
     * @param BlockType The Neo block type.
     * @return BlockTypeRecord The block type record associated with the given block type.
     * @throws BlockTypeNotFoundException if the given block type has an invalid ID.
     */
    private function _getRecord(BlockType $blockType): BlockTypeRecord
    {
        $record = null;

        if ($blockType->getIsNew()) {
            $record = new BlockTypeRecord();
        } else {
            $id = $blockType->id;

            if (isset(Memoize::$blockTypeRecordsById[$id])) {
                $record = Memoize::$blockTypeRecordsById[$id];
            } else {
                $record = BlockTypeRecord::findOne($id);

                if (!$record) {
                    throw new BlockTypeNotFoundException("Invalid Neo block type ID: $id");
                }

                Memoize::$blockTypeRecordsById[$id] = $record;
            }
        }

        return $record;
    }

    /**
     * Returns the block type record with the given UUID, if it exists; otherwise returns a new block type record.
     *
     * @param string $uid
     * @return BlockTypeRecord
     */
    private function _getRecordByUid(string $uid): BlockTypeRecord
    {
        $record = BlockTypeRecord::findOne(['uid' => $uid]);

        if ($record !== null) {
            Memoize::$blockTypeRecordsById[$record->id] = $record;
        } else {
            $record = new BlockTypeRecord();
        }

        return $record;
    }

    /**
     * Gets the condition builder field HTML for a block type.
     *
     * @param BlockType|null $blockType
     * @return string[]
     */
    private function _getConditions(?BlockType $blockType = null): array
    {
        if ($this->_conditionElementTypes === null) {
            // TODO: remove $event1 in Neo 4
            $event1 = new SetConditionElementTypesEvent([
                'elementTypes' => $this->_getSupportedConditionElementTypes(),
            ]);
            Event::trigger(SettingsAsset::class, SettingsAsset::EVENT_SET_CONDITION_ELEMENT_TYPES, $event1);

            $event2 = new SetConditionElementTypesEvent([
                'elementTypes' => $event1->elementTypes,
            ]);
            $this->trigger(self::EVENT_SET_CONDITION_ELEMENT_TYPES, $event2);
            $this->_conditionElementTypes = $event2->elementTypes;
        }

        $conditionsService = Craft::$app->getConditions();
        $conditionHtml = [];
        Neo::$isGeneratingConditionHtml = true;

        foreach ($this->_conditionElementTypes as $elementType) {
            $condition = !empty($blockType?->conditions) && isset($blockType->conditions[$elementType])
                ? $conditionsService->createCondition($blockType->conditions[$elementType])
                : $elementType::createCondition();
            $condition->mainTag = 'div';
            $condition->id = 'conditions-' . StringHelper::toKebabCase($elementType);
            $condition->name = "conditions[$elementType]";
            $condition->forProjectConfig = true;

            $conditionHtml[$elementType] = Cp::fieldHtml($condition->getBuilderHtml(), [
                'label' => Craft::t('neo', '{type} Condition', [
                    'type' => StringHelper::mb_ucwords($elementType::displayName()),
                ]),
                'instructions' => Craft::t('neo', 'Only allow this block type to be used on {type} if they match the following rules:', [
                    'type' => $elementType::pluralLowerDisplayName(),
                ]),
            ]);
        }

        Neo::$isGeneratingConditionHtml = false;

        return $conditionHtml;
    }

    /**
     * Get the element types supported by Neo for block type conditionals.
     *
     * @return string[]
     */
    private function _getSupportedConditionElementTypes(): array
    {
        // In-built Craft element types
        $elementTypes = [
            Entry::class,
            Category::class,
            Asset::class,
            User::class,
            Tag::class,
            Address::class,
            GlobalSet::class,
        ];

        // Craft Commerce element types
        if (Craft::$app->getPlugins()->isPluginInstalled('commerce')) {
            $elementTypes[] = Product::class;
            $elementTypes[] = Variant::class;
            $elementTypes[] = Order::class;
            $elementTypes[] = Subscription::class;
        }

        return $elementTypes;
    }
}
