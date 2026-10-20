<?php

declare(strict_types=1);

namespace App\Domain\Activity\RevertActivityMetrics;

use App\Domain\Activity\ActivityId;
use App\Infrastructure\CQRS\Command\Deserialize\CouldNotDeserializeCommand;
use App\Infrastructure\CQRS\Command\Deserialize\DeserializableCommand;
use App\Infrastructure\CQRS\Command\Deserialize\ProvidesCommandName;
use App\Infrastructure\CQRS\Command\DomainCommand;

final readonly class RevertActivityMetrics extends DomainCommand implements DeserializableCommand
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

        return new self(
            activityId: ActivityId::fromString($payload['activityId']),
        );
    }

    public function getActivityId(): ActivityId
    {
        return $this->activityId;
    }
}
