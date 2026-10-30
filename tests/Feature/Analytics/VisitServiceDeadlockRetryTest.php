<?php

namespace Tests\Feature\Analytics;

use App\Analytics\TrackingRequest;
use App\Contracts\Repositories\Analytics\VisitRepositoryInterface;
use App\Enums\Analytics\CollectFailureReason;
use App\Models\Analytics\AnalyticsAction;
use App\Models\Analytics\AnalyticsActionName;
use App\Models\Analytics\AnalyticsCollectFailure;
use App\Models\Analytics\AnalyticsConversion;
use App\Models\Analytics\AnalyticsVisit;
use App\Repositories\Analytics\VisitRepository;
use App\Services\Analytics\VisitService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

/**
 * 방문 적재 — 같은 방문자의 동시 요청이 만든 교착을 재시도로 넘긴다.
 *
 * 같은 방문자의 요청 두 개(서버 폴백과 비콘, 탭 두 개, 연속 화면 이동)가 거의 동시에 오면
 * 둘 다 「열린 방문」을 잠그며 읽고(`FOR UPDATE` + 범위 조건) 그 뒤 방문 행을 고치거나
 * 새로 넣는다. InnoDB 는 이 순서에서 교착(1213)을 감지해 한쪽을 롤백한다 — 정상 동작이고,
 * 롤백된 쪽은 처음부터 다시 하면 앞선 쪽이 남긴 방문에 붙는다. 다시 하지 않으면 방문 기록이
 * 통째로 버려지고 진단 화면에 「DB 오류」로 쌓인다.
 *
 * 재시도는 바깥 트랜잭션이 없을 때(수집 요청의 실제 상황)만 일어난다 — 바깥 트랜잭션이 있으면
 * MySQL 이 그 트랜잭션 전체를 이미 되돌렸으므로 다시 해서는 안 되고, 프레임워크도 그 경우
 * 재시도하지 않는다. 그래서 이 시험은 테스트 트랜잭션으로 감싸지 않고 바깥 트랜잭션이 없는
 * 상태에서 돌며, 만든 행은 시작 시점의 ID 경계로 직접 지운다.
 */
class VisitServiceDeadlockRetryTest extends TestCase
{
    /** @var array<class-string, int> 시험 시작 시점의 테이블별 최대 ID */
    private array $watermarks = [];

    /** 시험 전용 방문자 키 — 다른 행과 섞이지 않게 고정 (쿠키 형식: 소문자 16진 32자) */
    private const VISITOR_KEY = 'dead10ccdead10ccdead10ccdead10cc';

    /** @var int 열린 방문 조회 호출 수 */
    private int $lookups = 0;

    /**
     * 테스트 준비 — 설정 고정·ID 경계 기록.
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
    }

    /**
     * 테스트 정리 — 이 시험이 만든 행만 지운다.
     *
     * @return void 없음
     */
    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
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
     * 수집 요청 하나를 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 값
     * @return TrackingRequest 수집 요청
     */
    private function request(array $overrides = []): TrackingRequest
    {
        return new TrackingRequest(...array_merge([
            'type' => 'pageview',
            'url' => 'https://example.test/deadlock-retry/'.uniqid(),
            'title' => '교착 재시도 '.uniqid(),
            'ip' => '203.0.113.51',
            'userAgent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0.0.0 Safari/537.36',
            'browserLanguage' => 'ko-KR',
            'resolution' => '1536x864',
            'cookies' => ['g7_vid' => self::VISITOR_KEY],
            'receivedAt' => now()->format('Y-m-d H:i:s'),
        ], $overrides));
    }

    /**
     * 열린 방문 조회가 처음 N 번 교착으로 실패하는 저장소를 끼웁니다.
     *
     * 실제 교착과 같은 모양(SQLSTATE 40001 / 1213 문구를 담은 QueryException)으로 던진다.
     *
     * @param  int  $failures  교착으로 실패할 횟수
     * @return void 없음
     */
    private function failLookupsWithDeadlock(int $failures): void
    {
        $test = $this;

        $this->app->instance(VisitRepositoryInterface::class, new class($failures, $test) extends VisitRepository
        {
            /**
             * 교착으로 실패할 남은 횟수와 조회 횟수를 셀 시험 인스턴스를 받는다.
             *
             * @param  int  $remaining  남은 교착 실패 횟수
             * @param  VisitServiceDeadlockRetryTest  $test  조회 횟수를 세는 시험
             */
            public function __construct(private int $remaining, private VisitServiceDeadlockRetryTest $test) {}

            /**
             * 남은 횟수만큼 교착 예외를 던지고, 그 뒤에는 실제 조회로 넘긴다.
             *
             * @param  string  $visitorKey  방문자 키
             * @param  Carbon  $since  열린 방문으로 볼 최소 마지막 행동 시각
             * @return AnalyticsVisit|null 열린 방문
             */
            public function findOpenVisitForUpdate(string $visitorKey, Carbon $since): ?AnalyticsVisit
            {
                $this->test->countLookup();

                if ($this->remaining-- > 0) {
                    throw new QueryException(
                        'mysql',
                        'select * from `g7_analytics_visits` where `visitor_key` = ? and `last_action_at` >= ? order by `last_action_at` desc limit 1 for update',
                        [$visitorKey, $since->toDateTimeString()],
                        new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'),
                    );
                }

                return parent::findOpenVisitForUpdate($visitorKey, $since);
            }
        });
    }

