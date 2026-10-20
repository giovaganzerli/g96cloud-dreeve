<?php

declare(strict_types=1);

namespace App\Domain\Activity\RecalculateActivityMetrics;

use App\Domain\Activity\ActivityId;
use App\Infrastructure\CQRS\Command\Deserialize\CouldNotDeserializeCommand;
use App\Infrastructure\CQRS\Command\Deserialize\DeserializableCommand;
use App\Infrastructure\CQRS\Command\Deserialize\ProvidesCommandName;
use App\Infrastructure\CQRS\Command\DomainCommand;

/**
 * Recalculates the activity and the data derived from it. The result is stored on the
 * activity, waiting to be confirmed by the athlete.
 */
final readonly class RecalculateActivityMetrics extends DomainCommand implements DeserializableCommand
{
    use ProvidesCommandName;

    private function __construct(
        private ActivityId $activityId,
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

        return new self(activityId: ActivityId::fromString($payload['activityId']));
    }

    public function getActivityId(): ActivityId
    {
        return $this->activityId;
    }
}
