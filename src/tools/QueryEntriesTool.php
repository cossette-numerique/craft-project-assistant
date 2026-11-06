<?php

namespace cossette\projectassistant\tools;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\elements\User;
use craft\fields\data\MultiOptionsFieldData;
use craft\fields\data\SingleOptionFieldData;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\StringHelper;
use cossette\projectassistant\Plugin;

/**
 * Reads entries through Craft's element queries, limited to sections the
 * asking user can view. Drafts and revisions are never returned.
 */
class QueryEntriesTool extends BaseTool
{
    private const ORDER_BY = ['postDate', 'dateCreated', 'dateUpdated', 'title', 'id'];

    public function getName(): string
    {
        return 'query_entries';
    }

    public function getDescription(): string
    {
        return 'Find, count or read entries of one section. Filter by keyword search, status, site and custom field '
            . 'values (Craft query syntax, e.g. {"eventDate": ">= 2026-01-01", "region": "not :empty:"}). '
            . 'Set countOnly for totals. Pass an id to read one entry with all its field values. '
            . 'Returns CP edit links you can share with the user. Use describe_content_model first to know the field handles.';
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'section' => ['type' => 'string', 'description' => 'Section handle. Required unless id is given.'],
            'id' => ['type' => 'integer', 'description' => 'Read a single entry by ID, with all its fields.'],
            'search' => ['type' => 'string', 'description' => 'Craft keyword search.'],
            'status' => ['type' => 'string', 'enum' => ['any', 'live', 'pending', 'expired', 'disabled'], 'description' => 'Default any.'],
            'site' => ['type' => 'string', 'description' => 'Site handle. Default: the primary site.'],
            'type' => ['type' => 'string', 'description' => 'Entry type handle.'],
            'fieldFilters' => ['type' => 'object', 'description' => 'Custom field handle => Craft query param value.', 'additionalProperties' => true],
            'fields' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Field handles whose values to include for each entry.'],
            'orderBy' => ['type' => 'string', 'enum' => self::ORDER_BY, 'description' => 'Default postDate.'],
            'direction' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'description' => 'Default desc.'],
            'limit' => ['type' => 'integer', 'description' => 'Default 20, max 100.'],
            'offset' => ['type' => 'integer'],
            'countOnly' => ['type' => 'boolean'],
        ]);
    }

    public function getLabel(array $input): string
    {
        if (!empty($input['id'])) {
            return Craft::t(Plugin::HANDLE, 'Reading entry #{id}', ['id' => (int)$input['id']]);
        }

        return Craft::t(Plugin::HANDLE, 'Looking up “{section}” entries', ['section' => self::str($input, 'section')]);
    }

    public function execute(array $input, ToolContext $context): ToolResult
    {
        $query = Entry::find()->status(null);

        $siteHandle = self::str($input, 'site');
        if ($siteHandle !== '') {
            $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);
            if (!$site) {
                return ToolResult::error("Unknown site: $siteHandle");
            }
            $query->siteId($site->id);
        }

        if (!empty($input['id'])) {
            $entry = $query->id((int)$input['id'])->one();
            $section = $entry?->getSection();
            if (!$entry || ($section && !DescribeContentModelTool::canView($section, $context))) {
                return ToolResult::error('Entry not found or no access.');
            }

            return ToolResult::json($this->serializeEntry($entry, null));
        }

        $sectionHandle = self::str($input, 'section');
        $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle);
        if (!$section || !DescribeContentModelTool::canView($section, $context)) {
            return ToolResult::error("Unknown section or no access: $sectionHandle");
        }
        $query->section($section);

        $knownFields = [];
        foreach ($section->getEntryTypes() as $type) {
            foreach ($type->getFieldLayout()->getCustomFieldElements() as $element) {
                $knownFields[$element->getField()->handle] = true;
            }
        }

        $status = self::str($input, 'status', 'any');
        if ($status !== 'any') {
            $query->status($status);
        }
        if (($type = self::str($input, 'type')) !== '') {
            $query->type($type);
        }
        if (($search = self::str($input, 'search')) !== '') {
            $query->search($search);
        }
        foreach ((array)($input['fieldFilters'] ?? []) as $handle => $value) {
            if (!isset($knownFields[$handle])) {
                return ToolResult::error("Unknown field “{$handle}” in section {$sectionHandle}. Known: " . implode(', ', array_keys($knownFields)));
            }
            $query->$handle($value);
        }

        try {
            $total = (int)$query->count();
        } catch (\Throwable $e) {
            return ToolResult::error('Query failed: ' . $e->getMessage());
        }
        if (!empty($input['countOnly'])) {
            return ToolResult::json(['section' => $sectionHandle, 'total' => $total]);
        }

        $orderBy = in_array($input['orderBy'] ?? null, self::ORDER_BY, true) ? $input['orderBy'] : 'postDate';
        $direction = ($input['direction'] ?? 'desc') === 'asc' ? SORT_ASC : SORT_DESC;
        $query->orderBy([$orderBy => $direction])
            ->limit(self::int($input, 'limit', 20, 1, 100))
            ->offset(self::int($input, 'offset', 0, 0, PHP_INT_MAX));

        $fields = array_values(array_filter((array)($input['fields'] ?? []), fn($h) => is_string($h) && isset($knownFields[$h])));

        return ToolResult::json([
            'section' => $sectionHandle,
            'total' => $total,
            'entries' => array_map(fn(Entry $e) => $this->serializeEntry($e, $fields), $query->all()),
        ]);
    }

    /**
     * @param string[]|null $fields Field handles to include; null = all fields.
     */
    private function serializeEntry(Entry $entry, ?array $fields): array
    {
        $data = [
            'id' => $entry->id,
            'title' => $entry->title,
            'section' => $entry->getSection()?->handle,
            'type' => $entry->getType()->handle,
            'site' => $entry->getSite()->handle,
            'status' => $entry->getStatus(),
            'slug' => $entry->slug,
            'postDate' => $entry->postDate?->format('Y-m-d H:i'),
            'dateUpdated' => $entry->dateUpdated?->format('Y-m-d H:i'),
            'url' => $entry->getUrl(),
            'cpEditUrl' => $entry->getCpEditUrl(),
        ];
        if ($entry->level) {
            $data['level'] = $entry->level;
        }

        $values = [];
        foreach ($entry->getFieldLayout()?->getCustomFieldElements() ?? [] as $element) {
            /** @var CustomField $element */
            $handle = $element->getField()->handle;
            if ($fields !== null && !in_array($handle, $fields, true)) {
                continue;
            }
            $values[$handle] = self::serializeValue($entry->getFieldValue($handle));
        }
        if ($values) {
            $data['fields'] = $values;
        }

        return $data;
    }

    public static function serializeValue(mixed $value, int $depth = 0): mixed
    {
        if ($value === null || is_scalar($value)) {
            return is_string($value) ? self::shorten($value) : $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i');
        }
        if ($value instanceof SingleOptionFieldData) {
            return $value->value;
        }
        if ($value instanceof MultiOptionsFieldData) {
            // The ArrayObject holds the selected options only.
            return array_values(array_map(fn($o) => $o->value, $value->getArrayCopy()));
        }
        if ($value instanceof ElementQueryInterface) {
            $count = (int)(clone $value)->count();
            $items = array_map(fn(ElementInterface $el) => self::serializeElement($el, $depth), (clone $value)->limit(10)->all());

            return $count > 10 ? ['count' => $count, 'first10' => $items] : $items;
        }
        if ($value instanceof \Illuminate\Support\Collection) {
            return array_map(fn($el) => $el instanceof ElementInterface ? self::serializeElement($el, $depth) : self::serializeValue($el, $depth + 1), $value->take(10)->all());
        }
        if ($value instanceof ElementInterface) {
            return self::serializeElement($value, $depth);
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return self::shorten(strip_tags((string)$value));
        }
        if (is_array($value)) {
            return $depth > 2 ? '[…]' : array_map(fn($v) => self::serializeValue($v, $depth + 1), array_slice($value, 0, 20, true));
        }

        return '(' . get_debug_type($value) . ')';
    }

    private static function serializeElement(ElementInterface $element, int $depth): array|string
    {
        if ($element instanceof User) {
            // Name only: no emails or other account data through this tool.
            return ['user' => $element->getFriendlyName()];
        }
        if ($element instanceof Asset) {
            return ['asset' => $element->getFilename(), 'id' => $element->id];
        }
        if ($element instanceof Entry && $element->getOwnerId() !== null && $depth < 1) {
            // Nested (Matrix) entry: show its type and values, one level deep.
            $values = [];
            foreach ($element->getFieldLayout()?->getCustomFieldElements() ?? [] as $fieldElement) {
                $handle = $fieldElement->getField()->handle;
                $values[$handle] = self::serializeValue($element->getFieldValue($handle), $depth + 1);
            }

            return ['type' => $element->getType()->handle, 'title' => $element->title, 'fields' => $values];
        }

        return array_filter(['id' => $element->id, 'title' => (string)$element, 'type' => $element instanceof Entry ? $element->getSection()?->handle : $element::lowerDisplayName()]);
    }

    private static function shorten(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');

        return StringHelper::length($text) > 600 ? mb_substr($text, 0, 600) . '…' : $text;
    }
}
