<?php

declare(strict_types=1);

namespace App\Domain\Activity\ApplyActivityRecalculation;

use App\Domain\Activity\ActivityId;
use App\Infrastructure\CQRS\Command\Deserialize\CouldNotDeserializeCommand;
use App\Infrastructure\CQRS\Command\Deserialize\DeserializableCommand;
use App\Infrastructure\CQRS\Command\Deserialize\ProvidesCommandName;
use App\Infrastructure\CQRS\Command\DomainCommand;

/**
 * Applies the recalculation the athlete confirmed. Only the selected values are
 * applied, the others keep the value reported by the device.
 */
final readonly class ApplyActivityRecalculation extends DomainCommand implements DeserializableCommand
{
    use ProvidesCommandName;

    /**
     * @param list<string> $metrics
     */
    private function __construct(
        private ActivityId $activityId,
        private array $metrics,
        private bool $recalculateBestEfforts,
        private bool $recalculateSegmentEfforts,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        if (!isset($payload['activityId']) || !is_string($payload['activityId'])) {
            throw CouldNotDeserializeCommand::invalidPayload('An "activityId" is required.');
        }

        $metrics = [];
        foreach (is_array($payload['metrics'] ?? null) ? $payload['metrics'] : [] as $metric) {
            if (is_string($metric)) {
                $metrics[] = $metric;
            }
        }

        return new self(
            activityId: ActivityId::fromString($payload['activityId']),
            metrics: $metrics,
            recalculateBestEfforts: filter_var($payload['recalculateBestEfforts'] ?? false, FILTER_VALIDATE_BOOL),
            recalculateSegmentEfforts: filter_var($payload['recalculateSegmentEfforts'] ?? false, FILTER_VALIDATE_BOOL),
        );
    }

    public function getActivityId(): ActivityId
    {
        return $this->activityId;
    }

    /**
     * @return list<string>
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }

    public function shouldRecalculateBestEfforts(): bool
    {
        return $this->recalculateBestEfforts;
    }

    public function shouldRecalculateSegmentEfforts(): bool
    {
        return $this->recalculateSegmentEfforts;
    }
}
