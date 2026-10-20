<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin\Activity;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Activity\BestEffort\ActivityBestEffortRepository;
use App\Domain\Activity\Stream\ActivityStream;
use App\Domain\Activity\Stream\ActivityStreamRepository;
use App\Domain\Activity\Stream\StreamType;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\Controller\Admin\AdminWebTestCase;
use App\Tests\Domain\Activity\ActivityBuilder;

class RecalculateActivityMetricsRequestHandlerTest extends AdminWebTestCase
{
    public function testItRendersThePage(): void
    {
        $this->provideActivityWithStreams();

        $this->client->loginUser($this->adminUser());

        $this->client->request('GET', '/admin/activities/activity-1/recalculate');

        $this->assertResponseIsSuccessful();
        $crawler = $this->client->getCrawler();
        $this->assertStringContainsString('Ricalcola i dati dell', $crawler->text());
        // Without a pending recalculation there is nothing to confirm.
        $this->assertCount(0, $crawler->filter('input[name="metrics[]"]'));
    }

    public function testItRecalculatesAndShowsTheDifferences(): void
    {
        $this->provideActivityWithStreams();

        $this->client->loginUser($this->adminUser());

        $this->client->request('POST', '/admin/activities/activity-1/recalculate', [
            '_csrf_token' => $this->getCsrfToken(),
            'activityId' => 'activity-1',
            'action' => 'recalculate',
        ]);

        $this->assertResponseStatusCodeSame(303);

        // The recalculation is waiting for confirmation, the activity itself is untouched.
        $activity = $this->getContainer()->get(ActivityRepository::class)->find(ActivityId::fromString('activity-1'));
        $this->assertTrue($activity->hasPendingRecalculation());
        $this->assertFalse($activity->hasRecalculatedMetrics());

        $this->client->request('GET', '/admin/activities/activity-1/recalculate');
        $this->assertResponseIsSuccessful();
        $crawler = $this->client->getCrawler();
        $this->assertCount(1, $crawler->filter('input[name="metrics[]"][value="distance"]'));
        $this->assertCount(1, $crawler->filter('input[name="metrics[]"][value="movingTime"]'));
    }

    public function testItAppliesTheSelectedValues(): void
    {
        $this->provideActivityWithStreams();

        $this->client->loginUser($this->adminUser());

        $this->client->request('POST', '/admin/activities/activity-1/recalculate', [
            '_csrf_token' => $this->getCsrfToken(),
            'activityId' => 'activity-1',
            'action' => 'recalculate',
        ]);

        $this->client->request('POST', '/admin/activities/activity-1/recalculate', [
            '_csrf_token' => $this->getCsrfToken(),
            'activityId' => 'activity-1',
            'action' => 'apply',
            'metrics' => ['distance'],
        ]);

        $this->assertResponseStatusCodeSame(303);

        $activity = $this->getContainer()->get(ActivityRepository::class)->find(ActivityId::fromString('activity-1'));
        $this->assertFalse($activity->hasPendingRecalculation());
        $this->assertTrue($activity->hasRecalculatedMetrics());
        // Only the distance was selected, the times keep the values of the device.
        $this->assertNotNull($activity->getRecalculatedMetrics()?->getDistance());
        $this->assertNull($activity->getRecalculatedMetrics()?->getMovingTimeInSeconds());
        $this->assertSame(3600, $activity->getDeviceMovingTimeInSeconds());
    }

