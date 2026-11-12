<?php

declare(strict_types=1);

namespace App\Domain\Collection\GenerateCollectionDescription;

use App\Infrastructure\CQRS\Command\Deserialize\CouldNotDeserializeCommand;
use App\Infrastructure\CQRS\Command\Deserialize\DeserializableCommand;
use App\Infrastructure\CQRS\Command\Deserialize\ProvidesCommandName;
use App\Infrastructure\CQRS\Command\DomainCommand;
use App\Infrastructure\CQRS\Command\ProvidesFlashMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class GenerateCollectionDescription extends DomainCommand implements DeserializableCommand, ProvidesFlashMessage
{
    use ProvidesCommandName;

    private function __construct(
        private string $collectionId,
    ) {
    }

    public static function fromPayload(array $payload): self
    {
        if (!isset($payload['collectionId']) || !is_string($payload['collectionId']) || '' === trim($payload['collectionId'])) {
            throw CouldNotDeserializeCommand::invalidPayload('A "collectionId" is required.');
        }

        return new self(trim($payload['collectionId']));
    }

    public function getCollectionId(): string
    {
        return $this->collectionId;
    }

    public function getFlashMessage(TranslatorInterface $translator): string
    {
        return $translator->trans('A new description has been generated.');
    }
}
