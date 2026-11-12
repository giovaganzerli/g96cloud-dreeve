<?php

declare(strict_types=1);

namespace App\Domain\Collection\AddCollection;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityIds;
use App\Domain\Collection\ProvideCollectionStatisticsFromPayload;
use App\Domain\Collection\Statistics\CollectionStatistics;
use App\Domain\Image\NewImage;
use App\Domain\Image\ProvideLocalImageFromDropZonePayload;
use App\Infrastructure\CQRS\Command\Deserialize\CouldNotDeserializeCommand;
use App\Infrastructure\CQRS\Command\Deserialize\DeserializableCommand;
use App\Infrastructure\CQRS\Command\Deserialize\ProvidesCommandName;
use App\Infrastructure\CQRS\Command\DomainCommand;
use App\Infrastructure\CQRS\Command\ProvidesFlashMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class AddCollection extends DomainCommand implements DeserializableCommand, ProvidesFlashMessage
{
    use ProvideCollectionStatisticsFromPayload;
    use ProvideLocalImageFromDropZonePayload;
    use ProvidesCommandName;

    /**
     * @param array<string, string> $statisticOverrides
     * @param string[]              $statisticOrder
     */
    private function __construct(
        private string $name,
        private ?string $description,
        private ActivityIds $activityIds,
        private ?string $immichAlbumId,
        private ?NewImage $newImage,
        private array $statisticOverrides,
        private CollectionStatistics $customStatistics,
        private array $statisticOrder,
    ) {
    }

    public static function fromPayload(array $payload): self
    {
        if (!isset($payload['name']) || !is_string($payload['name'])) {
            throw CouldNotDeserializeCommand::invalidPayload('A "name" is required.');
        }

        $name = trim($payload['name']);
        if ('' === $name) {
            throw CouldNotDeserializeCommand::invalidPayload('The name cannot be empty.');
        }

        $activityIds = ActivityIds::empty();
        if (isset($payload['activityIds']) && is_array($payload['activityIds'])) {
            $ids = [];
            foreach ($payload['activityIds'] as $activityId) {
                if (!is_string($activityId) || '' === trim($activityId)) {
                    continue;
                }
                $ids[] = ActivityId::fromPrefixedOrUnprefixed(trim($activityId));
            }
            $activityIds = ActivityIds::fromArray($ids);
        }

        [$newImages] = self::parseImages($payload, 'localImagePath');
        [$statisticOverrides, $customStatistics, $statisticOrder] = self::parseStatistics($payload);

        return new self(
            name: $name,
            description: self::optionalString($payload['description'] ?? null),
            activityIds: $activityIds,
            immichAlbumId: self::optionalString($payload['immichAlbumId'] ?? null),
            newImage: $newImages[0] ?? null,
            statisticOverrides: $statisticOverrides,
            customStatistics: $customStatistics,
            statisticOrder: $statisticOrder,
        );
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getActivityIds(): ActivityIds
    {
        return $this->activityIds;
    }

    public function getImmichAlbumId(): ?string
    {
        return $this->immichAlbumId;
    }

    public function getNewImage(): ?NewImage
    {
        return $this->newImage;
    }

    /**
     * @return array<string, string>
     */
    public function getStatisticOverrides(): array
    {
        return $this->statisticOverrides;
    }

    public function getCustomStatistics(): CollectionStatistics
    {
        return $this->customStatistics;
    }

    /**
     * @return string[]
     */
    public function getStatisticOrder(): array
    {
        return $this->statisticOrder;
    }

    public function getFlashMessage(TranslatorInterface $translator): string
    {
        return $translator->trans('The collection has been created.');
    }
}
