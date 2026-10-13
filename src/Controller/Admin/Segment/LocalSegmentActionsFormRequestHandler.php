<?php

declare(strict_types=1);

namespace App\Controller\Admin\Segment;

use App\Domain\Segment\Geometry\GpxSegmentTrackParser;
use App\Domain\Segment\LocalSegmentGeometry;
use App\Domain\Segment\ManageLocalSegment\ManageLocalSegment;
use App\Domain\Segment\SegmentId;
use App\Domain\Segment\SegmentRepository;
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
final readonly class LocalSegmentActionsFormRequestHandler
{
    public function __construct(
        private Environment $twig,
        private SegmentRepository $segments,
        private LocalSegmentGeometry $geometry,
        private CommandBus $commands,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route(path: '/admin/segments/{segmentId}/local/{action}', name: 'admin_local_segment_action', requirements: ['segmentId' => 'segment-[^/]+', 'action' => 'search|generate|upload'], methods: ['GET', 'POST'], priority: 10)]
    public function handle(Request $request, string $segmentId, string $action): Response
    {
        try {
            $segment = $this->geometry->forDisplay($this->segments->find(SegmentId::fromString($segmentId)));
        } catch (EntityNotFound) {
            throw new NotFoundHttpException();
        }
        $error = null;
        if ($request->isMethod('POST')) {
            if ('upload' !== $action) {
                throw new NotFoundHttpException();
            }
            if (!$this->csrf->isTokenValid(new CsrfToken('dispatch-command', $request->request->getString('_csrf_token')))) {
                throw new AccessDeniedHttpException('Token CSRF non valido.');
            }
            try {
                $file = $request->files->get('track');
                if (!$file instanceof UploadedFile || !$file->isValid() || $file->getSize() > GpxSegmentTrackParser::MAX_BYTES) {
                    throw CouldNotProcessCommand::withReason('Seleziona un file GPX valido, fino a 5 MB.');
                }
                $this->commands->dispatch(ManageLocalSegment::fromPayload([
                    'segmentId' => $segmentId, 'action' => 'upload',
                    'filename' => $file->getClientOriginalName(), 'content' => base64_encode($file->getContent()),
                ]));
                return new RedirectResponse($this->urls->generate('segment', ['segmentId' => $segmentId]), Response::HTTP_SEE_OTHER);
            } catch (CouldNotProcessCommand|CouldNotDeserializeCommand $exception) {
                $error = $exception->getMessage();
            }
        }
        $response = new HtmlResponse($this->twig->render('html/admin/page/segment/local-segment-action.html.twig', [
            'segment' => $segment, 'action' => $action, 'error' => $error,
            'dispatchCommand' => ManageLocalSegment::getCommandName(),
        ]));
        return $response->setStatusCode(null !== $error ? Response::HTTP_BAD_REQUEST : Response::HTTP_OK);
    }
}
