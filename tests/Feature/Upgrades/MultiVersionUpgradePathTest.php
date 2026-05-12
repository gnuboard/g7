<?php

namespace Tests\Feature\Upgrades;

use App\Services\CoreUpdateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 다중 버전 업그레이드 경로 + partial migrate 멱등성 회귀 가드 (§8 + §10-B).
 *
 * 회귀 시나리오:
 *   1. 사용자가 beta.1 또는 beta.2 에서 beta.5 로 직접 점프하는 경우 — 본 테스트는 step 4건
 *      (beta.2/3/4/5) 이 버전 순서대로 실행되는지 in-memory 시뮬레이션으로 검증한다.
 *      (실제 fatal 가능성은 §9.6 잔존 결함으로 명시 — 본 테스트는 step 디스패치 순서 회귀 가드)
 *
 *   2. 마이그레이션이 부분 적용된 상태에서 `core:update --force` 재실행 시 동일 마이그레이션을
 *      재실행해도 SQL error 발생하지 않아야 한다 (Schema::hasColumn 가드 멱등성).
 */
class MultiVersionUpgradePathTest extends TestCase
{
    use RefreshDatabase;

    private string $stubStepDir;

    private array $stubStepFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->stubStepDir = base_path('upgrades');
    }

    protected function tearDown(): void
    {
        foreach ($this->stubStepFiles as $file) {
            if (File::exists($file)) {
                File::delete($file);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function runUpgradeSteps_는_다중_버전_step_을_버전_순서대로_실행한다(): void
    {
        // memory 가 target 이상이어야 stale 가드 우회 (테스트 환경)
        config(['app.version' => '0.0.5']);

        // beta.2 ~ beta.5 시뮬레이션을 0.0.2 ~ 0.0.5 로 대체 (실제 upgrades 디렉토리 오염 회피)
        foreach (['0_0_2', '0_0_3', '0_0_4', '0_0_5'] as $version) {
            $path = $this->stubStepDir.'/Upgrade_'.$version.'_test_multi_version.php';
            $this->stubStepFiles[] = $path;
            $className = 'Upgrade_'.$version.'_test_multi_version';
            File::put($path, <<<PHP
<?php

namespace App\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;

class {$className} implements UpgradeStepInterface
{
    public function run(UpgradeContext \$context): void
    {
        // no-op
    }
}
PHP);
        }

        $service = app(CoreUpdateService::class);

        $executedOrder = [];
        $service->runUpgradeSteps(
            '0.0.1',
            '0.0.5',
            function (string $version) use (&$executedOrder): void {
                $executedOrder[] = $version;
            },
        );

        // upgrade 파일명의 suffix (`_test_multi_version`) 는 prerelease 로 해석되어 버전에 추가됨
        $this->assertSame(
            [
                '0.0.2-test.multi.version',
                '0.0.3-test.multi.version',
                '0.0.4-test.multi.version',
                '0.0.5-test.multi.version',
            ],
            $executedOrder,
            'step 은 버전 순서대로 실행되어야 한다',
        );
    }

    #[Test]
    public function hasColumn_가드된_마이그레이션은_두_번_적용해도_SQL_error_없다(): void
    {
        // 테스트 전용 테이블 준비
        Schema::create('partial_migrate_test', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        try {
            // 멱등 마이그레이션을 함수로 추출 — partial migrate 후 재실행 시뮬레이션
            $idempotentMigration = function (): void {
                Schema::table('partial_migrate_test', function (Blueprint $table): void {
                    if (! Schema::hasColumn('partial_migrate_test', 'extra_col')) {
                        $table->string('extra_col')->nullable();
                    }
                });
            };

            // 1차 적용
            $idempotentMigration();
            $this->assertTrue(Schema::hasColumn('partial_migrate_test', 'extra_col'), '1차 적용 후 컬럼 존재');

            // 2차 적용 — 가드 덕분에 silent skip, SQL error 없어야 함
            $idempotentMigration();
            $this->assertTrue(Schema::hasColumn('partial_migrate_test', 'extra_col'), '2차 적용 후에도 컬럼 존재 (silent skip)');
        } finally {
            Schema::dropIfExists('partial_migrate_test');
        }
    }

    #[Test]
    public function hasColumn_가드없는_마이그레이션은_두_번_적용시_SQL_error_발생한다(): void
    {
        // 회귀 가드의 역명제 — 가드 없을 때 SQL error 가 발생함을 명시 확인
        Schema::create('partial_migrate_no_guard_test', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        try {
            $nonIdempotentMigration = function (): void {
                Schema::table('partial_migrate_no_guard_test', function (Blueprint $table): void {
                    $table->string('extra_col')->nullable();
                });
            };

            $nonIdempotentMigration();
            $this->assertTrue(Schema::hasColumn('partial_migrate_no_guard_test', 'extra_col'));

            // 2차 적용 — SQL error 발생 기대
            $exceptionCaught = false;
            try {
                $nonIdempotentMigration();
            } catch (\Throwable $e) {
                $exceptionCaught = true;
                // SQLSTATE 또는 "Duplicate column"/"already exists" 메시지 확인
                $this->assertMatchesRegularExpression(
                    '/(already exists|duplicate column|SQLSTATE)/i',
                    $e->getMessage(),
                );
            }

            $this->assertTrue(
                $exceptionCaught,
                '가드 없는 마이그레이션을 2번 적용하면 SQL error 발생해야 한다 (회귀 가드 의의 확인)',
            );
        } finally {
            Schema::dropIfExists('partial_migrate_no_guard_test');
        }
    }
}
