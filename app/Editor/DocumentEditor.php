<?php

declare(strict_types=1);

namespace App\Editor;

use App\Data\FieldDictionary;
use App\Extraction\DataNormalizer;

/**
 * Pure edit operations on a normalized document's working copy (Part D §6).
 *
 * Every operation validates its input, changes the document and re-runs the derived
 * values. Nothing here touches storage — SourceDataRepository applies the result
 * atomically.
 *
 * Operations
 *   set_value   key, value          set_label   key, label (custom rows)
 *   confirm     key                 recalculate key (edited calculated value → calculated again)
 *   include     key, included       include_many items[{key}|{list,rid}], included
 *   remove      key                 restore     key
 *   add_field   label, value        move_field  key, before (key|null = end of its section)
 *   list_set    list, rid, column, value
 *   list_add    list                list_include list, rid, included
 *   list_remove list, rid           list_restore list, rid
 *   list_move   list, rid, before (rid|null)
 *   list_confirm list, rid? (without rid: the list itself)
 */
final class DocumentEditor
{
    public const MAX_CUSTOM_FIELDS = 60;
    public const MAX_LIST_ROWS = 100;

    public const ACTIONS = [
        'set_value', 'set_label', 'confirm', 'recalculate', 'include', 'include_many', 'remove', 'restore',
        'add_field', 'move_field', 'list_set', 'list_add', 'list_include', 'list_remove', 'list_restore',
        'list_move', 'list_confirm',
    ];

