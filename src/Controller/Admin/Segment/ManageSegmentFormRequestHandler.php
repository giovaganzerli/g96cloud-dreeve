<?php

declare(strict_types=1);

namespace App\Controller\Admin\Segment;

use App\Domain\Activity\SportType\SportType;
use App\Domain\Import\ImportMode;
use App\Domain\Segment\AddCustomSegment\AddCustomSegment;
use App\Domain\Segment\DeleteSegment\DeleteSegment;
use App\Domain\Segment\Geometry\GpxSegmentTrackParser;
use App\Domain\Segment\Segment;
use App\Domain\Segment\SegmentId;
use App\Domain\Segment\SegmentRepository;
use App\Domain\Segment\UpdateSegment\UpdateSegment;
use App\Domain\Settings\SettingsRepository;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\CQRS\Command\CouldNotProcessCommand;
use App\Infrastructure\CQRS\Command\Deserialize\CouldNotDeserializeCommand;
use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\Http\HtmlResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

#[AsController]
final readonly class ManageSegmentFormRequestHandler
{
    public function __construct(
        private Environment $twig,
        private SettingsRepository $settingsRepository,
        private SegmentRepository $segmentRepository,
        private ImportMode $importMode,
        private CommandBus $commands,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route(path: '/admin/segments/add', name: 'admin_add_segment', methods: ['GET', 'POST'], priority: 10)]
    public function handleAdd(Request $request): Response
    {
        if (!$this->importMode->isFiles()) {
            throw new NotFoundHttpException('Page not found');
        }

        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->csrf->isTokenValid(new CsrfToken('dispatch-command', $request->request->getString('_csrf_token')))) {
                throw new AccessDeniedHttpException('Token CSRF non valido.');
            }
            try {
                $file = $request->files->get('track');
                if (!$file instanceof UploadedFile || !$file->isValid() || $file->getSize() > GpxSegmentTrackParser::MAX_BYTES) {
                    throw CouldNotProcessCommand::withReason('Seleziona un file GPX valido, fino a 5 MB.');
                }
                $this->commands->dispatch(AddCustomSegment::fromPayload([
                    'source' => 'gpx', 'filename' => $file->getClientOriginalName(), 'content' => base64_encode($file->getContent()),
                    'name' => $request->request->getString('name'), 'sportType' => $request->request->getString('sportType'),
                    'isFavourite' => $request->request->getBoolean('isFavourite'),
                ]));

                return new RedirectResponse($this->urls->generate('admin_manage_segment_overview'), Response::HTTP_SEE_OTHER);
            } catch (CouldNotProcessCommand|CouldNotDeserializeCommand $exception) {
                $error = $exception->getMessage();
            }
        }

        $unitSystem = $this->settingsRepository->appearance()->getUnitSystem();

        $response = new HtmlResponse($this->twig->render('html/admin/page/segment/add-segment.html.twig', [
            'dispatchCommand' => AddCustomSegment::getCommandName(),
            'error' => $error,
            'gpxName' => $request->request->getString('name'),
            'gpxSport' => $request->request->getString('sportType', 'Ride'),
            'gpxFavourite' => $request->request->getBoolean('isFavourite'),
            'sportTypes' => array_filter(SportType::cases(), static fn (SportType $sport): bool => $sport->supportsReverseGeocoding()),
            'unitSystem' => $unitSystem,
            'unitFactors' => [
                'distanceInMetersPerUnit' => $unitSystem->distance(1)->toMeter()->toFloat(),
                'elevationInMetersPerUnit' => $unitSystem->elevation(1)->toMeter()->toFloat(),
            ],
        ]));

        return $response->setStatusCode(null !== $error ? Response::HTTP_BAD_REQUEST : Response::HTTP_OK);
    }

    #[Route(path: '/admin/segments/{segmentId}/edit', name: 'admin_edit_segment', requirements: ['segmentId' => 'segment-[^/]+'], methods: ['GET'], priority: 10)]
    public function handleEdit(string $segmentId): HtmlResponse
    {
        $segment = $this->findSegment($segmentId);
        if ($segment->getType()->isImported()) {
            throw new NotFoundHttpException('Segments imported from Strava cannot be edited');
        }

        return new HtmlResponse($this->twig->render('html/admin/page/segment/edit-segment.html.twig', [
            'dispatchCommand' => UpdateSegment::getCommandName(),
            'segment' => $segment,
        ]));
    }

    #[Route(path: '/admin/segments/{segmentId}/delete', name: 'admin_delete_segment', requirements: ['segmentId' => 'segment-[^/]+'], methods: ['GET'], priority: 10)]
    public function handleDelete(string $segmentId): HtmlResponse
    {
        $segment = $this->findSegment($segmentId);
        if (!$segment->isDeletableIn($this->importMode)) {
            throw new NotFoundHttpException('Segment cannot be deleted');
        }

        return new HtmlResponse($this->twig->render('html/admin/page/segment/delete-segment.html.twig', [
            'dispatchCommand' => DeleteSegment::getCommandName(),
            'segment' => $segment,
        ]));
    }

    private function findSegment(string $segmentId): Segment
    {
        if (!$this->importMode->isFiles()) {
            throw new NotFoundHttpException('Page not found');
        }

        try {
            return $this->segmentRepository->find(SegmentId::fromString($segmentId));
        } catch (EntityNotFound) {
            throw new NotFoundHttpException('Segment not found');
        }
    }
}