    /**
     * 열린 방문 조회 호출을 셉니다 (끼운 저장소가 부른다).
     *
     * @return void 없음
     */
    public function countLookup(): void
    {
        $this->lookups++;
    }

    /**
     * 이 시험 시작 뒤 남은 DB 오류 실패 건수.
     *
     * @return int 건수
     */
    private function newDbErrorFailures(): int
    {
        return AnalyticsCollectFailure::query()
            ->where('id', '>', $this->watermarks[AnalyticsCollectFailure::class])
            ->where('reason', CollectFailureReason::DbError->value)
            ->count();
    }

    /**
     * 교착으로 롤백된 요청은 다시 시도해 앞선 요청의 방문에 붙고, 실패로 남지 않는다.
     *
     * 앞선 요청이 이미 방문을 열어 둔 상태(서버 폴백 뒤의 비콘, 탭 두 개의 두 번째 요청)에서
     * 뒤 요청의 첫 시도가 교착 희생자가 된다. 다시 시도하면 같은 방문을 찾아 액션만 더한다 —
     * 방문이 두 행으로 갈라지지 않는다.
     *
     * @scenario source=server+beacon, visit_state=open
     *
     * @effects deadlocked_store_is_retried_into_the_same_visit
     *
     * @return void 없음
     */
    public function test_deadlocked_request_is_retried_and_joins_the_open_visit(): void
    {
        $first = app(VisitService::class)->track($this->request(['source' => 'server', 'title' => null, 'resolution' => null]));
        $this->assertNotNull($first['visit_id'], '앞선 요청은 방문을 열어야 한다');

        $this->failLookupsWithDeadlock(1);
        $second = app(VisitService::class)->track($this->request(['source' => 'beacon']));

        $this->assertSame(2, $this->lookups, '교착 뒤 한 번 더 조회해야 한다');
        $this->assertSame($first['visit_id'], $second['visit_id'], '다시 시도한 요청은 열린 방문에 붙어야 한다');
        $this->assertSame(1, AnalyticsVisit::query()->where('visitor_key', self::VISITOR_KEY)->count(), '방문이 두 행으로 갈라지면 안 된다');
        $this->assertSame(2, AnalyticsAction::query()->where('visit_id', $first['visit_id'])->count());
        $this->assertSame(0, $this->newDbErrorFailures(), '재시도로 적재된 요청은 실패 목록에 남지 않아야 한다');
    }

    /**
     * 교착이 계속되면 정해진 횟수만 다시 하고, 그때 한 번만 실패로 남긴다.
     *
     * @scenario source=beacon, visit_state=new
     *
     * @effects deadlock_retries_are_bounded_and_logged_once
     *
     * @return void 없음
     */
    public function test_exhausted_retries_record_one_db_error(): void
    {
        $this->failLookupsWithDeadlock(PHP_INT_MAX);

        $result = app(VisitService::class)->track($this->request(['init' => true]));

        $this->assertNull($result['visit_id']);
        $this->assertSame(3, $this->lookups, '최대 3번까지 시도한다');
        $this->assertSame(0, AnalyticsVisit::query()->where('visitor_key', self::VISITOR_KEY)->count(), '실패한 시도는 아무것도 남기지 않는다');
        $this->assertSame(1, $this->newDbErrorFailures(), '재시도가 다 실패했을 때 한 번만 남긴다');
    }

    /**
     * 바깥 트랜잭션 안에서 불리면 다시 하지 않는다.
     *
     * 교착은 바깥 트랜잭션 전체를 되돌리므로, 안쪽만 다시 하면 바깥 작업 없이 통계만 따로
     * 확정되는 반쪽 상태가 된다. 한 번만 시도하고 실패로 남긴다.
     *
     * @scenario source=server, visit_state=new
     *
     * @effects deadlock_inside_outer_transaction_is_not_retried
     *
     * @return void 없음
     */
    public function test_deadlock_inside_an_outer_transaction_is_not_retried(): void
    {
        $this->failLookupsWithDeadlock(PHP_INT_MAX);

        DB::beginTransaction();
        $result = app(VisitService::class)->track($this->request(['source' => 'server']));
        $failures = $this->newDbErrorFailures();
        DB::rollBack();

        $this->assertNull($result['visit_id']);
        $this->assertSame(1, $this->lookups, '바깥 트랜잭션 안에서는 다시 하지 않는다');
        $this->assertSame(1, $failures);
    }
}
