<?php

declare(strict_types=1);

namespace App\Extraction;

use App\Pdf\Extract\CleanCell;
use App\Pdf\Extract\CleanLine;

/**
 * Applies a SourceProfile to cleaned lines (Part D §4 "Matching rules").
 *
 *  - sections are located independently (any order, missing sections allowed); "page_start"
 *    anchors must be the first line of a page
 *  - labels/anchors compare case-insensitively on the whole cell / whole line
 *  - a rule only looks inside its own section
 *  - the first occurrence of a label that has a value wins
 *  - a rule that finds nothing leaves its key empty with needs_review
 */
final class ProfileRuleEngine
{
    private const BULLET = '/^(?:[•▪]\s*|[\-–*]\s+)(\S.*)$/u';

    /** @var list<CleanLine> */
    private array $lines = [];

    /** @var array<string, array{0: int, 1: int}|null> */
    private array $ranges = [];

    /** @var array<int, bool> */
    private array $consumed = [];

    /**
     * @param list<CleanLine> $lines
     */
    public function apply(SourceProfile $profile, array $lines): ProfileResult
    {
        $this->lines = array_values($lines);
        $this->consumed = [];
        $this->ranges = $this->locateSections($profile);

        $fields = [];
        foreach ($profile->fields as $rule) {
            foreach ($this->fieldRule($rule) as $key => $result) {
                // First rule that produced a value for a key wins.
                if (!isset($fields[$key]) || ($fields[$key]['value'] === '' && $result['value'] !== '')) {
                    $fields[$key] = $result;
                }
            }
        }

        $lists = [];
        foreach ($profile->lists as $rule) {
            $lists[(string) $rule['key']] = $this->listRule($rule, $fields) + ['optional' => ($rule['optional'] ?? false) === true];
        }

        return new ProfileResult($profile->id, $fields, $lists, $this->consumed, $this->ranges);
    }

    /**
     * Sections are located independently of their declared order, so a report that leaves a
     * section out (or orders them differently) still works. With "page_start" the anchor must be
     * the first line of a page — sub-headings with the same words elsewhere are ignored.
     * A section ends where the next located section starts (or at its end_anchor).
     *
     * @return array<string, array{0: int, 1: int}|null> section id => [start, end) line indexes
     */
    private function locateSections(SourceProfile $profile): array
    {
        $count = count($this->lines);
        $starts = [];

        foreach ($profile->sections as $section) {
            $start = null;
            if ($section['anchor'] === null) {
                $start = 0;
            } else {
                $pattern = Pattern::compile((string) $section['anchor'], true);
                for ($i = 0; $i < $count && $pattern !== null; $i++) {
                    if (($section['page_start'] ?? false) && $i > 0 && $this->lines[$i - 1]->page === $this->lines[$i]->page) {
                        continue;
                    }
                    if (Pattern::match($pattern, trim($this->lines[$i]->plain()))) {
                        $start = $i;
                        break;
                    }
                }
            }
            $starts[$section['id']] = $start;
        }

        $ranges = [];
        foreach ($profile->sections as $section) {
            $start = $starts[$section['id']];
            if ($start === null) {
                $ranges[$section['id']] = null;
                continue;
            }
            $end = $count;
            foreach ($starts as $id => $other) {
                if ($id !== $section['id'] && $other !== null && $other > $start && $other < $end) {
                    $end = (int) $other;
                }
            }
            if ($section['end_anchor'] !== null) {
                $pattern = Pattern::compile((string) $section['end_anchor'], true);
                for ($i = $start + 1; $i < $end && $pattern !== null; $i++) {
                    if (Pattern::match($pattern, trim($this->lines[$i]->plain()))) {
                        $end = $i;
                        break;
                    }
                }
            }
            $ranges[$section['id']] = [$start, max($start, $end)];
        }

        return $ranges;
    }

