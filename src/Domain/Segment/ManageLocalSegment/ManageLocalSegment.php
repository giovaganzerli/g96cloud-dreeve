<?php

declare(strict_types=1);

namespace App\Domain\Segment\ManageLocalSegment;

use App\Domain\Segment\SegmentId;
use App\Domain\Segment\Geometry\GpxSegmentTrackParser;
use App\Infrastructure\CQRS\Command\Deserialize\CouldNotDeserializeCommand;
use App\Infrastructure\CQRS\Command\Deserialize\DeserializableCommand;
use App\Infrastructure\CQRS\Command\Deserialize\ProvidesCommandName;
use App\Infrastructure\CQRS\Command\DomainCommand;
use App\Infrastructure\CQRS\Command\ProvidesFlashMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ManageLocalSegment extends DomainCommand implements DeserializableCommand, ProvidesFlashMessage
{
    use ProvidesCommandName;

    private function __construct(private SegmentId $segmentId, private string $action, private ?string $contents)
    {
    }

    public static function fromPayload(array $payload): self
    {
        if (!isset($payload['segmentId'], $payload['action']) || !is_string($payload['segmentId'])
            || !in_array($payload['action'], ['search', 'generate', 'upload'], true)) {
            throw CouldNotDeserializeCommand::invalidPayload('Specificare un segmento e un’azione valida.');
        }
        try {
            $id = SegmentId::fromString($payload['segmentId']);
        } catch (\InvalidArgumentException) {
            throw CouldNotDeserializeCommand::invalidPayload('Identificativo del segmento non valido.');
        }
        $contents = null;
        if ('upload' === $payload['action']) {
            if (!in_array($payload['recalculate'] ?? null, [true, '1'], true)) {
                throw CouldNotDeserializeCommand::invalidPayload('Conferma il ricalcolo dei risultati sulla lunghezza e sul dislivello del GPX.');
            }
            if (!isset($payload['filename'], $payload['content']) || !is_string($payload['filename']) || !is_string($payload['content'])
                || 'gpx' !== strtolower(pathinfo($payload['filename'], PATHINFO_EXTENSION))
                || strlen($payload['content']) > (int) ceil(GpxSegmentTrackParser::MAX_BYTES * 4 / 3) + 4) {
                throw CouldNotDeserializeCommand::invalidPayload('Carica un file GPX fino a 5 MB.');
            }
            $contents = base64_decode($payload['content'], strict: true);
            if (false === $contents || '' === trim($contents) || strlen($contents) > GpxSegmentTrackParser::MAX_BYTES) {
                throw CouldNotDeserializeCommand::invalidPayload('Il contenuto del GPX non è valido.');
            }
        }
        return new self($id, $payload['action'], $contents);
    }

    public function getSegmentId(): SegmentId
    {
        return $this->segmentId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getContents(): ?string
    {
        return $this->contents;
    }

    public function getFlashMessage(TranslatorInterface $translator): string
    {
        return match ($this->action) {
            'search' => 'Ricerca completata. Gli eventuali nuovi passaggi sono visibili nel dettaglio del segmento.',
            'generate' => 'Traccia GPS generata dai dati archiviati. La mappa è disponibile nel dettaglio del segmento.',
            default => 'Traccia GPX caricata e risultati ricalcolati sulle attività GPS disponibili.',
        };
    }
}
