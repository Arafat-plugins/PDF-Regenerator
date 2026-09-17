<?php

declare(strict_types=1);

namespace App\Extraction;

use App\Data\FieldDictionary;
use App\Pdf\Extract\CleanLine;

/**
 * Generic heuristic detectors (Part D §4), applied after profile rules to the lines
 * no rule used. Results never overwrite profile results. Heuristic by nature — low
 * confidence values are flagged for review.
 */
final class GenericDetector
{
    /** label synonyms => canonical key */
    private const SYNONYMS = [
        'client_name' => ['client', 'client name', 'customer', 'customer name', 'bill to', 'billed to', 'prepared for', 'attention', 'contact name'],
        'address' => ['address', 'billing address', 'street address', 'location'],
        'description' => ['description', 'details', 'notes', 'note', 'summary'],
        'status' => ['status', 'state', 'payment status'],
        'email' => ['email', 'e-mail', 'email address', 'mail'],
        'phone' => ['phone', 'telephone', 'tel', 'mobile', 'cell', 'phone number', 'contact number'],
        'website_url' => ['website', 'web', 'url', 'site', 'homepage'],
        'date' => ['date', 'report date', 'invoice date', 'issue date', 'issued', 'issued on', 'dated'],
        'amount' => ['amount', 'subtotal', 'sub total', 'price'],
        'total' => ['total', 'grand total', 'amount due', 'total due', 'balance due', 'total amount'],
        'title' => ['title', 'subject', 're'],
        'subtitle' => ['subtitle'],
        // website maintenance / analytics reports from other tools
        'total_sessions' => ['sessions', 'total sessions', 'visits', 'total visits', 'total traffic'],
        'organic_traffic' => ['organic traffic', 'organic sessions', 'organic search', 'organic visits'],
        'unique_visitors' => ['unique visitors', 'users', 'visitors', 'unique users', 'total users'],
        'pages_per_session' => ['pages per session', 'page views per session', 'pages session', 'pageviews per session'],
        'uptime_percent' => ['uptime', 'uptime rate', 'availability', 'uptime percentage'],
        'pagespeed_score' => ['pagespeed', 'google pagespeed', 'pagespeed score', 'google pagespeed score', 'performance score', 'page speed', 'page speed score'],
        'response_time' => ['response time', 'server response time', 'average response time'],
        'fcp' => ['first contentful paint', 'first contentful paint fcp', 'fcp'],
        'lcp' => ['largest contentful paint', 'largest contentful paint lcp', 'lcp'],
        'tti' => ['time to interactive', 'time to interactive tti', 'tti'],
        'speed_index' => ['speed index'],
        'cls' => ['cumulative layout shift', 'cumulative layout shift cls', 'cls', 'layout shift'],
        'tbt' => ['total blocking time', 'total blocking time tbt', 'tbt', 'blocking time'],
        'plugins_updated_count' => ['plugins updated', 'number of plugins updated', 'plugin updates', 'updated plugins'],
        'themes_updated_count' => ['themes updated', 'number of themes updated', 'theme updates', 'updated themes'],
        'backups_count' => ['backups', 'number of backups', 'backups created', 'backup count'],
        'backup_frequency' => ['backup frequency', 'backup schedule'],
        'last_backup_date' => ['last backup', 'last backup date', 'latest backup'],
        'wp_version' => ['wordpress version', 'wp version', 'wordpress core version'],
        'php_version' => ['php version'],
        'custom_tasks_count' => ['custom tasks', 'number of tasks', 'custom work tasks', 'custom works'],
    ];

