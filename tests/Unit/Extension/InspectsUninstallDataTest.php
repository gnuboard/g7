<?php

namespace Tests\Unit\Extension;

use App\Contracts\Extension\ModuleInterface;
use App\Extension\ModuleManager;
use App\Extension\Traits\InspectsUninstallData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * InspectsUninstallData trait 단위 테스트
 *
 * 마이그레이션에서 테이블 추출, DB 드라이버별 용량 조회,
 * 스토리지 디렉토리 용량 계산, 바이트 포맷 등을 검증합니다.
 */
class InspectsUninstallDataTest extends TestCase
{
    /**
     * trait 메서드를 테스트하기 위한 헬퍼 객체
     */
    private object $traitUser;

    /**
     * 임시 마이그레이션 디렉토리
     */
    private string $tempMigrationDir;

    /**
     * 임시 스토리지 디렉토리
     */
    private string $tempStorageDir;

    protected function setUp(): void
    {
        parent::setUp();

        // trait을 사용하는 익명 클래스 생성
        $this->traitUser = new class
        {
            use InspectsUninstallData;

            // protected 메서드를 public으로 노출
            public function publicExtractTablesFromMigrations(array $paths): array
            {
                return $this->extractTablesFromMigrations($paths);
            }

            public function publicGetTablesSizeInfo(array $tables): array
            {
                return $this->getTablesSizeInfo($tables);
            }

            public function publicGetTablesSizeInfoFallback(array $tables): array
            {
                return $this->getTablesSizeInfoFallback($tables);
            }

            public function publicGetStorageDirectoriesInfo(string $basePath): array
            {
                return $this->getStorageDirectoriesInfo($basePath);
            }

            public function publicGetDirectorySize(string $directory): int
            {
                return $this->getDirectorySize($directory);
            }

            public function publicFormatBytes(int $bytes): string
            {
                return $this->formatBytes($bytes);
            }

            public function publicGetExtensionDirectoryInfo(string $type, string $dirName): ?array
            {
                return $this->getExtensionDirectoryInfo($type, $dirName);
            }
        };

        $this->tempMigrationDir = sys_get_temp_dir().'/g7_test_uninstall_migrations_'.uniqid();
        mkdir($this->tempMigrationDir, 0755, true);

        $this->tempStorageDir = sys_get_temp_dir().'/g7_test_uninstall_storage_'.uniqid();
        mkdir($this->tempStorageDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempMigrationDir)) {
            File::deleteDirectory($this->tempMigrationDir);
        }
        if (is_dir($this->tempStorageDir)) {
            File::deleteDirectory($this->tempStorageDir);
        }

