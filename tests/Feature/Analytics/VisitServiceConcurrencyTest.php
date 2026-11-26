<?php

namespace Tests\Feature\Analytics;

use App\Analytics\VisitorStoreLock;
use App\Models\Analytics\AnalyticsAction;
use App\Models\Analytics\AnalyticsActionName;
use App\Models\Analytics\AnalyticsCollectFailure;
use App\Models\Analytics\AnalyticsConversion;
use App\Models\Analytics\AnalyticsVisit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * 방문 적재 동시성 — 실제 프로세스 여러 개가 같은 순간에 적재한다 (실 MySQL).
 *
 * 같은 방문자의 동시 요청이 범위 잠금(간격 잠금)으로 서로 기다리다 교착(1213)이 나던 것을 방문자
 * 키 잠금으로 한 줄로 세워 없앴다. 한 프로세스 안에서는 동시성을 만들 수 없으므로 워커
 * 프로세스(`tests/Feature/Analytics/Support/concurrent-track-worker.php`)를 장벽 시각에 맞춰 함께 출발시킨다.
 *
 * 교착이 났는지는 실패 목록으로 알 수 없다 — 재시도가 다시 해서 성공하면 남지 않는다. 그래서
 * 워커가 적재 트랜잭션 롤백 횟수를 세어 돌려준다. 워커들이 커밋하므로 이 시험도 테스트
 * 트랜잭션으로 감싸지 않고, 만든 행은 시작 시점의 ID 경계로 직접 지운다.
 */
class VisitServiceConcurrencyTest extends TestCase
{
    /** 동시에 띄울 워커 수 */
    private const WORKERS = 6;

    /** 워커 하나가 연달아 보낼 비콘 수 */
    private const BEACONS_PER_WORKER = 4;

    /** @var array<class-string, int> 시험 시작 시점의 테이블별 최대 ID */
    private array $watermarks = [];

