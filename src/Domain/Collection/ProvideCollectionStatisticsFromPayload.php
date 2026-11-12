<?php

declare(strict_types=1);

namespace App\Domain\Collection;

use App\Domain\Collection\Statistics\CollectionStatistic;
use App\Domain\Collection\Statistics\CollectionStatisticCategory;
use App\Domain\Collection\Statistics\CollectionStatistics;
use App\Infrastructure\CQRS\Command\Deserialize\CouldNotDeserializeCommand;
use App\Infrastructure\Serialization\Json;

trait ProvideCollectionStatisticsFromPayload
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return array{0: array<string, string>, 1: CollectionStatistics, 2: string[]}
     */
    private static function parseStatistics(array $payload): array
    {
        $overrides = [];
        if (isset($payload['statisticOverrides']) && is_array($payload['statisticOverrides'])) {
            foreach ($payload['statisticOverrides'] as $key => $value) {
                if (is_string($key) && is_scalar($value) && '' !== trim((string) $value)) {
                    $overrides[$key] = trim((string) $value);
                }
            }
        }

        $customStatistics = CollectionStatistics::empty();
        if (isset($payload['customStatistics']) && is_string($payload['customStatistics']) && '' !== trim($payload['customStatistics'])) {
            $decoded = Json::decode($payload['customStatistics']);
            if (!is_array($decoded)) {
                throw CouldNotDeserializeCommand::invalidPayload('The "customStatistics" field is invalid.');
            }
            foreach ($decoded as $index => $customStatistic) {
                if (!is_array($customStatistic)) {
                    continue;
                }
                $label = trim((string) ($customStatistic['label'] ?? ''));
                if ('' === $label) {
                    continue;
                }
                $category = CollectionStatisticCategory::tryFrom((string) ($customStatistic['category'] ?? ''));
                $customStatistics->add(CollectionStatistic::custom(
                    id: (string) ($customStatistic['id'] ?? $index),
                    label: $label,
                    value: trim((string) ($customStatistic['value'] ?? '')),
                    unit: self::optionalString($customStatistic['unit'] ?? null),
                    icon: self::optionalString($customStatistic['icon'] ?? null),
                    category: $category,
                ));
            }
        }

        $order = [];
        if (isset($payload['statisticOrder']) && is_string($payload['statisticOrder']) && '' !== trim($payload['statisticOrder'])) {
            $decoded = Json::decode($payload['statisticOrder']);
            if (is_array($decoded)) {
                $order = array_values(array_filter($decoded, is_string(...)));
            }
        }

        return [$overrides, $customStatistics, $order];
    }

    private static function optionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
