<?php
namespace verbb\vizy\fields;

use verbb\vizy\Vizy;
use verbb\vizy\elements\Block as BlockElement;
use verbb\vizy\events\ModifyVizyConfigEvent;
use verbb\vizy\events\RegisterLinkOptionsEvent;
use verbb\vizy\gql\types\NodeCollectionType;
use verbb\vizy\helpers\Plugin;
use verbb\vizy\models\BlockType;
use verbb\vizy\models\NodeCollection;
use verbb\vizy\nodes\VizyBlock;
use verbb\vizy\web\assets\field\VizyAsset;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\helpers\ArrayHelper;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\web\twig\variables\Cp;

use yii\db\Schema;

use Throwable;

use GraphQL\Type\Definition\Type;


class VizyField extends Field
{
    // Constants
    // =========================================================================

    public const EVENT_DEFINE_VIZY_CONFIG = 'defineVizyConfig';
    public const EVENT_MODIFY_PURIFIER_CONFIG = 'modifyPurifierConfig';
    public const EVENT_REGISTER_LINK_OPTIONS = 'registerLinkOptions';


    // Static Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('vizy', 'Vizy');
    }

    public static function valueType(): string
    {
        return 'string|null';
    }


    // Properties
    // =========================================================================

    public array $fieldData = [];
    public string $vizyConfig = '';
    public string $configSelectionMode = 'choose';
    public string $manualConfig = '';
    public string|array|null $availableVolumes = '*';
    public string|array|null $availableTransforms = '*';
    public bool $showUnpermittedVolumes = false;
    public bool $showUnpermittedFiles = false;
    public string $defaultTransform = '';
    public bool $trimEmptyParagraphs = true;
    public string $columnType = Schema::TYPE_TEXT;

    private ?array $_blockTypesById = [];
    private ?array $_linkOptions = null;
    private ?array $_sectionSources = null;
    private ?array $_categorySources = null;
    private ?array $_volumeKeys = null;
    private ?array $_transforms = null;


    // Public Methods
    // =========================================================================

    public function __construct($config = [])
    {
        // Temporarily fix a config issue during beta
        if (array_key_exists('vizyConfig', $config)) {
            if (is_array($config['vizyConfig'])) {
                $config['vizyConfig'] = $config['vizyConfig'][0];

                Craft::$app->getDeprecator()->log("vizy:${config['handle']}", "Your Vizy field ${config['handle']} contains out of date settings. Please re-save the field.");
            }
        }

        parent::__construct($config);
    }

    public function getContentColumnType(): array|string
    {
        return $this->columnType;
    }

    public function isValueEmpty(mixed $value, ElementInterface $element): bool
    {
        $isValueEmpty = parent::isValueEmpty($value, $element);

        // Check for an empty paragraph
        if ($value instanceof NodeCollection) {
            $isValueEmpty = $isValueEmpty || $value->isEmpty();
        }

        return $isValueEmpty;
    }

    public function getSettingsHtml(): ?string
    {
        $view = Craft::$app->getView();

        $fieldData = $this->_getBlockGroupsForSettings();

        $settings = [
            'fieldId' => $this->id,
            'suggestions' => (new Cp())->getTemplateSuggestions(),
        ];

        $idPrefix = StringHelper::randomString(10);

        Plugin::registerAsset('field/src/js/vizy.js');

        // Create the Vizy Settings Vue component
        $js = 'new Craft.Vizy.Settings(' .
            Json::encode($idPrefix, JSON_UNESCAPED_UNICODE) . ', ' .
            Json::encode($fieldData, JSON_UNESCAPED_UNICODE) . ', ' .
            Json::encode($settings, JSON_UNESCAPED_UNICODE) .
        ');';

        // Wait for Vizy JS to be loaded, either through an event listener, or by a flag.
        // This covers if this script is run before, or after the Vizy JS has loaded
        $view->registerJs('document.addEventListener("vite-script-loaded", function(e) {' .
            'if (e.detail.path === "field/src/js/vizy.js") {' . $js . '}' .
        '}); if (Craft.VizyReady) {' . $js . '}');

        $volumeOptions = [];

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            if ($volume->getFs()->hasUrls) {
                $volumeOptions[] = [
                    'label' => Html::encode($volume->name),
                    'value' => $volume->uid,
                ];
            }
        }

        $transformOptions = [];

        foreach (Craft::$app->getImageTransforms()->getAllTransforms() as $transform) {
            $transformOptions[] = [
                'label' => Html::encode($transform->name),
                'value' => $transform->uid,
            ];
        }

        return $view->renderTemplate('vizy/field/settings', [
            'idPrefix' => $idPrefix,
            'field' => $this,
            'componentData' => [
                'id' => $idPrefix,
                'fieldData' => $fieldData,
                'settings' => $settings,
            ],
            'vizyConfigOptions' => $this->_getCustomConfigOptions('vizy'),
            'volumeOptions' => $volumeOptions,
            'transformOptions' => $transformOptions,
            'defaultTransformOptions' => [
                ...[
                    [
                        'label' => Craft::t('vizy', 'No transform'),
                        'value' => null,
                    ],
                ], ...$transformOptions,
            ],
        ]);
    }

    public function getInputHtml(mixed $value, ?ElementInterface $element = null): string
    {
        $view = Craft::$app->getView();
        $id = Html::id($this->handle);

        $site = ($element ? $element->getSite() : Craft::$app->getSites()->getCurrentSite());

        $defaultTransform = '';

        if (!empty($this->defaultTransform) && $transform = Craft::$app->getImageTransforms()->getTransformByUid($this->defaultTransform)) {
            $defaultTransform = $transform->handle;
        }

        // Cache the placeholder key for the fields' JS. Because we're caching the block type HTML/JS
        // we also need to cache the placeholder key to match that cached data.
        $placeholderKey = Vizy::$plugin->getCache()->getOrSet($this->getCacheKey('placeholderKey'), function() {
            return StringHelper::randomString(10);
        });

        $settings = [
            // The order of `blocks` and `blockGroups` is important here, to ensure that the blocks
            // are rendered with content, where `blockGroups` is just the template for new blocks.
            'blocks' => $this->_getBlocksForInput($value, $placeholderKey, $element),
            'blockGroups' => $this->_getBlockGroupsForInput($value, $placeholderKey, $element),
            'vizyConfig' => $this->_getVizyConfig(),
            'defaultTransform' => $defaultTransform,
            'elementSiteId' => $site->id,
            'showAllUploaders' => $this->showUnpermittedFiles,
            'placeholderKey' => $placeholderKey,
            'fieldHandle' => $this->handle,
            'isRoot' => true,
        ];

        // Only include some options if we need them - for performance
        $buttons = $settings['vizyConfig']['buttons'] ?? [];

        if (in_array('link', $buttons) || in_array('image', $buttons)) {
            $settings['linkOptions'] = $this->_getLinkOptions($element);
            $settings['volumes'] = $this->_getVolumeKeys();
            $settings['transforms'] = $this->_getTransforms();
        }

        // Let the field know if this is the root field for nested fields
        if ($element instanceof BlockElement) {
            $settings['isRoot'] = false;
        }

        // No need to output JS for any nested fields, all settings are rendered in the template
        // as Vue takes over and processes the props.
        // if (!$element instanceof BlockElement) {
        //     $view->registerAssetBundle(VizyAsset::class);
        //     $view->registerJs('new Craft.Vizy.Input(' .
        //         '"' . $view->namespaceInputId($id) . '", ' .
        //         '"' . $view->namespaceInputName($this->handle) . '"' .
        //     ');');
        // }

        Plugin::registerAsset('field/src/js/vizy.js');

        // Create the Vizy Input Vue component
        $js = 'new Craft.Vizy.Input(' .
            '"' . $view->namespaceInputId($id) . '", ' .
            '"' . $view->namespaceInputName($this->handle) . '"' .
        ');';

        // Wait for Vizy JS to be loaded, either through an event listener, or by a flag.
        // This covers if this script is run before, or after the Vizy JS has loaded
        $view->registerJs('document.addEventListener("vite-script-loaded", function(e) {' .
            'if (e.detail.path === "field/src/js/vizy.js") {' . $js . '}' .
        '}); if (Craft.VizyReady) {' . $js . '}');

        $rawNodes = $value->getRawNodes();

        // Normalise the content a little to handle special characters. 
        // TODO: This is temporary, but helps transition people with issues _now_ but is 
        // handled in `Nodes::serializeContent()` and `StringHelper::htmlEncode($text, ENT_NOQUOTES)`
        foreach ($rawNodes as $rawNodeKey => $rawNode) {
            $content = $rawNode['content'] ?? [];

            foreach ($content as $contentKey => $block) {
                // We only want to modify simple nodes and their text content, not complicated
                // nodes like VizyBlocks, which could mess things up as fields control their content.
                $text = $block['text'] ?? '';

                // Decode some HTML entities
                $text = StringHelper::htmlDecode($text);

                $rawNodes[$rawNodeKey]['content'][$contentKey]['text'] = $text;
            }
        }

        return $view->renderTemplate('vizy/field/input', [
            'id' => $id,
            'name' => $this->handle,
            'field' => $this,
            'value' => Json::encode($rawNodes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT),
            'settings' => Json::encode($settings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT),
        ]);
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element = null): NodeCollection
    {
        if ($value instanceof NodeCollection) {
            return $value;
        }

        if (is_string($value) && !empty($value)) {
            $value = Json::decodeIfJson($value);
        }

        if (!is_array($value)) {
            $value = [];
        }

        // Convert serialized data to a collection of nodes.
        return new NodeCollection($this, $value, $element);
    }

    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value instanceof NodeCollection) {
            return $value->serializeValues($element);
        }

        return $value;
    }

    public function getStaticHtml(mixed $value, ElementInterface $element): string
    {
        $view = Craft::$app->getView();

        $view->registerAssetBundle(VizyAsset::class);

        return Html::tag('div', $value->renderStaticHtml() ?: '&nbsp;', [
            'class' => 'text vizy-static',
        ]);
    }

    public function beforeSave(bool $isNew): bool
    {
        if (!parent::beforeSave($isNew)) {
            return false;
        }

        $request = Craft::$app->getRequest();
        $errors = [];

        // Prepare the setting data to be saved
        $this->fieldData = Json::decodeIfJson($this->fieldData) ?? [];

        foreach ($this->fieldData as $groupKey => $group) {
            $blockTypes = $group['blockTypes'] ?? [];

            foreach ($blockTypes as $blockTypeKey => $blockType) {
                // Ensure we catch errors to prevent data loss
                try {
                    // Remove this before populating the model
                    $layoutConfig = Json::decode(ArrayHelper::remove($blockType, 'layout'));

                    // Create a model so we can properly validate
                    $blockType = new BlockType($blockType);

                    if (!$blockType->validate()) {
                        foreach ($blockType->getErrors() as $key => $error) {
                            $errors[$blockType->id . ':' . $key] = $error;
                        }

                        continue;
                    }

                    // Check if there's any changes to be made
                    if ($layoutConfig && $fieldLayout = FieldLayout::createFromConfig($layoutConfig)) {
                        $fieldLayout->type = BlockType::class;

                        // Set the layout here, saving takes place in PC event handlers, straight after this
                        $blockType->setFieldLayout($fieldLayout);
                    }

                    // Override with our cleaned model data
                    $this->fieldData[$groupKey]['blockTypes'][$blockTypeKey] = $blockType->serializeArray();
                } catch (Throwable $e) {
                    $this->addErrors([$blockType['id'] . ':general' => $e->getMessage()]);

                    return false;
                }
            }
        }

        if ($errors) {
            $this->addErrors($errors);

            return false;
        }

        // Prevent any empty groups.
        foreach ($this->fieldData as $groupKey => $group) {
            $blocks = $group['blockTypes'] ?? [];

            if (!$blocks) {
                unset($this->fieldData[$groupKey]);
            }
        }

        // Be sure to reset the array keys, in case empty blocks have been deleted.
        // Can cause PC issues with `unpackAssociativeArray`.
        $this->fieldData = array_values($this->fieldData);

        // Any fields not in the global scope won't trigger a PC change event. Go manual.
        if ($this->context !== 'global') {
            Vizy::$plugin->getService()->saveField($this->fieldData);
        }

        return true;
    }

    public function beforeElementSave(ElementInterface $element, bool $isNew): bool
    {
        // If we're propagating the element (entry), we need to perform some additional checks in a specific scenario
        // If the Vizy field is set to un-translatable but the inner fields are, Craft's `_propagateElement()` will copy
        // values across all elements, which we don't want. As such, check each field and remove the duplicated content,
        // restoring the content that was there. This is tricky that Vizy fields don't use elements for their content
        // unlike Matrix, so we need to do a deep-dive into the content to re-jig it.
        //
        // We can also skip over this entirely if the Vizy field is translatable - that works as expected.
        if ($element->propagating && $this->translationMethod === Field::TRANSLATION_METHOD_NONE) {
            $translatableFields = [];

            // Before going any further, are there any inner fields in _any_ block type for this field
            // that are translatable? No need to go further if there aren't, and saves a lot of time.
            foreach ($this->getBlockTypes() as $blockType) {
                if (($fieldLayout = $blockType->getFieldLayout()) !== null) {
                    foreach ($fieldLayout->getCustomFields() as $field) {
                        if ($field->translationMethod !== Field::TRANSLATION_METHOD_NONE) {
                            $translatableFields[$blockType->id][] = $field->handle;
                        }
                    }
                }
            }

            if ($translatableFields) {
                // Fetch the current element, so we can get it's content before saving.
                $siteElement = Craft::$app->getElements()->getElementById($element->id, $element::class, $element->siteId);

                if ($siteElement) {
                    $hasUpdatedContent = false;
                    $newNodes = $element->getFieldValue($this->handle)->getRawNodes();

                    // Extract the raw content for _just_ the translatable fields
                    foreach ($siteElement->getFieldValue($this->handle)->getRawNodes() as $rawNode) {
                        if ($rawNode['type'] === 'vizyBlock') {
                            $blockId = $rawNode['attrs']['id'] ?? '';
                            $blockTypeId = $rawNode['attrs']['values']['type'] ?? '';
                            $fields = $translatableFields[$blockTypeId] ?? [];

                            foreach ($fields as $field) {
                                // Ensure we find the right block to update
                                foreach ($newNodes as $key => $newNode) {
                                    $newBlockId = $newNode['attrs']['id'] ?? '';

                                    if ($newBlockId === $blockId) {
                                        $hasUpdatedContent = true;

                                        $newNodes[$key]['attrs']['values']['content']['fields'][$field] = $rawNode['attrs']['values']['content']['fields'][$field] ?? '';
                                    }
                                }
                            }
                        }
                    }

                    if ($hasUpdatedContent) {
                        // Rebuild the node collection - if it's changed
                        $nodeCollection = new NodeCollection($this, $newNodes, $element);

                        $element->setFieldValue($this->handle, $nodeCollection);
                    }
                }
            }
        }

        return parent::beforeElementSave($element, $isNew);
    }

    public function getBlockTypeById($blockTypeId)
    {
        if (isset($this->_blockTypesById[$blockTypeId])) {
            return $this->_blockTypesById[$blockTypeId];
        }

        foreach ($this->fieldData as $groupKey => $group) {
            foreach ($group['blockTypes'] as $blockTypeKey => $block) {
                if ($block['id'] === $blockTypeId) {
                    return $this->_blockTypesById[$blockTypeId] = new BlockType($block);
                }
            }
        }

        return null;
    }

    public function getBlockTypes(): array
    {
        $blockTypes = [];

        foreach ($this->fieldData as $groupKey => $group) {
            $blocks = $group['blockTypes'] ?? [];

            foreach ($blocks as $blockTypeKey => $blockTypeData) {
                // Remove this before populating the model
                $layout = ArrayHelper::remove($blockTypeData, 'layout');

                $blockType = new BlockType($blockTypeData);
                $blockType->fieldId = $this->id;

                $blockTypes[] = $blockType;
            }
        }

        return $blockTypes;
    }

    public function getContentGqlType(): Type|array
    {
        return NodeCollectionType::getType($this);
    }

    public function getElementValidationRules(): array
    {
        return [
            [
                'validateBlocks',
                'on' => [Element::SCENARIO_ESSENTIALS, Element::SCENARIO_DEFAULT, Element::SCENARIO_LIVE],
                'skipOnEmpty' => false,
            ],
        ];
    }

    public function validateBlocks(ElementInterface $element): void
    {
        $value = $element->getFieldValue($this->handle);
        $blocks = $value->query()->where(['type' => 'vizyBlock'])->all();
        $scenario = $element->getScenario();

        foreach ($blocks as $i => $block) {
            $blockElement = $block->getBlockElement($element);
            $blockElement->setScenario($scenario);

            if (!$blockElement->validate()) {
                $element->addModelErrors($blockElement, "{$this->handle}[{$i}]");
            }
        }
    }

    protected function searchKeywords(mixed $value, ElementInterface $element): string
    {
        $keywords = parent::searchKeywords($value, $element);

        if ($value instanceof NodeCollection) {
            $nodes = $value->getRawNodes();

            // Any actual editor text
            $keywords = $this->_getNestedValues($nodes, 'text');

            // Fields are different, and we need to check on their searchability
            foreach ($value->getNodes() as $key => $block) {
                if ($block instanceof VizyBlock) {
                    if ($fieldLayout = $block->getFieldLayout()) {
                        foreach ($fieldLayout->getCustomFields() as $field) {
                            if (!$field->searchable) {
                                continue;
                            }

                            $fieldData = $block->getFieldValue($field->handle);

                            $keywords[] = $field->searchKeywords($fieldData, $element);
                        }
                    }
                }
            }
        }

        if (is_array($keywords)) {
            $keywords = trim(implode(' ', array_unique($keywords)));
        }

        return $keywords;
    }


    // Private Methods
    // =========================================================================

    private function getCacheKey($key)
    {
        return $this->id . '-' . $this->handle . '-' . $key;
    }

    private function _getNestedValues($value, $key, &$items = []): array
    {
        foreach ($value as $k => $v) {
            if ((string)$k === $key) {
                $items[] = $v;
            }

            if (is_array($v)) {
                $this->_getNestedValues($v, $key, $items);
            }
        }

        return $items;
    }

    private function _getBlockGroupsForSettings(): array
    {
        $data = $this->fieldData;

        foreach ($data as $groupKey => $group) {
            $blocks = $group['blockTypes'] ?? [];

            foreach ($blocks as $blockTypeKey => $blockTypeData) {
                // Remove this before populating the model
                $layout = ArrayHelper::remove($blockTypeData, 'layout');

                $blockType = new BlockType($blockTypeData);
                $blockTypeArray = $blockType->toArray();

                // Watch for Vue's reactivity with arrays/objects. Easier to just implement here.
                // Never actually stored in the DB, but needed for field layout designer
                $blockTypeArray['layout'] = $layout;

                // Override with prepped data for Vue
                $data[$groupKey]['blockTypes'][$blockTypeKey] = $blockTypeArray;
            }
        }

        return $data;
    }

    private function _getBlockGroupsForInput($value, $placeholderKey, ElementInterface $element = null): array
    {
        // Get from the cache, if we've already prepped this field's block groups.
        // The blocks HTML/JS is unique to this fields' ID and handle. Even if used multiple
        // times in an element, or nested, we only need to generate this once.
        return Vizy::$plugin->getCache()->getOrSet($this->getCacheKey('blockGroups'), function() use ($value, $placeholderKey, $element) {
            $view = Craft::$app->getView();

            $data = $this->fieldData;

            foreach ($data as $groupKey => $group) {
                $blocks = $group['blockTypes'] ?? [];

                foreach ($blocks as $blockTypeKey => $blockTypeData) {
                    // Skip any disabled blocktypes
                    $enabled = $blockTypeData['enabled'] ?? true;

                    if (!$enabled) {
                        continue;
                    }

                    $blockType = new BlockType($blockTypeData);

                    $fieldLayout = $blockType->getFieldLayout();

                    if (!$fieldLayout) {
                        // Discard the blocktype
                        unset($data[$groupKey]['blockTypes'][$blockTypeKey]);

                        continue;
                    }

                    $blockTypeArray = $blockType->toArray();

                    $view->startJsBuffer();

                    // Create a fake element with the same fieldtype as our block
                    $blockElement = new BlockElement();
                    $blockElement->setFieldLayout($fieldLayout);
                    $blockElement->setOwner($element);

                    $originalNamespace = $view->getNamespace();
                    $namespace = $view->namespaceInputName($this->handle . "[blocks][__VIZY_BLOCK_{$placeholderKey}__]", $originalNamespace);
                    $view->setNamespace($namespace);

                    $form = $fieldLayout->createForm($blockElement);
                    $blockTypeArray['tabs'] = $form->getTabMenu();
                    $blockTypeArray['fieldsHtml'] = $view->namespaceInputs($form->render());

                    $footHtml = $view->clearJsBuffer(false);

                    $view->setNamespace($originalNamespace);

                    if ($footHtml) {
                        $footHtml = '<script id="script-__VIZY_BLOCK_' . $placeholderKey . '__">' . $footHtml . '</script>';
                    }

                    $blockTypeArray['footHtml'] = $footHtml;

                    $data[$groupKey]['blockTypes'][$blockTypeKey] = $blockTypeArray;
                }

                // Ensure we reset array indexes to play nicely with JS
                $data[$groupKey]['blockTypes'] = array_values($data[$groupKey]['blockTypes']);
            }

            return $data;
        });
    }

    private function _getBlocksForInput($value, $placeholderKey, ElementInterface $element = null): array
    {
        $view = Craft::$app->getView();

        $blocks = [];

        if ($value instanceof NodeCollection) {
            foreach ($value->getNodes() as $i => $block) {
                if ($block instanceof VizyBlock) {
                    $blockId = $block->attrs['id'];
                    $fieldLayout = $block->getFieldLayout();

                    if (!$fieldLayout) {
                        continue;
                    }

                    $view->startJsBuffer();

                    // Create a fake element with the same fieldtype as our block
                    $blockElement = $block->getBlockElement($element);

                    $originalNamespace = $view->getNamespace();
                    $namespace = $view->namespaceInputName($this->handle . "[blocks][__VIZY_BLOCK_{$placeholderKey}__]", $originalNamespace);
                    $view->setNamespace($namespace);

                    $fieldsHtml = $view->namespaceInputs($fieldLayout->createForm($blockElement)->render());
                    $footHtml = $view->clearJsBuffer(false);

                    $view->setNamespace($originalNamespace);

                    if ($footHtml) {
                        $footHtml = '<script id="script-__VIZY_BLOCK_' . $placeholderKey . '__">' . $footHtml . '</script>';
                    }

                    $blocks[] = [
                        'id' => $blockId,
                        'fieldsHtml' => $fieldsHtml,
                        'footHtml' => $footHtml,
                    ];
                }
            }
        }

        return $blocks;
    }

    private function _getVizyConfig(): array
    {
        if ($this->configSelectionMode === 'manual') {
            $config = Json::decode($this->manualConfig);
        } else {
            $config = $this->_getConfig('vizy', $this->vizyConfig) ?: [];
        }

        // Give plugins a chance to modify the config
        $event = new ModifyVizyConfigEvent([
            'config' => $config,
            'field' => $this,
        ]);

        $this->trigger(self::EVENT_DEFINE_VIZY_CONFIG, $event);

        return $event->config;
    }

    private function _getConfig(string $dir, string $file = null)
    {
        if (!$file) {
            $file = 'Default.json';
        }

        $path = Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR . $file;

        if (!is_file($path)) {
            if ($file !== 'Default.json') {
                // Try again with Default
                return $this->_getConfig($dir);
            }
            return false;
        }

        return Json::decode(file_get_contents($path));
    }

    private function _getCustomConfigOptions(string $dir): array
    {
        $options = ['' => Craft::t('vizy', 'Default')];
        $path = Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . $dir;

        if (is_dir($path)) {
            $files = FileHelper::findFiles($path, [
                'only' => ['*.json'],
                'recursive' => false,
            ]);

            foreach ($files as $file) {
                $filename = basename($file);

                if ($filename !== 'Default.json') {
                    $options[$filename] = pathinfo($file, PATHINFO_FILENAME);
                }
            }
        }

        return $options;
    }

    private function _getLinkOptions(Element $element = null): array
    {
        if ($this->_linkOptions !== null) {
            return $this->_linkOptions;
        }

        $linkOptions = [];

        $sectionSources = $this->_getSectionSources($element);
        $categorySources = $this->_getCategorySources($element);
        $volumeKeys = $this->_getVolumeKeys();

        if (!empty($sectionSources)) {
            $linkOptions[] = [
                'optionTitle' => Craft::t('vizy', 'Link to an entry'),
                'elementType' => Entry::class,
                'refHandle' => Entry::refHandle(),
                'sources' => $sectionSources,
                'criteria' => ['uri' => ':notempty:'],
            ];
        }

        if (!empty($volumeKeys)) {
            $linkOptions[] = [
                'optionTitle' => Craft::t('vizy', 'Link to an asset'),
                'elementType' => Asset::class,
                'refHandle' => Asset::refHandle(),
                'sources' => $volumeKeys,
            ];
        }

        if (!empty($categorySources)) {
            $linkOptions[] = [
                'optionTitle' => Craft::t('vizy', 'Link to a category'),
                'elementType' => Category::class,
                'refHandle' => Category::refHandle(),
                'sources' => $categorySources,
            ];
        }

        // Give plugins a chance to add their own
        $event = new RegisterLinkOptionsEvent([
            'linkOptions' => $linkOptions,
        ]);
        $this->trigger(self::EVENT_REGISTER_LINK_OPTIONS, $event);
        $linkOptions = $event->linkOptions;

        // Fill in any missing ref handles
        foreach ($linkOptions as &$linkOption) {
            if (!isset($linkOption['refHandle'])) {
                /** @var ElementInterface|string $class */
                $class = $linkOption['elementType'];
                $linkOption['refHandle'] = $class::refHandle() ?? $class;
            }
        }

        return $this->_linkOptions = $linkOptions;
    }

    private function _getSectionSources(Element $element = null): array
    {
        if ($this->_sectionSources !== null) {
            return $this->_sectionSources;
        }

        $sources = [];
        $sections = Craft::$app->getSections()->getAllSections();
        $showSingles = false;

        // Get all sites
        $sites = Craft::$app->getSites()->getAllSites();

        foreach ($sections as $section) {
            if ($section->type === Section::TYPE_SINGLE) {
                $showSingles = true;
            } else if ($element) {
                $sectionSiteSettings = $section->getSiteSettings();

                foreach ($sites as $site) {
                    if (isset($sectionSiteSettings[$site->id]) && $sectionSiteSettings[$site->id]->hasUrls) {
                        $sources[] = 'section:' . $section->uid;
                    }
                }
            }
        }

        if ($showSingles) {
            array_unshift($sources, 'singles');
        }

        if (!empty($sources)) {
            array_unshift($sources, '*');
        }

        $sources = array_values(array_unique($sources));

        return $this->_sectionSources = $sources;
    }

    private function _getCategorySources(Element $element = null): array
    {
        if ($this->_categorySources !== null) {
            return $this->_categorySources;
        }

        $sources = [];

        if ($element) {
            $categoryGroups = Craft::$app->getCategories()->getAllGroups();

            foreach ($categoryGroups as $categoryGroup) {
                // Does the category group have URLs in the same site as the element we're editing?
                $categoryGroupSiteSettings = $categoryGroup->getSiteSettings();

                if (isset($categoryGroupSiteSettings[$element->siteId]) && $categoryGroupSiteSettings[$element->siteId]->hasUrls) {
                    $sources[] = 'group:' . $categoryGroup->uid;
                }
            }
        }

        $sources = array_values(array_unique($sources));

        return $this->_categorySources = $sources;
    }

    private function _getVolumeKeys(): array
    {
        if ($this->_volumeKeys !== null) {
            return $this->_volumeKeys;
        }

        if (!$this->availableVolumes) {
            return [];
        }

        $criteria = ['parentId' => ':empty:'];

        $allVolumes = Craft::$app->getVolumes()->getAllVolumes();
        $allowedVolumes = [];
        $userService = Craft::$app->getUser();

        foreach ($allVolumes as $volume) {
            $allowedBySettings = $this->availableVolumes === '*' || (is_array($this->availableVolumes) && in_array($volume->uid, $this->availableVolumes));
            
            if ($allowedBySettings && ($this->showUnpermittedVolumes || $userService->checkPermission("viewVolume:$volume->uid"))) {
                $allowedVolumes[] = 'volume:' . $volume->uid;
            }
        }

        $allowedVolumes = array_values(array_unique($allowedVolumes));

        return $this->_volumeKeys = $allowedVolumes;
    }

    private function _getTransforms(): array
    {
        if ($this->_transforms !== null) {
            return $this->_transforms;
        }

        if (!$this->availableTransforms) {
            return [];
        }

        $allTransforms = Craft::$app->getImageTransforms()->getAllTransforms();
        $transformList = [];

        foreach ($allTransforms as $transform) {
            if (!is_array($this->availableTransforms) || in_array($transform->uid, $this->availableTransforms, false)) {
                $transformList[] = [
                    'handle' => Html::encode($transform->handle),
                    'name' => Html::encode($transform->name),
                ];
            }
        }

        return $this->_transforms = $transformList;
    }
}