    /**
     * 테스트 준비 — MySQL 확인·ID 경계 기록.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('InnoDB 잠금 동작을 보는 시험이라 MySQL 에서만 돈다.');
        }

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
        foreach (array_reverse($this->ledgerModels()) as $model) {
            if (isset($this->watermarks[$model])) {
                $model::query()->where('id', '>', $this->watermarks[$model])->delete();
            }
        }

        parent::tearDown();
    }

    /**
     * 워커들이 쓸 수 있는 원장 모델 (지울 때는 역순).
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
     * 워커들을 장벽 시각에 맞춰 함께 출발시키고 결과를 모읍니다.
     *
     * @param  array<int, string>  $visitorKeys  워커별 방문자 키
     * @param  string  $cacheStore  워커가 쓸 캐시 저장소
     * @param  string  $tag  URL 꼬리표
     * @return array<int, array{serialized: bool, store: string, visit_ids: array<int, int|null>, action_ids: array<int, int|null>, rollbacks: int}> 워커별 결과
     */
    private function runWorkers(array $visitorKeys, string $cacheStore, string $tag): array
    {
        $php = (new PhpExecutableFinder)->find(false) ?: PHP_BINARY;
        $script = __DIR__.'/Support/concurrent-track-worker.php';
        // 워커 부팅(수백 ms~수 초)이 끝난 뒤 함께 출발하도록 장벽을 넉넉히 둔다.
        $startAt = sprintf('%.6F', microtime(true) + 6);

        $processes = [];
        foreach ($visitorKeys as $key) {
            $process = new Process(
                [$php, $script, $key, (string) self::BEACONS_PER_WORKER, $startAt, $tag],
                base_path(),
                ['APP_ENV' => 'testing', 'CACHE_STORE' => $cacheStore],
                null,
                120,
            );
            $process->start();
            $processes[] = $process;
        }

        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), '워커가 실패했다: '.$process->getErrorOutput().$process->getOutput());

            $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
            $decoded = json_decode((string) end($lines), true);
            $this->assertIsArray($decoded, '워커 출력이 JSON 이 아니다: '.$process->getOutput());
            $results[] = $decoded;
        }

        return $results;
    }

    /**
     * 같은 방문자 키로 비콘을 동시에 보내면 교착 없이 방문 1개·액션 N개가 남는다.
     *
     * @scenario store_lock=database|redis, concurrency=same_visitor
     *
     * @effects concurrent_same_visitor_beacons_never_deadlock
     *
     * @return void 없음
     */
    public function test_same_visitor_concurrent_beacons_make_one_visit_without_deadlock(): void
    {
        foreach (['database' => 'c0ffee00c0ffee00c0ffee00c0ffee01', 'redis' => 'c0ffee00c0ffee00c0ffee00c0ffee02'] as $store => $key) {
            $results = $this->runWorkers(array_fill(0, self::WORKERS, $key), $store, 'same-'.$store);

            $total = self::WORKERS * self::BEACONS_PER_WORKER;
            $visitIds = array_unique(array_merge(...array_column($results, 'visit_ids')));
            $actionIds = array_filter(array_merge(...array_column($results, 'action_ids')));

            foreach ($results as $result) {
                $this->assertTrue($result['serialized'], "{$store}: 워커는 방문자 키 잠금 경로를 타야 한다");
            }
            $this->assertSame(0, array_sum(array_column($results, 'rollbacks')), "{$store}: 교착·잠금 대기로 롤백된 적재가 없어야 한다");
            $this->assertCount(1, $visitIds, "{$store}: 같은 방문자의 동시 비콘은 방문 하나로 묶여야 한다");
            $this->assertNotContains(null, $visitIds, "{$store}: 모든 비콘이 적재돼야 한다");
            $this->assertCount($total, array_unique($actionIds), "{$store}: 비콘마다 액션이 하나씩 남아야 한다");

            $visit = AnalyticsVisit::query()->where('visitor_key', $key)->sole();
            $this->assertSame($total, (int) $visit->actions_count, "{$store}: 방문 카운터가 액션 수와 같아야 한다");
            $this->assertSame($total, AnalyticsAction::query()->where('visit_id', $visit->id)->count());
            $this->assertSame(0, AnalyticsCollectFailure::query()->where('id', '>', $this->watermarks[AnalyticsCollectFailure::class])->count(), "{$store}: 실패 목록에 남으면 안 된다");
        }
    }

    /**
     * 서로 다른 방문자 키로 동시에 보내면 방문이 키마다 하나씩 생긴다.
     *
     * 워커들이 같은 제목을 실어, 처음 보는 이름을 여럿이 동시에 사전에 넣는 경합도 함께 만든다.
     * 경합에 진 쪽의 재조회가 트랜잭션 스냅샷을 읽으면 먼저 확정된 행을 못 보고 비콘을 버렸다.
     *
     * @scenario store_lock=database, concurrency=different_visitors
     *
     * @effects concurrent_different_visitors_make_one_visit_each, concurrent_dictionary_insert_inside_store_keeps_the_beacon
     *
     * @return void 없음
     */
    public function test_different_visitors_concurrent_beacons_make_one_visit_each(): void
    {
        $keys = [];
        for ($i = 0; $i < self::WORKERS; $i++) {
            $keys[] = sprintf('d1ff00%026x', $i + 1);
        }

        $results = $this->runWorkers($keys, 'database', 'different');

        $this->assertSame(0, array_sum(array_column($results, 'rollbacks')), '교착·잠금 대기로 롤백된 적재가 없어야 한다');

        foreach ($keys as $index => $key) {
            $this->assertTrue($results[$index]['serialized']);
            $this->assertCount(1, array_unique($results[$index]['visit_ids']), '한 방문자의 비콘은 한 방문으로 묶여야 한다');
            $this->assertSame(1, AnalyticsVisit::query()->where('visitor_key', $key)->count(), '키마다 방문이 하나여야 한다');
        }

        $visitIds = array_merge(...array_column($results, 'visit_ids'));
        // 워커들은 같은 제목을 싣는다 — 처음 보는 이름을 여럿이 동시에 사전에 넣어도 비콘이 버려지면 안 된다.
        $this->assertNotContains(null, $visitIds, '이름 사전 동시 삽입이 비콘을 버리면 안 된다');
        $this->assertCount(self::WORKERS, array_unique($visitIds), '방문자마다 다른 방문이어야 한다');
        $this->assertSame(0, AnalyticsCollectFailure::query()->where('id', '>', $this->watermarks[AnalyticsCollectFailure::class])->count(), '실패 목록에 남으면 안 된다');
    }

    /**
     * 워커가 잠금 키를 남기지 않는다 — 시험 뒤 같은 키를 바로 잠글 수 있다.
     *
     * @scenario store_lock=database, concurrency=same_visitor
     *
     * @effects store_lock_is_released_after_commit
     *
     * @return void 없음
     */
    public function test_workers_release_the_visitor_lock(): void
    {
        $key = 'c0ffee00c0ffee00c0ffee00c0ffee03';
        $this->runWorkers(array_fill(0, 2, $key), 'database', 'release');

        config(['cache.default' => 'database']);
        $lock = Cache::lock(VisitorStoreLock::key($key), 1);

        $this->assertTrue($lock->get(), '워커가 끝나면 잠금이 풀려 있어야 한다');
        $lock->release();
    }
}