    public function testItAppliesTheDerivedDataOnlyWhenTheGroupIsSelected(): void
    {
        $this->provideActivityWithStreams();

        $this->client->loginUser($this->adminUser());

        $this->client->request('POST', '/admin/activities/activity-1/recalculate', [
            '_csrf_token' => $this->getCsrfToken(),
            'activityId' => 'activity-1',
            'action' => 'recalculate',
        ]);

        $this->client->request('GET', '/admin/activities/activity-1/recalculate');
        $crawler = $this->client->getCrawler();
        $this->assertCount(1, $crawler->filter('input[name="recalculateBestEfforts"]'));

        // Without selecting the group the personal best are not recalculated.
        $this->client->request('POST', '/admin/activities/activity-1/recalculate', [
            '_csrf_token' => $this->getCsrfToken(),
            'activityId' => 'activity-1',
            'action' => 'apply',
            'metrics' => ['distance'],
        ]);

        $this->assertCount(
            0,
            $this->getContainer()->get(ActivityBestEffortRepository::class)
                ->findAllByActivity(ActivityId::fromString('activity-1'))
        );

        // Selecting the group recalculates them.
        $this->client->request('POST', '/admin/activities/activity-1/recalculate', [
            '_csrf_token' => $this->getCsrfToken(),
            'activityId' => 'activity-1',
            'action' => 'recalculate',
        ]);
        $this->client->request('POST', '/admin/activities/activity-1/recalculate', [
            '_csrf_token' => $this->getCsrfToken(),
            'activityId' => 'activity-1',
            'action' => 'apply',
            'metrics' => ['distance'],
            'recalculateBestEfforts' => '1',
        ]);

        $this->assertGreaterThan(
            0,
            $this->getContainer()->get(ActivityBestEffortRepository::class)
                ->findAllByActivity(ActivityId::fromString('activity-1'))
                ->count()
        );
    }

    public function testItRevertsToTheDeviceValues(): void
    {
        $this->provideActivityWithStreams();

        $this->client->loginUser($this->adminUser());

        $this->client->request('POST', '/admin/activities/activity-1/recalculate', [
            '_csrf_token' => $this->getCsrfToken(),
            'activityId' => 'activity-1',
            'action' => 'recalculate',
        ]);
        $this->client->request('POST', '/admin/activities/activity-1/recalculate', [
            '_csrf_token' => $this->getCsrfToken(),
            'activityId' => 'activity-1',
            'action' => 'apply',
            'metrics' => ['distance', 'movingTime'],
        ]);

        $this->client->request('POST', '/admin/activities/activity-1/recalculate', [
            '_csrf_token' => $this->getCsrfToken(),
            'activityId' => 'activity-1',
            'action' => 'revert',
        ]);

        $this->assertResponseStatusCodeSame(303);

        $activity = $this->getContainer()->get(ActivityRepository::class)->find(ActivityId::fromString('activity-1'));
        $this->assertFalse($activity->hasRecalculatedMetrics());
        $this->assertFalse($activity->hasPendingRecalculation());
    }

    private function getCsrfToken(): string
    {
        // The token is bound to the URI, so it has to be taken from the rendered form.
        $this->client->request('GET', '/admin/activities/activity-1/recalculate');
        $token = $this->client->getCrawler()->filter('input[name="_csrf_token"]')->first()->attr('value');
        $this->assertIsString($token);

        return $token;
    }

    private function provideActivityWithStreams(): void
    {
        $activityId = ActivityId::fromString('activity-1');

        $this->getContainer()->get(ActivityRepository::class)->add(ActivityWithRawData::fromState(
            ActivityBuilder::fromDefaults()
                ->withActivityId($activityId)
                ->withDistance(\App\Infrastructure\Measurement\Length\Kilometer::from(10))
                ->withMovingTimeInSeconds(3600)
                ->withElapsedTimeInSeconds(3600)
                ->build(),
            [],
        ));

        $streamRepository = $this->getContainer()->get(ActivityStreamRepository::class);
        foreach ([
            [StreamType::TIME, [0, 1800, 3600]],
            [StreamType::DISTANCE, [0, 5000, 10000]],
            [StreamType::LAT_LNG, [[50.0, 4.0], [50.05, 4.0], [50.09, 4.0]]],
        ] as [$type, $data]) {
            $streamRepository->add(ActivityStream::create(
                activityId: $activityId,
                streamType: $type,
                streamData: $data,
                createdOn: SerializableDateTime::fromString('2023-01-01 00:00:00'),
            ));
        }
    }
}
