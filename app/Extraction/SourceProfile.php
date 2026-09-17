<?php

declare(strict_types=1);

namespace App\Extraction;

use App\Data\FieldDictionary;

/**
 * A validated source profile (source-profiles/*.json) — extraction rules for one PDF family.
 * Only the rule types of Part D §4 are accepted; anything else makes the profile invalid.
 */
final class SourceProfile
{
    public const FIELD_TYPES = ['cell_after_label', 'cell_above_label', 'line_after', 'regex', 'last_line', 'contains'];
    public const LIST_TYPES = ['bullets', 'row_regex', 'values_above_labels', 'fixed_rows'];

    /**
     * @param array{all_of: list<string>, any_of: list<string>} $fingerprint
     * @param list<array{id: string, anchor: ?string, end_anchor: ?string, page_start: bool}> $sections
     * @param list<array<string, mixed>> $fields
     * @param list<array<string, mixed>> $lists
     */
    private function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $fingerprint,
        public readonly array $sections,
        public readonly array $fields,
        public readonly array $lists,
    ) {
    }

    /**
     * @param array<string, mixed> $json
     * @throws ProfileException
     */
    public static function fromArray(array $json, ?string $expectedId = null): self
    {
        $id = $json['id'] ?? null;
        if (!is_string($id) || !preg_match('/^[a-z0-9][a-z0-9-]{0,60}$/', $id)) {
            throw new ProfileException('Profile id is missing or invalid.');
        }
        if ($expectedId !== null && $id !== $expectedId) {
            throw new ProfileException("Profile id \"$id\" does not match its file name.");
        }
        $name = is_string($json['name'] ?? null) && trim($json['name']) !== '' ? trim($json['name']) : $id;

        // Fingerprint
        $fp = $json['fingerprint'] ?? null;
        if (!is_array($fp)) {
            throw new ProfileException('Fingerprint is missing.');
        }
        $fingerprint = ['all_of' => self::stringList($fp['all_of'] ?? [], 'fingerprint.all_of'), 'any_of' => self::stringList($fp['any_of'] ?? [], 'fingerprint.any_of')];
        if ($fingerprint['all_of'] === [] && $fingerprint['any_of'] === []) {
            throw new ProfileException('Fingerprint needs all_of or any_of phrases.');
        }

        // Sections
        $sections = [];
        $seen = [];
        foreach (self::arrayList($json['sections'] ?? null, 'sections') as $i => $section) {
            $sid = $section['id'] ?? null;
            if (!is_string($sid) || !preg_match('/^[a-z0-9_]{1,40}$/', $sid) || isset($seen[$sid])) {
                throw new ProfileException("Section #$i has a missing, invalid or duplicate id.");
            }
            $seen[$sid] = true;
            $anchor = $section['anchor'] ?? null;
            if ($anchor === null && $i !== 0) {
                throw new ProfileException("Only the first section may have a null anchor ($sid).");
            }
            if ($anchor !== null) {
                self::regex($anchor, "sections.$sid.anchor");
            }
            $end = $section['end_anchor'] ?? null;
            if ($end !== null) {
                self::regex($end, "sections.$sid.end_anchor");
            }
            $pageStart = $section['page_start'] ?? false;
            if (!is_bool($pageStart)) {
                throw new ProfileException("sections.$sid.page_start must be true or false.");
            }
            $sections[] = ['id' => $sid, 'anchor' => $anchor, 'end_anchor' => $end, 'page_start' => $pageStart];
        }
        if ($sections === []) {
            throw new ProfileException('A profile needs at least one section.');
        }

        // Field rules
        $fields = [];
        foreach (self::arrayList($json['fields'] ?? [], 'fields') as $i => $rule) {
            $fields[] = self::fieldRule($rule, "fields[$i]", $seen);
        }

        // List rules
        $lists = [];
        foreach (self::arrayList($json['lists'] ?? [], 'lists') as $i => $rule) {
            $lists[] = self::listRule($rule, "lists[$i]", $seen);
        }

        return new self($id, $name, $fingerprint, $sections, $fields, $lists);
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, bool> $sections
     * @return array<string, mixed>
     */
    private static function fieldRule(array $rule, string $where, array $sections): array
    {
        $type = $rule['type'] ?? null;
        if (!in_array($type, self::FIELD_TYPES, true)) {
            throw new ProfileException("$where: unknown rule type.");
        }
        self::sectionRef($rule, $where, $sections);
        self::subRange($rule, $where);
        $transforms = self::transforms($rule['transform'] ?? [], $where, false);

        $keys = [];
        switch ($type) {
            case 'cell_after_label':
            case 'cell_above_label':
                self::nonEmptyString($rule['label'] ?? null, "$where.label");
                $keys[] = $rule['key'] ?? null;
                break;
            case 'line_after':
                self::regex($rule['match'] ?? null, "$where.match");
                $keys[] = $rule['key'] ?? null;
                break;
            case 'regex':
                self::regex($rule['pattern'] ?? null, "$where.pattern");
                $groups = $rule['groups'] ?? null;
                if (!is_array($groups) || $groups === []) {
                    throw new ProfileException("$where.groups must map keys to group numbers.");
                }
                foreach ($groups as $key => $group) {
                    if (!is_int($group) || $group < 1 || $group > 20) {
                        throw new ProfileException("$where.groups.$key must be a group number.");
                    }
                    $keys[] = $key;
                }
                break;
            case 'last_line':
                $keys[] = $rule['key'] ?? null;
                break;
            case 'contains':
                self::nonEmptyString($rule['text'] ?? null, "$where.text");
                if (!is_string($rule['value_if_found'] ?? null) || !is_string($rule['value_if_missing'] ?? null)) {
                    throw new ProfileException("$where needs value_if_found and value_if_missing.");
                }
                $keys[] = $rule['key'] ?? null;
                break;
        }

        foreach ($keys as $key) {
            if (!is_string($key) || !FieldDictionary::isScalar($key) || !FieldDictionary::isExtractable($key)) {
                throw new ProfileException("$where writes an unknown or non-extractable key.");
            }
        }

        $rule['transform'] = $transforms;

        return $rule;
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, bool> $sections
     * @return array<string, mixed>
     */
    private static function listRule(array $rule, string $where, array $sections): array
    {
        $type = $rule['type'] ?? null;
        if (!in_array($type, self::LIST_TYPES, true)) {
            throw new ProfileException("$where: unknown list rule type.");
        }
        self::sectionRef($rule, $where, $sections);
        self::subRange($rule, $where);

        $key = $rule['key'] ?? null;
        if (!is_string($key) || !FieldDictionary::isList($key) || FieldDictionary::isDerived($key)) {
            throw new ProfileException("$where writes an unknown list key.");
        }
        $allowed = array_keys(FieldDictionary::columns($key));

        $columns = $type === 'bullets' ? [$rule['column'] ?? null] : ($rule['columns'] ?? null);
        if (!is_array($columns) || $columns === []) {
            throw new ProfileException("$where needs columns.");
        }
        foreach ($columns as $column) {
            if (!is_string($column) || !in_array($column, $allowed, true)) {
                throw new ProfileException("$where uses a column that $key does not have.");
            }
        }

        switch ($type) {
            case 'row_regex':
                self::regex($rule['pattern'] ?? null, "$where.pattern");
                break;
            case 'values_above_labels':
                if (count($columns) !== 2) {
                    throw new ProfileException("$where needs exactly two columns: value, label.");
                }
                foreach (self::arrayList($rule['items'] ?? null, "$where.items") as $j => $item) {
                    self::nonEmptyString($item['label'] ?? null, "$where.items[$j].label");
                }
                break;
            case 'fixed_rows':
                if (count($columns) !== 2) {
                    throw new ProfileException("$where needs exactly two columns: label, value.");
                }
                foreach (self::arrayList($rule['rows'] ?? null, "$where.rows") as $j => $row) {
                    self::nonEmptyString($row['label'] ?? null, "$where.rows[$j].label");
                    $from = $row['from_key'] ?? null;
                    $default = $row['icon_default'] ?? null;
                    if (($from === null) === ($default === null)) {
                        throw new ProfileException("$where.rows[$j] needs exactly one of from_key / icon_default.");
                    }
                    if ($from !== null && (!is_string($from) || !FieldDictionary::isScalar($from))) {
                        throw new ProfileException("$where.rows[$j].from_key is not a known key.");
                    }
                    if ($default !== null && !is_string($default)) {
                        throw new ProfileException("$where.rows[$j].icon_default must be text.");
                    }
                }
                break;
        }

        $rule['columns'] = array_values($columns);
        $rule['transform'] = self::transforms($rule['transform'] ?? [], $where, true);

        return $rule;
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, bool> $sections
     */
    private static function sectionRef(array $rule, string $where, array $sections): void
    {
        $section = $rule['section'] ?? null;
        if (!is_string($section) || !isset($sections[$section])) {
            throw new ProfileException("$where refers to an unknown section.");
        }
    }

    /**
     * @return list<string>
     */
    private static function transforms(mixed $value, string $where, bool $inList): array
    {
        $list = self::stringList($value, "$where.transform");
        foreach ($list as $spec) {
            if (!Transforms::isValid($spec, $inList)) {
                throw new ProfileException("$where: unknown transform \"$spec\".");
            }
        }

        return $list;
    }

    private static function regex(mixed $value, string $where): void
    {
        if (!is_string($value) || Pattern::compile($value, false) === null) {
            throw new ProfileException("$where is not a valid regular expression.");
        }
    }

    private static function nonEmptyString(mixed $value, string $where): void
    {
        if (!is_string($value) || trim($value) === '') {
            throw new ProfileException("$where must be non-empty text.");
        }
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value, string $where): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new ProfileException("$where must be a list.");
        }
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new ProfileException("$where must contain text only.");
            }
        }

        return $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function arrayList(mixed $value, string $where): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new ProfileException("$where must be a list.");
        }
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new ProfileException("$where must contain objects.");
            }
        }

        return $value;
    }

    /**
     * Optional "after" / "before" line patterns narrow a rule to part of its section
     * (e.g. plugin rows after "Last plugin updates" and before "Last theme updates").
     *
     * @param array<string, mixed> $rule
     */
    private static function subRange(array $rule, string $where): void
    {
        foreach (['after', 'before'] as $k) {
            if (array_key_exists($k, $rule)) {
                self::regex($rule[$k], "$where.$k");
            }
        }
    }
}
