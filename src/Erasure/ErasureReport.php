<?php

declare(strict_types=1);

namespace Qiick\Settings\Erasure;

/** @api Reports erasure counts by store without including personal data. */
final readonly class ErasureReport
{
    /** @param array<string, array{deleted: int, anonymised: int, kept: int, reason: ?string}> $stores */
    public function __construct(
        public string $package,
        public string $mode,
        public bool $dryRun,
        public array $stores,
    ) {}

    public function changed(): int
    {
        return array_sum(array_map(
            static fn (array $counts): int => $counts['deleted'] + $counts['anonymised'],
            $this->stores,
        ));
    }

    /** @return array{package: string, mode: string, dry_run: bool, stores: array<string, array{deleted: int, anonymised: int, kept: int, reason: ?string}>} */
    public function toArray(): array
    {
        return ['package' => $this->package, 'mode' => $this->mode, 'dry_run' => $this->dryRun, 'stores' => $this->stores];
    }
}
