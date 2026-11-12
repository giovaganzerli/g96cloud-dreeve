<?php

declare(strict_types=1);

namespace App\Infrastructure\Twig;

use App\Domain\Activity\ActivityType;
use App\Domain\Activity\SportType\SportType;
use App\Domain\Collection\Statistics\CollectionStatisticCategory;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFunction;

final readonly class EnumTwigExtension
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    #[AsTwigFunction('sportTypeOptions')]
    public function getSportTypeOptions(): array
    {
        return array_map(
            fn (SportType $sportType): array => [
                'value' => $sportType->value,
                'label' => $sportType->trans($this->translator),
            ],
            SportType::cases(),
        );
    }

    #[AsTwigFunction('collectionStatisticCategoryLabel')]
    public function getCollectionStatisticCategoryLabel(CollectionStatisticCategory $category): string
    {
        return $category->trans($this->translator);
    }

    #[AsTwigFunction('activityTypeFrom')]
    public function getActivityTypeFrom(string $activityType): ActivityType
    {
        return ActivityType::from($activityType);
    }
}
