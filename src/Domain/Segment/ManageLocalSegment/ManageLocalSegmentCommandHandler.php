<?php

declare(strict_types=1);

namespace App\Domain\Segment\ManageLocalSegment;

use App\Application\Import\CalculateActivityMetrics\Pipeline\CalculateImportedSegmentEfforts;
use App\Domain\Activity\Scan\ActivityScanRepository;
use App\Domain\Activity\Scan\ActivityScanType;
use App\Domain\Segment\Geometry\GenerateSegmentGeometry;
use App\Domain\Segment\Geometry\GpxSegmentTrackParser;
use App\Domain\Segment\LocalSegmentGeometry;
use App\Domain\Segment\SegmentRepository;
use App\Infrastructure\CQRS\Command\Command;
use App\Infrastructure\CQRS\Command\CommandHandler;
use App\Infrastructure\CQRS\Command\CouldNotProcessCommand;
use App\Infrastructure\DependencyInjection\Mutex\WithMutex;
use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\Mutex\LockIsAlreadyAcquired;
use App\Infrastructure\Mutex\LockName;
use App\Infrastructure\Mutex\Mutex;

#[WithMutex(lockName: LockName::IMPORT_DATA)]
final readonly class ManageLocalSegmentCommandHandler implements CommandHandler
{
    public function __construct(
        private SegmentRepository $segmentRepository,
        private LocalSegmentGeometry $geometry,
        private GenerateSegmentGeometry $generator,
        private GpxSegmentTrackParser $gpxParser,
        private CalculateImportedSegmentEfforts $matcher,
        private ActivityScanRepository $scans,
        private Mutex $mutex,
    ) {
    }

    public function handle(Command $command): void
    {
        assert($command instanceof ManageLocalSegment);
        try {
            $segment = $this->segmentRepository->find($command->getSegmentId());
        } catch (EntityNotFound) {
            throw CouldNotProcessCommand::withReason('Il segmento non esiste.');
        }
        try {
            $this->mutex->acquireLock('manageLocalSegment');
        } catch (LockIsAlreadyAcquired) {
            throw CouldNotProcessCommand::withReason('È già in corso un’importazione o una ricerca. Riprova al termine.');
        }
        try {
            $hasGeometry = null !== $this->geometry->forDisplay($segment)->getPolyline();
            if ('search' === $command->getAction()) {
                if (!$hasGeometry) {
                    $polyline = $this->generator->generate($segment);
                    if (null === $polyline) {
                        throw CouldNotProcessCommand::withReason('I passaggi archiviati non contengono dati GPS sufficienti. Puoi caricare il GPX qui sotto.');
                    }
                    $this->geometry->saveMissing($segment, $polyline, 'generated');
                    $this->scans->deleteForSubject(ActivityScanType::CUSTOM_SEGMENT, (string) $segment->getId());
                }
                $this->matcher->searchSegment($segment->getId());
                return;
            }
            if ($hasGeometry) {
                throw CouldNotProcessCommand::withReason('Il segmento ha già una traccia GPS. Non è stata sostituita.');
            }
            $polyline = 'upload' === $command->getAction()
                ? $this->gpxParser->parse($command->getContents() ?? '', $segment->getDistance()->toMeter()->toFloat())
                : $this->generator->generate($segment);
            if (null === $polyline) {
                throw CouldNotProcessCommand::withReason('I passaggi archiviati non contengono dati GPS sufficienti per ricostruire il segmento. Puoi caricare il suo GPX.');
            }
            $this->geometry->saveMissing($segment, $polyline, 'upload' === $command->getAction() ? 'gpx' : 'generated');
            $this->scans->deleteForSubject(ActivityScanType::CUSTOM_SEGMENT, (string) $segment->getId());
        } finally {
            $this->mutex->releaseLock();
        }
    }
}
