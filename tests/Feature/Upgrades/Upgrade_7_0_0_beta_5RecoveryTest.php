<?php

namespace Tests\Feature\Upgrades;

use App\Extension\UpgradeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Upgrade_7_0_0_beta_5 의 단발성 복구 인프라 검증.
 *
 * 7.0.0-beta.5 도입 "버전별 데이터 스냅샷" 인프라로 재구성:
 *   - 핫픽스 4종이 upgrades/data/7.0.0-beta.5/migrations/ 안에 격리됨
 *   - 본 테스트는 각 Migration 클래스의 run() 을 직접 호출하여 회귀 가드 유지
 *
 * 회귀 시나리오 (#347): beta.4 의 `applyDiscoveredTopLevelPaths` 결함으로 활성 확장
 * 디렉토리(`modules/{id}`, `plugins/{id}`, `templates/{id}`, `lang-packs/{id}`) 가
 * 손실된 환경에서, beta.5 step 이 DB active row 와 `_bundled/{id}` 를 매칭하여 자동 복원.
 */
class Upgrade_7_0_0_beta_5RecoveryTest extends TestCase
{
    use RefreshDatabase;

    private UpgradeContext $context;

    /** @var array<int, string> 정리할 임시 디렉토리 */
    private array $tempDirs = [];

    private ?string $originalBasePath = null;

    private ?string $originalPendingPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $migrationsDir = base_path('upgrades/data/7.0.0-beta.5/migrations');
        require_once $migrationsDir.'/01_RecoverActiveExtensionDirs.php';
        require_once $migrationsDir.'/02_RecoverPendingStubFiles.php';
        require_once $migrationsDir.'/03_VerifyBundledLangPacksFallback.php';
        require_once $migrationsDir.'/05_RecoverPublicStorageSymlink.php';

        $this->context = new UpgradeContext(
            fromVersion: '7.0.0-beta.4',
            toVersion: '7.0.0-beta.5',
            currentStep: '7.0.0-beta.5',
        );

        $this->originalBasePath = base_path();
        $this->originalPendingPath = config('app.update.pending_path');
    }

    protected function tearDown(): void
    {
        if ($this->originalBasePath !== null) {
            app()->setBasePath($this->originalBasePath);
        }
        if ($this->originalPendingPath !== null) {
            config(['app.update.pending_path' => $this->originalPendingPath]);
        }

        foreach ($this->tempDirs as $dir) {
            if (File::isDirectory($dir)) {
                File::deleteDirectory($dir);
            }
        }

        parent::tearDown();
    }

    /**
     * 활성 디렉토리 부재 + DB active + `_bundled` 존재 → 활성 디렉토리 자동 복원.
     */
    public function test_recover_active_dir_when_bundled_exists(): void
    {
        $fakeBase = $this->arrangeFakeBase();

        // DB: templates 테이블 active row 1건
        DB::table('templates')->insert([
            'identifier' => 'sirsoft-basic',
            'vendor' => 'sirsoft',
            'name' => json_encode(['ko' => '테스트 템플릿', 'en' => 'Test Template']),
            'version' => '1.0.0',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // _bundled 만 존재, 활성 디렉토리 부재
        File::ensureDirectoryExists($fakeBase.'/templates/_bundled/sirsoft-basic');
        File::put($fakeBase.'/templates/_bundled/sirsoft-basic/manifest.json', '{"identifier":"sirsoft-basic"}');

        $this->invokeRecover();

        $this->assertFileExists(
            $fakeBase.'/templates/sirsoft-basic/manifest.json',
            '활성 templates/sirsoft-basic 이 _bundled 에서 자동 복원되어야 한다',
        );
    }

    /**
     * 활성 디렉토리 정상 존재 시 silent skip (멱등).
     */
    public function test_recover_skips_when_active_dir_present(): void
    {
        $fakeBase = $this->arrangeFakeBase();

        DB::table('templates')->insert([
            'identifier' => 'sirsoft-basic',
            'vendor' => 'sirsoft',
            'name' => json_encode(['ko' => '테스트 템플릿', 'en' => 'Test Template']),
            'version' => '1.0.0',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 활성 디렉토리에 사용자 수정 콘텐츠
        File::ensureDirectoryExists($fakeBase.'/templates/sirsoft-basic');
        File::put($fakeBase.'/templates/sirsoft-basic/manifest.json', '{"installed":true,"user_modified":true}');

        // _bundled 에 다른 내용
        File::ensureDirectoryExists($fakeBase.'/templates/_bundled/sirsoft-basic');
        File::put($fakeBase.'/templates/_bundled/sirsoft-basic/manifest.json', '{"identifier":"sirsoft-basic"}');

        $this->invokeRecover();

        // 활성 콘텐츠가 덮어쓰여지지 않아야 함
        $manifest = json_decode(File::get($fakeBase.'/templates/sirsoft-basic/manifest.json'), true);
        $this->assertTrue($manifest['user_modified'] ?? false, '정상 활성 디렉토리는 복구 대상에서 제외되어야 한다');
    }

    /**
     * 외부 확장 (DB active + `_bundled` 부재) → DB row 단발성 비활성화.
     */
    public function test_external_extension_marked_deactivated_when_bundled_missing(): void
    {
        $fakeBase = $this->arrangeFakeBase();

        DB::table('modules')->insert([
            'identifier' => 'external-vendor-x',
            'vendor' => 'external-vendor',
            'name' => json_encode(['ko' => '외부 모듈', 'en' => 'External Module']),
            'version' => '1.0.0',
            'status' => 'active',
            'github_url' => 'https://github.com/external-vendor/x',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // _bundled 디렉토리는 만들지만 해당 식별자는 부재 (외부 확장)
        File::ensureDirectoryExists($fakeBase.'/modules/_bundled');

        $this->invokeRecover();

        $row = DB::table('modules')->where('identifier', 'external-vendor-x')->first();
        $this->assertSame('inactive', $row->status, '외부 확장 row 는 inactive 로 전환되어야 한다');
        $this->assertSame('extension_dir_lost_beta5', $row->deactivated_reason);
        $this->assertNotNull($row->deactivated_at);
    }

    /**
     * `_pending/.gitignore` 와 `_pending/.gitkeep` 가 부재 시 stub 재생성.
     */
    public function test_pending_stub_files_recreated_when_missing(): void
    {
        $fakeBase = $this->arrangeFakeBase();

        // _pending 자체는 존재하나 stub 부재
        foreach (['modules', 'plugins', 'templates', 'lang-packs'] as $domain) {
            File::ensureDirectoryExists($fakeBase.'/'.$domain.'/_pending');
        }

        $this->invokeRecoverPendingStubs();

        foreach (['modules', 'plugins', 'templates', 'lang-packs'] as $domain) {
            $this->assertFileExists($fakeBase.'/'.$domain.'/_pending/.gitignore');
            $this->assertFileExists($fakeBase.'/'.$domain.'/_pending/.gitkeep');

            $content = File::get($fakeBase.'/'.$domain.'/_pending/.gitignore');
            $this->assertStringContainsString('*', $content);
            $this->assertStringContainsString('!.gitkeep', $content);
        }
    }

    /**
     * `_pending/.gitignore` 가 이미 존재하면 덮어쓰지 않음 (멱등).
     */
    public function test_pending_stub_files_preserved_when_present(): void
    {
        $fakeBase = $this->arrangeFakeBase();

        File::ensureDirectoryExists($fakeBase.'/modules/_pending');
        File::put($fakeBase.'/modules/_pending/.gitignore', "# customized\n*\n!.gitignore\n!.gitkeep\n");
        File::put($fakeBase.'/modules/_pending/.gitkeep', '');

        $this->invokeRecoverPendingStubs();

        $content = File::get($fakeBase.'/modules/_pending/.gitignore');
        $this->assertStringContainsString('# customized', $content, '기존 stub 은 덮어쓰여지지 않아야 한다');
    }

    /**
     * 4개 도메인 (modules/plugins/templates/language_packs) 일괄 복구.
     */
    public function test_all_four_domains_recovered_in_one_pass(): void
    {
        $fakeBase = $this->arrangeFakeBase();

        DB::table('modules')->insert([
            'identifier' => 'sirsoft-ecommerce',
            'vendor' => 'sirsoft',
            'name' => json_encode(['ko' => '이커머스', 'en' => 'Ecommerce']),
            'version' => '1.0.0',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('plugins')->insert([
            'identifier' => 'sirsoft-payment',
            'vendor' => 'sirsoft',
            'name' => json_encode(['ko' => '결제', 'en' => 'Payment']),
            'version' => '1.0.0',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('templates')->insert([
            'identifier' => 'sirsoft-basic',
            'vendor' => 'sirsoft',
            'name' => json_encode(['ko' => '템플릿', 'en' => 'Template']),
            'version' => '1.0.0',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // language_packs 는 마이그레이션 이후 컬럼 구조가 다름 — 컬럼이 있을 때만 시드
        if (Schema::hasTable('language_packs') && Schema::hasColumn('language_packs', 'identifier')) {
            DB::table('language_packs')->insert([
                'identifier' => 'g7-core-ja',
                'vendor' => 'g7',
                'scope' => 'core',
                'target_identifier' => null,
                'locale' => 'ja',
                'locale_name' => 'Japanese',
                'locale_native_name' => '日本語',
                'text_direction' => 'ltr',
                'version' => '1.0.0',
                'status' => 'active',
                'manifest' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            File::ensureDirectoryExists($fakeBase.'/lang-packs/_bundled/g7-core-ja');
            File::put($fakeBase.'/lang-packs/_bundled/g7-core-ja/language-pack.json', '{"identifier":"g7-core-ja"}');
        }

        File::ensureDirectoryExists($fakeBase.'/modules/_bundled/sirsoft-ecommerce');
        File::put($fakeBase.'/modules/_bundled/sirsoft-ecommerce/module.json', '{"identifier":"sirsoft-ecommerce"}');
        File::ensureDirectoryExists($fakeBase.'/plugins/_bundled/sirsoft-payment');
        File::put($fakeBase.'/plugins/_bundled/sirsoft-payment/plugin.json', '{"identifier":"sirsoft-payment"}');
        File::ensureDirectoryExists($fakeBase.'/templates/_bundled/sirsoft-basic');
        File::put($fakeBase.'/templates/_bundled/sirsoft-basic/manifest.json', '{"identifier":"sirsoft-basic"}');

        $this->invokeRecover();

        $this->assertFileExists($fakeBase.'/modules/sirsoft-ecommerce/module.json');
        $this->assertFileExists($fakeBase.'/plugins/sirsoft-payment/plugin.json');
        $this->assertFileExists($fakeBase.'/templates/sirsoft-basic/manifest.json');

        if (Schema::hasTable('language_packs') && Schema::hasColumn('language_packs', 'identifier')) {
            $this->assertFileExists($fakeBase.'/lang-packs/g7-core-ja/language-pack.json');
        }
    }

    /**
     * 복원된 활성 디렉토리에 `.preserve-ownership` 마커가 작성되지 않아야 한다.
     *
     * Step 11 `restoreOwnership` 의 재귀 chown 이 마커가 있으면 skip 하므로, 본 step 이
     * 우발적으로 마커를 만들면 영구 권한 비대칭 위험. `_bundled` 에 마커가 없으면 활성도
     * 마커 없이 복원되어야 한다.
     */
    public function test_recover_does_not_create_preserve_ownership_marker(): void
    {
        $fakeBase = $this->arrangeFakeBase();

        DB::table('templates')->insert([
            'identifier' => 'sirsoft-basic',
            'vendor' => 'sirsoft',
            'name' => json_encode(['ko' => '템플릿', 'en' => 'Template']),
            'version' => '1.0.0',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        File::ensureDirectoryExists($fakeBase.'/templates/_bundled/sirsoft-basic');
        File::put($fakeBase.'/templates/_bundled/sirsoft-basic/manifest.json', '{"identifier":"sirsoft-basic"}');

        $this->invokeRecover();

        $this->assertFileDoesNotExist(
            $fakeBase.'/templates/sirsoft-basic/.preserve-ownership',
            '복원된 활성 디렉토리에 .preserve-ownership 마커가 우발적으로 작성되어서는 안 된다',
        );
    }

    /**
     * lang-packs/_bundled 정상 존재 시 fallback skip (멱등 확인).
     */
    public function test_lang_packs_fallback_skipped_when_already_present(): void
    {
        $fakeBase = $this->arrangeFakeBase();

        File::ensureDirectoryExists($fakeBase.'/lang-packs/_bundled/g7-core-ja');
        File::put($fakeBase.'/lang-packs/_bundled/g7-core-ja/language-pack.json', '{"identifier":"g7-core-ja","preserve":true}');

        $this->invokeLangPacksFallback();

        $manifest = json_decode(File::get($fakeBase.'/lang-packs/_bundled/g7-core-ja/language-pack.json'), true);
        $this->assertTrue($manifest['preserve'] ?? false, '정상 데이터가 fallback 으로 덮어쓰여지면 안 된다');
    }

    /**
     * lang-packs/_bundled 부재 + _pending 에 소스 존재 시 fallback 복원.
     */
    public function test_lang_packs_fallback_recovers_from_deep_pending_source(): void
    {
        $fakeBase = $this->arrangeFakeBase();

        $pending = config('app.update.pending_path');
        // 비표준 깊이(5단계) 의 소스 — beta.4 의 3단계 제약을 회피하는 경로
        $deepSource = $pending.'/core_20260511_120000/extracted/wrapper/extra-layer/lang-packs/_bundled';
        File::ensureDirectoryExists($deepSource.'/g7-core-ja');
        File::put($deepSource.'/g7-core-ja/language-pack.json', '{"identifier":"g7-core-ja","source":"deep"}');

        $this->invokeLangPacksFallback();

        $this->assertFileExists($fakeBase.'/lang-packs/_bundled/g7-core-ja/language-pack.json');
        $manifest = json_decode(File::get($fakeBase.'/lang-packs/_bundled/g7-core-ja/language-pack.json'), true);
        $this->assertSame('deep', $manifest['source'] ?? null);
    }

    // ──────────────────────────────────────────
    // recoverPublicStorageSymlink (계획서 §5)
    // ──────────────────────────────────────────

    /**
     * 정상 symlink 상태면 silent skip (멱등).
     */
    public function test_recover_public_storage_symlink_skips_when_already_symlink(): void
    {
        $fakeBase = $this->arrangeFakeBase();
        File::ensureDirectoryExists($fakeBase.'/public');
        File::ensureDirectoryExists($fakeBase.'/storage/app/public');

        if (! @symlink($fakeBase.'/storage/app/public', $fakeBase.'/public/storage')) {
            $this->markTestSkipped('symlink 생성 권한 부족 (Windows 일반 사용자)');
        }

        $linkTargetBefore = readlink($fakeBase.'/public/storage');

        $this->invokeRecoverPublicStorageSymlink();

        $this->assertTrue(is_link($fakeBase.'/public/storage'), '정상 symlink 상태는 보존되어야 한다');
        $this->assertSame($linkTargetBefore, readlink($fakeBase.'/public/storage'), 'symlink target 미변경');
    }

    /**
     * 일반 디렉토리 + Laravel storage:link source 존재 → rename 보존 + symlink 재생성.
     */
    public function test_recover_public_storage_symlink_recovers_corrupted_directory(): void
    {
        $fakeBase = $this->arrangeFakeBase();
        File::ensureDirectoryExists($fakeBase.'/public/storage');
        File::ensureDirectoryExists($fakeBase.'/storage/app/public');

        // 손상 상태 시뮬레이션 — public/storage 안에 dereferenced 복사 콘텐츠
        File::put($fakeBase.'/public/storage/uploaded.txt', 'dereferenced content');
        File::put($fakeBase.'/storage/app/public/uploaded.txt', 'original content');

        if (! @symlink($fakeBase.'/storage/app/public', $fakeBase.'/test_symlink_capability')) {
            // 권한 없음 → recoverPublicStorageSymlink 가 rename 후 symlink 실패 → 원복 경로 검증으로 대체
            @unlink($fakeBase.'/test_symlink_capability');
            $this->markTestSkipped('symlink 생성 권한 부족 (Windows 일반 사용자)');
        }
        @unlink($fakeBase.'/test_symlink_capability');

        $this->invokeRecoverPublicStorageSymlink();

        // 1) public/storage 가 symlink 로 재생성
        $this->assertTrue(is_link($fakeBase.'/public/storage'), 'public/storage 가 symlink 로 재생성되어야 한다');
        $this->assertSame($fakeBase.'/storage/app/public', readlink($fakeBase.'/public/storage'));

        // 2) .broken.{timestamp} 백업 디렉토리 존재 + dereferenced 콘텐츠 보존
        $backups = array_values(array_filter(
            scandir($fakeBase.'/public'),
            fn ($e) => str_starts_with($e, 'storage.broken.'),
        ));
        $this->assertCount(1, $backups, '.broken.{timestamp} 백업 디렉토리 1개 존재');
        $this->assertFileExists($fakeBase.'/public/'.$backups[0].'/uploaded.txt');
        $this->assertSame('dereferenced content', File::get($fakeBase.'/public/'.$backups[0].'/uploaded.txt'));
    }

    /**
     * Laravel storage:link source (storage/app/public) 부재 → 운영자 의도적 구성으로 보고 skip.
     */
    public function test_recover_public_storage_symlink_skips_when_source_absent(): void
    {
        $fakeBase = $this->arrangeFakeBase();
        File::ensureDirectoryExists($fakeBase.'/public/storage');
        File::put($fakeBase.'/public/storage/custom.txt', 'operator content');
        // storage/app/public 의도적 미생성 — Laravel 표준 미사용 운영자 시뮬

        $this->invokeRecoverPublicStorageSymlink();

        // public/storage 는 일반 디렉토리로 유지 + 콘텐츠 보존
        $this->assertFalse(is_link($fakeBase.'/public/storage'), 'source 부재 시 symlink 생성 안 함');
        $this->assertTrue(is_dir($fakeBase.'/public/storage'));
        $this->assertFileExists($fakeBase.'/public/storage/custom.txt');
        $this->assertSame('operator content', File::get($fakeBase.'/public/storage/custom.txt'));

        // .broken.* 백업도 없어야 함 (rename 미실행)
        $entries = array_values(array_filter(
            scandir($fakeBase.'/public'),
            fn ($e) => str_starts_with($e, 'storage.broken.'),
        ));
        $this->assertEmpty($entries, 'source 부재 시 rename 백업도 생성되지 않아야 한다');
    }

    /**
     * public/storage 자체가 부재 (storage:link 미실행 환경) → skip.
     */
    public function test_recover_public_storage_symlink_skips_when_public_storage_absent(): void
    {
        $fakeBase = $this->arrangeFakeBase();
        File::ensureDirectoryExists($fakeBase.'/public');
        File::ensureDirectoryExists($fakeBase.'/storage/app/public');
        // public/storage 의도적 미생성 — storage:link 가 한 번도 실행되지 않은 환경

        $this->invokeRecoverPublicStorageSymlink();

        $this->assertFalse(is_link($fakeBase.'/public/storage'), 'public/storage 부재 시 자동 생성 안 함');
        $this->assertFalse(file_exists($fakeBase.'/public/storage'), 'public/storage 자체가 생성되지 않아야 한다');
    }

    /**
     * 격리된 base_path + pending_path 구성.
     */
    private function arrangeFakeBase(): string
    {
        $fakeBase = storage_path('test_beta5_recovery_'.uniqid());
        File::ensureDirectoryExists($fakeBase);
        $this->tempDirs[] = $fakeBase;
        app()->setBasePath($fakeBase);

        $fakePending = $fakeBase.'/storage/app/core_pending';
        File::ensureDirectoryExists($fakePending);
        config(['app.update.pending_path' => $fakePending]);

        return $fakeBase;
    }

    private function invokeRecover(): void
    {
        $class = 'App\\Upgrades\\Data\\V7_0_0_beta_5\\Migrations\\RecoverActiveExtensionDirs';
        (new $class)->run($this->context);
    }

    private function invokeRecoverPendingStubs(): void
    {
        $class = 'App\\Upgrades\\Data\\V7_0_0_beta_5\\Migrations\\RecoverPendingStubFiles';
        (new $class)->run($this->context);
    }

    private function invokeRecoverPublicStorageSymlink(): void
    {
        $class = 'App\\Upgrades\\Data\\V7_0_0_beta_5\\Migrations\\RecoverPublicStorageSymlink';
        (new $class)->run($this->context);
    }

    private function invokeLangPacksFallback(): void
    {
        $class = 'App\\Upgrades\\Data\\V7_0_0_beta_5\\Migrations\\VerifyBundledLangPacksFallback';
        (new $class)->run($this->context);
    }
}
