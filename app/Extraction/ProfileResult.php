<?php

declare(strict_types=1);

namespace App\Extraction;

/**
 * What a source profile extracted.
 */
final class ProfileResult
{
    /**
     * @param array<string, array{value: string, found: bool, needs_review: bool, page: ?int}> $fields
     * @param array<string, array{rows: list<array<string, mixed>>, found: bool, optional?: bool}> $lists
     * @param array<int, bool> $consumed line indexes used by a rule
     * @param array<string, array{0: int, 1: int}|null> $sections
     */
    public function __construct(
        public readonly string $profileId,
        public readonly array $fields,
        public readonly array $lists,
        public readonly array $consumed,
        public readonly array $sections,
    ) {
    }
}
