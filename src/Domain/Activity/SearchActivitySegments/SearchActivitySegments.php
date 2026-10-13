<?php

declare(strict_types=1);

namespace App\Domain\Activity\SearchActivitySegments;

use App\Domain\Activity\ActivityId;
use App\Infrastructure\CQRS\Command\Deserialize\CouldNotDeserializeCommand;
use App\Infrastructure\CQRS\Command\Deserialize\DeserializableCommand;
use App\Infrastructure\CQRS\Command\Deserialize\ProvidesCommandName;
use App\Infrastructure\CQRS\Command\DomainCommand;
use App\Infrastructure\CQRS\Command\ProvidesFlashMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class SearchActivitySegments extends DomainCommand implements DeserializableCommand, ProvidesFlashMessage
{
    use ProvidesCommandName;

    private function __construct(private ActivityId $activityId)
    {
    }

    public static function fromPayload(array $payload): self
    {
        if (!isset($payload['activityId']) || !is_string($payload['activityId'])) {
            throw CouldNotDeserializeCommand::invalidPayload('An "activityId" is required.');
        }
        try {
            return new self(ActivityId::fromString($payload['activityId']));
        } catch (\InvalidArgumentException) {
            throw CouldNotDeserializeCommand::invalidPayload('Invalid activity identifier.');
        }
    }

    public function getActivityId(): ActivityId
    {
        return $this->activityId;
    }

    public function getFlashMessage(TranslatorInterface $translator): string
    {
        return 'Ricerca completata. Gli eventuali nuovi passaggi sono visibili nel dettaglio dell’attività.';
    }
}