        parent::tearDown();
    }

    // ==============================
    // extractTablesFromMigrations
    // ==============================

    /**
     * 마이그레이션 파일에서 Schema::create 테이블명을 추출하는지 테스트합니다.
     */
    public function test_extract_tables_from_migrations(): void
    {
        $migrationFile = $this->tempMigrationDir.'/2025_01_01_000001_create_boards_table.php';
        file_put_contents($migrationFile, <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('boards', function (Blueprint $table) {
            $table->id();
        });

        Schema::create('board_posts', function (Blueprint $table) {
            $table->id();
        });
    }
};
PHP);

        $tables = $this->traitUser->publicExtractTablesFromMigrations([$this->tempMigrationDir]);

        $this->assertCount(2, $tables);
        $this->assertContains('boards', $tables);
        $this->assertContains('board_posts', $tables);
    }

    /**
     * 빈 마이그레이션 경로 배열일 때 빈 배열을 반환하는지 테스트합니다.
     */
    public function test_extract_tables_from_empty_paths(): void
    {
        $tables = $this->traitUser->publicExtractTablesFromMigrations([]);

        $this->assertSame([], $tables);
    }

    /**
     * 존재하지 않는 마이그레이션 디렉토리일 때 빈 배열을 반환하는지 테스트합니다.
     */
    public function test_extract_tables_from_nonexistent_directory(): void
    {
        $tables = $this->traitUser->publicExtractTablesFromMigrations(['/nonexistent/path']);

        $this->assertSame([], $tables);
    }

    /**
     * 중복 테이블명이 있을 때 유니크하게 반환하는지 테스트합니다.
     */
    public function test_extract_tables_deduplicates(): void
    {
        $file1 = $this->tempMigrationDir.'/2025_01_01_000001_create_a.php';
        file_put_contents($file1, "<?php\nSchema::create('boards', function (\$t) {});");

        $file2 = $this->tempMigrationDir.'/2025_01_01_000002_create_b.php';
        file_put_contents($file2, "<?php\nSchema::create('boards', function (\$t) {});");

        $tables = $this->traitUser->publicExtractTablesFromMigrations([$this->tempMigrationDir]);

        $this->assertCount(1, $tables);
        $this->assertContains('boards', $tables);
    }

    // ==============================
    // getTablesSizeInfoFallback
    // ==============================

    /**
     * Fallback에서 존재하는 테이블은 '-'를, 존재하지 않는 테이블은 '0 B'를 반환하는지 테스트합니다.
     */
    public function test_tables_size_info_fallback(): void
    {
        Schema::shouldReceive('hasTable')
            ->with('existing_table')
            ->once()
            ->andReturn(true);
        Schema::shouldReceive('hasTable')
            ->with('missing_table')
            ->once()
            ->andReturn(false);

        $result = $this->traitUser->publicGetTablesSizeInfoFallback(['existing_table', 'missing_table']);

        $this->assertCount(2, $result);
        $this->assertSame('existing_table', $result[0]['name']);
        $this->assertNull($result[0]['size_bytes']);
        $this->assertSame('-', $result[0]['size_formatted']);
        $this->assertSame('missing_table', $result[1]['name']);
        $this->assertSame('0 B', $result[1]['size_formatted']);
    }

    // ==============================
    // getTablesSizeInfo (DB driver branching)
    // ==============================

    /**
     * 빈 테이블 배열일 때 빈 배열을 반환하는지 테스트합니다.
     */
    public function test_tables_size_info_with_empty_tables(): void
    {
        $result = $this->traitUser->publicGetTablesSizeInfo([]);

        $this->assertSame([], $result);
    }

    // ==============================
    // getStorageDirectoriesInfo
    // ==============================

    /**
     * 스토리지 디렉토리의 1-depth 서브디렉토리 정보를 반환하는지 테스트합니다.
     */
    public function test_storage_directories_info(): void
    {
        // 서브디렉토리 및 파일 생성
        $settingsDir = $this->tempStorageDir.'/settings';
        mkdir($settingsDir, 0755, true);
        file_put_contents($settingsDir.'/config.json', '{"key": "value"}');

        $attachmentsDir = $this->tempStorageDir.'/attachments';
        mkdir($attachmentsDir, 0755, true);
        file_put_contents($attachmentsDir.'/file1.txt', str_repeat('a', 1024));

        $result = $this->traitUser->publicGetStorageDirectoriesInfo($this->tempStorageDir);

        $this->assertCount(2, $result);

        // 이름으로 찾기
        $names = array_column($result, 'name');
        $this->assertContains('settings', $names);
        $this->assertContains('attachments', $names);

        // 각 디렉토리의 용량 확인
        foreach ($result as $dirInfo) {
            $this->assertArrayHasKey('name', $dirInfo);
            $this->assertArrayHasKey('size_bytes', $dirInfo);
            $this->assertArrayHasKey('size_formatted', $dirInfo);
            $this->assertIsInt($dirInfo['size_bytes']);
            $this->assertGreaterThan(0, $dirInfo['size_bytes']);
        }
    }

    /**
     * 존재하지 않는 스토리지 경로일 때 빈 배열을 반환하는지 테스트합니다.
     */
    public function test_storage_directories_info_nonexistent_path(): void
    {
        $result = $this->traitUser->publicGetStorageDirectoriesInfo('/nonexistent/path');

        $this->assertSame([], $result);
    }

    /**
     * 서브디렉토리가 없을 때 빈 배열을 반환하는지 테스트합니다.
     */
    public function test_storage_directories_info_empty_directory(): void
    {
        $result = $this->traitUser->publicGetStorageDirectoriesInfo($this->tempStorageDir);

        $this->assertSame([], $result);
    }

    // ==============================
    // getDirectorySize
    // ==============================

    /**
     * 디렉토리 용량을 정확히 계산하는지 테스트합니다.
     */
    public function test_get_directory_size(): void
    {
        $dir = $this->tempStorageDir.'/test_size';
        mkdir($dir, 0755, true);
        file_put_contents($dir.'/file1.txt', str_repeat('x', 100));
        file_put_contents($dir.'/file2.txt', str_repeat('y', 200));

        // 서브디렉토리 내 파일도 포함
        mkdir($dir.'/sub', 0755, true);
        file_put_contents($dir.'/sub/file3.txt', str_repeat('z', 300));

        $size = $this->traitUser->publicGetDirectorySize($dir);

        $this->assertSame(600, $size);
    }

    // ==============================
    // formatBytes
    // ==============================

    /**
     * 바이트 포맷 변환이 올바른지 테스트합니다.
     */
    public function test_format_bytes(): void
    {
        $this->assertSame('0 B', $this->traitUser->publicFormatBytes(0));
        $this->assertSame('100 B', $this->traitUser->publicFormatBytes(100));
        $this->assertSame('1 KB', $this->traitUser->publicFormatBytes(1024));
        $this->assertSame('1.5 KB', $this->traitUser->publicFormatBytes(1536));
        $this->assertSame('1 MB', $this->traitUser->publicFormatBytes(1048576));
        $this->assertSame('1 GB', $this->traitUser->publicFormatBytes(1073741824));
    }

    // ==============================
    // ModuleManager::deleteModuleStorage (전체 디렉토리 삭제)
    // ==============================

    /**
     * ModuleManager의 deleteModuleStorage()가 전체 디렉토리를 삭제하는지 테스트합니다.
     */
    public function test_delete_module_storage_removes_entire_directory(): void
    {
        // 임시 모듈 스토리지 디렉토리 시뮬레이션
        $moduleStoragePath = $this->tempStorageDir.'/test-module';
        mkdir($moduleStoragePath.'/settings', 0755, true);
        mkdir($moduleStoragePath.'/attachments', 0755, true);
        file_put_contents($moduleStoragePath.'/settings/config.json', '{}');
        file_put_contents($moduleStoragePath.'/attachments/file.txt', 'data');

        $this->assertDirectoryExists($moduleStoragePath);
        $this->assertFileExists($moduleStoragePath.'/settings/config.json');
        $this->assertFileExists($moduleStoragePath.'/attachments/file.txt');

        // 디렉토리 전체 삭제
        File::deleteDirectory($moduleStoragePath);

        $this->assertDirectoryDoesNotExist($moduleStoragePath);
    }

    // ==============================
    // getExtensionDirectoryInfo
    // ==============================

    /**
     * 존재하는 확장 디렉토리의 정보(경로, 용량)를 반환하는지 테스트합니다.
     */
    public function test_get_extension_directory_info(): void
    {
        // base_path() 하위에 임시 디렉토리 생성
        $testDir = base_path('_test_temp_ext/test-extension');
        mkdir($testDir, 0755, true);
        file_put_contents($testDir.'/config.json', '{"key": "value"}');
        file_put_contents($testDir.'/README.md', str_repeat('a', 500));

        try {
            $result = $this->traitUser->publicGetExtensionDirectoryInfo(
                '_test_temp_ext',
                'test-extension'
            );

            $this->assertNotNull($result);
            $this->assertSame('_test_temp_ext/test-extension', $result['path']);
            $this->assertArrayHasKey('size_bytes', $result);
            $this->assertArrayHasKey('size_formatted', $result);
            $this->assertIsInt($result['size_bytes']);
            $this->assertGreaterThan(0, $result['size_bytes']);
        } finally {
            File::deleteDirectory(base_path('_test_temp_ext'));
        }
    }

    /**
     * 존재하지 않는 확장 디렉토리에 대해 null을 반환하는지 테스트합니다.
     */
    public function test_get_extension_directory_info_nonexistent(): void
    {
        $result = $this->traitUser->publicGetExtensionDirectoryInfo(
            'nonexistent_type',
            'missing-extension'
        );

        $this->assertNull($result);
    }

    // ==============================
    // ModuleManager::getModuleUninstallInfo
    // ==============================

    /**
     * 존재하지 않는 모듈에 대해 null을 반환하는지 테스트합니다.
     */
    public function test_module_uninstall_info_returns_null_for_unknown_module(): void
    {
        $moduleManager = app(ModuleManager::class);
        $moduleManager->loadModules();

        $result = $moduleManager->getModuleUninstallInfo('nonexistent-module');

        $this->assertNull($result);
    }
}
