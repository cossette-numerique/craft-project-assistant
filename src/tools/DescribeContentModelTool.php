<?php

namespace cossette\projectassistant\tools;

use Craft;
use craft\base\FieldInterface;
use craft\fields\BaseOptionsField;
use craft\fields\BaseRelationField;
use craft\fields\Matrix;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use cossette\projectassistant\Plugin;

/**
 * Describes the content model through Craft's APIs, so it works on any
 * project without configuration: sites, sections, entry types and fields.
 */
class DescribeContentModelTool extends BaseTool
{
    public function getName(): string
    {
        return 'describe_content_model';
    }

    public function getDescription(): string
    {
        return 'Describe the CMS content model. Without arguments: sites, sections (with entry types and URL formats), '
            . 'global sets and category groups. With a section handle: every entry type of that section and its fields '
            . '(handle, type, required, instructions, options, related sections, nested Matrix entry types). '
            . 'Start here for any question about what content exists or what editors can and cannot do.';
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'section' => ['type' => 'string', 'description' => 'Section handle to describe in detail.'],
            'globalSet' => ['type' => 'string', 'description' => 'Global set handle to describe in detail.'],
        ]);
    }

    public function getLabel(array $input): string
    {
        $section = self::str($input, 'section') ?: self::str($input, 'globalSet');

        return $section !== ''
            ? Craft::t(Plugin::HANDLE, 'Looking at the “{section}” content model', ['section' => $section])
            : Craft::t(Plugin::HANDLE, 'Looking at the content model');
    }

    public function execute(array $input, ToolContext $context): ToolResult
    {
        $sectionHandle = self::str($input, 'section');
        $globalHandle = self::str($input, 'globalSet');

        if ($sectionHandle !== '') {
            $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle);
            if (!$section || !self::canView($section, $context)) {
                return ToolResult::error("Unknown section or no access: $sectionHandle");
            }

            return ToolResult::json([
                'section' => $this->sectionSummary($section),
                'entryTypes' => array_map(fn(EntryType $type) => $this->entryTypeDetail($type), $section->getEntryTypes()),
            ]);
        }

        if ($globalHandle !== '') {
            $set = Craft::$app->getGlobals()->getSetByHandle($globalHandle);
            if (!$set) {
                return ToolResult::error("Unknown global set: $globalHandle");
            }

            return ToolResult::json([
                'globalSet' => ['handle' => $set->handle, 'name' => $set->name],
                'fields' => $this->layoutFields($set->getFieldLayout()),
            ]);
        }

        $sections = array_values(array_filter(
            Craft::$app->getEntries()->getAllSections(),
            fn(Section $s) => self::canView($s, $context),
        ));

        return ToolResult::json([
            'sites' => array_map(fn($site) => [
                'handle' => $site->handle,
                'name' => $site->getName(),
                'language' => $site->language,
                'primary' => $site->primary,
                'baseUrl' => $site->getBaseUrl(),
            ], Craft::$app->getSites()->getAllSites()),
            'sections' => array_map(fn(Section $s) => $this->sectionSummary($s), $sections),
            'globalSets' => array_map(fn($set) => ['handle' => $set->handle, 'name' => $set->name], Craft::$app->getGlobals()->getAllSets()),
            'categoryGroups' => array_map(fn($g) => ['handle' => $g->handle, 'name' => $g->name], Craft::$app->getCategories()->getAllGroups()),
            'tagGroups' => array_map(fn($g) => ['handle' => $g->handle, 'name' => $g->name], Craft::$app->getTags()->getAllTagGroups()),
            'volumes' => array_map(fn($v) => ['handle' => $v->handle, 'name' => $v->name], Craft::$app->getVolumes()->getAllVolumes()),
        ]);
    }

    public static function canView(Section $section, ToolContext $context): bool
    {
        return $context->user->can('viewEntries:' . $section->uid);
    }

    private function sectionSummary(Section $section): array
    {
        $sites = Craft::$app->getSites();
        $uris = [];
        foreach ($section->getSiteSettings() as $siteId => $settings) {
            $site = $sites->getSiteById($siteId);
            if ($site) {
                $uris[$site->handle] = $settings->hasUrls ? $settings->uriFormat : null;
            }
        }

        return [
            'handle' => $section->handle,
            'name' => $section->name,
            'type' => $section->type,
            'entryTypes' => array_map(fn(EntryType $t) => $t->handle, $section->getEntryTypes()),
            'uriFormats' => $uris,
            'maxLevels' => $section->type === Section::TYPE_STRUCTURE ? $section->maxLevels : null,
        ];
    }

    private function entryTypeDetail(EntryType $type, int $depth = 0): array
    {
        return [
            'handle' => $type->handle,
            'name' => $type->name,
            'hasTitleField' => $type->hasTitleField,
            'fields' => $this->layoutFields($type->getFieldLayout(), $depth),
        ];
    }

    private function layoutFields(FieldLayout $layout, int $depth = 0): array
    {
        $fields = [];
        foreach ($layout->getTabs() as $tab) {
            foreach ($tab->getElements() as $element) {
                if (!$element instanceof \craft\fieldlayoutelements\CustomField) {
                    continue;
                }
                $field = $element->getField();
                $fields[] = array_filter([
                    'handle' => $field->handle,
                    'name' => $field->name,
                    'type' => self::fieldType($field),
                    'tab' => $tab->name,
                    'required' => $element->required ?: null,
                    'translatable' => $field->getIsTranslatable(null) ?: null,
                    'instructions' => $field->instructions ? mb_strimwidth(strip_tags($field->instructions), 0, 300, '…') : null,
                ] + $this->fieldExtras($field, $depth), fn($v) => $v !== null && $v !== '' && $v !== []);
            }
        }

        return $fields;
    }

    private function fieldExtras(FieldInterface $field, int $depth): array
    {
        if ($field instanceof BaseOptionsField) {
            return ['options' => array_values(array_filter(array_map(
                fn($o) => isset($o['value']) ? $o['value'] . (isset($o['label']) && $o['label'] !== $o['value'] ? ' (' . $o['label'] . ')' : '') : null,
                $field->options,
            )))];
        }
        if ($field instanceof Matrix) {
            return ['nestedEntryTypes' => array_map(
                fn(EntryType $t) => $depth < 1 ? $this->entryTypeDetail($t, $depth + 1) : $t->handle,
                $field->getEntryTypes(),
            )];
        }
        if ($field instanceof BaseRelationField) {
            $sources = $field->sources ?? ($field->source ? [$field->source] : '*');

            return ['relatesTo' => is_array($sources) ? array_map([self::class, 'describeSource'], $sources) : $sources];
        }

        return [];
    }

    private static function describeSource(string $source): string
    {
        if (str_starts_with($source, 'section:')) {
            $section = Craft::$app->getEntries()->getSectionByUid(substr($source, 8));

            return $section ? 'section:' . $section->handle : $source;
        }

        return $source;
    }

    private static function fieldType(FieldInterface $field): string
    {
        return (new \ReflectionClass($field))->getShortName();
    }
}
