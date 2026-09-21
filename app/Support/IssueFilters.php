<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What to narrow an issue listing or search by, besides its text and state. Values inside one list are
 * alternatives (an issue in any of `areas`), except `labels`, which must all be present. Names are
 * matched case-insensitively and may stand in for ids, so a caller never has to look an id up first.
 * `parentId` alone means the direct children; with `descendants` it means the whole tree beneath it.
 */
final readonly class IssueFilters
{
    /**
     * @param  list<int>  $areas  Project ids
     * @param  list<string>  $areaNames  Project titles
     * @param  list<int>  $groups  Group option ids
     * @param  list<string>  $groupNames  Group names, in whichever Project has one of that name
     * @param  list<string>  $labels  every one must be present
     * @param  list<string>  $anyLabels  at least one must be present
     * @param  list<string>  $excludeLabels  none may be present
     */
    public function __construct(
        public array $areas = [],
        public array $areaNames = [],
        public array $groups = [],
        public array $groupNames = [],
        public array $labels = [],
        public array $anyLabels = [],
        public array $excludeLabels = [],
        public ?int $parentId = null,
        public bool $descendants = false,
    ) {}

    /** Whether any narrowing is requested; `descendants` alone narrows nothing. */
    public function isEmpty(): bool
    {
        return $this->areas === [] && $this->areaNames === [] && $this->groups === [] && $this->groupNames === []
            && $this->labels === [] && $this->anyLabels === [] && $this->excludeLabels === [] && $this->parentId === null;
    }

    /** Fold in the older single-value arguments (`area`, `group`, `label`, `parentId`) so both spellings work. */
    public function withLegacy(?int $area, ?int $group, ?string $label, ?int $parentId): self
    {
        return new self(
            areas: $area === null ? $this->areas : [...$this->areas, $area],
            areaNames: $this->areaNames,
            groups: $group === null ? $this->groups : [...$this->groups, $group],
            groupNames: $this->groupNames,
            labels: $label === null ? $this->labels : [...$this->labels, $label],
            anyLabels: $this->anyLabels,
            excludeLabels: $this->excludeLabels,
            parentId: $parentId ?? $this->parentId,
            descendants: $this->descendants,
        );
    }
}
