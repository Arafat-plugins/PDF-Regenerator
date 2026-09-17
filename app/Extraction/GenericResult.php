<?php

declare(strict_types=1);

namespace App\Extraction;

final class GenericResult
{
    /**
     * @param array<string, array{value: string, source: string, needs_review: bool, page: ?int, label: ?string}> $fields
     * @param list<array<string, mixed>> $lineItems
     * @param list<array{page: int, text: string}> $unmatched lines no rule or detector used
     */
    public function __construct(
        public readonly array $fields,
        public readonly array $lineItems,
        public readonly array $unmatched,
    ) {
    }
}
