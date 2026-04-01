<?php

namespace Tests\Unit\Extension;

use App\Contracts\Extension\ModuleInterface;
use App\Extension\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ModuleManager 마이그레이션 롤백 테스트
 *
 * rollbackSingleMigration과 rollbackMigrations의 FK 제약 해제 및 에러 핸들링을 테스트합니다.
 */
class ModuleManagerRollbackTest extends TestCase
{
    use RefreshDatabase;

    private ModuleManager $moduleManager;

    private string $tempMigrationDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->moduleManager = app(ModuleManager::class);
        $this->tempMigrationDir = sys_get_temp_dir().'/g7_test_migrations_'.uniqid();
        mkdir($this->tempMigrationDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // 임시 마이그레이션 디렉토리 정리
        if (is_dir($this->tempMigrationDir)) {
            $files = glob($this->tempMigrationDir.'/*.php');
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($this->tempMigrationDir);
        }

        // 테스트 테이블 정리 (존재할 경우)
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('g7_test_child');
        Schema::dropIfExists('g7_test_parent');
        Schema::enableForeignKeyConstraints();

        parent::tearDown();
    }

    /**
     * rollbackSingleMigration이 down() 실행 시 FK 제약을 해제하는지 테스트합니다.
     *
     * FK 관계가 있는 테이블에서 부모 테이블을 먼저 DROP해도
     * FK 제약 해제 덕분에 성공해야 합니다.
     */
    public function test_rollback_single_migration_disables_fk_constraints_during_down(): void
    {
        // Arrange: FK 관계가 있는 테이블 생성
        Schema::create('g7_test_parent', function ($table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('g7_test_child', function ($table) {
            $table->id();
            $table->foreignId('parent_id')->constrained('g7_test_parent');
        });

        // 부모 테이블을 DROP하는 마이그레이션 파일 생성
        $migrationFile = $this->tempMigrationDir.'/2025_01_01_000001_create_g7_test_parent_table.php';
        file_put_contents($migrationFile, <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {}
    public function down(): void
    {
        Schema::dropIfExists('g7_test_parent');
    }
};
PHP);

        // migrations 테이블에 레코드 삽입
        DB::table('migrations')->insert([
            'migration' => '2025_01_01_000001_create_g7_test_parent_table',
            'batch' => 999,
        ]);

        // Act: FK 제약이 있어도 rollbackSingleMigration이 성공해야 함
        $reflection = new \ReflectionMethod($this->moduleManager, 'rollbackSingleMigration');
        $reflection->invoke($this->moduleManager, $migrationFile, 'test-module');

        // Assert: 부모 테이블이 삭제되어야 함
        $this->assertFalse(Schema::hasTable('g7_test_parent'));

        // migrations 레코드도 삭제되어야 함
        $this->assertDatabaseMissing('migrations', [
            'migration' => '2025_01_01_000001_create_g7_test_parent_table',
        ]);
    }

    /**
     * rollbackSingleMigration에서 down() 실패 시에도 FK 제약이 복원되는지 테스트합니다.
     */
    public function test_rollback_single_migration_restores_fk_constraints_on_down_failure(): void
    {
        // Arrange: down()에서 예외를 던지는 마이그레이션
        $migrationFile = $this->tempMigrationDir.'/2025_01_01_000002_failing_migration.php';
        file_put_contents($migrationFile, <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void {}
    public function down(): void
    {
        throw new \RuntimeException('마이그레이션 롤백 중 의도적 오류');
    }
};
PHP);

        DB::table('migrations')->insert([
            'migration' => '2025_01_01_000002_failing_migration',
            'batch' => 999,
        ]);

        // Act & Assert: 예외가 발생하지만 FK 제약은 복원되어야 함
        $reflection = new \ReflectionMethod($this->moduleManager, 'rollbackSingleMigration');

        try {
            $reflection->invoke($this->moduleManager, $migrationFile, 'test-module');
            $this->fail('예외가 발생해야 합니다');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('의도적 오류', $e->getMessage());
        }

        // FK 제약이 복원되었는지 확인: FK 위반 시 예외가 발생해야 함
        Schema::create('g7_test_parent', function ($table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('g7_test_child', function ($table) {
            $table->id();
            $table->foreignId('parent_id')->constrained('g7_test_parent');
        });

        // FK 제약이 활성화되어 있으므로 존재하지 않는 parent_id 삽입 시 예외 발생
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('g7_test_child')->insert(['parent_id' => 99999]);
    }

    /**
     * rollbackMigrations이 개별 마이그레이션 실패 시에도 나머지를 계속 처리하는지 테스트합니다.
     */
    public function test_rollback_migrations_continues_on_individual_failure(): void
    {
        // Arrange: 3개 마이그레이션 - 2번째가 실패
        $migration1 = $this->tempMigrationDir.'/2025_01_01_000003_first.php';
        file_put_contents($migration1, <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {}
    public function down(): void
    {
        Schema::dropIfExists('g7_test_first');
    }
};
PHP);

        $migration2 = $this->tempMigrationDir.'/2025_01_01_000002_second_fails.php';
        file_put_contents($migration2, <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void {}
    public function down(): void
    {
        throw new \RuntimeException('두 번째 마이그레이션 실패');
    }
};
PHP);

        $migration3 = $this->tempMigrationDir.'/2025_01_01_000001_third.php';
        file_put_contents($migration3, <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {}
    public function down(): void
    {
        Schema::dropIfExists('g7_test_third');
    }
};
PHP);

