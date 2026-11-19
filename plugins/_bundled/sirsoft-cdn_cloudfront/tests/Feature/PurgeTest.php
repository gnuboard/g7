<?php

namespace Plugins\Sirsoft\CdnCloudfront\Tests\Feature;

use App\Extension\HookManager;
use App\Thumbnails\ThumbnailPurgeBuffer;
use App\Thumbnails\ThumbnailStore;
use Aws\CloudFront\Exception\CloudFrontException;
use Aws\Command;
use Aws\Result;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Plugins\Sirsoft\CdnCloudfront\Listeners\PurgeThumbnailsListener;
use Plugins\Sirsoft\CdnCloudfront\Models\CloudfrontPurge;
use Plugins\Sirsoft\CdnCloudfront\Repositories\Contracts\PurgeQueueRepositoryInterface;
use Plugins\Sirsoft\CdnCloudfront\Services\PurgeService;
use Plugins\Sirsoft\CdnCloudfront\Support\CloudFrontDisk;
use Plugins\Sirsoft\CdnCloudfront\Support\InvalidationPaths;
use Plugins\Sirsoft\CdnCloudfront\Tests\PluginTestCase;

/**
 * 코어 퍼지 훅 → CloudFront 무효화 · 실패 대기열 · 재시도
 *
 * @scenario-manifest tests/scenarios/cloudfront-purge.yaml
 */
class PurgeTest extends PluginTestCase
{
    /**
     * 테스트 시작 — 리스너를 활성 플러그인과 같이 등록하고 시각을 고정한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->registerListeners();
    }

    /**
     * 시각 고정을 푼다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * 요청 하나에서 지운 접두 여러 개가 CreateInvalidation 한 번으로 묶여 나가고, 본문에 와일드카드와 원본 경로가 담긴다.
     *
     * @scenario case=wildcard_and_original
     *
     * @effects single_invalidation_per_batch, wildcard_per_source_and_original_path
     *
     * @return void 반환값 없음
     */
    public function test_purge_batch_sends_one_invalidation_with_wildcards(): void
    {
        $this->configureReady();
        $this->aws->append(new Result(['Invalidation' => ['Id' => 'I1', 'Status' => 'InProgress']]));

        app(ThumbnailPurgeBuffer::class)->batch(function () {
            $buffer = app(ThumbnailPurgeBuffer::class);
            $buffer->add($this->purgeItem('modules/shop/images/p1.jpg', self::CDN.'/modules/shop/images/p1.jpg'));
            $buffer->add($this->purgeItem('modules/shop/images/p2.jpg'));
            // 로컬 공개 디스크 파생본 — CDN 호스트가 아니라 빠진다
            $buffer->add($this->purgeItem('modules/shop/images/p3.jpg', null, 'http://localhost/storage'));
        });

        $this->assertCount(1, $this->commands);
        $command = $this->commands[0];
        $this->assertSame('CreateInvalidation', $command->getName());
        $this->assertSame(self::DISTRIBUTION, $command['DistributionId']);
        $this->assertSame([
            'Quantity' => 3,
            'Items' => [
                '/thumbnails/cloudfront/modules/shop/images/p1.jpg/*',
                '/thumbnails/cloudfront/modules/shop/images/p2.jpg/*',
                '/modules/shop/images/p1.jpg',
            ],
        ], $command['InvalidationBatch']['Paths']);
        $this->assertStringStartsWith('g7-', $command['InvalidationBatch']['CallerReference']);
        $this->assertSame(0, CloudfrontPurge::query()->count());
        $this->assertNotNull(app(PurgeService::class)->status()['last_success_at']);
    }