    /**
     * Normalize an older / freshly extracted document for editing: every list row gets a
     * stable id (rid). Missing ids are derived from the row position, so reading the same
     * stored document twice always yields the same ids; the first saved edit stores them.
     *
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    public static function prepare(array $doc): array
    {
        $doc['fields'] = array_values(array_filter((array) ($doc['fields'] ?? []), static fn ($r): bool => is_array($r) && is_string($r['key'] ?? null)));
        $lists = [];
        foreach ((array) ($doc['lists'] ?? []) as $key => $rows) {
            if (!is_string($key) || !is_array($rows)) {
                continue;
            }
            $seen = [];
            $out = [];
            foreach (array_values($rows) as $i => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rid = is_string($row['rid'] ?? null) && preg_match('/^[a-z0-9]{2,16}$/', $row['rid']) ? $row['rid'] : 'x' . $i;
                while (isset($seen[$rid])) {
                    $rid = 'x' . $i . 'd' . count($seen);
                }
                $seen[$rid] = true;
                $row['rid'] = $rid;
                $out[] = $row;
            }
            $lists[$key] = $out;
        }
        $doc['lists'] = $lists;

        return $doc;
    }

    /**
     * @param array<string, mixed> $doc working copy
     * @param array<string, mixed> $input request fields
     * @return array{doc: array<string, mixed>, result: array<string, mixed>}
     * @throws EditorException
     */
    public static function apply(array $doc, string $action, array $input): array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new EditorException('Unknown action.', 'invalid', 'action');
        }
        $doc = self::prepare($doc);
        $result = [];

        switch ($action) {
            case 'set_value':
                $i = self::field($doc, $input, false);
                if ($i === null) {
                    // "Empty — enter value" rows: a dictionary field the template needs but the PDF did not have
                    $newKey = is_string($input['key'] ?? null) ? $input['key'] : '';
                    if (!FieldDictionary::isScalar($newKey) || FieldDictionary::isDerived($newKey)) {
                        throw new EditorException('This field no longer exists. Please reload the page.', 'stale', 'key');
                    }
                    $row = DataNormalizer::row($newKey, '', 'manual', false);
                    $row['included'] = true;
                    $doc['fields'][] = $row;
                    $doc = DataNormalizer::sortFields($doc);
                    $i = (int) DataNormalizer::find($doc, $newKey);
                    $result['created'] = true;
                }
                $key = $doc['fields'][$i]['key'];
                $value = FieldValidator::scalar($key, $input['value'] ?? '');
                // An unchanged value keeps its source (a calculated value stays calculated).
                if ($value !== (string) ($doc['fields'][$i]['value'] ?? '')) {
                    $doc['fields'][$i]['value'] = $value;
                    $doc['fields'][$i]['source'] = 'manual';
                }
                $doc['fields'][$i]['needs_review'] = false;
                $result['value'] = $value;
                break;

            case 'set_label':
                $i = self::field($doc, $input);
                if (!FieldDictionary::isCustom($doc['fields'][$i]['key'])) {
                    throw new EditorException('Only fields you added can be renamed.', 'invalid', 'label');
                }
                $doc['fields'][$i]['label'] = FieldValidator::label($input['label'] ?? '');
                $result['label'] = $doc['fields'][$i]['label'];
                break;

            case 'confirm':
                $i = self::field($doc, $input);
                $doc['fields'][$i]['needs_review'] = false;
                if ($doc['fields'][$i]['key'] === 'plugins_updated_count') {
                    $doc['list_meta']['plugin_updates']['ack'] = DataNormalizer::pluginCountSignature($doc);
                }
                break;

            case 'recalculate':
                $i = self::field($doc, $input);
                if (!FieldDictionary::isDerived($doc['fields'][$i]['key'])) {
                    throw new EditorException('This value is not calculated.', 'invalid');
                }
                $doc['fields'][$i]['source'] = 'derived';
                break;

            case 'include':
                $i = self::field($doc, $input);
                $doc['fields'][$i]['included'] = self::bool($input['included'] ?? null);
                break;

            case 'include_many':
                $included = self::bool($input['included'] ?? null);
                $items = $input['items'] ?? null;
                if (!is_array($items) || count($items) > 1000) {
                    throw new EditorException('Nothing to change.', 'invalid', 'items');
                }
                $count = 0;
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    if (isset($item['list'])) {
                        $l = self::listKey($doc, $item);
                        $r = self::rowIndex($doc, $l, $item, false);
                        if ($r !== null && empty($doc['lists'][$l][$r]['removed'])) {
                            $doc['lists'][$l][$r]['included'] = $included;
                            $count++;
                        }
                    } else {
                        $i = self::field($doc, $item, false);
                        if ($i !== null && empty($doc['fields'][$i]['removed'])) {
                            $doc['fields'][$i]['included'] = $included;
                            $count++;
                        }
                    }
                }
                $result['changed'] = $count;
                break;

            case 'remove':
            case 'restore':
                $i = self::field($doc, $input);
                $doc['fields'][$i]['removed'] = $action === 'remove';
                if ($action === 'restore') {
                    $doc['fields'][$i]['included'] = true;
                }
                break;

            case 'add_field':
                $label = FieldValidator::label($input['label'] ?? '');
                $custom = array_filter($doc['fields'], static fn (array $r): bool => FieldDictionary::isCustom($r['key']));
                if (count($custom) >= self::MAX_CUSTOM_FIELDS) {
                    throw new EditorException('You can add up to ' . self::MAX_CUSTOM_FIELDS . ' fields.', 'invalid', 'label');
                }
                $taken = [];
                foreach ($doc['fields'] as $row) {
                    $taken[$row['key']] = true;
                }
                $key = FieldDictionary::customKey($label, $taken);
                $value = FieldValidator::scalar($key, $input['value'] ?? '');
                $row = DataNormalizer::row($key, $value, 'manual', false);
                $row['label'] = $label;
                $row['included'] = true;
                $doc['fields'][] = $row;
                $doc = DataNormalizer::sortFields($doc);
                $result['key'] = $key;
                break;

            case 'move_field':
                $i = self::field($doc, $input);
                $row = $doc['fields'][$i];
                $section = FieldDictionary::section($row['key']);
                $before = $input['before'] ?? null;
                array_splice($doc['fields'], $i, 1);
                $at = null;
                if ($before !== null && $before !== '') {
                    $b = self::field($doc, ['key' => $before], false);
                    if ($b === null || FieldDictionary::section($doc['fields'][$b]['key']) !== $section) {
                        throw new EditorException('This row can only be moved within its own group.', 'stale', 'before');
                    }
                    $at = $b;
                } else {
                    foreach ($doc['fields'] as $n => $other) {
                        if (FieldDictionary::section($other['key']) === $section) {
                            $at = $n + 1;
                        }
                    }
                }
                array_splice($doc['fields'], $at ?? count($doc['fields']), 0, [$row]);
                $doc['field_order'] = 'manual';
                $doc = DataNormalizer::sortFields($doc);
                break;

            case 'list_set':
                $l = self::listKey($doc, $input);
                $r = self::rowIndex($doc, $l, $input);
                $column = is_string($input['column'] ?? null) ? $input['column'] : '';
                $value = FieldValidator::cell($l, $column, $input['value'] ?? '');
                $row = &$doc['lists'][$l][$r];
                if ($value !== (string) ($row[$column] ?? '')) {
                    $row[$column] = $value;
                    $row['source'] = 'manual';
                }
                $row['needs_review'] = false;
                unset($row);
                self::clearListFlag($doc, $l);
                $result['value'] = $value;
                break;

            case 'list_add':
                $l = self::listKey($doc, $input);
                if (count($doc['lists'][$l]) >= self::MAX_LIST_ROWS) {
                    throw new EditorException('A list can have up to ' . self::MAX_LIST_ROWS . ' rows.', 'invalid', 'list');
                }
                $row = [];
                foreach (FieldDictionary::columns($l) as $column => $type) {
                    $row[$column] = '';
                }
                $rid = self::newRid($doc['lists'][$l]);
                $row += ['source' => 'manual', 'needs_review' => false, 'removed' => false, 'included' => true, 'rid' => $rid];
                $doc['lists'][$l][] = $row;
                self::clearListFlag($doc, $l);
                $result['rid'] = $rid;
                break;

            case 'list_include':
                $l = self::listKey($doc, $input);
                $r = self::rowIndex($doc, $l, $input);
                $doc['lists'][$l][$r]['included'] = self::bool($input['included'] ?? null);
                break;

            case 'list_remove':
            case 'list_restore':
                $l = self::listKey($doc, $input);
                $r = self::rowIndex($doc, $l, $input);
                $doc['lists'][$l][$r]['removed'] = $action === 'list_remove';
                if ($action === 'list_restore') {
                    $doc['lists'][$l][$r]['included'] = true;
                }
                break;

            case 'list_move':
                $l = self::listKey($doc, $input);
                $r = self::rowIndex($doc, $l, $input);
                $rows = $doc['lists'][$l];
                $row = $rows[$r];
                array_splice($rows, $r, 1);
                $at = count($rows);
                $before = $input['before'] ?? null;
                if ($before !== null && $before !== '') {
                    $at = null;
                    foreach ($rows as $n => $other) {
                        if ($other['rid'] === $before) {
                            $at = $n;
                            break;
                        }
                    }
                    if ($at === null) {
                        throw new EditorException('This item no longer exists. Please reload the page.', 'stale', 'before');
                    }
                }
                array_splice($rows, $at, 0, [$row]);
                $doc['lists'][$l] = $rows;
                break;

            case 'list_confirm':
                $l = self::listKey($doc, $input);
                if (is_string($input['rid'] ?? null) && $input['rid'] !== '') {
                    $r = self::rowIndex($doc, $l, $input);
                    $doc['lists'][$l][$r]['needs_review'] = false;
                } else {
                    // derive() writes the plugin-count note again while the mismatch exists
                    $doc['list_meta'][$l]['needs_review'] = false;
                    $doc['list_meta'][$l]['note'] = '';
                    if ($l === 'plugin_updates') {
                        $doc['list_meta'][$l]['ack'] = DataNormalizer::pluginCountSignature($doc);
                    }
                }
                break;
        }

        return ['doc' => DataNormalizer::derive($doc), 'result' => $result];
    }

    /**
     * Reset: the stored original, with the user's template / palette / profile choices kept.
     *
     * @param array<string, mixed> $original
     * @param array<string, mixed> $working
     * @return array<string, mixed>
     */
    public static function reset(array $original, array $working): array
    {
        foreach (['template', 'palette', 'palette_chosen', 'custom_colors', 'blueprint_id', 'profile', 'logo_mode', 'profile_hint_dismissed', 'field_mappings'] as $keep) {
            if (array_key_exists($keep, $working)) {
                $original[$keep] = $working[$keep];
            }
        }

        return $original;
    }

    /**
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $input
     */
    private static function field(array $doc, array $input, bool $required = true): ?int
    {
        $key = $input['key'] ?? null;
        $i = is_string($key) ? DataNormalizer::find($doc, $key) : null;
        if ($i === null && $required) {
            throw new EditorException('This field no longer exists. Please reload the page.', 'stale', 'key');
        }

        return $i;
    }

    /**
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $input
     */
    private static function listKey(array $doc, array $input): string
    {
        $key = $input['list'] ?? null;
        if (!is_string($key) || !FieldDictionary::isList($key) || FieldDictionary::isDerived($key) || !isset($doc['lists'][$key])) {
            throw new EditorException('This list no longer exists. Please reload the page.', 'stale', 'list');
        }

        return $key;
    }

    /**
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $input
     */
    private static function rowIndex(array $doc, string $list, array $input, bool $required = true): ?int
    {
        $rid = $input['rid'] ?? null;
        if (is_string($rid)) {
            foreach ($doc['lists'][$list] as $n => $row) {
                if ($row['rid'] === $rid) {
                    return $n;
                }
            }
        }
        if ($required) {
            throw new EditorException('This row no longer exists. Please reload the page.', 'stale', 'rid');
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function newRid(array $rows): string
    {
        $taken = array_flip(array_map(static fn (array $r): string => (string) $r['rid'], $rows));
        do {
            $rid = 'r' . bin2hex(random_bytes(4));
        } while (isset($taken[$rid]));

        return $rid;
    }

    /**
     * Editing a list answers "Not found in the PDF" — the plugin-count check is re-run by derive().
     *
     * @param array<string, mixed> $doc
     */
    private static function clearListFlag(array &$doc, string $list): void
    {
        if (!empty($doc['list_meta'][$list]['needs_review'])) {
            $doc['list_meta'][$list]['needs_review'] = false;
            $doc['list_meta'][$list]['note'] = '';
        }
    }

    private static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, [1, '1', 'true', 'on', 'yes'], true)) {
            return true;
        }
        if (in_array($value, [0, '0', 'false', 'off', 'no', ''], true)) {
            return false;
        }
        throw new EditorException('Please choose yes or no.', 'invalid', 'included');
    }
}
