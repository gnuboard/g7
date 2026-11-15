<?php

namespace Tests\Feature\Analytics;

use App\Models\Analytics\AnalyticsActionName;
use App\Models\Analytics\AnalyticsSalt;
use App\Repositories\Analytics\ActionNameRepository;
use App\Repositories\Analytics\SaltRepository;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 유일 키 경합 뒤 재조회 — 적재 트랜잭션 안에서도 다른 요청이 먼저 확정한 행을 읽는다.
 *
 * 이름 사전과 일별 솔트는 「없으면 넣고, 유일 키에 걸리면 먼저 넣은 쪽 행을 다시 읽는」 방식이다.
 * 트랜잭션 안의 일반 조회는 트랜잭션 첫 조회 시점의 스냅샷(REPEATABLE READ)을 읽어 그사이 다른
 * 연결이 확정한 행을 보지 못한다. 이름 사전은 방문 적재 트랜잭션 안에서 불리므로 재조회가 빈손이
 * 되어 유일 키 예외를 다시 던지고 비콘 하나가 통째로 버려졌다(#841). 솔트는 지금 적재 트랜잭션
 * 밖에서 불리지만 같은 모양이라, 트랜잭션 안에서 불려도 맞게 읽도록 함께 잠근다. 재조회는 잠금
 * 읽기로 최신 확정 값을 읽어야 한다.
 *
 * 경합은 두 번째 DB 연결로 만든다 — 같은 연결로 끼운 행은 같은 트랜잭션 안이라 스냅샷과 무관하게
 * 보이므로 결함을 재현하지 못한다. 두 번째 연결이 확정한 행은 테스트 트랜잭션이 되돌리지 못하므로
 * 이 시험은 테스트 트랜잭션으로 감싸지 않고, 쓴 행을 직접 지운다.
 *
 * 트러블슈팅 `[case:backend-74]` 의 회귀 그물이다.
 */
class UniqueRaceRereadTest extends TestCase
{
    /** 두 번째 연결의 이름 */
    private const RACE_CONNECTION = 'analytics_race';

    /** 시험 전용 솔트 날짜 — 실제 날짜와 겹치지 않는 먼 미래 */
    private const SALT_DATE = '2099-12-31';

    /** 시험 전용 이름 */
    private const ACTION_NAME = 'example.test/unique-race-reread';

    /**
     * 테스트 준비 — 기본 연결과 같은 설정으로 두 번째 연결을 만들고 남은 행을 지운다.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $default = config('database.default');
        config(['database.connections.'.self::RACE_CONNECTION => config('database.connections.'.$default)]);

        $this->cleanUp();
    }

    /**
     * 테스트 정리 — 열린 트랜잭션을 되돌린 뒤(두 번째 연결의 삭제가 잠금에 막히지 않게) 쓴 행을 지운다.
     *
     * @return void 없음
     */
    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        $this->cleanUp();
        DB::purge(self::RACE_CONNECTION);

        parent::tearDown();
    }

    /**
     * 처음 보는 이름을 다른 요청이 먼저 확정해도 적재 트랜잭션 안의 재조회가 그 행을 돌려준다.
     *
     * @return void 없음
     *
     * @scenario transaction=outer, concurrency=different_visitors
     *
     * @effects dictionary_reread_inside_transaction_sees_row_committed_elsewhere
     */
    public function test_dictionary_reread_inside_transaction_sees_row_committed_elsewhere(): void
    {
        $repo = new ActionNameRepository;
        $hash = md5('title|'.self::ACTION_NAME);
        $committedId = null;

        AnalyticsActionName::creating(function () use ($hash, &$committedId): void {
            if ($committedId !== null) {
                return;
            }

            // 이 요청이 「없음」을 읽은 뒤, INSERT 직전에 다른 요청이 같은 이름을 넣고 확정한다.
            $committedId = (int) $this->race()->table('analytics_action_names')->insertGetId([
                'type' => 'title',
                'name' => self::ACTION_NAME,
                'name_hash' => $hash,
                'url_prefix' => null,
                'created_at' => now(),
            ]);
        });

        try {
            $row = DB::transaction(fn (): AnalyticsActionName => $repo->firstOrCreateByHash('title', self::ACTION_NAME));
        } finally {
            AnalyticsActionName::flushEventListeners();
        }

        $this->assertNotNull($committedId);
        $this->assertSame($committedId, $row->id, '먼저 확정된 행을 돌려줘야 한다');
        $this->assertSame(1, $this->race()->table('analytics_action_names')->where('name_hash', $hash)->count());
    }

    /**
     * 그날 솔트를 다른 요청이 먼저 확정해도 적재 트랜잭션 안의 재조회가 그 값을 돌려준다.
     *
     * 다른 값을 쓰면 같은 날 같은 방문자의 키가 두 갈래로 갈린다.
     *
     * @return void 없음
     *
     * @scenario transaction=outer, concurrency=different_visitors
     *
     * @effects daily_salt_reread_inside_transaction_sees_salt_committed_elsewhere
     */
    public function test_daily_salt_reread_inside_transaction_sees_salt_committed_elsewhere(): void
    {
        $repo = new SaltRepository;
        $committedSalt = str_repeat('ab', 32);
        $inserted = false;

        AnalyticsSalt::creating(function () use ($committedSalt, &$inserted): void {
            if ($inserted) {
                return;
            }

            $inserted = true;

            // 이 요청이 「없음」을 읽은 뒤, INSERT 직전에 다른 요청이 그날 솔트를 넣고 확정한다.
            $this->race()->table('analytics_salts')->insert([
                'salt_date' => self::SALT_DATE,
                'salt' => $committedSalt,
                'created_at' => now(),
            ]);
        });

        try {
            $salt = DB::transaction(fn (): string => $repo->forDate(self::SALT_DATE));
        } finally {
            AnalyticsSalt::flushEventListeners();
        }

        $this->assertTrue($inserted);
        $this->assertSame($committedSalt, $salt, '먼저 확정된 솔트를 써야 같은 날 방문자 키가 갈리지 않는다');
    }

    /**
     * 두 번째 연결 (자동 확정 — 다른 요청 역할).
     *
     * @return Connection 두 번째 연결
     */
    private function race(): Connection
    {
        return DB::connection(self::RACE_CONNECTION);
    }

    /**
     * 이 시험이 쓴 행을 지웁니다.
     *
     * @return void 없음
     */
    private function cleanUp(): void
    {
        $this->race()->table('analytics_action_names')->where('name_hash', md5('title|'.self::ACTION_NAME))->delete();
        $this->race()->table('analytics_salts')->where('salt_date', self::SALT_DATE)->delete();
    }
}