    /**
     * 무효화용 키·배포 ID 가 비었거나 자동 무효화가 꺼져 있으면 API 를 부르지 않고 대기열에도 넣지 않는다.
     * S3 자격이 있어도 그것으로 대신하지 않는다.
     *
     * @scenario case=credentials_missing
     *
     * @effects no_call_without_purge_credentials
     *
     * @return void 반환값 없음
     */
    public function test_no_call_when_credentials_missing_or_disabled(): void
    {
        config(['filesystems.disks.s3.key' => 'S3KEYPRESENT', 'filesystems.disks.s3.secret' => 's3-secret-present']);

        foreach ([['access_key' => ''], ['secret_key' => ''], ['distribution_id' => ''], ['cdn_base_url' => ''], ['purge_enabled' => false]] as $override) {
            $this->configureReady($override);

            HookManager::doAction('core.thumbnail.after_purge', [$this->purgeItem('modules/a/x.jpg')]);

            $this->assertSame([], $this->commands, json_encode($override));
            $this->assertSame(0, CloudfrontPurge::query()->count());
        }
    }

    /**
     * 무효화가 실패하면 경로 묶음을 대기열에 넣고(5분 뒤 재시도), 사용자 작업은 실패하지 않는다.
     *
     * @scenario case=failure_queued
     *
     * @effects failure_enqueued_for_retry
     *
     * @return void 반환값 없음
     */
    public function test_failure_is_enqueued_for_retry(): void
    {
        $this->configureReady();
        $this->aws->append($this->awsError('AccessDenied', 'User is not authorized to perform cloudfront:CreateInvalidation'));

        HookManager::doAction('core.thumbnail.after_purge', [$this->purgeItem('modules/a/x.jpg')]);

        $this->assertCount(1, $this->commands);
        $row = CloudfrontPurge::query()->sole();
        $this->assertSame(['/thumbnails/cloudfront/modules/a/x.jpg/*'], $row->paths);
        $this->assertSame(1, $row->attempts);
        $this->assertStringStartsWith('AccessDenied:', $row->last_error);
        $this->assertStringNotContainsString('test-secret-value', $row->last_error);
        $this->assertTrue($row->next_attempt_at->equalTo(Carbon::now()->addMinutes(5)));
    }

    /**
     * 재시도 명령은 시각이 된 건만 다시 보내고, 성공하면 대기열에서 지운다. 호출자 참조는 대기 건마다 고정이다.
     *
     * @scenario case=retry_success
     *
     * @effects retry_resends_due_and_clears_on_success
     *
     * @return void 반환값 없음
     */
    public function test_retry_command_resends_due_rows_and_clears_on_success(): void
    {
        $this->configureReady();
        $due = CloudfrontPurge::query()->create(['paths' => ['/a/*'], 'attempts' => 1, 'last_error' => 'x', 'next_attempt_at' => Carbon::now()->subMinute()]);
        $later = CloudfrontPurge::query()->create(['paths' => ['/b/*'], 'attempts' => 1, 'last_error' => 'x', 'next_attempt_at' => Carbon::now()->addMinute()]);
        $this->aws->append(new Result(['Invalidation' => ['Id' => 'I2', 'Status' => 'InProgress']]));

        $this->artisan('sirsoft-cdn_cloudfront:retry-purges')->assertSuccessful();

        $this->assertCount(1, $this->commands);
        $this->assertSame(['/a/*'], $this->commands[0]['InvalidationBatch']['Paths']['Items']);
        $this->assertSame('g7-'.$due->caller_reference, $this->commands[0]['InvalidationBatch']['CallerReference']);
        $this->assertFalse(CloudfrontPurge::query()->whereKey($due->id)->exists());
        $this->assertTrue(CloudfrontPurge::query()->whereKey($later->id)->exists());
    }

