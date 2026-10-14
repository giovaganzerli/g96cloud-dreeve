<?php

declare(strict_types=1);

namespace App\Domain\Segment\AddCustomSegment;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\SportType\SportType;
use App\Domain\Segment\Geometry\GpxSegmentTrackParser;
use App\Infrastructure\CQRS\Command\Deserialize\CouldNotDeserializeCommand;
use App\Infrastructure\CQRS\Command\Deserialize\DeserializableCommand;
use App\Infrastructure\CQRS\Command\Deserialize\ProvidesCommandName;
use App\Infrastructure\CQRS\Command\DomainCommand;
use App\Infrastructure\CQRS\Command\ProvidesFlashMessage;
use App\Infrastructure\ValueObject\String\Name;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class AddCustomSegment extends DomainCommand implements DeserializableCommand, ProvidesFlashMessage
{
    use ProvidesCommandName;

    private function __construct(
        private ?ActivityId $activityId,
        private int $startIndex,
        private int $endIndex,
        private Name $name,
        private bool $isFavourite,
        private ?string $contents = null,
        private ?SportType $sportType = null,
    ) {
    }

    public static function fromPayload(array $payload): self
    {
        if (!isset($payload['name']) || !is_string($payload['name']) || '' === trim($payload['name'])) {
            throw CouldNotDeserializeCommand::invalidPayload('A "name" is required.');
        }

        if ('gpx' === ($payload['source'] ?? 'activity')) {
            $sportType = is_string($payload['sportType'] ?? null) ? SportType::tryFrom($payload['sportType']) : null;
            if (null === $sportType || !$sportType->supportsReverseGeocoding()) {
                throw CouldNotDeserializeCommand::invalidPayload('Seleziona uno sport all’aperto valido per il segmento.');
            }
            if (!is_string($payload['filename'] ?? null) || 'gpx' !== strtolower(pathinfo($payload['filename'], PATHINFO_EXTENSION))
                || !is_string($payload['content'] ?? null) || strlen($payload['content']) > (int) ceil(GpxSegmentTrackParser::MAX_BYTES * 4 / 3) + 4) {
                throw CouldNotDeserializeCommand::invalidPayload('Carica un file GPX fino a 5 MB.');
            }
            $contents = base64_decode($payload['content'], strict: true);
            if (false === $contents || '' === trim($contents) || strlen($contents) > GpxSegmentTrackParser::MAX_BYTES) {
                throw CouldNotDeserializeCommand::invalidPayload('Il contenuto del GPX non è valido.');
            }
            return new self(null, 0, 0, Name::fromString(trim($payload['name'])),
                filter_var($payload['isFavourite'] ?? false, FILTER_VALIDATE_BOOLEAN), $contents, $sportType);
        }
        if ('activity' !== ($payload['source'] ?? 'activity')) {
            throw CouldNotDeserializeCommand::invalidPayload('Seleziona un’attività o un file GPX.');
        }
        if (!isset($payload['activityId']) || !is_string($payload['activityId'])) {
            throw CouldNotDeserializeCommand::invalidPayload('An "activityId" is required.');
        }

        foreach (['startIndex', 'endIndex'] as $key) {
            $value = $payload[$key] ?? null;
            if (!is_numeric($value) || (int) $value != $value || (int) $value < 0) {
                throw CouldNotDeserializeCommand::invalidPayload(sprintf('The "%s" must be a positive whole number.', $key));
            }
        }

        if ((int) $payload['startIndex'] >= (int) $payload['endIndex']) {
            throw CouldNotDeserializeCommand::invalidPayload('The end of the segment must come after its start.');
        }

        return new self(
            activityId: ActivityId::fromString($payload['activityId']),
            startIndex: (int) $payload['startIndex'],
            endIndex: (int) $payload['endIndex'],
            name: Name::fromString(trim($payload['name'])),
            isFavourite: filter_var($payload['isFavourite'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }

    public function getActivityId(): ?ActivityId
    {
        return $this->activityId;
    }

    public function getStartIndex(): int
    {
        return $this->startIndex;
    }

    public function getEndIndex(): int
    {
        return $this->endIndex;
    }

    public function getContents(): ?string
    {
        return $this->contents;
    }

    public function getSportType(): ?SportType
    {
        return $this->sportType;
    }

    public function getName(): Name
    {
        return $this->name;
    }

    public function isFavourite(): bool
    {
        return $this->isFavourite;
    }

    public function getFlashMessage(TranslatorInterface $translator): string
    {
        return $translator->trans('The segment has been created. Its efforts will show up after the next import run.', [], 'admin');
    }
}