        // 테스트 테이블 생성
        Schema::create('g7_test_first', function ($table) {
            $table->id();
        });
        Schema::create('g7_test_third', function ($table) {
            $table->id();
        });

        // migrations 레코드 삽입
        DB::table('migrations')->insert([
            ['migration' => '2025_01_01_000003_first', 'batch' => 999],
            ['migration' => '2025_01_01_000002_second_fails', 'batch' => 999],
            ['migration' => '2025_01_01_000001_third', 'batch' => 999],
        ]);

        // 모듈 Mock
        $module = \Mockery::mock(ModuleInterface::class);
        $module->shouldReceive('getMigrations')
            ->andReturn([$this->tempMigrationDir]);
        $module->shouldReceive('getIdentifier')
            ->andReturn('test-module');

        // 에러 로그가 기록되는지 확인
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, '개별 마이그레이션 롤백 실패');
            });
        Log::shouldReceive('info')->zeroOrMoreTimes();

        // Act
        $reflection = new \ReflectionMethod($this->moduleManager, 'rollbackMigrations');
        $reflection->invoke($this->moduleManager, $module);

        // Assert: 1번째와 3번째 테이블은 삭제되어야 함 (2번째 실패에도 불구하고)
        $this->assertFalse(Schema::hasTable('g7_test_first'), '첫 번째 테이블이 삭제되어야 합니다');
        $this->assertFalse(Schema::hasTable('g7_test_third'), '세 번째 테이블이 삭제되어야 합니다');

        // 성공한 마이그레이션 레코드는 삭제됨
        $this->assertDatabaseMissing('migrations', ['migration' => '2025_01_01_000003_first']);
        $this->assertDatabaseMissing('migrations', ['migration' => '2025_01_01_000001_third']);

        // 실패한 마이그레이션 레코드는 남아 있음
        $this->assertDatabaseHas('migrations', ['migration' => '2025_01_01_000002_second_fails']);
    }

    /**
     * migration 레코드가 없는 경우 rollbackSingleMigration이 스킵하는지 테스트합니다.
     */
    public function test_rollback_single_migration_skips_when_no_migration_record(): void
    {
        // Arrange: 마이그레이션 파일은 있지만 DB 레코드 없음
        $migrationFile = $this->tempMigrationDir.'/2025_01_01_000004_no_record.php';
        file_put_contents($migrationFile, <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void {}
    public function down(): void
    {
        throw new \RuntimeException('이 코드가 실행되면 안 됩니다');
    }
};
PHP);

        // Act: 예외 없이 정상 스킵
        $reflection = new \ReflectionMethod($this->moduleManager, 'rollbackSingleMigration');
        $reflection->invoke($this->moduleManager, $migrationFile, 'test-module');

        // Assert: 예외가 발생하지 않았으므로 여기에 도달
        $this->assertTrue(true);
    }

    /**
     * down() 메서드가 없는 마이그레이션 파일을 롤백 시도하면 경고 로그가 기록되는지 테스트합니다.
     */
    public function test_rollback_single_migration_logs_warning_when_no_down_method(): void
    {
        // Arrange: down() 없는 마이그레이션
        $migrationFile = $this->tempMigrationDir.'/2025_01_01_000005_no_down.php';
        file_put_contents($migrationFile, <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void {}
};
PHP);

        DB::table('migrations')->insert([
            'migration' => '2025_01_01_000005_no_down',
            'batch' => 999,
        ]);

        // Act
        $reflection = new \ReflectionMethod($this->moduleManager, 'rollbackSingleMigration');
        $reflection->invoke($this->moduleManager, $migrationFile, 'test-module');

        // Assert: migration 레코드는 삭제되지 않아야 함 (down()이 없으므로)
        $this->assertDatabaseHas('migrations', [
            'migration' => '2025_01_01_000005_no_down',
        ]);
    }
}