    /**
     * 실패가 이어지면 대기가 5·10·20·40분으로 늘고, 5번째 실패에서 재시도를 멈춘다. 멈춘 건은 다시 보내지 않는다.
     *
     * @scenario case=retry_exhausted
     *
     * @effects backoff_doubles_and_stops_after_five_failures
     *
     * @return void 반환값 없음
     */
    public function test_retry_backs_off_and_stops_after_five_failures(): void
    {
        $this->configureReady();
        $this->aws->append($this->awsError('ServiceUnavailable', 'down'));

        HookManager::doAction('core.thumbnail.after_purge', [$this->purgeItem('modules/a/x.jpg')]);
        $row = CloudfrontPurge::query()->sole();
        $waits = [];

        for ($attempt = 2; $attempt <= 5; $attempt++) {
            Carbon::setTestNow($row->next_attempt_at);
            $this->aws->append($this->awsError('ServiceUnavailable', 'down'));
            $before = Carbon::now();

            $result = app(PurgeService::class)->retryDue();
            $row->refresh();

            $this->assertSame(1, $result['retried']);
            $this->assertSame($attempt, $row->attempts);
            $waits[] = $row->next_attempt_at === null ? null : (int) $before->diffInMinutes($row->next_attempt_at);
        }

        $this->assertSame([10, 20, 40, null], $waits);
        $this->assertSame(['pending' => 0, 'abandoned' => 1], array_intersect_key(app(PurgeService::class)->status(), ['pending' => 0, 'abandoned' => 0]));

        $sent = count($this->commands);
        Carbon::setTestNow(Carbon::now()->addDays(2));
        $this->assertSame(0, app(PurgeService::class)->retryDue()['retried']);
        $this->assertCount($sent, $this->commands);
    }

    /**
     * 자격을 지운 동안에는 재시도가 시도 횟수를 쓰지 않는다 — 키를 고치는 사이 대기 건이 상한에 닿아 버려지지 않는다.
     *
     * @scenario case=credentials_missing
     *
     * @effects no_call_without_purge_credentials
     *
     * @return void 반환값 없음
     */
    public function test_retry_waits_without_credentials(): void
    {
        $this->configureReady(['secret_key' => '']);
        $row = CloudfrontPurge::query()->create(['paths' => ['/a/*'], 'attempts' => 2, 'last_error' => 'x', 'next_attempt_at' => Carbon::now()->subMinute()]);

        $this->artisan('sirsoft-cdn_cloudfront:retry-purges')->assertSuccessful();

        $this->assertSame([], $this->commands);
        $this->assertSame(2, $row->refresh()->attempts);
    }

    /**
     * 첫 시도와 재시도가 같은 호출자 참조를 쓴다 — 첫 요청이 실제로는 접수됐는데 응답만 잃은 경우 CloudFront 가
     * 같은 무효화를 돌려줘 같은 경로를 두 번 무효화하지 않는다.
     *
     * @scenario case=retry_success
     *
     * @effects caller_reference_kept_across_retries
     *
     * @return void 반환값 없음
     */
    public function test_retry_reuses_caller_reference_of_first_attempt(): void
    {
        $this->configureReady();
        $this->aws->append($this->awsError('ServiceUnavailable', 'down'));

        HookManager::doAction('core.thumbnail.after_purge', [$this->purgeItem('modules/a/x.jpg')]);
        $row = CloudfrontPurge::query()->sole();
        $first = $this->commands[0]['InvalidationBatch']['CallerReference'];

        $this->assertNotEmpty($row->caller_reference);
        $this->assertSame('g7-'.$row->caller_reference, $first);

        Carbon::setTestNow($row->next_attempt_at);
        $this->aws->append(new Result(['Invalidation' => ['Id' => 'I3', 'Status' => 'InProgress']]));
        app(PurgeService::class)->retryDue();

        $this->assertCount(2, $this->commands);
        $this->assertSame($first, $this->commands[1]['InvalidationBatch']['CallerReference']);
        $this->assertSame($row->paths, $this->commands[1]['InvalidationBatch']['Paths']['Items']);
        $this->assertSame(0, CloudfrontPurge::query()->count());
    }