    /** Value clean-up per key (same transforms as the source profiles). */
    private const KEY_TRANSFORMS = [
        'total_sessions' => ['int'], 'organic_traffic' => ['int'], 'unique_visitors' => ['int'], 'pages_per_session' => ['decimal1'],
        'uptime_percent' => ['strip_trailing_zero_percent'], 'pagespeed_score' => ['score_0_100'],
        'fcp' => ['seconds1'], 'lcp' => ['seconds1'], 'tti' => ['seconds1'], 'speed_index' => ['seconds1'], 'cls' => ['decimal3'],
        'plugins_updated_count' => ['int'], 'themes_updated_count' => ['int'], 'backups_count' => ['int'], 'custom_tasks_count' => ['int'],
        'backup_frequency' => ['title_case'], 'last_backup_date' => ['date_iso'],
    ];

    private const MONTH = '(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)';
    private const MONEY = '/(?:[$€£¥₹৳]\s?|\b(?:USD|EUR|GBP|BDT|INR|AUD|CAD)\s?)-?\d{1,3}(?:[,\s]\d{3})*(?:\.\d{1,2})?|-?\d{1,3}(?:,\d{3})*(?:\.\d{2})\s?(?:USD|EUR|GBP|BDT|INR)\b/u';
    private const EMAIL = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,24}/';
    private const URL = '~\bhttps?://[^\s<>"\']{3,300}|\bwww\.[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)+[^\s<>"\']*~i';
    private const PHONE = '/(?<![\w.+])(?:\+\d{1,3}[\s.\-]?)?\(?\d{2,4}\)?[\s.\-]?\d{3,4}[\s.\-]?\d{3,5}(?![\w.%])/';

    /**
     * @param list<CleanLine> $lines
     * @param array<int, bool> $consumed line indexes already used by profile rules
     * @param array<string, bool> $filled keys that already have a value
     * @param bool $profileMatched when true, unnamed rows are NOT turned into custom fields
     */
    public function detect(array $lines, array $consumed, array $filled, bool $profileMatched): GenericResult
    {
        $fields = [];
        $used = $consumed;
        $customTaken = [];

        $set = function (string $key, string $value, string $source, bool $review, ?int $page, ?string $label = null) use (&$fields, $filled): void {
            if ($value === '' || isset($filled[$key]) || isset($fields[$key])) {
                return;
            }
            $fields[$key] = ['value' => $value, 'source' => $source, 'needs_review' => $review, 'page' => $page, 'label' => $label];
        };

        // 1. Key:value lines
        foreach ($lines as $i => $line) {
            if (isset($used[$i])) {
                continue;
            }
            $pairs = $this->keyValue($line);
            if ($pairs === null && !$profileMatched) {
                $pairs = $this->cellPairs($line);
            }
            foreach ($pairs ?? [] as [$label, $value]) {
                $key = $this->keyForLabel($label);
                if ($key !== null) {
                    $normalized = $this->normalizeValue($key, $value);
                    $set($key, $normalized ?? $value, 'kv', $normalized === null, $line->page);
                    $used[$i] = true;
                } elseif (!$profileMatched && count($customTaken) < 60) {
                    $custom = FieldDictionary::customKey($label, $customTaken + $fields);
                    $customTaken[$custom] = true;
                    $set($custom, $value, 'kv', true, $line->page, $label);
                    $used[$i] = true;
                }
            }
        }

        // 2. Table rows → line items (before the value detectors, so row amounts are not taken as totals)
        $lineItems = [];
        $tableLines = [];
        if (!$profileMatched && !isset($filled['line_items'])) {
            $before = $used;
            $lineItems = $this->tableRows($lines, $used);
            $tableLines = array_diff_key($used, $before);
        }

        // 3–5. Dates, money, contacts (first hit wins; lines used by rules or tables are skipped)
        foreach ($lines as $i => $line) {
            if (isset($consumed[$i]) || isset($tableLines[$i])) {
                continue;
            }
            $text = $line->plain();

            if (preg_match('/\b\d{4}-\d{2}-\d{2}\b|\b' . self::MONTH . '\.?\s+\d{1,2}(?:st|nd|rd|th)?,?\s+\d{4}\b|\b\d{1,2}\s+' . self::MONTH . '\.?,?\s+\d{4}\b|\b\d{1,2}[\/.]\d{1,2}[\/.]\d{4}\b/i', $text, $m)) {
                $iso = Transforms::dateIso($m[0]);
                if ($iso !== null) {
                    $set('date', $iso, 'date', true, $line->page);
                }
            }
            if (preg_match(self::EMAIL, $text, $m)) {
                $set('email', $m[0], 'contact', false, $line->page);
            }
            if (preg_match(self::URL, $text, $m)) {
                $url = rtrim($m[0], '.,;)');
                $set('website_url', str_starts_with(strtolower($url), 'www.') ? 'https://' . $url : $url, 'contact', false, $line->page);
            }
            if (!preg_match(self::EMAIL, $text) && preg_match(self::PHONE, $text, $m) && preg_match_all('/\d/', $m[0]) >= 7 && preg_match_all('/\d/', $m[0]) <= 15) {
                $set('phone', trim($m[0]), 'contact', true, $line->page);
            }
            if (preg_match_all(self::MONEY, $text, $all)) {
                $amount = trim(end($all[0]));
                if (preg_match('/\btotal\b|\bamount due\b|\bbalance\b/i', $text)) {
                    $set('total', $amount, 'money', true, $line->page);
                } else {
                    $set('amount', $amount, 'money', true, $line->page);
                }
            }
        }

        // 6. Title — the most prominent line near the top of page 1
        if (!$profileMatched) {
            [$titleIndex, $title] = $this->prominentLine($lines);
            if ($title !== null && !isset($used[$titleIndex])) {
                $set('title', $title, 'title', true, 1);
                $used[$titleIndex] = true;
            }
        }

        $unmatched = [];
        foreach ($lines as $i => $line) {
            if (!isset($used[$i]) && count($unmatched) < 400) {
                $unmatched[] = ['page' => $line->page, 'text' => mb_substr($line->display(), 0, 300)];
            }
        }

        return new GenericResult($fields, $lineItems, $unmatched);
    }

    /**
     * @return list<array{0: string, 1: string}>|null
     */
    private function keyValue(CleanLine $line): ?array
    {
        $plain = trim($line->plain());

        // "Label: value"
        if (preg_match('/^([\p{L}][\p{L}\p{N} .\/&\'()#-]{0,40}?)\s*:\s+(\S.{0,300})$/u', $plain, $m) && !preg_match('~^https?$~i', $m[1])) {
            return [[trim($m[1]), trim($m[2])]];
        }

        // Two cells: "Label | value"
        if (count($line->cells) === 2) {
            $label = trim(rtrim($line->cells[0]->text, ':'));
            $value = trim($line->cells[1]->text);
            if ($value !== '' && self::isLabel($label)) {
                return [[$label, $value]];
            }
        }

        return null;
    }

    /**
     * Four or six cells laid out as "Label | value | Label | value" (dashboard-style rows).
     * Every label must be a known field label, so ordinary table rows are left to the table detector.
     *
     * @return list<array{0: string, 1: string}>|null
     */
    private function cellPairs(CleanLine $line): ?array
    {
        $n = count($line->cells);
        if ($n < 4 || $n > 8 || $n % 2 !== 0) {
            return null;
        }
        $pairs = [];
        for ($c = 0; $c < $n; $c += 2) {
            $label = trim(rtrim($line->cells[$c]->text, ':'));
            $value = trim($line->cells[$c + 1]->text);
            if ($value === '' || !self::isLabel($label) || $this->keyForLabel($label) === null) {
                return null;
            }
            $pairs[] = [$label, $value];
        }

        return $pairs;
    }

    private static function isLabel(string $label): bool
    {
        return (bool) preg_match('/^[\p{L}][\p{L} .\/&\'()-]{1,40}$/u', $label) && mb_strlen($label) <= 40;
    }

    private function keyForLabel(string $label): ?string
    {
        $norm = SourceProfileManager::normalize((string) preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $label));
        if ($norm === '') {
            return null;
        }

        $best = null;
        $bestScore = 0.0;
        foreach (self::SYNONYMS as $key => $synonyms) {
            foreach ($synonyms as $synonym) {
                if ($norm === $synonym) {
                    return $key;
                }
                similar_text($norm, $synonym, $percent);
                if ($percent > $bestScore) {
                    $bestScore = $percent;
                    $best = $key;
                }
            }
        }

        return $bestScore >= 88.0 ? $best : null;
    }

    private function normalizeValue(string $key, string $value): ?string
    {
        if (isset(self::KEY_TRANSFORMS[$key])) {
            foreach (self::KEY_TRANSFORMS[$key] as $transform) {
                $value = Transforms::apply($transform, $value);
                if ($value === null) {
                    return null;
                }
            }

            return $value;
        }

        return match (FieldDictionary::type($key)) {
            'date' => Transforms::dateIso($value),
            'email' => preg_match(self::EMAIL, $value, $m) ? $m[0] : null,
            'url' => preg_match(self::URL, $value, $m) ? $m[0] : null,
            default => $value,
        };
    }

    /**
     * Runs of ≥ 3 consecutive lines with the same cell count (≥ 2) whose last cell is numeric.
     *
     * @param list<CleanLine> $lines
     * @param array<int, bool> $used
     * @return list<array<string, mixed>>
     */
    private function tableRows(array $lines, array &$used): array
    {
        $rows = [];
        $run = [];
        $flush = function () use (&$run, &$rows, &$used, $lines): void {
            if (count($run) >= 3) {
                // A header line directly above with the same number of text-only cells belongs to the table.
                $header = $run[0] - 1;
                if ($header >= 0 && !isset($used[$header]) && count($lines[$header]->cells) === count($lines[$run[0]]->cells)
                    && !preg_match('/\d/', $lines[$header]->plain())) {
                    $used[$header] = true;
                }
                foreach ($run as $i) {
                    $cells = array_map(static fn ($c): string => $c->text, $lines[$i]->cells);
                    $n = count($cells);
                    $rows[] = [
                        'name' => $cells[0],
                        'quantity' => $n >= 4 ? $cells[1] : '',
                        'price' => $n >= 3 ? $cells[$n - 2] : '',
                        'total' => $cells[$n - 1],
                        'source' => 'table',
                        'needs_review' => true,
                    ];
                    $used[$i] = true;
                }
            }
            $run = [];
        };

        $width = 0;
        foreach ($lines as $i => $line) {
            $n = count($line->cells);
            $numeric = $n >= 2 && preg_match('/^[^\p{L}]*\d[\d,.\s]*[^\p{L}]*$/u', $line->cells[$n - 1]->text);
            if (isset($used[$i]) || !$numeric) {
                $flush();
                $width = 0;
                continue;
            }
            if ($n !== $width) {
                $flush();
                $width = $n;
            }
            $run[] = $i;
            if (count($rows) >= 200) {
                break;
            }
        }
        $flush();

        return array_slice($rows, 0, 200);
    }

    /**
     * @param list<CleanLine> $lines
     * @return array{0: int, 1: ?string}
     */
    private function prominentLine(array $lines): array
    {
        $best = null;
        $bestIndex = -1;
        $bestSize = 0.0;
        foreach ($lines as $i => $line) {
            if ($line->page !== 1 || $i > 25) {
                break;
            }
            $size = max(array_map(static fn ($c): float => $c->fontSize, $line->cells));
            $text = trim($line->plain());
            if ($size > $bestSize + 0.5 && preg_match('/\p{L}{3}/u', $text) && mb_strlen($text) <= 120) {
                $best = $text;
                $bestIndex = $i;
                $bestSize = $size;
            }
        }

        return [$bestIndex, $best];
    }
}
