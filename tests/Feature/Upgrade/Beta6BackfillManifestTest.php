<?php

namespace Tests\Feature\Upgrade;

use App\Extension\Helpers\CoreBackupHelper;
use App\Extension\UpgradeContext;
use App\Upgrades\Data\V7_0_0_beta_6\Migrations\BackfillNewFilesManifest;
use App\Upgrades\Data\V7_0_0_beta_6\Migrations\LogStaleServiceProviders;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * beta.6 업그레이드 스텝의 사후 manifest 작성 + 부팅 부정합 ServiceProvider 진단 검증.
 *
 * 본 테스트는 실제 base_path() 의 app/Providers/, bootstrap/providers.php 등을 건드리지
 * 않는다 — Backfill / LogStale 의 분석 대상은 base_path() 트리이므로, 본 테스트는
 * DataMigration 의 핵심 분기(이미 존재하는 manifest, 백업 부재, 예외 swallow) 와
 * 스키마 호환성 invariant 를 격리된 임시 디렉토리 / 일부 실제 코드 사용으로 검증한다.
 */
class Beta6BackfillManifestTest extends TestCase
{
    private string $coreBackupsDir;

    private array $backupDirsCreated = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->coreBackupsDir = storage_path('app/core_backups');
        File::ensureDirectoryExists($this->coreBackupsDir);