    /**
     * 재시도는 시각이 된 대기 건들의 경로를 합쳐 다시 접은 뒤 CreateInvalidation 을 한 번만 보낸다 — 건마다 보내면
     * 와일드카드가 배포 전체 진행 중 한도(15)를 금방 채운다. 같은 대기 건 묶음은 다시 보내도 같은 호출자 참조다.
     *
     * @scenario case=retry_merge
     *
     * @effects retry_merges_due_rows_into_one_request
     *
     * @return void 반환값 없음
     */
    public function test_retry_merges_due_rows_into_one_request(): void
    {
        $this->configureReady();
        $a = CloudfrontPurge::query()->create(['paths' => ['/a/*'], 'attempts' => 1, 'last_error' => 'x', 'next_attempt_at' => Carbon::now()->subMinutes(3)]);
        $b = CloudfrontPurge::query()->create(['paths' => ['/b/*', '/x.jpg'], 'attempts' => 1, 'last_error' => 'x', 'next_attempt_at' => Carbon::now()->subMinutes(2)]);
        $c = CloudfrontPurge::query()->create(['paths' => ['/a/sub/*', '/b/y.jpg'], 'attempts' => 1, 'last_error' => 'x', 'next_attempt_at' => Carbon::now()->subMinute()]);
        $later = CloudfrontPurge::query()->create(['paths' => ['/z/*'], 'attempts' => 1, 'last_error' => 'x', 'next_attempt_at' => Carbon::now()->addHour()]);

        // 병합 요청이 호출 제한에 걸리면 세 건 모두 그대로 두고, 다음 차례에 같은 묶음을 같은 참조로 다시 보낸다.
        $this->aws->append($this->awsError('Throttling', 'slow down'));
        $deferred = app(PurgeService::class)->retryDue();

        $this->assertSame(['retried' => 3, 'succeeded' => 0, 'failed' => 0, 'abandoned' => 0, 'deferred' => 3], $deferred);
        $this->assertCount(1, $this->commands);
        $this->assertSame(['/a/*', '/b/*', '/x.jpg'], $this->commands[0]['InvalidationBatch']['Paths']['Items']);
        $reference = $this->commands[0]['InvalidationBatch']['CallerReference'];
        $this->assertNotContains($reference, ['g7-'.$a->caller_reference, 'g7-'.$b->caller_reference, 'g7-'.$c->caller_reference]);
        $this->assertSame([1, 1, 1], [$a->refresh()->attempts, $b->refresh()->attempts, $c->refresh()->attempts]);

        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        $this->aws->append(new Result(['Invalidation' => ['Id' => 'I4', 'Status' => 'InProgress']]));
        $succeeded = app(PurgeService::class)->retryDue();

        $this->assertSame(3, $succeeded['succeeded']);
        $this->assertCount(2, $this->commands);
        $this->assertSame($reference, $this->commands[1]['InvalidationBatch']['CallerReference']);
        $this->assertSame([$later->id], CloudfrontPurge::query()->pluck('id')->all());
    }

    /**
     * 병합 요청이 거절돼도 시도 횟수를 쓰지 않고 건별로 다시 보낸다 — 불량 행만 실패로 세고, 정상 행은 그 자리에서 끝난다.
     * 건별 재전송은 그 건의 첫 시도 본문과 호출자 참조를 그대로 쓴다.
     *
     * @scenario case=retry_isolation
     *
     * @effects merged_failure_isolates_bad_row
     *
     * @return void 반환값 없음
     */
    public function test_merged_failure_is_resent_row_by_row_and_isolates_bad_row(): void
    {
        $this->configureReady();
        $good = CloudfrontPurge::query()->create(['paths' => ['/a/*'], 'attempts' => 1, 'last_error' => 'x', 'next_attempt_at' => Carbon::now()->subMinutes(3)]);
        $bad = CloudfrontPurge::query()->create(['paths' => ['/bad path'], 'attempts' => 1, 'last_error' => 'x', 'next_attempt_at' => Carbon::now()->subMinutes(2)]);
        $last = CloudfrontPurge::query()->create(['paths' => ['/c/*'], 'attempts' => 4, 'last_error' => 'x', 'next_attempt_at' => Carbon::now()->subMinute()]);
        $this->aws->append(
            $this->awsError('InvalidArgument', 'merged rejected'),
            new Result(['Invalidation' => ['Id' => 'I7', 'Status' => 'InProgress']]),
            $this->awsError('InvalidArgument', 'bad path'),
            new Result(['Invalidation' => ['Id' => 'I8', 'Status' => 'InProgress']]),
        );

        $result = app(PurgeService::class)->retryDue();

        $this->assertSame(['retried' => 3, 'succeeded' => 2, 'failed' => 1, 'abandoned' => 0, 'deferred' => 0], $result);
        $this->assertCount(4, $this->commands);
        $this->assertSame(['/a/*'], $this->commands[1]['InvalidationBatch']['Paths']['Items']);
        $this->assertSame('g7-'.$good->caller_reference, $this->commands[1]['InvalidationBatch']['CallerReference']);
        $this->assertSame('g7-'.$bad->caller_reference, $this->commands[2]['InvalidationBatch']['CallerReference']);
        $this->assertSame([$bad->id], CloudfrontPurge::query()->pluck('id')->all());
        $this->assertSame(2, $bad->refresh()->attempts);
        $this->assertStringStartsWith('InvalidArgument: bad path', $bad->last_error);
        $this->assertFalse(CloudfrontPurge::query()->whereKey($last->id)->exists());
    }

