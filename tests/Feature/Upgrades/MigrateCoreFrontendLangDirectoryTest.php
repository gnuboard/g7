<?php

namespace Tests\Feature\Upgrades;

use App\Extension\UpgradeContext;
use App\Upgrades\Data\V7_0_0\Migrations\MigrateCoreFrontendLangDirectory;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `MigrateCoreFrontendLangDirectory` 회귀 가드.
 *
 * 본 마이그레이션은 `resources/js/core/lang/{ko,en}.json` 을 `lang/{ko,en}.json` 으로
 * 이동하고 빈 레거시 디렉토리를 정리한다. base_path() 기반이므로 테스트에서
 * `app()->setBasePath()` 로 sandbox 루트를 일시 교체한다.
 *
 * 검증 시나리오:
 *   - 레거시 파일 양쪽 존재 + 신 위치 부재 → 양쪽 이동 + 디렉토리 정리
 *   - 레거시 + 신 위치 모두 존재 → 신 위치 보존, 레거시 정리만
 *   - 부분 마이그레이션 (ko 만) → 안전 처리
 *   - 레거시 디렉토리 부재 → 즉시 skip (에러 없음)
 *   - 두 번 실행 → idempotent (동일 결과)
 */
class MigrateCoreFrontendLangDirectoryTest extends TestCase
{
    private MigrateCoreFrontendLangDirectory $migration;

    private UpgradeContext $context;

    private string $sandboxBasePath;

    private string $originalBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        require_once base_path('upgrades/data/7.0.0/migrations/02_MigrateCoreFrontendLangDirectory.php');

        $this->migration = new MigrateCoreFrontendLangDirectory;
        $this->context = new UpgradeContext(
            fromVersion: '7.0.0-beta.7',
            toVersion: '7.0.0',
            currentStep: '7.0.0',
        );

        $this->sandboxBasePath = storage_path('framework/testing/core-lang-migration-'.uniqid());
        File::ensureDirectoryExists($this->sandboxBasePath);
        File::ensureDirectoryExists($this->sandboxBasePath.'/lang');

        $this->originalBasePath = base_path();
        app()->setBasePath($this->sandboxBasePath);
    }

    protected function tearDown(): void
    {
        app()->setBasePath($this->originalBasePath);

        if (File::isDirectory($this->sandboxBasePath)) {
            File::deleteDirectory($this->sandboxBasePath);
        }

        parent::tearDown();
    }

    public function test_name_returns_identifier(): void
    {
        $this->assertSame('MigrateCoreFrontendLangDirectory', $this->migration->name());
    }

    public function test_skips_when_legacy_directory_absent(): void
    {
        // 레거시 디렉토리 미생성 — 즉시 return
        $this->migration->run($this->context);

        // 신 위치도 그대로 (생성/삭제 없음)
        $this->assertFalse(file_exists($this->sandboxBasePath.'/lang/ko.json'));
    }

    public function test_moves_both_legacy_files_to_new_location(): void
    {
        $legacyDir = $this->sandboxBasePath.'/resources/js/core/lang';
        File::ensureDirectoryExists($legacyDir);
        file_put_contents($legacyDir.'/ko.json', '{"core":{"errors":{"x":"한글"}}}');
        file_put_contents($legacyDir.'/en.json', '{"core":{"errors":{"x":"English"}}}');

        $this->migration->run($this->context);

        $this->assertFileExists($this->sandboxBasePath.'/lang/ko.json');
        $this->assertFileExists($this->sandboxBasePath.'/lang/en.json');
        $this->assertSame('{"core":{"errors":{"x":"한글"}}}', file_get_contents($this->sandboxBasePath.'/lang/ko.json'));
        // 레거시 파일은 사라짐
        $this->assertFileDoesNotExist($legacyDir.'/ko.json');
        $this->assertFileDoesNotExist($legacyDir.'/en.json');
        // 빈 레거시 디렉토리도 정리
        $this->assertDirectoryDoesNotExist($legacyDir);
    }

    public function test_preserves_new_location_when_already_migrated(): void
    {
        $legacyDir = $this->sandboxBasePath.'/resources/js/core/lang';
        File::ensureDirectoryExists($legacyDir);
        file_put_contents($legacyDir.'/ko.json', '{"core":{"errors":{"x":"OLD"}}}');
        // 신 위치에 이미 새 내용이 있음
        file_put_contents($this->sandboxBasePath.'/lang/ko.json', '{"core":{"errors":{"x":"NEW"}}}');

        $this->migration->run($this->context);

        // 신 위치 보존 (덮어쓰기 금지)
        $this->assertSame('{"core":{"errors":{"x":"NEW"}}}', file_get_contents($this->sandboxBasePath.'/lang/ko.json'));
        // 레거시는 정리됨
        $this->assertFileDoesNotExist($legacyDir.'/ko.json');
    }

    public function test_handles_partial_ko_only_migration(): void
    {
        $legacyDir = $this->sandboxBasePath.'/resources/js/core/lang';
        File::ensureDirectoryExists($legacyDir);
        // ko 만 존재, en 부재
        file_put_contents($legacyDir.'/ko.json', '{"core":{"a":1}}');

        $this->migration->run($this->context);

        $this->assertFileExists($this->sandboxBasePath.'/lang/ko.json');
        $this->assertFileDoesNotExist($this->sandboxBasePath.'/lang/en.json');
        $this->assertDirectoryDoesNotExist($legacyDir);
    }

    public function test_is_idempotent_on_repeated_execution(): void
    {
        $legacyDir = $this->sandboxBasePath.'/resources/js/core/lang';
        File::ensureDirectoryExists($legacyDir);
        file_put_contents($legacyDir.'/ko.json', '{"core":{"a":1}}');

        $this->migration->run($this->context);
        // 두 번째 실행 — 레거시 부재 상태에서 안전하게 skip
        $this->migration->run($this->context);

        $this->assertFileExists($this->sandboxBasePath.'/lang/ko.json');
        $this->assertSame('{"core":{"a":1}}', file_get_contents($this->sandboxBasePath.'/lang/ko.json'));
    }

    public function test_preserves_legacy_directory_when_unrelated_files_remain(): void
    {
        $legacyDir = $this->sandboxBasePath.'/resources/js/core/lang';
        File::ensureDirectoryExists($legacyDir);
        file_put_contents($legacyDir.'/ko.json', '{"core":{"a":1}}');
        // 사용자가 추가한 무관한 파일
        file_put_contents($legacyDir.'/custom.json', '{"keep":true}');

        $this->migration->run($this->context);

        $this->assertFileExists($this->sandboxBasePath.'/lang/ko.json');
        $this->assertFileExists($legacyDir.'/custom.json');
        // 디렉토리는 무관 파일 때문에 보존됨
        $this->assertDirectoryExists($legacyDir);
    }
}
