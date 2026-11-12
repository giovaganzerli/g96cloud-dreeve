<?php

declare(strict_types=1);

namespace App\Domain\Collection\Statistics;

use App\Domain\Activity\ActivityId;

/**
 * A single statistic of a collection. It either gets calculated automatically from the
 * activities in the collection, or it's manually added by the user.
 */
final readonly class CollectionStatistic implements \JsonSerializable
{
    private function __construct(
        private string $key,
        private string $label,
        private string $value,
        private ?string $unit,
        private string $icon,
        private CollectionStatisticCategory $category,
        private bool $isManuallyEdited,
        private ?float $numericValue,
        private ?ActivityId $linkActivityId,
    ) {
    }

    public static function create(
        string $key,
        string $label,
        string $value,
        ?string $unit = null,
        ?string $icon = null,
        ?CollectionStatisticCategory $category = null,
        bool $isManuallyEdited = false,
        ?float $numericValue = null,
        ?ActivityId $linkActivityId = null,
    ): self {
        $category ??= CollectionStatisticCategory::OTHER;

        return new self(
            key: $key,
            label: $label,
            value: $value,
            unit: $unit,
            icon: $icon ?? $category->getIcon(),
            category: $category,
            isManuallyEdited: $isManuallyEdited,
            numericValue: $numericValue,
            linkActivityId: $linkActivityId,
        );
    }

    public static function custom(
        string $id,
        string $label,
        string $value,
        ?string $unit = null,
        ?string $icon = null,
        ?CollectionStatisticCategory $category = null,
    ): self {
        return self::create(
            key: self::customKey($id),
            label: $label,
            value: $value,
            unit: $unit,
            icon: $icon,
            category: $category,
            isManuallyEdited: true,
        );
    }

    public static function customKey(string $id): string
    {
        return 'custom.'.$id;
    }

    public function withValue(string $value, ?float $numericValue = null): self
    {
        return new self(
            key: $this->key,
            label: $this->label,
            value: $value,
            unit: $this->unit,
            icon: $this->icon,
            category: $this->category,
            isManuallyEdited: true,
            numericValue: $numericValue,
            linkActivityId: $this->linkActivityId,
        );
    }

    /**
     * The activity this statistic points to, so it can be opened from the collection.
     */
    public function getLinkActivityId(): ?ActivityId
    {
        return $this->linkActivityId;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function getCategory(): CollectionStatisticCategory
    {
        return $this->category;
    }

    public function isManuallyEdited(): bool
    {
        return $this->isManuallyEdited;
    }

    public function isCustom(): bool
    {
        return str_starts_with($this->key, 'custom.');
    }

    public function getNumericValue(): ?float
    {
        return $this->numericValue;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'value' => $this->value,
            'unit' => $this->unit,
            'icon' => $this->icon,
            'category' => $this->category->value,
            'isManuallyEdited' => $this->isManuallyEdited,
            'numericValue' => $this->numericValue,
            'linkActivityId' => $this->linkActivityId?->toUnprefixedString(),
        ];
    }
}