    /**
     * 건별 재전송 도중 호출 제한에 걸리면 그 건과 남은 건을 시도 횟수 없이 미룬다 — 남은 건을 보내 봐야 같은 오류다.
     *
     * @scenario case=retry_isolation
     *
     * @effects throttled_retry_does_not_consume_attempts
     *
     * @return void 반환값 없음
     */
    public function test_throttle_during_row_by_row_resend_defers_the_rest(): void
    {
        $this->configureReady();
        $first = CloudfrontPurge::query()->create(['paths' => ['/a/*'], 'attempts' => 1, 'last_error' => 'x', 'next_attempt_at' => Carbon::now()->subMinutes(3)]);
        $second = CloudfrontPurge::query()->create(['paths' => ['/b/*'], 'attempts' => 2, 'last_error' => 'x', 'next_attempt_at' => Carbon::now()->subMinutes(2)]);
        $third = CloudfrontPurge::query()->create(['paths' => ['/c/*'], 'attempts' => 3, 'last_error' => 'x', 'next_attempt_at' => Carbon::now()->subMinute()]);
        $this->aws->append(
            $this->awsError('InvalidArgument', 'merged rejected'),
            new Result(['Invalidation' => ['Id' => 'I9', 'Status' => 'InProgress']]),
            $this->awsError('Throttling', 'slow down'),
        );

        $result = app(PurgeService::class)->retryDue();

        $this->assertSame(['retried' => 3, 'succeeded' => 1, 'failed' => 0, 'abandoned' => 0, 'deferred' => 2], $result);
        $this->assertCount(3, $this->commands);
        $this->assertFalse(CloudfrontPurge::query()->whereKey($first->id)->exists());
        $this->assertSame([2, 3], [$second->refresh()->attempts, $third->refresh()->attempts]);
        $this->assertTrue($third->next_attempt_at->equalTo(Carbon::now()->addMinutes(PurgeService::BASE_DELAY_MINUTES)));
    }

    /**
     * 시각이 된 대기 건을 합친 경로가 요청 하나에 안 들어가면 요청을 나눠 보낸다 — 나눈 묶음도 같은 대기 건이면 같은 참조다.
     *
     * @scenario case=split
     *
     * @effects merged_retry_splits_requests
     *
     * @return void 반환값 없음
     */
    public function test_merged_retry_splits_requests_over_wildcard_limit(): void
    {
        $this->configureReady();

        for ($i = 1; $i <= InvalidationPaths::MAX_WILDCARDS + 1; $i++) {
            CloudfrontPurge::query()->create(['paths' => [sprintf('/tree%02d/*', $i)], 'attempts' => 1, 'last_error' => 'x', 'next_attempt_at' => Carbon::now()->subMinute()]);
        }

        $this->aws->append(
            new Result(['Invalidation' => ['Id' => 'I10', 'Status' => 'InProgress']]),
            $this->awsError('Throttling', 'slow down'),
        );
        $deferred = app(PurgeService::class)->retryDue();

        $this->assertSame(InvalidationPaths::MAX_WILDCARDS + 1, $deferred['deferred']);
        $this->assertSame([InvalidationPaths::MAX_WILDCARDS, 1], array_map(static fn ($command) => $command['InvalidationBatch']['Paths']['Quantity'], $this->commands));
        $references = array_map(static fn ($command) => $command['InvalidationBatch']['CallerReference'], $this->commands);
        $this->assertCount(2, array_unique($references));

        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        $this->aws->append(new Result(['Invalidation' => ['Id' => 'I10', 'Status' => 'InProgress']]), new Result(['Invalidation' => ['Id' => 'I11', 'Status' => 'InProgress']]));
        $succeeded = app(PurgeService::class)->retryDue();

        $this->assertSame(InvalidationPaths::MAX_WILDCARDS + 1, $succeeded['succeeded']);
        $this->assertSame($references, array_map(static fn ($command) => $command['InvalidationBatch']['CallerReference'], array_slice($this->commands, 2)));
        $this->assertSame(0, CloudfrontPurge::query()->count());
    }

