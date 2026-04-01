<?php

namespace Tests\Unit\Extension;

use App\Contracts\Extension\PluginInterface;
use App\Extension\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PluginManager 마이그레이션 롤백 테스트
 *
 * rollbackSingleMigration과 rollbackMigrations의 FK 제약 해제 및 에러 핸들링을 테스트합니다.
 */
class PluginManagerRollbackTest extends TestCase
{
    use RefreshDatabase;

    private PluginManager $pluginManager;

    private string $tempMigrationDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pluginManager = app(PluginManager::class);
        $this->tempMigrationDir = sys_get_temp_dir().'/g7_test_plugin_migrations_'.uniqid();
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
        Schema::dropIfExists('g7_test_plugin_child');
        Schema::dropIfExists('g7_test_plugin_parent');
        Schema::enableForeignKeyConstraints();

        parent::tearDown();
    }

    /**
     * rollbackSingleMigration이 down() 실행 시 FK 제약을 해제하는지 테스트합니다.
     */
    public function test_rollback_single_migration_disables_fk_constraints_during_down(): void
    {
        // Arrange: FK 관계가 있는 테이블 생성
        Schema::create('g7_test_plugin_parent', function ($table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('g7_test_plugin_child', function ($table) {
            $table->id();
            $table->foreignId('parent_id')->constrained('g7_test_plugin_parent');
        });

        // 부모 테이블을 DROP하는 마이그레이션 파일 생성
        $migrationFile = $this->tempMigrationDir.'/2025_01_01_000001_create_g7_test_plugin_parent_table.php';
        file_put_contents($migrationFile, <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {}
    public function down(): void
    {
        Schema::dropIfExists('g7_test_plugin_parent');
    }
};
PHP);

        DB::table('migrations')->insert([
            'migration' => '2025_01_01_000001_create_g7_test_plugin_parent_table',
            'batch' => 999,
        ]);

        // Act
        $reflection = new \ReflectionMethod($this->pluginManager, 'rollbackSingleMigration');
        $reflection->invoke($this->pluginManager, $migrationFile, 'test-plugin');

        // Assert
        $this->assertFalse(Schema::hasTable('g7_test_plugin_parent'));
        $this->assertDatabaseMissing('migrations', [
            'migration' => '2025_01_01_000001_create_g7_test_plugin_parent_table',
        ]);
    }

    /**
     * rollbackSingleMigration에서 down() 실패 시에도 FK 제약이 복원되는지 테스트합니다.
     */
    public function test_rollback_single_migration_restores_fk_constraints_on_down_failure(): void
    {
        // Arrange
        $migrationFile = $this->tempMigrationDir.'/2025_01_01_000002_failing_migration.php';
        file_put_contents($migrationFile, <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void {}
    public function down(): void
    {
        throw new \RuntimeException('플러그인 마이그레이션 롤백 중 의도적 오류');
    }
};
PHP);

        DB::table('migrations')->insert([
            'migration' => '2025_01_01_000002_failing_migration',
            'batch' => 999,
        ]);

        // Act & Assert
        $reflection = new \ReflectionMethod($this->pluginManager, 'rollbackSingleMigration');

        try {
            $reflection->invoke($this->pluginManager, $migrationFile, 'test-plugin');
            $this->fail('예외가 발생해야 합니다');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('의도적 오류', $e->getMessage());
        }

        // FK 제약이 복원되었는지 확인
        Schema::create('g7_test_plugin_parent', function ($table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('g7_test_plugin_child', function ($table) {
            $table->id();
            $table->foreignId('parent_id')->constrained('g7_test_plugin_parent');
        });

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('g7_test_plugin_child')->insert(['parent_id' => 99999]);
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
        Schema::dropIfExists('g7_test_plugin_first');
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
        Schema::dropIfExists('g7_test_plugin_third');
    }
};
PHP);

        Schema::create('g7_test_plugin_first', function ($table) {
            $table->id();
        });
        Schema::create('g7_test_plugin_third', function ($table) {
            $table->id();
        });

        DB::table('migrations')->insert([
            ['migration' => '2025_01_01_000003_first', 'batch' => 999],
            ['migration' => '2025_01_01_000002_second_fails', 'batch' => 999],
            ['migration' => '2025_01_01_000001_third', 'batch' => 999],
        ]);

        $plugin = \Mockery::mock(PluginInterface::class);
        $plugin->shouldReceive('getMigrations')
            ->andReturn([$this->tempMigrationDir]);
        $plugin->shouldReceive('getIdentifier')
            ->andReturn('test-plugin');

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, '개별 마이그레이션 롤백 실패');
            });
        Log::shouldReceive('info')->zeroOrMoreTimes();

        // Act
        $reflection = new \ReflectionMethod($this->pluginManager, 'rollbackMigrations');
        $reflection->invoke($this->pluginManager, $plugin);

        // Assert
        $this->assertFalse(Schema::hasTable('g7_test_plugin_first'), '첫 번째 테이블이 삭제되어야 합니다');
        $this->assertFalse(Schema::hasTable('g7_test_plugin_third'), '세 번째 테이블이 삭제되어야 합니다');
        $this->assertDatabaseMissing('migrations', ['migration' => '2025_01_01_000003_first']);
        $this->assertDatabaseMissing('migrations', ['migration' => '2025_01_01_000001_third']);
        $this->assertDatabaseHas('migrations', ['migration' => '2025_01_01_000002_second_fails']);
    }
}