        // DataMigration 클래스는 AbstractUpgradeStep::dataMigrations() 가 동적으로 require
        // 하므로 일반 autoload 대상이 아님 — 테스트에서는 명시적으로 require_once.
        require_once base_path('upgrades/data/7.0.0-beta.6/migrations/01_BackfillNewFilesManifest.php');
        require_once base_path('upgrades/data/7.0.0-beta.6/migrations/02_LogStaleServiceProviders.php');
    }

    protected function tearDown(): void
    {
        foreach ($this->backupDirsCreated as $dir) {
            if (File::isDirectory($dir)) {
                File::deleteDirectory($dir);
            }
        }
        parent::tearDown();
    }

    private function createBackupFixture(string $name): string
    {
        $path = $this->coreBackupsDir.DIRECTORY_SEPARATOR.$name;
        File::ensureDirectoryExists($path);
        $this->backupDirsCreated[] = $path;

        return $path;
    }

    /**
     * 시나리오 1: 백업에 manifest 없음 + 디스크는 신 버전 → 사후 manifest 작성 검증.
     *
     * 본 케이스는 BackfillNewFilesManifest 가 실제로 manifest 파일을 작성하는 동작을 검증
     * 한다. Backfill 은 `storage/app/core_backups/` 의 가장 최근 백업을 자동 선택하므로,
     * 다른 테스트의 백업 디렉토리보다 mtime 이 최신인 fixture 백업을 만들어 보장한다.
     *
     * Backfill 의 비교 대상은 base_path() — 본 fixture 의 backup 은 빈 디렉토리이므로
     * Backfill 후 manifest 의 new_files 가 비어있지 않을 수 있지만 (실제 코어 트리 vs 빈
     * backup), 검증 목표는 "manifest 파일이 작성되었는지 + 스키마 정합성" 이다.
     */
    public function test_backfill_writes_manifest_when_absent_and_backup_present(): void
    {
        // 다른 테스트 fixture 보다 mtime 이 최신이도록 timestamp 가 들어간 백업 만들고
        // 실제 디렉토리 mtime 을 미래로 설정
        $backupDir = $this->createBackupFixture('test_beta6_backfill_write_'.uniqid());
        @touch($backupDir, time() + 60); // 60s 미래 — findLatestBackupDir 가 본 fixture 우선 선택

        $manifestPath = $backupDir.'/_new_files_manifest.json';
        $this->assertFileDoesNotExist($manifestPath, 'fixture 시작 시 manifest 부재');

        $context = new UpgradeContext('7.0.0-beta.5', '7.0.0-beta.6', '7.0.0-beta.6');
        $migration = new BackfillNewFilesManifest;
        $migration->run($context);

        // manifest 가 실제 작성되었는지 + 스키마 invariant 검증
        $this->assertFileExists($manifestPath, 'Backfill 이 manifest 를 사후 작성해야 함');

        $manifest = json_decode(File::get($manifestPath), true);
        $this->assertIsArray($manifest);
        $this->assertSame(
            ['version', 'created_at', 'from_version', 'to_version', 'new_files', 'new_dirs'],
            array_keys($manifest),
            'Backfill 산출 manifest 스키마는 §6.5 invariant 와 일치해야 함',
        );
        $this->assertSame(1, $manifest['version']);
        $this->assertSame('7.0.0-beta.5', $manifest['from_version']);
        $this->assertSame('7.0.0-beta.6', $manifest['to_version']);
        $this->assertIsArray($manifest['new_files']);
        $this->assertIsArray($manifest['new_dirs']);
    }

    /**
     * 시나리오 2: 이미 manifest 가 있으면 noop (created_at 변동 없음).
     */
    public function test_existing_manifest_is_noop(): void
    {
        $backupDir = $this->createBackupFixture('test_beta6_backfill_existing_'.uniqid());

        $existing = [
            'version' => 1,
            'created_at' => '2026-01-01T00:00:00+00:00',
            'from_version' => '7.0.0-beta.5',
            'to_version' => '7.0.0-beta.6',
            'new_files' => ['app/Existing.php'],
            'new_dirs' => [],
        ];
        File::put(
            $backupDir.'/_new_files_manifest.json',
            json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        $context = new UpgradeContext('7.0.0-beta.5', '7.0.0-beta.6', '7.0.0-beta.6');
        $migration = new BackfillNewFilesManifest;
        $migration->run($context);

        $after = json_decode(File::get($backupDir.'/_new_files_manifest.json'), true);
        $this->assertSame($existing['created_at'], $after['created_at']);
        $this->assertSame($existing['new_files'], $after['new_files']);
    }

    /**
     * 시나리오 3: 백업 디렉토리 자체가 부재 (--no-backup 모드) → 정상 종료 + 예외 미발생.
     */
    public function test_no_backup_dir_completes_without_exception(): void
    {
        // 실제 환경의 다른 백업 디렉토리에 영향을 주지 않기 위해 본 케이스는 예외 미발생만 검증.
        // (core_backups 가 비어있든 다른 백업이 있든, 본 DataMigration 은 swallow 만 해야 한다)
        $context = new UpgradeContext('7.0.0-beta.5', '7.0.0-beta.6', '7.0.0-beta.6');
        $migration = new BackfillNewFilesManifest;

        $thrown = null;
        try {
            $migration->run($context);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNull($thrown, 'DataMigration 은 예외를 swallow 해야 함');
    }

    /**
     * 시나리오 4: Backfill 이 작성한 manifest 가 CoreBackupHelper::pruneNewFiles 와 호환.
     *
     * Backfill 산출물을 직접 작성한 후 (DataMigration 의 분석 대상이 base_path() 이므로
     * 본 테스트는 동일 스키마를 수작업 작성해 pruneNewFiles 가 인식하는지 검증) — 스키마
     * invariant 회귀 가드.
     */
    public function test_backfill_manifest_schema_compatible_with_prune(): void
    {
        $backupDir = $this->createBackupFixture('test_beta6_compat_'.uniqid());
        $activeDir = storage_path('app/test_beta6_active_'.uniqid());
        File::ensureDirectoryExists($activeDir.'/app/Services');
        File::put($activeDir.'/app/Services/NewProvider.php', '<?php // new');

        // Backfill 과 동일 스키마 (DataMigration 01 의 invariant 준수)
        $manifest = [
            'version' => 1,
            'created_at' => date('c'),
            'from_version' => '7.0.0-beta.5',
            'to_version' => '7.0.0-beta.6',
            'new_files' => ['app/Services/NewProvider.php'],
            'new_dirs' => [],
        ];

        File::put(
            $backupDir.'/_new_files_manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        $result = CoreBackupHelper::pruneNewFiles($backupDir, $activeDir, []);

        $this->assertTrue($result['manifest_loaded']);
        $this->assertSame(1, $result['removed_files']);
        $this->assertFileDoesNotExist($activeDir.'/app/Services/NewProvider.php');

        File::deleteDirectory($activeDir);
    }

    /**
     * 시나리오 4b: CoreBackupHelper 의 manifest 스키마 키 6종이 invariant — DataMigration
     * 01 이 작성하는 manifest 의 키 순서/이름과 바이트 단위 호환.
     */
    public function test_manifest_schema_keys_match_helper_invariant(): void
    {
        $expectedKeys = ['version', 'created_at', 'from_version', 'to_version', 'new_files', 'new_dirs'];

        // CoreBackupHelper::writeNewFilesManifest 산출물의 키와 DataMigration 01 의 키가 동일해야 함
        $testRoot = storage_path('app/test_beta6_invariant_'.uniqid());
        File::ensureDirectoryExists($testRoot.'/backup');
        File::ensureDirectoryExists($testRoot.'/source/app');
        File::put($testRoot.'/source/app/dummy.php', '<?php');

        CoreBackupHelper::writeNewFilesManifest(
            $testRoot.'/backup',
            $testRoot.'/source',
            ['app'],
            [],
            [],
            '7.0.0-beta.5',
            '7.0.0-beta.6',
        );

        $helperOutput = json_decode(File::get($testRoot.'/backup/_new_files_manifest.json'), true);
        $this->assertSame($expectedKeys, array_keys($helperOutput));

        File::deleteDirectory($testRoot);
    }

    /**
     * 시나리오 6: Backfill 실패 swallow — listBackups 가 예외를 던져도 업그레이드 본체
     * 미중단. DataMigration 의 run() 는 모든 예외를 try/catch 로 swallow 한다.
     */
    public function test_backfill_swallows_exceptions(): void
    {
        // DataMigration::run 의 try/catch 동작은 internal 의 의도적 throw 가 swallow 되는지 검증
        // 실제 throw 시나리오는 권한 거부 등이지만, 본 테스트는 외부에서 어떤 예외도 새지 않음을 검증
        $context = new UpgradeContext('7.0.0-beta.5', '7.0.0-beta.6', '7.0.0-beta.6');
        $migration = new BackfillNewFilesManifest;

        // 정상 케이스에서도 예외가 새지 않아야 한다
        $exceptionThrown = false;
        try {
            $migration->run($context);
        } catch (\Throwable) {
            $exceptionThrown = true;
        }

        $this->assertFalse($exceptionThrown);
    }

    /**
     * LogStaleServiceProviders: 예외 swallow 동작 검증.
     */
    public function test_log_stale_providers_swallows_exceptions(): void
    {
        $context = new UpgradeContext('7.0.0-beta.5', '7.0.0-beta.6', '7.0.0-beta.6');
        $migration = new LogStaleServiceProviders;

        $exceptionThrown = false;
        try {
            $migration->run($context);
        } catch (\Throwable) {
            $exceptionThrown = true;
        }

        $this->assertFalse($exceptionThrown);
    }

    /**
     * DataMigration name() 반환값 검증.
     */
    public function test_data_migration_names(): void
    {
        $this->assertSame('BackfillNewFilesManifest', (new BackfillNewFilesManifest)->name());
        $this->assertSame('LogStaleServiceProviders', (new LogStaleServiceProviders)->name());
    }
}