    /**
     * 경로가 요청 하나에 안 들어가면 나눠 보내고, 실패한 요청만 대기열에 넣는다. 재시도는 그 묶음과 참조를 그대로 쓴다.
     *
     * @scenario case=split
     *
     * @effects requests_split_within_limits, failure_enqueued_for_retry
     *
     * @return void 반환값 없음
     */
    public function test_purge_splits_requests_and_queues_only_failed_one(): void
    {
        $this->configureReady();
        $items = [];
        for ($i = 1; $i <= InvalidationPaths::MAX_WILDCARDS + 2; $i++) {
            $items[] = ['public_url_prefix' => self::CDN.sprintf('/tree%02d/x', $i), 'original_public_url' => null];
        }
        $this->aws->append(
            new Result(['Invalidation' => ['Id' => 'I12', 'Status' => 'InProgress']]),
            $this->awsError('ServiceUnavailable', 'down'),
        );

        $this->assertSame('queued', app(PurgeService::class)->purgeItems($items));

        $this->assertCount(2, $this->commands);
        $this->assertSame(InvalidationPaths::MAX_WILDCARDS, $this->commands[0]['InvalidationBatch']['Paths']['Quantity']);
        $row = CloudfrontPurge::query()->sole();
        $this->assertSame(['/tree11/x/*', '/tree12/x/*'], $row->paths);
        $this->assertSame('g7-'.$row->caller_reference, $this->commands[1]['InvalidationBatch']['CallerReference']);
    }

    /**
     * 나눈 요청 중 하나가 호출 제한에 걸리면 남은 묶음은 보내지 않고 대기열에 넣는다 — 호출 제한을 더 밀어붙이지 않는다.
     *
     * @scenario case=split
     *
     * @effects throttled_retry_does_not_consume_attempts
     *
     * @return void 반환값 없음
     */
    public function test_throttled_split_request_queues_remaining_without_sending(): void
    {
        $this->configureReady();
        $items = [];
        for ($i = 1; $i <= (InvalidationPaths::MAX_WILDCARDS * 2) + 1; $i++) {
            $items[] = ['public_url_prefix' => self::CDN.sprintf('/tree%02d/x', $i), 'original_public_url' => null];
        }
        $this->aws->append($this->awsError('TooManyInvalidationsInProgress', 'limit'));

        $this->assertSame('queued', app(PurgeService::class)->purgeItems($items));

        $this->assertCount(1, $this->commands);
        $this->assertSame([0, 0, 0], CloudfrontPurge::query()->orderBy('id')->pluck('attempts')->all());
    }

    /**
     * 운영 모드에서 내부 예외(서버 경로·SQL 이 담긴 PHP 오류·DB 오류) 원문은 대기열 사유와 연결 시험 응답에 싣지 않는다.
     * AWS 오류는 코드와 메시지를 그대로 둔다.
     *
     * @scenario case=failure_queued
     *
     * @effects internal_error_detail_not_exposed
     *
     * @return void 반환값 없음
     */
    public function test_internal_exception_detail_is_not_exposed(): void
    {
        config(['app.debug' => false]);
        $this->configureReady();
        $leak = 'fopen(/srv/private/app/secret.key): Failed to open stream';
        $this->aws->append(new \ErrorException($leak), new \ErrorException($leak));

        app(PurgeService::class)->purgeItems([$this->purgeItem('modules/a/x.jpg')]);
        $tested = app(PurgeService::class)->testConnection();

        $this->assertStringNotContainsString('/srv/private', CloudfrontPurge::query()->sole()->last_error);
        $this->assertFalse($tested['ok']);
        $this->assertStringNotContainsString('/srv/private', (string) $tested['error']);
    }

