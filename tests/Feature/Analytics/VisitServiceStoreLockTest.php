<?php

namespace Tests\Feature\Analytics;

use App\Analytics\TrackingRequest;
use App\Analytics\VisitorRecognizer;
use App\Analytics\VisitorStoreLock;
use App\Contracts\Repositories\Analytics\VisitRepositoryInterface;
use App\Models\Analytics\AnalyticsAction;
use App\Models\Analytics\AnalyticsActionName;
use App\Models\Analytics\AnalyticsCollectFailure;
use App\Models\Analytics\AnalyticsConversion;
use App\Models\Analytics\AnalyticsVisit;
use App\Repositories\Analytics\VisitRepository;
use App\Services\Analytics\VisitService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 방문 적재 잠금 — 캐시 저장소에 따라 방문자 키 잠금 경로와 지금 방식(범위 잠금 + 재시도)을 고른다.
 *
 * 같은 방문자의 동시 요청이 범위 잠금(간격 잠금)으로 서로 기다리다 교착(1213)이 나던 것을, 캐시
 * 저장소가 서버 간 공유·원자 잠금을 지원하면(DB·Redis) 방문자 키 잠금으로 한 줄로 세워 없앤다.
 * 파일·배열 캐시, 바깥 트랜잭션, 잠금 대기 초과, 캐시 장애에서는 지금 방식으로 되돌아간다(#841).
 *
 * 잠금 경로는 바깥 트랜잭션이 없을 때만 타므로(수집 요청의 실제 상황) 이 시험은 테스트 트랜잭션으로
 * 감싸지 않고, 만든 행은 시작 시점의 ID 경계로 직접 지운다.
 */
class VisitServiceStoreLockTest extends TestCase
{
    /** @var array<class-string, int> 시험 시작 시점의 테이블별 최대 ID */
    private array $watermarks = [];

    /** 시험 전용 방문자 키 (쿠키 형식: 소문자 16진 32자) */
    private const VISITOR_KEY = '10c4ed0010c4ed0010c4ed0010c4ed00';

    /** @var array<string, int> 저장소 메서드별 호출 수 */
    private array $calls = [];

    /** @var array<int, string> 시험 중 잡은 잠금 키 (정리용) */
    private array $heldLockKeys = [];

    /**
     * 테스트 준비 — 설정 고정·ID 경계 기록·호출을 세는 저장소 끼우기.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['g7_settings.core.analytics.privacy_mode' => 'full']);
        config(['g7_settings.core.analytics.enabled' => true]);

        foreach ($this->ledgerModels() as $model) {
            $this->watermarks[$model] = (int) $model::query()->max('id');
        }

        $this->spyRepository();
    }

    /**
     * 테스트 정리 — 잡은 잠금을 풀고 이 시험이 만든 행만 지운다.
     *
     * @return void 없음
     */
    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        foreach (['database', 'redis'] as $store) {
            foreach (array_unique($this->heldLockKeys) as $key) {
                try {
                    Cache::store($store)->lock($key)->forceRelease();
                } catch (\Throwable) {
                    // 저장소가 없거나 잠금이 이미 풀렸다.
                }
            }
        }

        foreach (array_reverse($this->ledgerModels()) as $model) {
            $model::query()->where('id', '>', $this->watermarks[$model] ?? PHP_INT_MAX)->delete();
        }

        parent::tearDown();
    }

    /**
     * 이 시험이 쓸 수 있는 원장 모델 (지울 때는 역순).
     *
     * @return array<int, class-string> 모델 클래스 목록
     */
    private function ledgerModels(): array
    {
        return [
            AnalyticsActionName::class,
            AnalyticsVisit::class,
            AnalyticsAction::class,
            AnalyticsConversion::class,
            AnalyticsCollectFailure::class,
        ];
    }

    /**
     * 저장소 메서드 호출을 세는 저장소를 끼웁니다 (동작은 실제 저장소 그대로).
     *
     * @return void 없음
     */
    private function spyRepository(): void
    {
        $test = $this;

        $this->app->instance(VisitRepositoryInterface::class, new class($test) extends VisitRepository
        {
            /**
             * 호출 수를 셀 시험 인스턴스를 받는다.
             *
             * @param  VisitServiceStoreLockTest  $test  호출 수를 세는 시험
             */
            public function __construct(private VisitServiceStoreLockTest $test) {}

            /**
             * 범위 잠금 조회 호출을 세고 실제 조회로 넘긴다.
             *
             * @param  string  $visitorKey  방문자 키
             * @param  Carbon  $since  열린 방문으로 볼 최소 마지막 행동 시각
             * @return AnalyticsVisit|null 열린 방문
             */
            public function findOpenVisitForUpdate(string $visitorKey, Carbon $since): ?AnalyticsVisit
            {
                $this->test->countCall('findOpenVisitForUpdate');

                return parent::findOpenVisitForUpdate($visitorKey, $since);
            }

            /**
             * 행 잠금 조회 호출을 세고 실제 조회로 넘긴다.
             *
             * @param  string  $visitorKey  방문자 키
             * @param  Carbon  $since  열린 방문으로 볼 최소 마지막 행동 시각
             * @return AnalyticsVisit|null 열린 방문
             */
            public function findOpenVisitWithRowLock(string $visitorKey, Carbon $since): ?AnalyticsVisit
            {
                $this->test->countCall('findOpenVisitWithRowLock');

                return parent::findOpenVisitWithRowLock($visitorKey, $since);
            }

            /**
             * 범위 잠금 이어받기 호출을 세고 실제 이어받기로 넘긴다.
             *
             * @param  string  $provisionalKey  임시 키
             * @param  string  $newKey  새 쿠키 키
             * @param  Carbon  $since  최소 마지막 행동 시각
             * @return AnalyticsVisit|null 이어받은 방문
             */
            public function adoptServerVisit(string $provisionalKey, string $newKey, Carbon $since): ?AnalyticsVisit
            {
                $this->test->countCall('adoptServerVisit');

                return parent::adoptServerVisit($provisionalKey, $newKey, $since);
            }

            /**
             * 행 잠금 이어받기 호출을 세고 실제 이어받기로 넘긴다.
             *
             * @param  string  $provisionalKey  임시 키
             * @param  string  $newKey  새 쿠키 키
             * @param  Carbon  $since  최소 마지막 행동 시각
             * @return AnalyticsVisit|null 이어받은 방문
             */
            public function adoptServerVisitWithRowLock(string $provisionalKey, string $newKey, Carbon $since): ?AnalyticsVisit
            {
                $this->test->countCall('adoptServerVisitWithRowLock');

                return parent::adoptServerVisitWithRowLock($provisionalKey, $newKey, $since);
            }
        });
    }

    /**
     * 저장소 메서드 호출을 셉니다 (끼운 저장소가 부른다).
     *
     * @param  string  $method  메서드 이름
     * @return void 없음
     */
    public function countCall(string $method): void
    {
        $this->calls[$method] = ($this->calls[$method] ?? 0) + 1;
    }

    /**
     * 메서드 호출 수.
     *
     * @param  string  $method  메서드 이름
     * @return int 호출 수
     */
    private function callsOf(string $method): int
    {
        return $this->calls[$method] ?? 0;
    }

    /**
     * 기본 캐시 저장소를 바꿉니다.
     *
     * @param  string  $store  저장소 이름
     * @return void 없음
     */
    private function useCacheStore(string $store): void
    {
        config(['cache.default' => $store]);
    }

    /**
     * 수집 요청 하나를 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 값
     * @return TrackingRequest 수집 요청
     */
    private function request(array $overrides = []): TrackingRequest
    {
        return new TrackingRequest(...array_merge([
            'type' => 'pageview',
            'url' => 'https://example.test/store-lock/'.uniqid(),
            'title' => '적재 잠금 '.uniqid(),
            'ip' => '203.0.113.71',
            'userAgent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0.0.0 Safari/537.36',
            'browserLanguage' => 'ko-KR',
            'resolution' => '1536x864',
            'cookies' => ['g7_vid' => self::VISITOR_KEY],
            'receivedAt' => now()->format('Y-m-d H:i:s'),
        ], $overrides));
    }

    /**
     * 다른 소유자로 방문자 키 잠금을 미리 잡아 둡니다 (다른 요청이 적재 중인 상황).
     *
     * @param  string  $visitorKey  방문자 키
     * @return void 없음
     */
    private function holdLock(string $visitorKey): void
    {
        $key = VisitorStoreLock::key($visitorKey);
        $this->heldLockKeys[] = $key;

        $this->assertTrue(Cache::lock($key, VisitorStoreLock::LOCK_SECONDS)->get(), '시험 준비: 잠금을 미리 잡아야 한다');
    }

    /**
     * 공유 캐시 저장소(DB·Redis)면 방문자 키 잠금 경로를 타고 범위 잠금 조회를 쓰지 않는다.
     *
     * 두 번째 요청이 같은 방문에 붙는지, 적재가 끝나면 잠금이 풀리는지도 함께 본다.
     *
     * @scenario store_lock=database|redis, visit_state=new|open
     *
     * @effects shared_cache_store_serializes_same_visitor_stores, store_lock_is_released_after_commit
     *
     * @return void 없음
     */
    public function test_shared_cache_stores_take_the_visitor_lock_path(): void
    {
        foreach (['database', 'redis'] as $store) {
            $this->calls = [];
            $this->useCacheStore($store);
            $key = $store === 'database' ? self::VISITOR_KEY : 'abcdef00abcdef00abcdef00abcdef00';

            $this->assertTrue(app(VisitorStoreLock::class)->isAvailable(), "{$store}: 공유 저장소는 잠금 경로를 써야 한다");

            $first = app(VisitService::class)->track($this->request(['cookies' => ['g7_vid' => $key]]));
            $second = app(VisitService::class)->track($this->request(['cookies' => ['g7_vid' => $key]]));

            $this->assertNotNull($first['visit_id'], "{$store}: 첫 요청은 방문을 열어야 한다");
            $this->assertSame($first['visit_id'], $second['visit_id'], "{$store}: 두 번째 요청은 같은 방문에 붙어야 한다");
            $this->assertSame(2, $this->callsOf('findOpenVisitWithRowLock'), "{$store}: 행 잠금 조회를 써야 한다");
            $this->assertSame(0, $this->callsOf('findOpenVisitForUpdate'), "{$store}: 범위 잠금 조회를 쓰면 안 된다");
            $this->assertSame(1, AnalyticsVisit::query()->where('visitor_key', $key)->count());
            $this->assertSame(2, AnalyticsAction::query()->where('visit_id', $first['visit_id'])->count());

            $lock = Cache::lock(VisitorStoreLock::key($key), 1);
            $this->assertTrue($lock->get(), "{$store}: 적재가 끝나면 잠금이 풀려 있어야 한다");
            $lock->release();
        }
    }

    /**
     * 서버마다 따로인 캐시(파일·배열)면 지금 방식(범위 잠금 + 재시도)으로 적재한다.
     *
     * @scenario store_lock=file|array, visit_state=new|open
     *
     * @effects local_cache_store_keeps_range_lock_with_retry
     *
     * @return void 없음
     */
    public function test_local_cache_stores_keep_the_range_lock_path(): void
    {
        foreach (['file', 'array'] as $store) {
            $this->calls = [];
            $this->useCacheStore($store);

            $this->assertFalse(app(VisitorStoreLock::class)->isAvailable(), "{$store}: 서버마다 따로인 저장소는 잠금을 쓰지 않는다");

            $first = app(VisitService::class)->track($this->request());
            $second = app(VisitService::class)->track($this->request());

            $this->assertSame($first['visit_id'], $second['visit_id'], "{$store}: 같은 방문에 붙어야 한다");
            $this->assertSame(2, $this->callsOf('findOpenVisitForUpdate'), "{$store}: 범위 잠금 조회를 써야 한다");
            $this->assertSame(0, $this->callsOf('findOpenVisitWithRowLock'), "{$store}: 행 잠금 조회를 쓰면 안 된다");

            AnalyticsAction::query()->where('visit_id', $first['visit_id'])->delete();
            AnalyticsVisit::query()->whereKey($first['visit_id'])->delete();
        }
    }

    /**
     * 같은 방문자의 다른 적재가 잠금을 쥔 채 대기 시간을 넘기면 지금 방식으로 적재한다.
     *
     * @scenario store_lock=database, lock_state=held
     *
     * @effects lock_wait_timeout_falls_back_to_range_lock
     *
     * @return void 없음
     */
    public function test_lock_wait_timeout_falls_back_to_the_range_lock_path(): void
    {
        $this->useCacheStore('database');
        $this->holdLock(self::VISITOR_KEY);

        $started = hrtime(true);
        $result = app(VisitService::class)->track($this->request());
        $elapsed = (hrtime(true) - $started) / 1e9;

        $this->assertNotNull($result['visit_id'], '잠금을 못 잡아도 적재는 해야 한다');
        $this->assertSame(1, $this->callsOf('findOpenVisitForUpdate'), '지금 방식으로 되돌아가야 한다');
        $this->assertSame(0, $this->callsOf('findOpenVisitWithRowLock'));
        $this->assertGreaterThanOrEqual(VisitorStoreLock::WAIT_SECONDS - 0.1, $elapsed, '정해진 시간만큼은 기다려야 한다');
        $this->assertLessThan(VisitorStoreLock::WAIT_SECONDS + 1.5, $elapsed, '정해진 시간을 넘겨 기다리면 안 된다');
    }

    /**
     * 캐시 저장소가 잠금 중 오류를 내면 수집을 멈추지 않고 지금 방식으로 적재한다.
     *
     * @scenario store_lock=redis, lock_state=store_error
     *
     * @effects lock_store_error_falls_back_to_range_lock
     *
     * @return void 없음
     */
    public function test_lock_store_error_falls_back_to_the_range_lock_path(): void
    {
        config(['database.redis.broken' => [
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 0,
            'timeout' => 0.2,
            'read_timeout' => 0.2,
            'max_retries' => 0,
        ]]);
        config(['cache.stores.redis_broken' => ['driver' => 'redis', 'connection' => 'broken', 'lock_connection' => 'broken']]);
        $this->useCacheStore('redis_broken');

        $result = app(VisitService::class)->track($this->request());

        $this->assertNotNull($result['visit_id'], '캐시 장애에도 적재는 해야 한다');
        $this->assertSame(1, $this->callsOf('findOpenVisitForUpdate'), '지금 방식으로 되돌아가야 한다');
        $this->assertSame(0, $this->callsOf('findOpenVisitWithRowLock'));
    }

    /**
     * 바깥 트랜잭션 안에서는 공유 저장소여도 잠금을 쓰지 않는다.
     *
     * 잠금이 바깥 트랜잭션 확정 전에 풀려 다음 요청이 확정되지 않은 방문을 못 보기 때문이다.
     *
     * @scenario store_lock=database, transaction=outer
     *
     * @effects outer_transaction_keeps_range_lock
     *
     * @return void 없음
     */
    public function test_outer_transaction_keeps_the_range_lock_path(): void
    {
        $this->useCacheStore('database');

        DB::beginTransaction();
        $available = app(VisitorStoreLock::class)->isAvailable();
        $result = app(VisitService::class)->track($this->request());
        DB::rollBack();

        $this->assertFalse($available);
        $this->assertNotNull($result['visit_id']);
        $this->assertSame(1, $this->callsOf('findOpenVisitForUpdate'));
        $this->assertSame(0, $this->callsOf('findOpenVisitWithRowLock'));
    }

    /**
     * 쿠키를 방금 받은 첫 비콘은 서버 폴백의 임시 키도 잠그고, 그 방문을 행 잠금으로 이어받는다.
     *
     * 서버 폴백은 임시 키로, 첫 비콘은 새 쿠키 키로 적재하므로 자기 키만 잠그면 두 요청이 한 줄에
     * 서지 않는다. 임시 키를 다른 요청이 쥐고 있으면 첫 비콘은 기다렸다가 지금 방식으로 이어받는다.
     *
     * @scenario store_lock=database, source=server+beacon, lock_state=free|held
     *
     * @effects first_beacon_locks_the_provisional_key_too
     *
     * @return void 없음
     */
    public function test_first_beacon_locks_the_provisional_key_and_adopts_with_row_lock(): void
    {
        $this->useCacheStore('database');
        $recognizer = app(VisitorRecognizer::class);

        // 잠금이 비어 있으면 행 잠금으로 이어받는다.
        $serverRequest = $this->request(['source' => 'server', 'cookies' => [], 'title' => null, 'resolution' => null]);
        $server = app(VisitService::class)->track($serverRequest);
        $beacon = app(VisitService::class)->track($this->request(['source' => 'beacon', 'cookies' => [], 'init' => true, 'url' => $serverRequest->url]));

        $this->assertNotNull($server['visit_id']);
        $this->assertSame($server['visit_id'], $beacon['visit_id'], '첫 비콘은 서버 폴백의 방문을 이어받아야 한다');
        $this->assertSame(1, $this->callsOf('adoptServerVisitWithRowLock'));
        $this->assertSame(0, $this->callsOf('adoptServerVisit'));
        $adopted = AnalyticsVisit::query()->findOrFail($server['visit_id']);
        $this->assertSame('cookie', $adopted->visitor_key_type);
        $this->assertSame($beacon['new_cookie'], $adopted->visitor_key);

        // 임시 키를 다른 요청이 쥐고 있으면 기다렸다가 지금 방식으로 이어받는다.
        $this->calls = [];
        $secondServer = $this->request(['source' => 'server', 'cookies' => [], 'title' => null, 'resolution' => null, 'ip' => '203.0.113.72']);
        $server2 = app(VisitService::class)->track($secondServer);
        $this->holdLock($recognizer->configId($secondServer));

        $beacon2 = app(VisitService::class)->track($this->request(['source' => 'beacon', 'cookies' => [], 'init' => true, 'url' => $secondServer->url, 'ip' => '203.0.113.72']));

        $this->assertSame($server2['visit_id'], $beacon2['visit_id']);
        $this->assertSame(1, $this->callsOf('adoptServerVisit'), '임시 키 잠금을 못 잡으면 지금 방식으로 이어받아야 한다');
        $this->assertSame(0, $this->callsOf('adoptServerVisitWithRowLock'));
    }

    /**
     * 행 잠금 조회는 기본키로만 잠근다 — 범위 조건 잠금(간격 잠금)을 걸지 않는다.
     *
     * 순차 실행에서는 범위 잠금이 남아 있어도 결과가 같으므로 SQL 자체를 본다.
     *
     * @scenario store_lock=database, visit_state=open
     *
     * @effects row_lock_lookup_locks_only_the_primary_key
     *
     * @return void 없음
     */
    public function test_row_lock_lookup_locks_only_the_primary_key(): void
    {
        $this->useCacheStore('database');
        app(VisitService::class)->track($this->request());

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        app(VisitService::class)->track($this->request());

        $locking = array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'for update')));
        $this->assertNotEmpty($locking, '찾은 방문 행은 잠가야 한다');

        foreach ($locking as $sql) {
            $this->assertStringContainsString('analytics_visits', $sql);
            $this->assertMatchesRegularExpression('/where `[a-z0-9_]*analytics_visits`\.`id` = \?/', $sql, '기본키로만 잠가야 한다');
            $this->assertStringNotContainsString('last_action_at', $sql, '범위 조건으로 잠그면 안 된다');
            $this->assertStringNotContainsString('visitor_key', $sql, '방문자 키 색인으로 잠그면 안 된다');
        }
    }
}
