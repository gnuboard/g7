<?php

namespace Tests\Unit\Services;

use App\Services\CoreUpdateService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * CoreUpdateService 단위 테스트
 *
 * 코어 업데이트 서비스의 주요 메서드를 검증합니다:
 * - 업데이트 확인 (GitHub API)
 * - CHANGELOG.md 파싱
 * - _pending 디렉토리 검증
 * - 유지보수 모드 전환
 * - .env 버전 갱신
 * - 실패 리포트 생성
 * - _pending 정리
 */
class CoreUpdateServiceTest extends TestCase
{
    private CoreUpdateService $service;

    /**
     * 테스트에서 사용하는 임시 디렉토리 목록 (tearDown에서 정리)
     *
     * @var array<string>
     */
    private array $tempDirs = [];

    /**
     * 테스트에서 사용하는 임시 파일 목록 (tearDown에서 정리)
     *
     * @var array<string>
     */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CoreUpdateService;
    }

    protected function tearDown(): void
    {
        // 임시 파일 정리
        foreach ($this->tempFiles as $file) {
            if (File::exists($file)) {
                File::delete($file);
            }
        }

        // 임시 디렉토리 정리
        foreach ($this->tempDirs as $dir) {
            if (File::isDirectory($dir)) {
                File::deleteDirectory($dir);
            }
        }

        parent::tearDown();
    }

    // ========================================================================
    // checkForUpdates() - GitHub API를 통한 업데이트 확인
    // ========================================================================

    /**
     * checkForUpdates()가 올바른 구조의 배열을 반환하는지 검증합니다.
     */
    public function test_check_for_updates_returns_correct_structure(): void
    {
        config(['app.update.github_url' => 'https://github.com/test-owner/test-repo']);

        $service = new class extends CoreUpdateService
        {
            protected function fetchLatestVersionFromGithub(string $githubUrl): array
            {
                return ['version' => '2.0.0', 'error' => null];
            }
        };

        $result = $service->checkForUpdates();

        // 반환 구조 검증
        $this->assertIsArray($result);
        $this->assertArrayHasKey('update_available', $result);
        $this->assertArrayHasKey('current_version', $result);
        $this->assertArrayHasKey('latest_version', $result);
        $this->assertArrayHasKey('github_url', $result);

        // 타입 검증
        $this->assertIsBool($result['update_available']);
        $this->assertIsString($result['current_version']);
        $this->assertIsString($result['latest_version']);

        // 최신 버전이 현재보다 높으면 업데이트 가능
        $this->assertEquals('2.0.0', $result['latest_version']);
        $this->assertArrayNotHasKey('check_failed', $result);
    }

    /**
     * GitHub API 실패 시 check_failed를 포함한 에러 응답을 반환하는지 검증합니다.
     */
    public function test_check_for_updates_returns_check_failed_when_github_fails(): void
    {
        config(['app.update.github_url' => 'https://github.com/test-owner/test-repo']);

        $service = new class extends CoreUpdateService
        {
            protected function fetchLatestVersionFromGithub(string $githubUrl): array
            {
                return ['version' => null, 'error' => '프라이빗 저장소입니다.'];
            }
        };

        $result = $service->checkForUpdates();

        $this->assertFalse($result['update_available']);
        $this->assertEquals($result['current_version'], $result['latest_version']);
        $this->assertTrue($result['check_failed']);
        $this->assertEquals('프라이빗 저장소입니다.', $result['error']);
    }

    /**
     * GitHub API 성공 + 릴리스 없음 (version null, error null)일 때 정상 처리되는지 검증합니다.
     */
    public function test_check_for_updates_handles_no_releases(): void
    {
        config(['app.update.github_url' => 'https://github.com/test-owner/test-repo']);

        $service = new class extends CoreUpdateService
        {
            protected function fetchLatestVersionFromGithub(string $githubUrl): array
            {
                return ['version' => null, 'error' => '릴리스가 없습니다.'];
            }
        };

        $result = $service->checkForUpdates();

        $this->assertFalse($result['update_available']);
        $this->assertTrue($result['check_failed']);
    }

    // ========================================================================
    // getChangelog() - CHANGELOG.md 파싱
    // ========================================================================

    // 참고: GitHub API 헤더/상태코드/URL 해석/다운로드 로직은 `GithubHelper`로 이관되어
    // `GithubHelperTest`에서 Http::fake() 기반으로 검증합니다. CoreUpdateService에 있던
    // buildGithubHeaders / extractHttpStatusCode / resolveGithubArchiveUrl / downloadArchive
    // 중복 구현은 제거되었습니다. (`allow_url_fopen=Off` 대응)

    /**
     * CHANGELOG.md 파일을 파싱하여 올바른 버전 엔트리를 반환하는지 검증합니다.
     */
    public function test_get_changelog_parses_file(): void
    {
        $changelogContent = <<<'MD'
# Changelog

## [1.2.0] - 2026-03-01

### Added
- 새로운 기능 A
- 새로운 기능 B

### Fixed
- 버그 수정 C

## [1.1.0] - 2026-02-15

### Changed
- 변경 사항 D
MD;

        $changelogPath = base_path('CHANGELOG.md');
        $originalExists = File::exists($changelogPath);
        $originalContent = $originalExists ? File::get($changelogPath) : null;

        File::put($changelogPath, $changelogContent);
        $this->tempFiles[] = $changelogPath;

        try {
            $result = $this->service->getChangelog();

            $this->assertIsArray($result);
            $this->assertCount(2, $result);

            // 첫 번째 엔트리 검증
            $this->assertEquals('1.2.0', $result[0]['version']);
            $this->assertEquals('2026-03-01', $result[0]['date']);
            $this->assertCount(2, $result[0]['categories']);

            // Added 카테고리 검증
            $addedCategory = $result[0]['categories'][0];
            $this->assertEquals('Added', $addedCategory['name']);
            $this->assertCount(2, $addedCategory['items']);
            $this->assertContains('새로운 기능 A', $addedCategory['items']);
            $this->assertContains('새로운 기능 B', $addedCategory['items']);

            // Fixed 카테고리 검증
            $fixedCategory = $result[0]['categories'][1];
            $this->assertEquals('Fixed', $fixedCategory['name']);
            $this->assertCount(1, $fixedCategory['items']);

            // 두 번째 엔트리 검증
            $this->assertEquals('1.1.0', $result[1]['version']);
        } finally {
            // 원본 파일 복원
            if ($originalContent !== null) {
                File::put($changelogPath, $originalContent);
            }
            // tempFiles 에서 제거 (원본 복원 완료)
            $this->tempFiles = array_filter($this->tempFiles, fn ($f) => $f !== $changelogPath);
        }
    }

    /**
     * 버전 범위를 지정하여 CHANGELOG를 필터링하는지 검증합니다.
     */
    public function test_get_changelog_with_version_range(): void
    {
        $changelogContent = <<<'MD'
# Changelog

## [1.3.0] - 2026-03-15

### Added
- 기능 E

## [1.2.0] - 2026-03-01

### Added
- 기능 D

## [1.1.0] - 2026-02-15

### Added
- 기능 C

## [1.0.0] - 2026-01-01

### Added
- 초기 릴리스
MD;

        $changelogPath = base_path('CHANGELOG.md');
        $originalExists = File::exists($changelogPath);
        $originalContent = $originalExists ? File::get($changelogPath) : null;

        File::put($changelogPath, $changelogContent);
        $this->tempFiles[] = $changelogPath;

        // 캐시 파일이 존재하면 임시 제거 (캐시가 로컬보다 우선하므로)
        $cachePath = storage_path('app/temp/core_remote_changelog.md');
        $cacheExists = File::exists($cachePath);
        $cacheContent = $cacheExists ? File::get($cachePath) : null;
        if ($cacheExists) {
            File::delete($cachePath);
        }

        try {
            // 1.1.0 초과 ~ 1.3.0 이하 범위 필터링
            $result = $this->service->getChangelog('1.1.0', '1.3.0');

            $this->assertIsArray($result);
            $this->assertCount(2, $result);

            $versions = array_column($result, 'version');
            $this->assertContains('1.2.0', $versions);
            $this->assertContains('1.3.0', $versions);
            $this->assertNotContains('1.1.0', $versions);
            $this->assertNotContains('1.0.0', $versions);
        } finally {
            if ($originalContent !== null) {
                File::put($changelogPath, $originalContent);
            }
            // 캐시 파일 복원
            if ($cacheContent !== null) {
                File::put($cachePath, $cacheContent);
            }
            $this->tempFiles = array_filter($this->tempFiles, fn ($f) => $f !== $changelogPath);
        }
    }

    /**
     * CHANGELOG.md 파일이 없을 때 빈 배열을 반환하는지 검증합니다.
     */
    public function test_get_changelog_returns_empty_when_file_missing(): void
    {
        // CHANGELOG.md가 없는 경로를 시뮬레이션하기 위해
        // 실제 파일이 존재하는 경우 임시로 이름 변경
        $changelogPath = base_path('CHANGELOG.md');
        $backupPath = base_path('CHANGELOG.md.bak_test');
        $renamed = false;

        if (File::exists($changelogPath)) {
            File::move($changelogPath, $backupPath);
            $renamed = true;
        }

        try {
            $result = $this->service->getChangelog();

            $this->assertIsArray($result);
            $this->assertEmpty($result);
        } finally {
            if ($renamed && File::exists($backupPath)) {
                File::move($backupPath, $changelogPath);
            }
        }
    }

    // ========================================================================
    // validatePendingPath() - _pending 디렉토리 검증
    // ========================================================================

    /**
     * 존재하지 않는 _pending 디렉토리를 자동 생성하는지 검증합니다.
     */
    public function test_validate_pending_path_creates_directory(): void
    {
        $tempPendingPath = storage_path('test_pending_'.uniqid());
        $this->tempDirs[] = $tempPendingPath;

        config(['app.update.pending_path' => $tempPendingPath]);

        // 디렉토리가 존재하지 않음을 확인
        $this->assertFalse(File::isDirectory($tempPendingPath));

        $result = $this->service->validatePendingPath();

        // 반환 구조 검증
        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('path', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('owner', $result);
        $this->assertArrayHasKey('group', $result);
        $this->assertArrayHasKey('permissions', $result);

        // 경로 확인
        $this->assertEquals($tempPendingPath, $result['path']);

        // 디렉토리가 생성되었는지 확인
        $this->assertTrue(File::isDirectory($tempPendingPath));

        // valid 상태 확인
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    // ========================================================================
    // enableMaintenanceMode() / disableMaintenanceMode() - 유지보수 모드
    // ========================================================================

    /**
     * 유지보수 모드 활성화/비활성화가 정상 동작하는지 검증합니다.
     */
    public function test_enable_disable_maintenance_mode(): void
    {
        // enableMaintenanceMode: Artisan::call('down', ...) 호출 검증
        Artisan::shouldReceive('call')
            ->once()
            ->with('down', \Mockery::on(function ($args) {
                return isset($args['--secret'])
                    && isset($args['--retry'])
                    && $args['--retry'] === 60
                    && isset($args['--refresh'])
                    && $args['--refresh'] === 15;
            }));

        $secret = $this->service->enableMaintenanceMode();

        // secret은 UUID 형식이어야 함
        $this->assertIsString($secret);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $secret
        );

        // disableMaintenanceMode: Artisan::call('up') 호출 검증
        Artisan::shouldReceive('call')
            ->once()
            ->with('up');

        $this->service->disableMaintenanceMode();
    }

    // ========================================================================
    // updateVersionInEnv() - .env 파일 버전 갱신
    // ========================================================================

    /**
     * .env 파일의 APP_VERSION 값을 올바르게 갱신하는지 검증합니다.
     *
     * 실제 프로젝트 루트의 `.env` 를 절대 건드리지 않기 위해 임시 디렉토리로
     * `app()->setBasePath()` 를 일시적으로 전환한 뒤 검증합니다. 과거 본 테스트가
     * `base_path('.env')` 를 직접 덮어쓰는 구조였고, 테스트 실행이 중단되면
     * `finally` 의 복원 로직이 손상된 fixture 를 그대로 다시 기록해 영구 고착되는
     * 자기 영속화 결함이 있어 회귀 차단을 위해 격리 기반으로 재작성됨.
     */
    public function test_update_version_in_env(): void
    {
        $tempEnvContent = "APP_NAME=G7\nAPP_VERSION=1.0.0\nAPP_ENV=testing\n";

        $this->withIsolatedBasePath(function (string $tempBase) use ($tempEnvContent): void {
            File::put($tempBase.DIRECTORY_SEPARATOR.'.env', $tempEnvContent);

            $this->service->updateVersionInEnv('2.5.0');

            $updatedContent = File::get($tempBase.DIRECTORY_SEPARATOR.'.env');

            // APP_VERSION 이 갱신되었는지 확인
            $this->assertStringContainsString('APP_VERSION=2.5.0', $updatedContent);
            $this->assertStringNotContainsString('APP_VERSION=1.0.0', $updatedContent);

            // 다른 설정은 유지되는지 확인
            $this->assertStringContainsString('APP_NAME=G7', $updatedContent);
            $this->assertStringContainsString('APP_ENV=testing', $updatedContent);
        });
    }

    /**
     * .env 파일에 APP_VERSION 이 없을 때 새로 추가하는지 검증합니다.
     */
    public function test_update_version_in_env_appends_when_missing(): void
    {
        $tempEnvContent = "APP_NAME=G7\nAPP_ENV=testing\n";

        $this->withIsolatedBasePath(function (string $tempBase) use ($tempEnvContent): void {
            File::put($tempBase.DIRECTORY_SEPARATOR.'.env', $tempEnvContent);

            $this->service->updateVersionInEnv('1.5.0');

            $updatedContent = File::get($tempBase.DIRECTORY_SEPARATOR.'.env');

            // APP_VERSION 이 추가되었는지 확인
            $this->assertStringContainsString('APP_VERSION=1.5.0', $updatedContent);
        });
    }

    /**
     * 회귀 테스트 — `updateVersionInEnv` 가 실 프로젝트 루트의 `.env` 를
     * 어떤 경우에도 수정하지 않는지 검증합니다.
     *
     * 본 테스트가 실행되어도 진짜 `.env` 의 mtime/내용 모두 불변이어야 합니다.
     * 과거 회귀 (테스트 fixture 가 실 `.env` 를 덮어쓰고 finally 복원 실패 시
     * 영구 고착) 의 재발을 차단합니다.
     */
    public function test_update_version_in_env_never_touches_real_env(): void
    {
        $realEnvPath = base_path('.env');

        $existedBefore = File::exists($realEnvPath);
        $contentBefore = $existedBefore ? File::get($realEnvPath) : null;
        $mtimeBefore = $existedBefore ? File::lastModified($realEnvPath) : null;

        $this->test_update_version_in_env();
        $this->test_update_version_in_env_appends_when_missing();

        $this->assertSame($existedBefore, File::exists($realEnvPath), '실 .env 존재 여부가 변경되었습니다');

        if ($existedBefore) {
            $this->assertSame($contentBefore, File::get($realEnvPath), '실 .env 내용이 변경되었습니다');
            $this->assertSame($mtimeBefore, File::lastModified($realEnvPath), '실 .env mtime 이 변경되었습니다');
        }
    }

    /**
     * 임시 디렉토리로 application base path 를 격리한 채 콜백을 실행합니다.
     *
     * `setBasePath` 는 `path.base` 등 다수 컨테이너 바인딩을 재바인딩하지만,
     * 본 테스트가 사용하는 helper (base_path) 만 새 경로를 따라가도록 충분합니다.
     * finally 로 원본 base path 를 복원하고 임시 디렉토리는 tempDirs 에 등록되어
     * tearDown 에서 일괄 정리됩니다.
     *
     * @param  \Closure(string $tempBase): void  $callback
     */
    private function withIsolatedBasePath(\Closure $callback): void
    {
        $tempBase = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g7-core-update-test-'.uniqid('', true);
        File::makeDirectory($tempBase, 0755, true);
        $this->tempDirs[] = $tempBase;

        $originalBase = $this->app->basePath();
        $this->app->setBasePath($tempBase);

        try {
            $callback($tempBase);
        } finally {
            $this->app->setBasePath($originalBase);
        }
    }

    // ========================================================================
    // generateFailureReport() - 실패 리포트 생성
    // ========================================================================

    /**
     * 업데이트 실패 리포트 파일이 올바르게 생성되는지 검증합니다.
     */
    public function test_generate_failure_report_creates_log_file(): void
    {
        $exception = new \RuntimeException('테스트 오류 메시지');

        $reportPath = $this->service->generateFailureReport($exception, '1.0.0', '2.0.0');
        $this->tempFiles[] = $reportPath;

        // 파일이 생성되었는지 확인
        $this->assertTrue(File::exists($reportPath));

        // 파일 경로가 storage/logs 하위인지 확인
        $this->assertStringStartsWith(storage_path('logs'), $reportPath);
        $this->assertStringContainsString('core_update_failure_', $reportPath);
        $this->assertStringEndsWith('.log', $reportPath);

        // 파일 내용 검증
        $content = File::get($reportPath);

        $this->assertStringContainsString('그누보드7 코어 업데이트 실패 리포트', $content);
        $this->assertStringContainsString('시작 버전: 1.0.0', $content);
        $this->assertStringContainsString('대상 버전: 2.0.0', $content);
        $this->assertStringContainsString('테스트 오류 메시지', $content);
        $this->assertStringContainsString('PHP: '.PHP_VERSION, $content);
        $this->assertStringContainsString('스택 트레이스', $content);
    }

    // ========================================================================
    // cleanupPending() - _pending 디렉토리 정리 (타임스탬프 기반)
    // ========================================================================

    /**
     * 타임스탬프 기반 pending 디렉토리가 통째로 삭제되는지 검증합니다.
     *
     * cleanupPending(string $pendingPath)는 지정된 경로를 전체 삭제합니다.
     */
    public function test_cleanup_pending_removes_directory(): void
    {
        $tempPendingPath = storage_path('test_pending_cleanup_'.uniqid());
        $this->tempDirs[] = $tempPendingPath;

        // 임시 디렉토리 및 파일 생성
        File::ensureDirectoryExists($tempPendingPath);
        File::put($tempPendingPath.DIRECTORY_SEPARATOR.'test_file.txt', '테스트 내용');

        // 디렉토리 존재 확인
        $this->assertTrue(File::isDirectory($tempPendingPath));

        $this->service->cleanupPending($tempPendingPath);

        // 디렉토리 전체가 삭제됨
        $this->assertFalse(File::isDirectory($tempPendingPath));
    }

    /**
     * 존재하지 않는 경로에 대해 cleanupPending()이 예외 없이 처리되는지 검증합니다.
     */
    public function test_cleanup_pending_does_not_fail_when_directory_missing(): void
    {
        $nonExistentPath = storage_path('non_existent_pending_'.uniqid());

        // 예외 없이 정상 실행되는지 확인
        $this->service->cleanupPending($nonExistentPath);

        $this->assertFalse(File::isDirectory($nonExistentPath));
    }

    // ========================================================================
    // createPendingDirectory() - 타임스탬프 기반 pending 디렉토리 생성
    // ========================================================================

    /**
     * createPendingDirectory()가 타임스탬프 형식의 디렉토리를 생성하는지 검증합니다.
     */
    public function test_create_pending_directory_creates_timestamped_directory(): void
    {
        $basePath = storage_path('test_pending_base_'.uniqid());
        $this->tempDirs[] = $basePath;

        File::ensureDirectoryExists($basePath);
        config(['app.update.pending_path' => $basePath]);

        $pendingPath = $this->service->createPendingDirectory();
        $this->tempDirs[] = $pendingPath;

        // 디렉토리가 생성되었는지 확인
        $this->assertTrue(File::isDirectory($pendingPath));

        // 경로가 basePath 하위인지 확인
        $this->assertStringStartsWith($basePath, $pendingPath);

        // core_ 접두사 + 타임스탬프 형식인지 확인
        $dirName = basename($pendingPath);
        $this->assertMatchesRegularExpression('/^core_\d{8}_\d{6}$/', $dirName);
    }

    /**
     * createPendingDirectory()가 매 호출마다 고유한 경로를 반환하는지 검증합니다.
     */
    public function test_create_pending_directory_returns_unique_paths(): void
    {
        $basePath = storage_path('test_pending_unique_'.uniqid());
        $this->tempDirs[] = $basePath;

        File::ensureDirectoryExists($basePath);
        config(['app.update.pending_path' => $basePath]);

        $path1 = $this->service->createPendingDirectory();
        $this->tempDirs[] = $path1;

        // 1초 대기하여 다른 타임스탬프 보장
        sleep(1);

        $path2 = $this->service->createPendingDirectory();
        $this->tempDirs[] = $path2;

        $this->assertNotEquals($path1, $path2);
        $this->assertTrue(File::isDirectory($path1));
        $this->assertTrue(File::isDirectory($path2));
    }

    // ========================================================================
    // clearAllCaches() - 모든 캐시 초기화
    // ========================================================================

    /**
     * 모든 캐시 초기화 및 패키지 재생성 명령이 순서대로 호출되는지 검증합니다.
     */
    public function test_clear_all_caches_calls_artisan_commands(): void
    {
        Artisan::shouldReceive('call')->once()->with('config:clear');
        Artisan::shouldReceive('call')->once()->with('cache:clear');
        Artisan::shouldReceive('call')->once()->with('route:clear');
        Artisan::shouldReceive('call')->once()->with('view:clear');
        Artisan::shouldReceive('call')->once()->with('package:discover');
        Artisan::shouldReceive('call')->once()->with('extension:update-autoload');

        $this->service->clearAllCaches();
    }

    // ========================================================================
    // core:check-updates 커맨드 - 에러 출력 검증
    // ========================================================================

    /**
     * 업데이트 확인 성공 시 커맨드가 정상 종료되는지 검증합니다.
     */
    public function test_check_updates_command_shows_success(): void
    {
        config(['app.update.github_url' => 'https://github.com/test/repo']);

        $this->instance(CoreUpdateService::class, new class extends CoreUpdateService
        {
            protected function fetchLatestVersionFromGithub(string $githubUrl): array
            {
                return ['version' => '7.0.0-alpha.1', 'error' => null];
            }
        });

        $this->artisan('core:check-updates')
            ->expectsOutputToContain('현재 최신 버전입니다.')
            ->assertExitCode(0);
    }

    /**
     * 업데이트 확인 실패 시 커맨드가 에러 메시지를 출력하는지 검증합니다.
     */
    public function test_check_updates_command_shows_error_on_failure(): void
    {
        config(['app.update.github_url' => 'https://github.com/test/repo']);

        $this->instance(CoreUpdateService::class, new class extends CoreUpdateService
        {
            protected function fetchLatestVersionFromGithub(string $githubUrl): array
            {
                return ['version' => null, 'error' => 'GitHub 저장소를 찾을 수 없습니다.'];
            }
        });

        $this->artisan('core:check-updates')
            ->expectsOutputToContain('업데이트 확인 실패: GitHub 저장소를 찾을 수 없습니다.')
            ->assertExitCode(1);
    }

    /**
     * core:update 커맨드에서 업데이트 확인 실패 시 에러 출력 후 종료하는지 검증합니다.
     */
    public function test_update_command_shows_error_on_check_failure(): void
    {
        config(['app.update.github_url' => 'https://github.com/test/repo']);

        $this->instance(CoreUpdateService::class, new class extends CoreUpdateService
        {
            public function checkSystemRequirements(): array
            {
                return ['valid' => true, 'errors' => [], 'available_methods' => ['ZipArchive']];
            }

            protected function fetchLatestVersionFromGithub(string $githubUrl): array
            {
                return ['version' => null, 'error' => 'GitHub 저장소를 찾을 수 없습니다.'];
            }
        });

        $this->artisan('core:update')
            ->expectsOutputToContain('업데이트 확인 실패')
            ->assertExitCode(1);
    }

    /**
     * 토큰 인증 실패 시 커맨드가 적절한 에러를 출력하는지 검증합니다.
     */
    public function test_check_updates_command_shows_auth_error(): void
    {
        config(['app.update.github_url' => 'https://github.com/test/private-repo']);

        $this->instance(CoreUpdateService::class, new class extends CoreUpdateService
        {
            protected function fetchLatestVersionFromGithub(string $githubUrl): array
            {
                return ['version' => null, 'error' => __('settings.core_update.github_token_invalid')];
            }
        });

        $this->artisan('core:check-updates')
            ->expectsOutputToContain('업데이트 확인 실패')
            ->assertExitCode(1);
    }

    // ========================================================================
    // targets 설정 검증
    // ========================================================================

    /**
     * targets에 public 디렉토리가 통합 포함되고 분할 항목이 없는지 검증합니다.
     */
    public function test_update_targets_includes_public_directory(): void
    {
        $targets = config('app.update.targets');

        // public 디렉토리가 통합 포함
        $this->assertContains('public', $targets);

        // 이전 분할 항목이 제거됨
        $this->assertNotContains('public/build', $targets);
        $this->assertNotContains('public/index.php', $targets);
        $this->assertNotContains('public/install', $targets);
    }

    /**
     * targets에 라라벨 기본 탑재 파일이 모두 포함되는지 검증합니다.
     */
    public function test_update_targets_includes_all_laravel_default_files(): void
    {
        $targets = config('app.update.targets');

        $laravelDefaults = [
            'artisan',
            'composer.json',
            'composer.lock',
            'package.json',
            'package-lock.json',
            'vite.config.js',
            'phpunit.xml',
            '.editorconfig',
            '.gitattributes',
            '.gitignore',
            'README.md',
        ];

        foreach ($laravelDefaults as $file) {
            $this->assertContains($file, $targets, "라라벨 기본 파일 '{$file}'이 targets에 포함되어야 합니다.");
        }
    }

    /**
     * targets에 코어 추가 파일이 모두 포함되는지 검증합니다.
     */
    public function test_update_targets_includes_additional_core_files(): void
    {
        $targets = config('app.update.targets');

        $coreAdditional = [
            'vite.config.core.js',
            'vitest.config.ts',
            'tsconfig.json',
            'composer.json.default',
            'tests',
            'docs',
        ];

        foreach ($coreAdditional as $item) {
            $this->assertContains($item, $targets, "코어 추가 항목 '{$item}'이 targets에 포함되어야 합니다.");
        }
    }

    // ========================================================================
    // applyUpdate() — removeOrphans 동작 검증
    // ========================================================================

    // ========================================================================
    // checkSystemRequirements() — 시스템 요구사항 검증
    // ========================================================================

    /**
     * 현재 환경에서 시스템 요구사항 검증이 올바른 구조를 반환하는지 확인합니다.
     */
    public function test_check_system_requirements_returns_correct_structure(): void
    {
        $result = $this->service->checkSystemRequirements();

        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('available_methods', $result);
        $this->assertIsBool($result['valid']);
        $this->assertIsArray($result['errors']);
        $this->assertIsArray($result['available_methods']);
    }

    // ========================================================================
    // buildExtractionStrategies() — 추출 전략 빌드
    // ========================================================================

    /**
     * 추출 전략에 PharData가 포함되지 않는지 검증합니다 (tar 경로 길이 제한으로 제거됨).
     */
    public function test_build_extraction_strategies_does_not_include_phardata(): void
    {
        $strategies = $this->invokeProtectedMethod($this->service, 'buildExtractionStrategies');

        $labels = array_map(fn ($s) => $s['label'], $strategies);
        $this->assertNotContains('PharData', $labels);
    }

    /**
     * ZipArchive 사용 가능 시 전략에 포함되는지 검증합니다.
     */
    public function test_build_extraction_strategies_includes_ziparchive_when_available(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $strategies = $this->invokeProtectedMethod($this->service, 'buildExtractionStrategies');

        $labels = array_map(fn ($s) => $s['label'], $strategies);
        $this->assertContains('ZipArchive', $labels);
    }

    /**
     * 모든 전략이 zipball 타입을 사용하는지 검증합니다.
     */
    public function test_build_extraction_strategies_all_use_zipball(): void
    {
        $strategies = $this->invokeProtectedMethod($this->service, 'buildExtractionStrategies');

        if (empty($strategies)) {
            $this->markTestSkipped('ZipArchive/unzip이 없는 환경에서는 전략이 생성되지 않습니다.');
        }

        foreach ($strategies as $strategy) {
            $this->assertEquals('zipball', $strategy['archive_type'], "{$strategy['label']}은 zipball 타입이어야 합니다.");
        }
    }

    // ========================================================================
    // extractWithZipArchive() — ZipArchive 추출 검증
    // ========================================================================

    /**
     * ZipArchive로 실제 ZIP 파일을 추출할 수 있는지 검증합니다.
     */
    public function test_extract_with_zip_archive(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $tempDir = storage_path('test_zip_extract_'.uniqid());
        $this->tempDirs[] = $tempDir;
        File::ensureDirectoryExists($tempDir);

        // 테스트 ZIP 생성
        $zipPath = $tempDir.DIRECTORY_SEPARATOR.'test.zip';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('test-dir/hello.txt', 'Hello World');
        $zip->close();

        $extractDir = $tempDir.DIRECTORY_SEPARATOR.'extracted';
        File::ensureDirectoryExists($extractDir);

        $this->invokeProtectedMethod($this->service, 'extractWithZipArchive', [$zipPath, $extractDir]);

        $this->assertTrue(File::exists($extractDir.DIRECTORY_SEPARATOR.'test-dir'.DIRECTORY_SEPARATOR.'hello.txt'));
        $this->assertEquals('Hello World', File::get($extractDir.DIRECTORY_SEPARATOR.'test-dir'.DIRECTORY_SEPARATOR.'hello.txt'));
    }

    // ========================================================================
    // validatePendingUpdate() — 패키지 검증 (G7 프로젝트 확인)
    // ========================================================================

    /**
     * 유효한 G7 패키지 디렉토리가 검증을 통과하는지 검증합니다.
     */
    public function test_validate_pending_update_passes_for_valid_g7_package(): void
    {
        $tempDir = storage_path('test_validate_g7_'.uniqid());
        $this->tempDirs[] = $tempDir;

        File::ensureDirectoryExists($tempDir.DIRECTORY_SEPARATOR.'app');
        File::ensureDirectoryExists($tempDir.DIRECTORY_SEPARATOR.'config');
        File::put($tempDir.DIRECTORY_SEPARATOR.'composer.json', '{}');
        File::put($tempDir.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'app.php', "<?php\nreturn ['version' => '1.0.0'];");

        // 예외 없이 통과
        $this->service->validatePendingUpdate($tempDir);
        $this->assertTrue(true);
    }

    /**
     * config/app.php가 없는 디렉토리에서 예외가 발생하는지 검증합니다.
     */
    public function test_validate_pending_update_fails_for_non_g7_package(): void
    {
        $tempDir = storage_path('test_validate_non_g7_'.uniqid());
        $this->tempDirs[] = $tempDir;

        File::ensureDirectoryExists($tempDir.DIRECTORY_SEPARATOR.'app');
        File::put($tempDir.DIRECTORY_SEPARATOR.'composer.json', '{}');
        // config/app.php 없음

        $this->expectException(\RuntimeException::class);
        $this->service->validatePendingUpdate($tempDir);
    }

    /**
     * config/app.php에 version 키가 없을 때 예외가 발생하는지 검증합니다.
     */
    public function test_validate_pending_update_fails_without_version_key(): void
    {
        $tempDir = storage_path('test_validate_no_version_'.uniqid());
        $this->tempDirs[] = $tempDir;

        File::ensureDirectoryExists($tempDir.DIRECTORY_SEPARATOR.'app');
        File::ensureDirectoryExists($tempDir.DIRECTORY_SEPARATOR.'config');
        File::put($tempDir.DIRECTORY_SEPARATOR.'composer.json', '{}');
        File::put($tempDir.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'app.php', "<?php\nreturn ['name' => 'NotG7'];");

        $this->expectException(\RuntimeException::class);
        $this->service->validatePendingUpdate($tempDir);
    }

    // ========================================================================
    // 헬퍼 메서드
    // ========================================================================

    /**
     * protected/private 메서드를 호출합니다.
     *
     * @param  object  $object  대상 객체
     * @param  string  $method  메서드명
     * @param  array  $args  인수
     */
    private function invokeProtectedMethod(object $object, string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }

    // ========================================================================
    // extractZipToPending() — 외부 ZIP 추출
    // ========================================================================

    /**
     * 평탄 루트 G7 ZIP 을 _pending 으로 추출하고 검증을 통과하는지 확인합니다.
     */
    public function test_extract_zip_to_pending_flat_layout(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $pendingBase = storage_path('test_core_zip_pending_'.uniqid());
        $this->tempDirs[] = $pendingBase;
        File::ensureDirectoryExists($pendingBase);
        config(['app.update.pending_path' => $pendingBase]);

        $zipDir = storage_path('test_core_zip_src_'.uniqid());
        $this->tempDirs[] = $zipDir;
        File::ensureDirectoryExists($zipDir);
        $zipPath = $zipDir.DIRECTORY_SEPARATOR.'g7.zip';

        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('composer.json', '{"name":"g7/core"}');
        $zip->addFromString('app/.gitkeep', '');
        $zip->addFromString('config/app.php', "<?php\nreturn ['version' => '9.9.9'];");
        $zip->close();

        $result = $this->service->extractZipToPending($zipPath);
        $this->assertTrue(File::exists($result.DIRECTORY_SEPARATOR.'composer.json'));
        $this->assertTrue(File::isDirectory($result.DIRECTORY_SEPARATOR.'app'));
    }

    /**
     * 래퍼 디렉토리(owner-repo-hash/) 를 자동 감지하여 그 내부를 반환하는지 검증합니다.
     */
    public function test_extract_zip_to_pending_unwraps_wrapper_directory(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $pendingBase = storage_path('test_core_zip_pending_wrap_'.uniqid());
        $this->tempDirs[] = $pendingBase;
        File::ensureDirectoryExists($pendingBase);
        config(['app.update.pending_path' => $pendingBase]);

        $zipDir = storage_path('test_core_zip_wrap_src_'.uniqid());
        $this->tempDirs[] = $zipDir;
        File::ensureDirectoryExists($zipDir);
        $zipPath = $zipDir.DIRECTORY_SEPARATOR.'g7-wrap.zip';

        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('g7-core-abc123/composer.json', '{"name":"g7/core"}');
        $zip->addFromString('g7-core-abc123/app/.gitkeep', '');
        $zip->addFromString('g7-core-abc123/config/app.php', "<?php\nreturn ['version' => '7.0.1'];");
        $zip->close();

        $result = $this->service->extractZipToPending($zipPath);
        $this->assertStringEndsWith('g7-core-abc123', $result);
        $this->assertTrue(File::exists($result.DIRECTORY_SEPARATOR.'composer.json'));
    }

    /**
     * ZIP 파일이 존재하지 않을 때 RuntimeException 이 발생하는지 검증합니다.
     */
    public function test_extract_zip_to_pending_throws_when_zip_missing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->extractZipToPending(storage_path('nonexistent_'.uniqid().'.zip'));
    }

    /**
     * ZIP 내용이 G7 패키지가 아닐 때 검증에서 RuntimeException 이 발생하는지 확인합니다.
     */
    public function test_extract_zip_to_pending_throws_for_invalid_package(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $pendingBase = storage_path('test_core_zip_pending_invalid_'.uniqid());
        $this->tempDirs[] = $pendingBase;
        File::ensureDirectoryExists($pendingBase);
        config(['app.update.pending_path' => $pendingBase]);

        $zipDir = storage_path('test_core_zip_invalid_src_'.uniqid());
        $this->tempDirs[] = $zipDir;
        File::ensureDirectoryExists($zipDir);
        $zipPath = $zipDir.DIRECTORY_SEPARATOR.'invalid.zip';

        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('README.md', '# not g7');
        $zip->close();

        $this->expectException(\RuntimeException::class);
        $this->service->extractZipToPending($zipPath);
    }

    /**
     * applyUpdate가 소스에 없는 파일을 삭제하는지 검증합니다.
     */
    public function test_apply_update_removes_orphan_files(): void
    {
        $tempSource = storage_path('test_apply_source_'.uniqid());
        $this->tempDirs[] = $tempSource;

        // 소스에 app 디렉토리와 파일 생성
        File::ensureDirectoryExists($tempSource.DIRECTORY_SEPARATOR.'app');
        File::put($tempSource.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'NewFile.php', '<?php // new');

        // 대상(base_path)에 orphan 파일 시뮬레이션을 위해 임시 디렉토리 사용
        $tempDest = storage_path('test_apply_dest_'.uniqid());
        $this->tempDirs[] = $tempDest;

        File::ensureDirectoryExists($tempDest.DIRECTORY_SEPARATOR.'app');
        File::put($tempDest.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'NewFile.php', '<?php // old');
        File::put($tempDest.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'OrphanFile.php', '<?php // orphan');

        // FilePermissionHelper::copyDirectory 직접 호출로 검증
        \App\Extension\Helpers\FilePermissionHelper::copyDirectory(
            $tempSource.DIRECTORY_SEPARATOR.'app',
            $tempDest.DIRECTORY_SEPARATOR.'app',
            removeOrphans: true
        );

        // NewFile.php는 소스 내용으로 교체
        $this->assertEquals('<?php // new', File::get($tempDest.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'NewFile.php'));

        // OrphanFile.php는 삭제됨
        $this->assertFalse(File::exists($tempDest.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'OrphanFile.php'));
    }

    // ========================================================================
    // applyUpdate() — 신규 최상위 디렉토리 자동 발견 폴백 (회귀: lang-packs 누락)
    // ========================================================================

    /**
     * 부모 프로세스의 stale `app.update.targets` 가 신버전 신규 최상위 디렉토리를 인식하지
     * 못할 때, applyUpdate 가 source 디렉토리에서 자동 발견하여 복사하는지 검증합니다.
     *
     * 회귀 시나리오: beta.3 → beta.4 업그레이드에서 beta.3 의 targets 에 `lang-packs/_bundled`
     * 가 없어 신버전 zip 의 lang-packs 가 활성 디렉토리로 복사되지 않은 결함.
     */
    public function test_apply_update_auto_discovers_new_top_level_directories(): void
    {
        $sourcePath = storage_path('test_apply_autodisc_src_'.uniqid());
        $this->tempDirs[] = $sourcePath;
        File::ensureDirectoryExists($sourcePath);

        // 신버전 source 에 신규 최상위 디렉토리 + 보호 대상 디렉토리 + 파일 배치
        File::ensureDirectoryExists($sourcePath.DIRECTORY_SEPARATOR.'lang-packs'.DIRECTORY_SEPARATOR.'_bundled'.DIRECTORY_SEPARATOR.'g7-core-ja');
        File::put(
            $sourcePath.DIRECTORY_SEPARATOR.'lang-packs'.DIRECTORY_SEPARATOR.'_bundled'.DIRECTORY_SEPARATOR.'g7-core-ja'.DIRECTORY_SEPARATOR.'language-pack.json',
            '{"identifier":"g7-core-ja"}'
        );
        File::ensureDirectoryExists($sourcePath.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app');
        File::put($sourcePath.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'leak.txt', 'should_not_copy');
        File::put($sourcePath.DIRECTORY_SEPARATOR.'.env', 'APP_VERSION=hijacked');

        // 격리된 base_path 시뮬레이션
        $fakeBase = storage_path('test_apply_autodisc_base_'.uniqid());
        $this->tempDirs[] = $fakeBase;
        File::ensureDirectoryExists($fakeBase);

        $originalBasePath = base_path();
        app()->setBasePath($fakeBase);

        // 활성 측에 보호 대상 dir 가 이미 존재하고 내용이 있는 상태 (덮어쓰면 안 됨)
        File::ensureDirectoryExists($fakeBase.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app');
        File::put($fakeBase.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'runtime.txt', 'KEEP_ME');
        File::put($fakeBase.DIRECTORY_SEPARATOR.'.env', 'APP_VERSION=local');

        try {
            // stale targets 시뮬레이션 — beta.3 의 targets 에는 lang-packs 가 없음
            config([
                'app.update.targets' => ['app'],
                'app.update.excludes' => [],
                'app.update.protected_paths' => ['.env', 'storage', 'vendor', 'node_modules', '.git'],
            ]);

            // app 디렉토리도 source 에 있어야 targets 분기를 거침
            File::ensureDirectoryExists($sourcePath.DIRECTORY_SEPARATOR.'app');
            File::put($sourcePath.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Marker.php', '<?php // marker');

            $this->service->applyUpdate($sourcePath);

            // targets 명시 항목은 기존대로 적용
            $this->assertFileExists($fakeBase.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Marker.php');

            // 자동 발견: lang-packs/_bundled 가 source 에서 활성으로 복사되어야 함
            $this->assertFileExists(
                $fakeBase.DIRECTORY_SEPARATOR.'lang-packs'.DIRECTORY_SEPARATOR.'_bundled'.DIRECTORY_SEPARATOR.'g7-core-ja'.DIRECTORY_SEPARATOR.'language-pack.json',
                'lang-packs/_bundled 가 source 에서 자동 발견되어 복사되어야 한다'
            );

            // PROTECTED 보호: storage/.env 는 자동 발견 폴백이 절대 덮지 않음
            $this->assertSame('KEEP_ME', File::get($fakeBase.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'runtime.txt'));
            $this->assertSame('APP_VERSION=local', File::get($fakeBase.DIRECTORY_SEPARATOR.'.env'));
        } finally {
            app()->setBasePath($originalBasePath);
        }
    }

    /**
     * 자동 발견 폴백이 protected_paths 와 user excludes 양쪽을 존중하는지 검증합니다.
     */
    public function test_apply_update_auto_discovery_respects_protected_paths_and_excludes(): void
    {
        $sourcePath = storage_path('test_apply_protected_src_'.uniqid());
        $this->tempDirs[] = $sourcePath;
        File::ensureDirectoryExists($sourcePath);

        File::ensureDirectoryExists($sourcePath.DIRECTORY_SEPARATOR.'unknown-new-dir');
        File::put($sourcePath.DIRECTORY_SEPARATOR.'unknown-new-dir'.DIRECTORY_SEPARATOR.'a.txt', 'x');
        File::ensureDirectoryExists($sourcePath.DIRECTORY_SEPARATOR.'.claude');
        File::put($sourcePath.DIRECTORY_SEPARATOR.'.claude'.DIRECTORY_SEPARATOR.'leak.txt', 'should_not_copy');
        File::ensureDirectoryExists($sourcePath.DIRECTORY_SEPARATOR.'excluded-by-user');
        File::put($sourcePath.DIRECTORY_SEPARATOR.'excluded-by-user'.DIRECTORY_SEPARATOR.'a.txt', 'x');

        $fakeBase = storage_path('test_apply_protected_base_'.uniqid());
        $this->tempDirs[] = $fakeBase;
        File::ensureDirectoryExists($fakeBase);

        $originalBasePath = base_path();
        app()->setBasePath($fakeBase);

        try {
            config([
                'app.update.targets' => [],
                'app.update.excludes' => ['excluded-by-user'],
                'app.update.protected_paths' => ['.claude', '.env', 'storage', 'vendor', 'node_modules', '.git'],
            ]);

            $this->service->applyUpdate($sourcePath);

            // 자동 발견 통과
            $this->assertFileExists($fakeBase.DIRECTORY_SEPARATOR.'unknown-new-dir'.DIRECTORY_SEPARATOR.'a.txt');

            // protected_paths 차단
            $this->assertDirectoryDoesNotExist($fakeBase.DIRECTORY_SEPARATOR.'.claude');

            // user excludes 차단
            $this->assertDirectoryDoesNotExist($fakeBase.DIRECTORY_SEPARATOR.'excluded-by-user');
        } finally {
            app()->setBasePath($originalBasePath);
        }
    }
}