    /**
     * 재시도를 멈춘 지 오래된 건은 정기 작업이 지운다. 보관 기간 안의 멈춘 건과 재시도 대기 건은 남는다.
     *
     * @scenario case=retry_exhausted
     *
     * @effects old_abandoned_rows_pruned
     *
     * @return void 반환값 없음
     */
    public function test_retry_prunes_old_abandoned_rows(): void
    {
        $old = CloudfrontPurge::query()->create(['paths' => ['/old/*'], 'attempts' => 5, 'last_error' => 'x', 'next_attempt_at' => null]);
        $old->forceFill(['updated_at' => Carbon::now()->subDays(PurgeService::ABANDONED_RETENTION_DAYS + 1)])->saveQuietly();
        $recent = CloudfrontPurge::query()->create(['paths' => ['/recent/*'], 'attempts' => 5, 'last_error' => 'x', 'next_attempt_at' => null]);
        $waiting = CloudfrontPurge::query()->create(['paths' => ['/waiting/*'], 'attempts' => 1, 'last_error' => 'x', 'next_attempt_at' => Carbon::now()->addHour()]);

        $this->artisan('sirsoft-cdn_cloudfront:retry-purges')->assertSuccessful();

        $this->assertEqualsCanonicalizing([$recent->id, $waiting->id], CloudfrontPurge::query()->pluck('id')->all());
    }

    /**
     * 진행 중 무효화 한도(`TooManyInvalidationsInProgress`)·호출 제한(Throttling) 오류는 시도 횟수로 세지 않는다 — 앞선
     * 무효화가 끝나기를 기다리는 동안 대기 건이 상한에 닿아 버려지지 않게 한다. 다음 정기 작업에서 다시 보낸다.
     *
     * @scenario case=throttled
     *
     * @effects throttled_retry_does_not_consume_attempts
     *
     * @return void 반환값 없음
     */
    public function test_throttled_retry_does_not_consume_attempts(): void
    {
        $this->configureReady();

        foreach (['TooManyInvalidationsInProgress', 'Throttling'] as $code) {
            CloudfrontPurge::query()->delete();
            $this->commands = [];
            $first = CloudfrontPurge::query()->create(['paths' => ['/a/*'], 'attempts' => 4, 'last_error' => 'x', 'next_attempt_at' => Carbon::now()->subMinute()]);
            $second = CloudfrontPurge::query()->create(['paths' => ['/b/*'], 'attempts' => 2, 'last_error' => 'x', 'next_attempt_at' => Carbon::now()->subMinute()]);
            $this->aws->append($this->awsError($code, 'limit'));

            $result = app(PurgeService::class)->retryDue();

            $this->assertCount(1, $this->commands, $code);
            $this->assertSame(2, $result['deferred'], $code);
            $this->assertSame(0, $result['abandoned'], $code);
            $this->assertSame([4, 2], [$first->refresh()->attempts, $second->refresh()->attempts], $code);
            $this->assertStringStartsWith($code.':', $first->last_error);
            $this->assertTrue($first->next_attempt_at->equalTo(Carbon::now()->addMinutes(PurgeService::BASE_DELAY_MINUTES)), $code);
        }

        // 첫 시도가 한도 오류면 대기열에는 넣되 시도 횟수는 0 이다.
        CloudfrontPurge::query()->delete();
        $this->aws->append($this->awsError('TooManyInvalidationsInProgress', 'limit'));
        HookManager::doAction('core.thumbnail.after_purge', [$this->purgeItem('modules/a/x.jpg')]);
        $this->assertSame(0, CloudfrontPurge::query()->sole()->attempts);
    }