    /**
     * The rule's section, narrowed by its optional "after" / "before" line patterns.
     * "after" that is not found → no range (the part is missing from this report).
     *
     * @param array<string, mixed> $rule
     * @return array{0: int, 1: int}|null
     */
    private function ruleRange(array $rule): ?array
    {
        $range = $this->ranges[(string) $rule['section']] ?? null;
        if ($range === null) {
            return null;
        }
        [$start, $end] = $range;
        if (isset($rule['after'])) {
            $pattern = Pattern::compile((string) $rule['after'], true);
            $found = null;
            for ($i = $start; $i < $end && $pattern !== null; $i++) {
                if (Pattern::match($pattern, trim($this->lines[$i]->plain()))) {
                    $found = $i + 1;
                    break;
                }
            }
            if ($found === null) {
                return null;
            }
            $start = $found;
        }
        if (isset($rule['before'])) {
            $pattern = Pattern::compile((string) $rule['before'], true);
            for ($i = $start; $i < $end && $pattern !== null; $i++) {
                if (Pattern::match($pattern, trim($this->lines[$i]->plain()))) {
                    $end = $i;
                    break;
                }
            }
        }

        return [$start, max($start, $end)];
    }

    /**
     * @param array<string, mixed> $rule
     * @return array<string, array{value: string, found: bool, needs_review: bool, page: ?int}>
     */
    private function fieldRule(array $rule): array
    {
        $range = $this->ruleRange($rule);
        $type = (string) $rule['type'];
        $transforms = $rule['transform'] ?? [];

        if ($type === 'regex') {
            $keys = array_keys($rule['groups']);
            $out = array_fill_keys($keys, self::missing());
            if ($range === null) {
                return $out;
            }
            $pattern = Pattern::compile((string) $rule['pattern'], false);
            foreach ($this->indexes($range) as $i) {
                if ($pattern !== null && Pattern::match($pattern, $this->lines[$i]->text, $m)) {
                    $this->consumed[$i] = true;
                    foreach ($rule['groups'] as $key => $group) {
                        $out[$key] = $this->found(trim((string) ($m[$group] ?? '')), $transforms, $this->lines[$i]->page);
                    }
                    break;
                }
            }

            return $out;
        }

        $key = (string) $rule['key'];
        if ($range === null) {
            return [$key => $type === 'contains' ? $this->missingWithValue((string) $rule['value_if_missing']) : self::missing()];
        }

        switch ($type) {
            case 'cell_after_label':
                foreach ($this->labelCells($range, (string) $rule['label']) as [$i, $c]) {
                    $value = $this->nextCell($this->lines[$i], $c);
                    if ($value !== null) {
                        $this->consumed[$i] = true;

                        return [$key => $this->found($value, $transforms, $this->lines[$i]->page)];
                    }
                }
                break;

            case 'cell_above_label':
                foreach ($this->labelCells($range, (string) $rule['label']) as [$i, $c]) {
                    [$value, $above] = $this->cellAbove($range, $i, $this->lines[$i]->cells[$c]);
                    if ($value !== null) {
                        $this->consumed[$i] = true;
                        $this->consumed[$above] = true;

                        return [$key => $this->found($value, $transforms, $this->lines[$i]->page)];
                    }
                }
                break;

            case 'line_after':
                $pattern = Pattern::compile((string) $rule['match'], false);
                foreach ($this->indexes($range) as $i) {
                    if ($pattern !== null && Pattern::match($pattern, $this->lines[$i]->text)) {
                        if ($i + 1 < $range[1]) {
                            $this->consumed[$i] = true;
                            $this->consumed[$i + 1] = true;

                            return [$key => $this->found(trim($this->lines[$i + 1]->plain()), $transforms, $this->lines[$i + 1]->page)];
                        }
                        break;
                    }
                }
                break;

            case 'last_line':
                if ($range[1] > $range[0]) {
                    $i = $range[1] - 1;
                    $this->consumed[$i] = true;

                    return [$key => $this->found(trim($this->lines[$i]->plain()), $transforms, $this->lines[$i]->page)];
                }
                break;

            case 'contains':
                $needle = SourceProfileManager::normalize((string) $rule['text']);
                foreach ($this->indexes($range) as $i) {
                    if (str_contains(SourceProfileManager::normalize($this->lines[$i]->plain()), $needle)) {
                        $this->consumed[$i] = true;

                        return [$key => $this->found((string) $rule['value_if_found'], [], $this->lines[$i]->page)];
                    }
                }

                return [$key => $this->missingWithValue((string) $rule['value_if_missing'])];
        }

        return [$key => self::missing()];
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, array{value: string, found: bool, needs_review: bool, page: ?int}> $fields
     * @return array{rows: list<array<string, mixed>>, found: bool}
     */
    private function listRule(array $rule, array $fields): array
    {
        $range = $this->ruleRange($rule);
        $columns = $rule['columns'];
        $type = (string) $rule['type'];
        $rows = [];

        if ($range === null && $type !== 'fixed_rows') {
            return ['rows' => [], 'found' => false];
        }

        switch ($type) {
            case 'bullets':
                $column = $columns[0];
                $last = null;
                foreach ($this->indexes($range) as $i) {
                    $line = $this->lines[$i];
                    $text = trim($line->plain());
                    if (preg_match(self::BULLET, $text, $m)) {
                        $rows[] = [$column => trim($m[1]), 'source' => 'profile_rule', 'needs_review' => false];
                        $this->consumed[$i] = true;
                        $last = $line;
                        continue;
                    }
                    // Wrapped bullet text: indented, directly below, same page.
                    if (
                        $last !== null && $line->page === $last->page
                        && $line->cells[0]->x > $last->cells[0]->x + 1.0
                        && ($line->y - $last->y) <= 2.2 * max(1.0, $last->cells[0]->fontSize)
                    ) {
                        $rows[count($rows) - 1][$column] .= ' ' . $text;
                        $this->consumed[$i] = true;
                        $last = $line;
                        continue;
                    }
                    $last = null;
                }
                break;

            case 'row_regex':
                $pattern = Pattern::compile((string) $rule['pattern'], false);
                foreach ($this->indexes($range) as $i) {
                    if ($pattern !== null && Pattern::match($pattern, $this->lines[$i]->text, $m)) {
                        $row = [];
                        foreach ($columns as $n => $column) {
                            $row[$column] = trim((string) ($m[$n + 1] ?? ''));
                        }
                        $row['source'] = 'profile_rule';
                        $row['needs_review'] = in_array('', array_intersect_key($row, array_flip($columns)), true);
                        $rows[] = $row;
                        $this->consumed[$i] = true;
                    }
                }
                break;

            case 'values_above_labels':
                [$valueColumn, $labelColumn] = $columns;
                $any = false;
                foreach ($rule['items'] as $item) {
                    $value = null;
                    foreach ($this->labelCells($range, (string) $item['label']) as [$i, $c]) {
                        [$value, $above] = $this->cellAbove($range, $i, $this->lines[$i]->cells[$c]);
                        if ($value !== null) {
                            $this->consumed[$i] = true;
                            $this->consumed[$above] = true;
                            break;
                        }
                    }
                    $any = $any || $value !== null;
                    $rows[] = [
                        $valueColumn => $value ?? '',
                        $labelColumn => (string) ($item['display_label'] ?? $item['label']),
                        'source' => 'profile_rule',
                        'needs_review' => $value === null,
                    ];
                }
                if (!$any) {
                    return ['rows' => [], 'found' => false];
                }
                break;

            case 'fixed_rows':
                [$labelColumn, $valueColumn] = $columns;
                foreach ($rule['rows'] as $def) {
                    $display = (string) ($def['display_label'] ?? $def['label']);
                    if (isset($def['from_key'])) {
                        $field = $fields[$def['from_key']] ?? self::missing();
                        $rows[] = [
                            $labelColumn => $display,
                            $valueColumn => $field['value'],
                            'source' => 'profile_rule',
                            'needs_review' => $field['value'] === '' || $field['needs_review'],
                        ];
                        if ($range !== null) {
                            foreach ($this->labelCells($range, (string) $def['label']) as [$i]) {
                                $this->consumed[$i] = true;
                            }
                        }
                        continue;
                    }

                    $labelSeen = false;
                    $value = null;
                    if ($range !== null) {
                        foreach ($this->labelCells($range, (string) $def['label']) as [$i, $c]) {
                            $labelSeen = true;
                            $this->consumed[$i] = true;
                            $value = $this->nextCell($this->lines[$i], $c);
                            if ($value !== null) {
                                break;
                            }
                        }
                    }

                    if ($value !== null) {
                        $rows[] = [$labelColumn => $display, $valueColumn => $value, 'source' => 'profile_rule', 'needs_review' => false];
                    } elseif ($labelSeen) {
                        // Icon-only value (a green check mark next to the label): the label is in the PDF and
                        // the report only ever prints the check for a passed item, so this is PDF data.
                        $rows[] = [$labelColumn => $display, $valueColumn => (string) $def['icon_default'], 'source' => 'profile_rule', 'needs_review' => false];
                    } else {
                        $rows[] = [$labelColumn => $display, $valueColumn => '', 'source' => 'profile_rule', 'needs_review' => true];
                    }
                }

                return ['rows' => Transforms::applyToRows($rows, $rule['transform'] ?? []), 'found' => $range !== null];
        }

        if ($rows === []) {
            return ['rows' => [], 'found' => false];
        }

        return ['rows' => Transforms::applyToRows($rows, $rule['transform'] ?? []), 'found' => true];
    }

    /**
     * @param array{0: int, 1: int} $range
     * @return iterable<int>
     */
    private function indexes(array $range): iterable
    {
        for ($i = $range[0]; $i < $range[1]; $i++) {
            yield $i;
        }
    }

    /**
     * Every (line index, cell index) whose whole cell equals the label.
     *
     * @param array{0: int, 1: int} $range
     * @return list<array{0: int, 1: int}>
     */
    private function labelCells(array $range, string $label): array
    {
        $want = self::normLabel($label);
        $hits = [];
        foreach ($this->indexes($range) as $i) {
            foreach ($this->lines[$i]->cells as $c => $cell) {
                if (self::normLabel($cell->text) === $want) {
                    $hits[] = [$i, $c];
                }
            }
        }

        return $hits;
    }

    private function nextCell(CleanLine $line, int $labelIndex): ?string
    {
        $count = count($line->cells);
        for ($j = $labelIndex + 1; $j < $count; $j++) {
            $text = trim($line->cells[$j]->text);
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    /**
     * The cell on the nearest line above (same section and page) whose x-range overlaps the label.
     *
     * @param array{0: int, 1: int} $range
     * @return array{0: ?string, 1: int}
     */
    private function cellAbove(array $range, int $lineIndex, CleanCell $label): array
    {
        $above = $lineIndex - 1;
        if ($above < $range[0] || $this->lines[$above]->page !== $this->lines[$lineIndex]->page) {
            return [null, $above];
        }

        $best = null;
        $bestOverlap = -INF;
        $labelCenter = ($label->x + $label->xEnd) / 2;
        foreach ($this->lines[$above]->cells as $cell) {
            if (!$cell->overlaps($label) || trim($cell->text) === '') {
                continue;
            }
            // Prefer the cell whose center is closest to the label's center.
            $score = -abs(($cell->x + $cell->xEnd) / 2 - $labelCenter);
            if ($score > $bestOverlap) {
                $bestOverlap = $score;
                $best = trim($cell->text);
            }
        }

        return [$best, $above];
    }

    /**
     * @param list<string> $transforms
     * @return array{value: string, found: bool, needs_review: bool, page: ?int}
     */
    private function found(string $raw, array $transforms, ?int $page): array
    {
        $value = $raw;
        $review = false;
        foreach ($transforms as $spec) {
            [$name] = Transforms::split($spec);
            $converted = Transforms::apply($name, $value);
            if ($converted === null) {
                // Could not convert — keep what the PDF says and ask the user.
                $review = true;
                break;
            }
            $value = $converted;
        }

        return ['value' => $value, 'found' => true, 'needs_review' => $review, 'page' => $page];
    }

    /**
     * @return array{value: string, found: bool, needs_review: bool, page: ?int}
     */
    private function missingWithValue(string $value): array
    {
        return ['value' => $value, 'found' => false, 'needs_review' => $value === '', 'page' => null];
    }

    /**
     * @return array{value: string, found: bool, needs_review: bool, page: ?int}
     */
    private static function missing(): array
    {
        return ['value' => '', 'found' => false, 'needs_review' => true, 'page' => null];
    }

    private static function normLabel(string $text): string
    {
        return SourceProfileManager::normalize(rtrim(trim($text), ':'));
    }
}
