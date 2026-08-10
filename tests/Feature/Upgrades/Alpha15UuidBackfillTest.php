<?php

namespace Tests\Feature\Upgrades;

use App\Extension\UpgradeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 코어 7.0.0-alpha.15 업그레이드 스텝의 UUID 백필 누락 회귀 테스트.
 *
 * 재현 대상 결함: `whereNull('uuid')` 로 필터한 결과를 `chunk()`(OFFSET 페이지네이션)로
 * 순회하면서 콜백이 그 필터 컬럼(uuid)을 채운다. 처리된 행이 다음 페이지 조회의 결과
 * 집합에서 이탈하므로 OFFSET 이 아직 처리되지 않은 행을 건너뛴다.
 *
 * 250건(청크 100) 기준: 1페이지 100건 처리 → 남은 150건에 OFFSET 100 적용 → 50건만
 * 조회 → 3페이지는 OFFSET 200 이라 0건 → 총 150건만 처리되고 100건이 누락된다.
 * `chunkById()`(키셋 순회)로 바꾸면 누락이 0건이 된다.
 */
class Alpha15UuidBackfillTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 시드 건수 (스텝의 청크 크기 100 보다 충분히 커야 skip 이 드러남)
     */
    private const SEED_COUNT = 250;

    /**
     * 시드 사용자 이메일 프리픽스 (tearDown 정리 대상 식별용)
     */
    private const EMAIL_PREFIX = 'alpha15-chunkskip-';

    private object $upgrade;

    private UpgradeContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        require_once base_path('upgrades/Upgrade_7_0_0_alpha_15.php');

        $class = 'App\\Upgrades\\Upgrade_7_0_0_alpha_15';
        $this->upgrade = new $class;
        $this->context = new UpgradeContext(
            fromVersion: '7.0.0-alpha.14',
            toVersion: '7.0.0-alpha.15',
            currentStep: '7.0.0-alpha.15',
        );
    }

    /**
     * 스텝의 ALTER TABLE 은 MySQL 에서 암묵적 커밋을 유발하므로 RefreshDatabase 의
     * 트랜잭션 롤백으로 되돌아가지 않는다. 시드 행과 컬럼 제약을 명시적으로 원복한다.
     */
    protected function tearDown(): void
    {
        try {
            DB::table('users')->where('email', 'LIKE', self::EMAIL_PREFIX.'%')->delete();

            if ($this->uuidColumnIsNotNull()) {
                Schema::table('users', function ($table) {
                    $table->uuid('uuid')->nullable()->change();
                });
            }
        } catch (\Throwable) {
            // 정리 실패는 테스트 결과에 영향을 주지 않도록 무시 (RefreshDatabase 가 다음 실행에서 재생성)
        }

        parent::tearDown();
    }

    /**
     * uuid 컬럼이 NOT NULL 인지 확인합니다.
     *
     * @return bool NOT NULL 이면 true
     */
    private function uuidColumnIsNotNull(): bool
    {
        $column = DB::selectOne("SHOW COLUMNS FROM {$this->context->table('users')} WHERE Field = 'uuid'");

        return $column !== null && $column->Null === 'NO';
    }

    /**
     * uuid 가 NULL 인 사용자 레코드를 시드합니다.
     *
     * @return void
     */
    private function seedUsersWithoutUuid(): void
    {
        $rows = [];

        for ($i = 0; $i < self::SEED_COUNT; $i++) {
            $rows[] = [
                'uuid' => null,
                'name' => 'chunk skip user '.$i,
                'email' => self::EMAIL_PREFIX.$i.'@g7.test',
                'password' => bcrypt('password'),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 100) as $batch) {
            DB::table('users')->insert($batch);
        }
    }

    public function test_uuid_backfill_leaves_no_null_rows_when_filter_column_is_mutated_during_iteration(): void
    {
        $this->seedUsersWithoutUuid();

        $this->assertSame(
            self::SEED_COUNT,
            DB::table('users')->whereNull('uuid')->count(),
            '시드 직후에는 uuid NULL 레코드가 시드 건수만큼 존재해야 합니다.'
        );

        $this->upgrade->run($this->context);

        $this->assertSame(
            0,
            DB::table('users')->whereNull('uuid')->count(),
            'OFFSET 순회로 인해 백필되지 않고 남은 레코드가 있습니다 (chunk → chunkById 필요).'
        );

        // 백필이 끝나야 컬럼을 NOT NULL 로 조일 수 있다 — 한 건이라도 남으면
        // 스텝의 ALTER TABLE 이 실패하거나 제약이 적용되지 않은 채 넘어간다.
        $this->assertTrue(
            $this->uuidColumnIsNotNull(),
            '백필 후 uuid 컬럼이 NOT NULL 로 전환되지 않았습니다.'
        );
    }

    public function test_backfilled_uuids_are_unique(): void
    {
        $this->seedUsersWithoutUuid();

        $this->upgrade->run($this->context);

        $uuids = DB::table('users')
            ->where('email', 'LIKE', self::EMAIL_PREFIX.'%')
            ->pluck('uuid')
            ->all();

        $this->assertCount(self::SEED_COUNT, $uuids);
        $this->assertCount(self::SEED_COUNT, array_unique($uuids), '백필된 UUID 는 모두 고유해야 합니다.');
    }
}