    /**
     * 실패를 대기열에 넣는 것마저 실패해도(DB 장애) 예외가 리스너 밖으로 나가지 않고 경고 로그만 남는다.
     *
     * @scenario case=failure_queued
     *
     * @effects queue_write_failure_contained
     *
     * @return void 반환값 없음
     */
    public function test_queue_write_failure_does_not_escape_listener(): void
    {
        $this->configureReady();
        $queue = \Mockery::mock(PurgeQueueRepositoryInterface::class);
        $queue->shouldReceive('create')->once()->andThrow(new \RuntimeException('database is gone'));
        $this->app->instance(PurgeQueueRepositoryInterface::class, $queue);
        $this->aws->append($this->awsError('ServiceUnavailable', 'down'));
        Log::spy();

        app(PurgeThumbnailsListener::class)->purge([$this->purgeItem('modules/a/x.jpg')]);

        $this->assertCount(1, $this->commands);
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context = []) => str_contains($context['queue_error'] ?? '', 'database is gone'),
        )->once();
    }

    /**
     * 코어 경로 그대로 — CDN 디스크에 실제 파생본을 두고 `ThumbnailStore::purgePrefix()` 를 부르면 파생본이 지워지고,
     * `core.thumbnail.after_purge` → 이 플러그인 리스너를 거쳐 CreateInvalidation 본문에 파생본 디렉터리와 원본 경로가 담긴다.
     * 원본을 디렉터리째 지우면 원본 쪽도 와일드카드다.
     *
     * @scenario case=core_integration
     *
     * @effects core_purge_prefix_reaches_create_invalidation
     *
     * @return void 반환값 없음
     */
    public function test_core_purge_prefix_reaches_create_invalidation(): void
    {
        config([
            'filesystems.disks.s3.bucket' => 'test-bucket',
            'filesystems.disks.s3.region' => 'ap-northeast-2',
            'filesystems.disks.s3.key' => 'S3TESTKEY',
            'filesystems.disks.s3.secret' => 's3-test-secret',
        ]);
        $this->configureReady();
        $this->assertTrue(app(CloudFrontDisk::class)->register());
        // 디스크 정의(s3 + CDN url)는 그대로 두고 파일 입출력만 로컬 가짜 디스크로 받는다 — url 은 CDN 주소 그대로다.
        Storage::fake('cloudfront', ['url' => self::CDN]);
        config(['core.thumbnail.disk_public' => 'cloudfront']);

        $store = app(ThumbnailStore::class);
        $this->assertSame('cloudfront', $store->diskFor(false));

        $fileDirectory = $store->sourceDirectory('cloudfront', 'modules/shop/images/p1.jpg');
        $folderDirectory = $store->sourceDirectory('cloudfront', 'modules/shop/images/42');
        Storage::disk('cloudfront')->put($fileDirectory.'/sm_0123abcd.jpeg', 'derived');
        Storage::disk('cloudfront')->put($folderDirectory.'/a.jpg/sm_0123abcd.jpeg', 'derived');
        $this->aws->append(new Result(['Invalidation' => ['Id' => 'I5', 'Status' => 'InProgress']]));
        $this->aws->append(new Result(['Invalidation' => ['Id' => 'I6', 'Status' => 'InProgress']]));

        $this->assertSame(1, $store->purgePrefix('cloudfront', 'modules/shop/images/p1.jpg'));
        $this->assertSame(1, $store->purgePrefix('cloudfront', 'modules/shop/images/42', true));

        $this->assertFalse(Storage::disk('cloudfront')->directoryExists($fileDirectory));
        $this->assertFalse(Storage::disk('cloudfront')->directoryExists($folderDirectory));
        $this->assertCount(2, $this->commands);
        $this->assertSame([
            '/thumbnails/cloudfront/modules/shop/images/p1.jpg/*',
            '/modules/shop/images/p1.jpg',
        ], $this->commands[0]['InvalidationBatch']['Paths']['Items']);
        $this->assertSame([
            '/modules/shop/images/42/*',
            '/thumbnails/cloudfront/modules/shop/images/42/*',
        ], $this->commands[1]['InvalidationBatch']['Paths']['Items']);
        $this->assertSame(0, CloudfrontPurge::query()->count());
    }

    /**
     * CloudFront 오류 응답을 만듭니다.
     *
     * @param  string  $code  오류 코드
     * @param  string  $message  오류 메시지
     * @return CloudFrontException 예외
     */
    private function awsError(string $code, string $message): CloudFrontException
    {
        return new CloudFrontException($message, new Command('CreateInvalidation'), ['code' => $code, 'message' => $message]);
    }
}
