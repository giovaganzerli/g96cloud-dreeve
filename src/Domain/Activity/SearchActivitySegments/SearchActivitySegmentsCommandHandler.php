<?php

declare(strict_types=1);

namespace App\Domain\Activity\SearchActivitySegments;

use App\Application\Import\CalculateActivityMetrics\Pipeline\CalculateImportedSegmentEfforts;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ImportSource;
use App\Domain\Activity\Stream\ActivityStreamRepository;
use App\Domain\Activity\Stream\StreamType;
use App\Infrastructure\CQRS\Command\Command;
use App\Infrastructure\CQRS\Command\CommandHandler;
use App\Infrastructure\CQRS\Command\CouldNotProcessCommand;
use App\Infrastructure\DependencyInjection\Mutex\WithMutex;
use App\Infrastructure\Mutex\LockIsAlreadyAcquired;
use App\Infrastructure\Mutex\LockName;
use App\Infrastructure\Mutex\Mutex;

#[WithMutex(lockName: LockName::IMPORT_DATA)]
final readonly class SearchActivitySegmentsCommandHandler implements CommandHandler
{
    public function __construct(
        private ActivityRepository $activityRepository,
        private ActivityStreamRepository $activityStreamRepository,
        private CalculateImportedSegmentEfforts $segmentSearch,
        private Mutex $mutex,
    ) {
    }

    public function handle(Command $command): void
    {
        assert($command instanceof SearchActivitySegments);
        $id = $command->getActivityId();
        if (!$this->activityRepository->exists($id)) {
            throw CouldNotProcessCommand::withReason('L’attività non esiste.');
        }
        if (ImportSource::MANUAL === $this->activityRepository->find($id)->getImportSource()) {
            throw CouldNotProcessCommand::withReason('La ricerca non è disponibile per le attività create manualmente in Dreeve.');
        }
        $streams = $this->activityStreamRepository->findByActivityId($id);
        foreach ([StreamType::LAT_LNG, StreamType::TIME, StreamType::DISTANCE] as $type) {
            if ([] === ($streams->filterOnType($type)?->getData() ?? [])) {
                throw CouldNotProcessCommand::withReason('Servono una traccia GPS e i dati di tempo e distanza per cercare i segmenti.');
            }
        }
        try {
            $this->mutex->acquireLock('searchActivitySegments');
        } catch (LockIsAlreadyAcquired) {
            throw CouldNotProcessCommand::withReason('È già in corso un’importazione o una ricerca. Riprova al termine.');
        }
        try {
            $this->segmentSearch->searchActivity($id);
        } finally {
            $this->mutex->releaseLock();
        }
    }
}
