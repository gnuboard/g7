<?php

namespace Tests\Unit\Services;

use App\Extension\Helpers\BackupDirectoryName;
use App\Extension\Helpers\CoreBackupHelper;
use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\Helpers\RootWebConfigUpdater;
use App\Services\CoreUpdateService;
use App\Services\StorageLeftoverPruneService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsZipFixtures;
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
    use BuildsZipFixtures;

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

    /**
     * 진입 시점의 업데이트 트리 플래그 (tearDown 에서 복원)
     */
    private string|false $originalUpdateFlag = false;

    /**
     * 진입 시점의 APP_VERSION 3채널 값 (tearDown 에서 복원)
     *
     * @var array{env: string|null, server: string|null, putenv: string|false}
     */
    private array $originalAppVersion = ['env' => null, 'server' => null, 'putenv' => false];

    /**
     * APP_VERSION 3채널과 업데이트 트리 플래그를 스냅샷하고 시험 대상 서비스를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // `updateVersionInEnv()` 는 `.env` 파일만이 아니라 프로세스 환경(3채널)도 갱신한다.
        // 그 부수효과가 클래스 안에 남으면 **뒤이은 테스트가 부팅하는 앱의 `config('app.version')`**
        // 이 오염된다(`config/app.php` 가 `env('APP_VERSION', …)` 이므로). 실제로 그 순서 의존으로
        // `test_check_updates_command_shows_success` 가 "현재 최신 버전입니다" 대신 업데이트 있음으로
        // 갈렸다. 진입 시 스냅샷을 떠 두고 종료 시 되돌린다.
        $this->originalAppVersion = [
            'env' => $_ENV['APP_VERSION'] ?? null,
            'server' => $_SERVER['APP_VERSION'] ?? null,
            'putenv' => getenv('APP_VERSION'),
        ];

        // 이 클래스의 한 케이스가 `core:update` 를 실제로 호출하는데, 부모 커맨드는 "프로세스
        // 종료와 함께 소멸" 을 전제로 `G7_UPDATE_IN_PROGRESS` 를 정리하지 않는다. PHPUnit 은
        // 프로세스가 이어지므로 그 플래그가 이후 모든 테스트로 새어 나가, 부팅마다
        // `bootstrap/app.php` 의 매니페스트 자가 치유가 발동한다(내용은 같아도 불필요한 churn).
        // 진입 시 비우고 종료 시 되돌린다 — ExecuteUpgradeStepsStandaloneTest 와 같은 규율.
        $this->originalUpdateFlag = getenv('G7_UPDATE_IN_PROGRESS');
        putenv('G7_UPDATE_IN_PROGRESS');
        unset($_ENV['G7_UPDATE_IN_PROGRESS'], $_SERVER['G7_UPDATE_IN_PROGRESS']);

        $this->service = new CoreUpdateService;
    }

    /**
     * APP_VERSION 3채널·업데이트 트리 플래그를 진입 시점 값으로 되돌리고 임시 파일·디렉토리를 정리한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        // APP_VERSION 3채널 복원 (updateVersionInEnv 부수효과 격리)
        foreach (['env' => &$_ENV, 'server' => &$_SERVER] as $slot => &$bag) {
            if ($this->originalAppVersion[$slot] === null) {
                unset($bag['APP_VERSION']);
            } else {
                $bag['APP_VERSION'] = $this->originalAppVersion[$slot];
            }
        }
        unset($bag);

        if ($this->originalAppVersion['putenv'] === false) {
            putenv('APP_VERSION');
        } else {
            putenv('APP_VERSION='.$this->originalAppVersion['putenv']);
        }

        if ($this->originalUpdateFlag === false) {
            putenv('G7_UPDATE_IN_PROGRESS');
            unset($_ENV['G7_UPDATE_IN_PROGRESS'], $_SERVER['G7_UPDATE_IN_PROGRESS']);
        } else {
            putenv('G7_UPDATE_IN_PROGRESS='.$this->originalUpdateFlag);
            $_ENV['G7_UPDATE_IN_PROGRESS'] = $this->originalUpdateFlag;
            $_SERVER['G7_UPDATE_IN_PROGRESS'] = $this->originalUpdateFlag;
        }

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
     *
     * @effects checkForUpdates_returns_version_comparison_structure
     *
     * @return void 반환값 없음
     */
    public function test_check_for_updates_returns_correct_structure(): void
    {
        config(['app.update.github_url' => 'https://github.com/test-owner/test-repo']);

        $service = new class extends CoreUpdateService
        {
            /**
             * GitHub 조회를 시험용 고정 응답으로 대체한다.
             *
             * @param  string  $githubUrl  조회 대상 GitHub 저장소 URL
             * @return array 버전과 오류 메시지를 담은 고정 응답
             */
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
     *
     * @effects checkForUpdates_marks_check_failed_with_error_when_lookup_fails
     *
     * @return void 반환값 없음
     */
    public function test_check_for_updates_returns_check_failed_when_github_fails(): void
    {
        config(['app.update.github_url' => 'https://github.com/test-owner/test-repo']);

        $service = new class extends CoreUpdateService
        {
            /**
             * GitHub 조회를 시험용 고정 응답으로 대체한다.
             *
             * @param  string  $githubUrl  조회 대상 GitHub 저장소 URL
             * @return array 버전과 오류 메시지를 담은 고정 응답
             */
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
     *
     * @effects checkForUpdates_marks_check_failed_with_error_when_lookup_fails
     *
     * @return void 반환값 없음
     */
    public function test_check_for_updates_handles_no_releases(): void
    {
        config(['app.update.github_url' => 'https://github.com/test-owner/test-repo']);

        $service = new class extends CoreUpdateService
        {
            /**
             * GitHub 조회를 시험용 고정 응답으로 대체한다.
             *
             * @param  string  $githubUrl  조회 대상 GitHub 저장소 URL
             * @return array 버전과 오류 메시지를 담은 고정 응답
             */
            protected function fetchLatestVersionFromGithub(string $githubUrl): array
            {
                return ['version' => null, 'error' => '릴리스가 없습니다.'];
            }
        };

        $result = $service->checkForUpdates();

        $this->assertFalse($result['update_available']);
        $this->assertTrue($result['check_failed']);
    }

    /**
     * 릴리스 응답에 `tag_name` 이 없을 때의 안내에 **치환 자리가 남지 않는다.**
     *
     * `no_releases_found` 는 `(HTTP :status — :message)` 를 싣는 키다. 파라미터 없이
     * 부르면 번역기가 자리표시자를 그대로 둔 문장을 돌려주므로, 운영자 화면에 `:status`·
     * `:message` 가 노출된다. 이 경로는 GitHub 이 200 을 주면서 페이로드가 비어 있을 때만
     * 도는 실패 분기라 정상 흐름 테스트로는 드러나지 않는다.
     *
     * @scenario release_payload=missing_tag_name, api_message=absent
     *
     * @effects version_check_failure_message_has_no_unfilled_placeholder
     *
     * @return void 반환값 없음
     */
    public function test_missing_tag_name_error_has_no_unfilled_placeholder(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response([], 200),
        ]);

        $service = new class extends CoreUpdateService
        {
            /**
             * 보호 메서드를 테스트에서 직접 호출하기 위한 노출 지점.
             *
             * @param  string  $githubUrl  저장소 URL
             * @return array{version: ?string, error: ?string} 조회 결과
             */
            public function exposeFetch(string $githubUrl): array
            {
                return $this->fetchLatestVersionFromGithub($githubUrl);
            }
        };

        $result = $service->exposeFetch('https://github.com/test-owner/test-repo');

        $this->assertNull($result['version']);
        $this->assertIsString($result['error']);
        $this->assertStringNotContainsString(
            ':status',
            $result['error'],
            '치환 자리가 그대로 남았다 — 운영자 화면에 `:status` 가 노출된다'
        );
        $this->assertStringNotContainsString(
            ':message',
            $result['error'],
            '치환 자리가 그대로 남았다 — 운영자 화면에 `:message` 가 노출된다'
        );
    }

    /**
     * 사유가 **비어 있는** 응답에서도 화면 문구에 빈 괄호가 남지 않는다 — 형제 분기 전수.
     *
     * `(HTTP :status — :message)` 서식을 쓰는 키는 다섯이다. 종전에는 그중 하나만
     * 폴백을 갖고 나머지 넷은 GitHub 이 준 `message` 를 **그대로** 넘겼다 — 비-JSON
     * 본문이나 `message` 없는 응답에서는 그 값이 빈 문자열이라 운영자 화면에
     * `(HTTP 401 — )` 가 뜬다. 치환 자리가 남는 것과 증상만 다를 뿐 같은 결함이다.
     *
     * 상태코드별로 다른 분기를 타므로 **분기마다** 잰다 — 한 코드만 재면 나머지가
     * 그대로 빠져도 초록이고, 실제로 그 상태였다.
     *
     * @scenario api_message=absent, http_status=401|403|404|500
     *
     * @effects version_check_failure_message_has_no_unfilled_placeholder
     *
     * @return void 반환값 없음
     */
    public function test_empty_api_message_does_not_leave_a_blank_reason(): void
    {
        $service = new class extends CoreUpdateService
        {
            /**
             * 보호 메서드를 테스트에서 직접 호출하기 위한 노출 지점.
             *
             * @param  string  $githubUrl  저장소 URL
             * @return array{version: ?string, error: ?string} 조회 결과
             */
            public function exposeFetch(string $githubUrl): array
            {
                return $this->fetchLatestVersionFromGithub($githubUrl);
            }
        };

        // 401·403 은 토큰이 있을 때만 `github_token_invalid` 분기를 탄다(없으면 안내가 다르다).
        config()->set('core_update.github_token', 'dummy-token');

        foreach ([401, 403, 404, 500] as $status) {
            // 본문에 `message` 가 없다 — 실제 GitHub 이 비-JSON 오류 본문을 줄 때의 형태다.
            Http::fake([
                'api.github.com/*' => Http::response([], $status),
            ]);

            $result = $service->exposeFetch('https://github.com/test-owner/test-repo');

            $this->assertIsString($result['error'], "HTTP {$status} 에서 사유 문자열이 만들어지지 않았다");

            $this->assertDoesNotMatchRegularExpression(
                '/—\s*\)/u',
                $result['error'],
                "HTTP {$status} 의 안내에 빈 사유가 남았다 — 운영자 화면에 `(HTTP {$status} — )` 가 뜬다"
            );
        }
    }

    /**
     * 교체 직전 사본의 **부분 쓰기**는 「남겼다」로 보고되지 않는다.
     *
     * `file_put_contents` 는 디스크가 차면 `false` 가 아니라 쓴 바이트 수를 돌려준다.
     * `=== false` 만 보면 잘린 사본이 성공으로 통과해 「여기 사본이 있습니다」라는 안내가
     * 나가는데, 그 사본으로는 되찾을 수 없다 — 이 자리는 업데이트 성공 시 코어 백업이
     * 즉시 삭제된 뒤 남는 **유일한 복구 수단**이다. 형제 두 지점은 이미 길이를 대조한다.
     *
     * 잘린 사본을 남겨 두는 것도 안 된다 — 잔존물 정리기가 그것을 백업으로 보고
     * 「최신 1개」 자리를 차지하게 해, 되돌릴 수 없는 파일이 되돌릴 수단 자리를 막는다.
     *
     * @scenario write_outcome=partial, write_outcome=complete, recovery_slot=occupied, recovery_slot=free
     *
     * @effects replaced_root_index_copy_reports_only_a_complete_write
     *
     * @return void 반환값 없음
     */
    public function test_partial_write_of_the_preserved_root_index_is_reported_as_not_preserved(): void
    {
        $work = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g7-preserve-partial-'.bin2hex(random_bytes(4));
        $storage = $work.DIRECTORY_SEPARATOR.'storage';
        File::ensureDirectoryExists($storage);
        $this->tempDirs[] = $work;

        $originalStoragePath = app()->storagePath();
        app()->useStoragePath($storage);

        $service = new class extends CoreUpdateService
        {
            /**
             * 보호 메서드를 테스트에서 직접 호출하기 위한 노출 지점.
             *
             * @param  string  $contents  교체 직전 파일 내용
             * @return string|null 사본 경로 (남기지 못했으면 null)
             */
            public function exposePreserve(string $contents): ?string
            {
                return $this->preserveReplacedRootIndex($contents);
            }
        };

        $contents = str_repeat('A', 512);
        $copyRoot = $storage.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'core-update';

        try {
            // 디스크가 찬 상황 — 쓰기는 성공을 반환하지만 일부만 기록된다.
            File::partialMock()
                ->shouldReceive('put')
                ->once()
                ->andReturn(3);

            $this->assertNull(
                $service->exposePreserve($contents),
                '잘린 사본을 「남겼다」로 보고하면 운영자가 복구 가능하다고 믿는다'
            );

            $this->assertSame(
                [],
                is_dir($copyRoot) ? array_values(array_diff((array) scandir($copyRoot), ['.', '..'])) : [],
                '잘린 사본이 남았다 — 잔존물 정리기가 그것을 「최신 1개」로 보존해 진짜 복구 수단 자리를 막는다'
            );
        } finally {
            app()->useStoragePath($originalStoragePath);
        }
    }

    /**
     * 대조군 — 온전히 쓰인 사본은 경로를 돌려준다.
     *
     * 이 대조군이 없으면 「무엇을 넣어도 null 을 돌려주는」 구현으로도 위 테스트가 초록이
     * 되고, 복구 수단이 통째로 사라지는 기능 축소를 아무도 잡지 못한다.
     *
     * @scenario write_outcome=complete
     *
     * @effects replaced_root_index_copy_reports_only_a_complete_write
     *
     * @return void 반환값 없음
     */
    public function test_complete_write_of_the_preserved_root_index_returns_the_copy_path(): void
    {
        $work = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g7-preserve-full-'.bin2hex(random_bytes(4));
        $storage = $work.DIRECTORY_SEPARATOR.'storage';
        File::ensureDirectoryExists($storage);
        $this->tempDirs[] = $work;

        $originalStoragePath = app()->storagePath();
        app()->useStoragePath($storage);

        $service = new class extends CoreUpdateService
        {
            /**
             * 보호 메서드를 테스트에서 직접 호출하기 위한 노출 지점.
             *
             * @param  string  $contents  교체 직전 파일 내용
             * @return string|null 사본 경로 (남기지 못했으면 null)
             */
            public function exposePreserve(string $contents): ?string
            {
                return $this->preserveReplacedRootIndex($contents);
            }
        };

        try {
            $contents = "<?php\n// 운영자 랜딩\n";
            $copy = $service->exposePreserve($contents);

            $this->assertIsString($copy, '온전히 쓴 사본을 「남기지 못했다」로 보고하면 복구 안내가 사라진다');
            $this->assertFileExists($copy);
            $this->assertSame($contents, (string) file_get_contents($copy));
        } finally {
            app()->useStoragePath($originalStoragePath);
        }
    }

    /**
     * 루트 진입 파일 복사가 **조용히 실패**하면 운영자에게 도달한다.
     *
     * `FilePermissionHelper::copyFile()` 은 `void` 이고 그 안의 `File::copy()` 가 돌려주는
     * `false` 를 삼킨다. 그래서 루트 파일만 웹 프로세스가 덮어쓸 수 없는 배치(FTP 로 푼
     * 사이트 — 이 안이 대상으로 삼은 바로 그 호스팅)에서는 예외 없이 돌아오고 **구버전
     * 파일이 그대로 남는다.** 종전 판정은 대상 파일이 **없을 때만** 통보했는데, 이 경우는
     * `is_file()` 이 참이라 통보가 통째로 빠졌다 — 크기를 재고도 그 결과를 버린 셈이라
     * 길이 대조를 지워도 관측 동작이 같았다.
     *
     * @scenario copy_outcome=silently_failed, copy_outcome=succeeded, destination=stale_file
     *
     * @effects root_file_copy_that_did_not_land_is_reported_not_claimed_as_updated
     *
     * @return void 반환값 없음
     */
    public function test_silently_failed_root_file_copy_is_reported(): void
    {
        $work = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g7-rootcopy-fail-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($work);
        $this->tempDirs[] = $work;

        $source = $work.DIRECTORY_SEPARATOR.'source-index.php';
        $destination = $work.DIRECTORY_SEPARATOR.'index.php';
        file_put_contents($source, str_repeat('N', 256));
        file_put_contents($destination, 'OLD');

        $service = $this->makeRootCopyService();

        // 덮어쓰지 못하는 배치 — 예외는 없고 반환값만 false 다(호출부가 그 값을 버린다).
        File::partialMock()
            ->shouldReceive('copy')
            ->once()
            ->andReturn(false);

        $service->exposeCopyRootFile('index.php', $source, $destination);

        $warnings = $service->getLastRootFileWarnings();

        $this->assertNotEmpty(
            $warnings,
            '복사가 도달하지 않았는데 통보가 없다 — 콘솔에는 「코어 파일 적용 완료」만 뜨고 루트 진입 파일은 구버전으로 남는다'
        );
        $this->assertStringContainsString(
            $destination,
            implode("\n", $warnings),
            '통보가 대상 경로를 싣지 않으면 운영자는 무엇을 확인해야 할지 알 수 없다'
        );
        $this->assertSame('OLD', (string) file_get_contents($destination), '이 케이스의 전제(구버전 잔존)가 성립하지 않았다');
    }

    /**
     * 대조군 — 온전히 도달한 복사에는 통보가 붙지 않는다.
     *
     * 이 대조군이 없으면 「언제나 경고하는」 구현으로도 위 검사가 초록이 되고, 정상
     * 업데이트마다 거짓 경보가 뜨는 회귀를 아무도 잡지 못한다.
     *
     * @scenario copy_outcome=succeeded
     *
     * @effects root_file_copy_that_did_not_land_is_reported_not_claimed_as_updated
     *
     * @return void 반환값 없음
     */
    public function test_successful_root_file_copy_records_no_warning(): void
    {
        $work = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g7-rootcopy-ok-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($work);
        $this->tempDirs[] = $work;

        $source = $work.DIRECTORY_SEPARATOR.'source-index.php';
        $destination = $work.DIRECTORY_SEPARATOR.'index.php';
        file_put_contents($source, str_repeat('N', 256));
        file_put_contents($destination, 'OLD');

        $service = $this->makeRootCopyService();

        $service->exposeCopyRootFile('index.php', $source, $destination);

        $this->assertSame(
            [],
            $service->getLastRootFileWarnings(),
            '정상 복사에 경보가 붙으면 매 업데이트마다 거짓 경보가 뜬다'
        );
        $this->assertSame(str_repeat('N', 256), (string) file_get_contents($destination));
    }

    /**
     * 보호 메서드 `copyRootFileOrWarn()` 을 테스트에서 부르기 위한 노출 지점.
     *
     * @return CoreUpdateService 노출 메서드를 가진 서비스
     */
    private function makeRootCopyService(): CoreUpdateService
    {
        return new class extends CoreUpdateService
        {
            /**
             * 보호 메서드를 테스트에서 직접 호출하기 위한 노출 지점.
             *
             * @param  string  $target  정규화된 대상 이름
             * @param  string  $source  소스 파일
             * @param  string  $destination  대상 파일
             * @return void 반환값 없음
             */
            public function exposeCopyRootFile(string $target, string $source, string $destination): void
            {
                $this->copyRootFileOrWarn($target, $source, $destination);
            }
        };
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
     *
     * @effects getChangelog_parses_versions_and_categories
     *
     * @return void 반환값 없음
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
     *
     * @effects getChangelog_filters_by_version_range
     *
     * @return void 반환값 없음
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
     *
     * @effects getChangelog_returns_empty_when_file_missing
     *
     * @return void 반환값 없음
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
     *
     * @effects validatePendingPath_creates_missing_directory
     *
     * @return void 반환값 없음
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
     *
     * @effects maintenance_mode_toggles_via_down_and_up_commands
     *
     * @return void 반환값 없음
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
     *
     * @effects updateVersionInEnv_replaces_app_version_keeping_other_keys
     *
     * @return void 반환값 없음
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
     * `.env` 파일만이 아니라 프로세스 환경(3채널)도 함께 갱신하는지 검증합니다.
     *
     * 부모가 config 캐시 없이 부팅했다면 Dotenv 가 `$_ENV['APP_VERSION']` 에 이전 버전을 채워 두고,
     * env 저장소가 불변이라 뒤이어 부팅하는 `config:cache` 프로세스가 `.env` 를 다시 읽어도 그 값이
     * 바뀌지 않는다. 그러면 `bootstrap/cache/config.php` 에 **이전 버전**이 박제되어 이후 모든 웹
     * 요청이 옛 버전으로 판정한다 — 새 코어를 요구하는 확장이 `incompatible_core` 로 꺼지는 경로다.
     * (2026-09-07 실측: `config:clear` 후 7.0.10 → 7.0.11 업데이트가 config 캐시에 7.0.10 을 구웠다.)
     *
     * @effects updateVersionInEnv_syncs_process_env_channels, updateVersionInEnv_syncs_process_env_channels_so_config_cache_bakes_new_version
     *
     * @return void 반환값 없음
     */
    public function test_update_version_in_env_also_updates_process_env(): void
    {
        $originalEnv = $_ENV['APP_VERSION'] ?? null;
        $originalServer = $_SERVER['APP_VERSION'] ?? null;
        $originalPutenv = getenv('APP_VERSION');

        try {
            // 업데이트 전 프로세스 환경 = 이전 버전 (캐시 없이 부팅한 부모의 상태)
            $_ENV['APP_VERSION'] = '1.0.0';
            $_SERVER['APP_VERSION'] = '1.0.0';
            putenv('APP_VERSION=1.0.0');

            $this->withIsolatedBasePath(function (string $tempBase): void {
                File::put($tempBase.DIRECTORY_SEPARATOR.'.env', "APP_NAME=G7\nAPP_VERSION=1.0.0\n");

                $this->service->updateVersionInEnv('2.5.0');
            });

            $this->assertSame('2.5.0', $_ENV['APP_VERSION'], '$_ENV 채널이 갱신되어야 한다');
            $this->assertSame('2.5.0', $_SERVER['APP_VERSION'], '$_SERVER 채널이 갱신되어야 한다');
            $this->assertSame('2.5.0', getenv('APP_VERSION'), 'putenv 채널이 갱신되어야 한다');
        } finally {
            if ($originalEnv === null) {
                unset($_ENV['APP_VERSION']);
            } else {
                $_ENV['APP_VERSION'] = $originalEnv;
            }
            if ($originalServer === null) {
                unset($_SERVER['APP_VERSION']);
            } else {
                $_SERVER['APP_VERSION'] = $originalServer;
            }
            if ($originalPutenv === false) {
                putenv('APP_VERSION');
            } else {
                putenv('APP_VERSION='.$originalPutenv);
            }
        }
    }

    /**
     * .env 파일에 APP_VERSION 이 없을 때 새로 추가하는지 검증합니다.
     *
     * @effects updateVersionInEnv_appends_app_version_when_missing
     *
     * @return void 반환값 없음
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
     *
     * @effects updateVersionInEnv_never_touches_real_env_file
     *
     * @return void 반환값 없음
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
     * @param  \Closure(string $tempBase): void  $callback  격리된 임시 base path 를 인자로 받아 실행할 콜백
     * @return void 반환값 없음
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
     *
     * @effects generateFailureReport_writes_log_with_versions_and_trace
     *
     * @return void 반환값 없음
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
     *
     * @effects cleanupPending_removes_directory_and_tolerates_missing_path
     *
     * @return void 반환값 없음
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
     *
     * @effects cleanupPending_removes_directory_and_tolerates_missing_path
     *
     * @return void 반환값 없음
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
     *
     * @effects createPendingDirectory_creates_unique_timestamped_directory
     *
     * @return void 반환값 없음
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
        // 초 단위 타임스탬프 뒤에 난수 접미사가 붙는다 — 같은 초에 시작한 두 실행이
        // 같은 디렉토리를 받으면 한쪽이 내려받는 중인 페이로드에 다른 쪽이 겹쳐 쓴다.
        $this->assertMatchesRegularExpression('/^core_\d{8}_\d{6}_[0-9a-f]{8}$/', $dirName);
    }

    /**
     * createPendingDirectory()가 매 호출마다 고유한 경로를 반환하는지 검증합니다.
     *
     * @effects createPendingDirectory_creates_unique_timestamped_directory
     *
     * @return void 반환값 없음
     */
    public function test_create_pending_directory_returns_unique_paths(): void
    {
        $basePath = storage_path('test_pending_unique_'.uniqid());
        $this->tempDirs[] = $basePath;

        File::ensureDirectoryExists($basePath);
        config(['app.update.pending_path' => $basePath]);

        $path1 = $this->service->createPendingDirectory();
        $this->tempDirs[] = $path1;

        // 경로 이름은 PHP date('Ymd_His') 라 시각을 옮길 수 없다 — 1초를 통째로 쉬지 않고 다음 초가 될 때까지만 기다린다
        $second = date('Ymd_His');
        while (date('Ymd_His') === $second) {
            usleep(20_000);
        }

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
     *
     * @effects clearAllCaches_runs_cache_clear_and_package_discovery_commands
     *
     * @return void 반환값 없음
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
     *
     * @effects core_check_updates_command_reports_up_to_date
     *
     * @return void 반환값 없음
     */
    public function test_check_updates_command_shows_success(): void
    {
        config(['app.update.github_url' => 'https://github.com/test/repo']);

        $this->instance(CoreUpdateService::class, new class extends CoreUpdateService
        {
            /**
             * GitHub 조회를 시험용 고정 응답으로 대체한다.
             *
             * @param  string  $githubUrl  조회 대상 GitHub 저장소 URL
             * @return array 버전과 오류 메시지를 담은 고정 응답
             */
            protected function fetchLatestVersionFromGithub(string $githubUrl): array
            {
                return ['version' => '7.0.0-alpha.1', 'error' => null];
            }
        });

        $this->artisan('core:check-updates')
            ->expectsOutputToContain(__('common.console.core_check_updates.up_to_date'))
            ->assertExitCode(0);
    }

    /**
     * 업데이트 확인 실패 시 커맨드가 에러 메시지를 출력하는지 검증합니다.
     *
     * @effects core_check_updates_command_prints_failure_and_exits_nonzero
     *
     * @return void 반환값 없음
     */
    public function test_check_updates_command_shows_error_on_failure(): void
    {
        config(['app.update.github_url' => 'https://github.com/test/repo']);

        $this->instance(CoreUpdateService::class, new class extends CoreUpdateService
        {
            /**
             * GitHub 조회를 시험용 고정 응답으로 대체한다.
             *
             * @param  string  $githubUrl  조회 대상 GitHub 저장소 URL
             * @return array 버전과 오류 메시지를 담은 고정 응답
             */
            protected function fetchLatestVersionFromGithub(string $githubUrl): array
            {
                return ['version' => null, 'error' => 'GitHub 저장소를 찾을 수 없습니다.'];
            }
        });

        $this->artisan('core:check-updates')
            ->expectsOutputToContain(__('common.console.core_check_updates.failed', ['error' => 'GitHub 저장소를 찾을 수 없습니다.']))
            ->assertExitCode(1);
    }

    /**
     * core:update 커맨드에서 업데이트 확인 실패 시 에러 출력 후 종료하는지 검증합니다.
     *
     * @effects core_update_command_aborts_when_update_check_fails
     *
     * @return void 반환값 없음
     */
    public function test_update_command_shows_error_on_check_failure(): void
    {
        config(['app.update.github_url' => 'https://github.com/test/repo']);

        $this->instance(CoreUpdateService::class, new class extends CoreUpdateService
        {
            /**
             * 시스템 요구사항 검사를 항상 통과하는 고정 응답으로 대체한다.
             *
             * @return array 통과 상태와 사용 가능한 추출 방식을 담은 고정 응답
             */
            public function checkSystemRequirements(): array
            {
                return ['valid' => true, 'errors' => [], 'available_methods' => ['ZipArchive']];
            }

            /**
             * GitHub 조회를 시험용 고정 응답으로 대체한다.
             *
             * @param  string  $githubUrl  조회 대상 GitHub 저장소 URL
             * @return array 버전과 오류 메시지를 담은 고정 응답
             */
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
     *
     * @effects core_check_updates_command_prints_failure_and_exits_nonzero
     *
     * @return void 반환값 없음
     */
    public function test_check_updates_command_shows_auth_error(): void
    {
        config(['app.update.github_url' => 'https://github.com/test/private-repo']);

        $this->instance(CoreUpdateService::class, new class extends CoreUpdateService
        {
            /**
             * GitHub 조회를 시험용 고정 응답으로 대체한다.
             *
             * @param  string  $githubUrl  조회 대상 GitHub 저장소 URL
             * @return array 버전과 오류 메시지를 담은 고정 응답
             */
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
     *
     * @effects update_targets_include_public_and_core_root_files
     *
     * @return void 반환값 없음
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
     *
     * @effects update_targets_include_public_and_core_root_files
     *
     * @return void 반환값 없음
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
     *
     * @effects update_targets_include_public_and_core_root_files
     *
     * @return void 반환값 없음
     */
    public function test_update_targets_includes_additional_core_files(): void
    {
        $targets = config('app.update.targets');

        $coreAdditional = [
            'vite.config.core.js',
            'vitest.config.ts',
            'tsconfig.json',
            // tsconfig.json 이 참조하는 두 설정(7.2.0) — 빠지면 업데이트 뒤 루트 설정이 없는 파일을 가리킨다.
            'tsconfig.app.json',
            'tsconfig.test.json',
            'composer.json.default',
            'tests',
            'docs',
            // 한국어 README — 영문 README.md 와 짝을 이루는 공개 문서이므로 함께 배포되어야 한다.
            'README.ko.md',
            // 빌드 보조 스크립트 폴더와 개발 대시보드 빌드 설정 — 배포본에 실리는데 대상에서 빠지면 업데이트 뒤에도 옛 것이 남는다.
            'scripts',
            'vite.config.devdashboard.js',
            // 터미널 설치 명령 진입 파일(7.1.4) — artisan 과 같은 루트 실행 파일이라 빠지면 업데이트 뒤에도 옛 것이 남는다.
            'g7-install',
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
     *
     * @effects checkSystemRequirements_returns_validity_structure
     *
     * @return void 반환값 없음
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
     *
     * @effects extraction_strategies_exclude_phardata_and_use_zipball
     *
     * @return void 반환값 없음
     */
    public function test_build_extraction_strategies_does_not_include_phardata(): void
    {
        $strategies = $this->invokeProtectedMethod($this->service, 'buildExtractionStrategies');

        $labels = array_map(fn ($s) => $s['label'], $strategies);
        $this->assertNotContains('PharData', $labels);
    }

    /**
     * ZipArchive 사용 가능 시 전략에 포함되는지 검증합니다.
     *
     * @effects extraction_strategies_exclude_phardata_and_use_zipball
     *
     * @return void 반환값 없음
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
     *
     * @effects extraction_strategies_exclude_phardata_and_use_zipball
     *
     * @return void 반환값 없음
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
     *
     * @effects extractWithZipArchive_extracts_zip_contents
     *
     * @return void 반환값 없음
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
     *
     * @effects validatePendingUpdate_requires_g7_config_with_version
     *
     * @return void 반환값 없음
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
     *
     * @effects validatePendingUpdate_requires_g7_config_with_version
     *
     * @return void 반환값 없음
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
     *
     * @effects validatePendingUpdate_requires_g7_config_with_version
     *
     * @return void 반환값 없음
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
     * @return mixed 호출한 메서드의 반환값
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
     *
     * @effects extractZipToPending_returns_package_root_for_flat_and_wrapped_zip
     *
     * @return void 반환값 없음
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
     *
     * @effects extractZipToPending_returns_package_root_for_flat_and_wrapped_zip
     *
     * @return void 반환값 없음
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
     * GitHub 모양(감싼 폴더의 폴더 항목이 맨 앞)에 Mac 재압축 부속 폴더(`__MACOSX/`)까지 붙은 ZIP 도 감싼 폴더를 벗긴 루트를 돌려주는지 확인합니다.
     *
     * 부속 폴더가 함께 풀리면 해제 결과 최상위가 폴더 둘이 되어 래퍼 판정이 실패하고, `core:update --zip` 이
     * 「잘못된 패키지」로 멈췄다.
     *
     * @effects extractZipToPending_returns_package_root_for_flat_and_wrapped_zip
     *
     * @return void 반환값 없음
     */
    public function test_extract_zip_to_pending_unwraps_github_and_mac_shaped_zip(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $pendingBase = storage_path('test_core_zip_pending_mac_'.uniqid());
        $this->tempDirs[] = $pendingBase;
        File::ensureDirectoryExists($pendingBase);
        config(['app.update.pending_path' => $pendingBase]);

        $zipDir = storage_path('test_core_zip_mac_src_'.uniqid());
        $this->tempDirs[] = $zipDir;
        File::ensureDirectoryExists($zipDir);
        $zipPath = $zipDir.DIRECTORY_SEPARATOR.'g7-7.2.0.zip';

        $this->makeZipFixture($zipPath, [
            'composer.json' => '{"name":"g7/core"}',
            'app/.gitkeep' => '',
            'config/app.php' => "<?php
return ['version' => '7.2.0'];",
        ], ['wrapper' => 'g7-7.2.0', 'directoryEntries' => true, 'macosx' => true]);

        $result = $this->service->extractZipToPending($zipPath);

        $this->assertStringEndsWith('g7-7.2.0', $result);
        $this->assertTrue(File::exists($result.DIRECTORY_SEPARATOR.'composer.json'));
        $this->assertDirectoryDoesNotExist(dirname($result).DIRECTORY_SEPARATOR.'__MACOSX', '부속 폴더가 함께 풀렸다');
    }

    /**
     * ZIP 파일이 존재하지 않을 때 RuntimeException 이 발생하는지 검증합니다.
     *
     * @effects extractZipToPending_rejects_missing_or_invalid_zip
     *
     * @return void 반환값 없음
     */
    public function test_extract_zip_to_pending_throws_when_zip_missing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->extractZipToPending(storage_path('nonexistent_'.uniqid().'.zip'));
    }

    /**
     * ZIP 내용이 G7 패키지가 아닐 때 검증에서 RuntimeException 이 발생하는지 확인합니다.
     *
     * @effects extractZipToPending_rejects_missing_or_invalid_zip
     *
     * @return void 반환값 없음
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
     *
     * @effects copyDirectory_remove_orphans_deletes_files_absent_from_source
     *
     * @return void 반환값 없음
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
        FilePermissionHelper::copyDirectory(
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
    // applyUpdate() — 기본(증분) 3-way 적용 + --prune (공개 #64)
    // ========================================================================

    /**
     * 증분 모드(applyList 지정): 코어가 변경하지 않은 파일(applyList 미포함)은
     * 스킵되어 사용자가 수정한 현재 디스크 내용이 보존됩니다.
     *
     * 이슈 #64 핵심 회귀 가드 — `.htaccess` 커스텀 블록 소실 방지.
     *
     * @scenario apply_mode=incremental
     *
     * @effects test_apply_update_incremental_preserves_user_modified_unchanged_file
     *
     * @return void 반환값 없음
     */
    public function test_apply_update_incremental_preserves_user_modified_unchanged_file(): void
    {
        [$source, $fakeBase, $restore] = $this->prepareApplyUpdateEnv(['public']);

        try {
            // source(theirs): .htaccess 는 코어가 변경하지 않았다고 가정 → applyList 미포함
            File::ensureDirectoryExists($source.DIRECTORY_SEPARATOR.'public');
            File::put($source.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'.htaccess', "# core default\n");
            File::put($source.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'index.php', "<?php // core v2\n");

            // 활성(mine): 사용자가 .htaccess 에 커스텀 블록을 넣은 상태
            File::ensureDirectoryExists($fakeBase.DIRECTORY_SEPARATOR.'public');
            File::put($fakeBase.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'.htaccess', "# core default\n# USER CUSTOM CACHE BLOCK\n");
            File::put($fakeBase.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'index.php', "<?php // core v1\n");

            // 코어가 실제로 바꾼 파일은 index.php 뿐 (.htaccess 는 목록에 없음)
            $applyList = ['public/index.php'];

            $this->service->applyUpdate($source, null, prune: false, applyList: $applyList);

            // .htaccess: 사용자 커스텀 블록 보존 (스킵)
            $this->assertStringContainsString(
                'USER CUSTOM CACHE BLOCK',
                File::get($fakeBase.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'.htaccess'),
                '.htaccess 는 applyList 에 없으므로 사용자 수정이 보존되어야 한다',
            );

            // index.php: 코어 변경분 적용
            $this->assertStringContainsString(
                'core v2',
                File::get($fakeBase.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'index.php'),
            );
        } finally {
            $restore();
        }
    }

    /**
     * 증분 모드: applyList 에 없는 신규 파일이 source 에 있어도 스킵됩니다.
     * (코어가 실제 추가했다면 applyList 에 담겼을 것 — 목록이 최종 권위)
     *
     * @scenario apply_mode=incremental
     *
     * @effects test_apply_update_incremental_applies_only_listed_files
     *
     * @return void 반환값 없음
     */
    public function test_apply_update_incremental_applies_only_listed_files(): void
    {
        [$source, $fakeBase, $restore] = $this->prepareApplyUpdateEnv(['app']);

        try {
            File::ensureDirectoryExists($source.DIRECTORY_SEPARATOR.'app');
            File::put($source.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Listed.php', '<?php // listed');
            File::put($source.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Unlisted.php', '<?php // unlisted');

            File::ensureDirectoryExists($fakeBase.DIRECTORY_SEPARATOR.'app');

            $this->service->applyUpdate($source, null, prune: false, applyList: ['app/Listed.php']);

            $this->assertFileExists($fakeBase.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Listed.php');
            $this->assertFileDoesNotExist(
                $fakeBase.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Unlisted.php',
                'applyList 에 없는 파일은 복사되지 않아야 한다',
            );
        } finally {
            $restore();
        }
    }

    /**
     * end-to-end 회귀 (공개 #64): 코어 업데이트가 번들 확장의 변경된
     * `_bundled` 파일을 실제로 반영합니다.
     *
     * computeApplyList(3-way 산출) → applyUpdate(적용) 전 체인을 실제 config 조합
     * (protected 에 `modules`, targets 에 `modules/_bundled`)으로 검증한다.
     * protected 필터가 `_bundled` 갱신을 삼키던 결함으로 인해, 코어 배포본에
     * 새 번들(vendor-bundle.json/composer.json 갱신)이 포함돼도 활성 서버의
     * `_bundled` 가 갱신되지 않아 이후 `module:update` 무결성 검증이 실패하던
     * 회귀를 차단한다.
     *
     * @scenario apply_mode=incremental
     *
     * @effects test_incremental_apply_reflects_changed_bundled_extension_file
     *
     * @return void 반환값 없음
     */
    public function test_incremental_apply_reflects_changed_bundled_extension_file(): void
    {
        [$source, $fakeBase, $restore] = $this->prepareApplyUpdateEnv(['modules/_bundled']);

        try {
            // 실제 config 조합 재현: protected 에 부모 도메인 포함
            config(['app.update.protected_paths' => ['.env', 'storage', 'vendor', 'modules', 'plugins', 'templates', 'lang-packs']]);

            $rel = 'modules/_bundled/sirsoft-ecommerce/vendor-bundle.json';
            $relPlatform = str_replace('/', DIRECTORY_SEPARATOR, $rel);

            // base(구버전) = 옛 해시, source(신버전) = 새 해시 (코어가 변경한 번들 파일)
            File::ensureDirectoryExists(dirname($fakeBase.DIRECTORY_SEPARATOR.$relPlatform));
            File::put($fakeBase.DIRECTORY_SEPARATOR.$relPlatform, '{"composer_json_sha256":"OLD"}');
            File::ensureDirectoryExists(dirname($source.DIRECTORY_SEPARATOR.$relPlatform));
            File::put($source.DIRECTORY_SEPARATOR.$relPlatform, '{"composer_json_sha256":"NEW-differs-in-length"}');

            // 3-way 산출: base != theirs → changed 로 목록 포함되어야 한다
            $applyResult = CoreBackupHelper::computeApplyList(
                $fakeBase,
                $source,
                config('app.update.targets'),
                config('app.update.protected_paths'),
                config('app.update.excludes'),
            );
            $this->assertContains($rel, $applyResult['apply'], 'changed 된 _bundled 파일이 apply 목록에 포함되어야 한다');

            // 적용: 활성(base) 파일이 신버전 내용으로 갱신되어야 한다
            $this->service->applyUpdate($source, null, prune: false, applyList: $applyResult['apply']);

            $this->assertSame(
                '{"composer_json_sha256":"NEW-differs-in-length"}',
                File::get($fakeBase.DIRECTORY_SEPARATOR.$relPlatform),
                '코어 업데이트가 변경된 _bundled 번들 파일을 실제로 반영해야 한다',
            );
        } finally {
            $restore();
        }
    }

    /**
     * 증분 모드는 orphan(소스에 없는 대상 파일)을 삭제하지 않습니다
     * (사용자가 추가한 신규 파일 보존).
     *
     * @scenario apply_mode=incremental
     *
     * @effects test_apply_update_incremental_does_not_remove_orphans
     *
     * @return void 반환값 없음
     */
    public function test_apply_update_incremental_does_not_remove_orphans(): void
    {
        [$source, $fakeBase, $restore] = $this->prepareApplyUpdateEnv(['app']);

        try {
            File::ensureDirectoryExists($source.DIRECTORY_SEPARATOR.'app');
            File::put($source.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Core.php', '<?php // core');

            File::ensureDirectoryExists($fakeBase.DIRECTORY_SEPARATOR.'app');
            File::put($fakeBase.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'UserAdded.php', '<?php // user file');

            $this->service->applyUpdate($source, null, prune: false, applyList: ['app/Core.php']);

            $this->assertFileExists(
                $fakeBase.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'UserAdded.php',
                '증분 모드는 orphan 을 삭제하지 않아야 한다',
            );
        } finally {
            $restore();
        }
    }

    /**
     * prune=true: 전체 덮어쓰기 + orphan 삭제 (기존 동작).
     *
     * @scenario apply_mode=prune
     *
     * @effects test_apply_update_prune_removes_orphans_and_overwrites_all
     *
     * @return void 반환값 없음
     */
    public function test_apply_update_prune_removes_orphans_and_overwrites_all(): void
    {
        [$source, $fakeBase, $restore] = $this->prepareApplyUpdateEnv(['app']);

        try {
            File::ensureDirectoryExists($source.DIRECTORY_SEPARATOR.'app');
            File::put($source.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Core.php', '<?php // core v2');

            File::ensureDirectoryExists($fakeBase.DIRECTORY_SEPARATOR.'app');
            File::put($fakeBase.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Core.php', '<?php // core v1');
            File::put($fakeBase.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Orphan.php', '<?php // orphan');

            // prune 모드는 applyList 를 무시하고 전체 덮어쓰기
            $this->service->applyUpdate($source, null, prune: true, applyList: ['app/Core.php']);

            $this->assertStringContainsString('core v2', File::get($fakeBase.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Core.php'));
            $this->assertFileDoesNotExist(
                $fakeBase.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Orphan.php',
                'prune 모드는 orphan 을 삭제해야 한다',
            );
        } finally {
            $restore();
        }
    }

    /**
     * `applyUpdate(prune:true)` 가 `public` 타깃을 정리할 때 정적 게시본
     * (`public/build/ext`)을 orphan 으로 삭제하지 않고 보존합니다 (#122).
     *
     * 게시본은 릴리즈 소스에 없는 로컬 파생물이라 excludes(`build/ext`) 미등록 시
     * prune 이 삭제해, 업데이트 완료까지 정적 fast path 가 불필요하게 끊긴다.
     *
     * @scenario apply_mode=prune
     *
     * @effects applyUpdate_prune_preserves_static_publish_dir
     *
     * @return void 반환값 없음
     */
    public function test_apply_update_prune_preserves_static_publish_dir(): void
    {
        [$source, $fakeBase, $restore] = $this->prepareApplyUpdateEnv(['public']);

        // prepareApplyUpdateEnv 가 excludes 를 비우므로 실제 기본값(config/app.php)을 재주입 —
        // 이 테스트의 검증 대상이 바로 excludes 의 `build/ext` 항목이다
        config(['app.update.excludes' => ['node_modules', '.git', 'bootstrap/cache', 'build/ext']]);

        try {
            // source(릴리즈): public/build/core 는 추적 배포 산출물이라 항상 존재,
            // build/ext 는 로컬 파생물이라 릴리즈 소스에 없다
            File::ensureDirectoryExists($source.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'core');
            File::put($source.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'index.php', "<?php // core\n");
            File::put(
                $source.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'core'.DIRECTORY_SEPARATOR.'template-engine.min.js',
                '// bundle'
            );

            // 활성(mine): 정적 게시본 + manifest
            $publishDir = $fakeBase.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'build'
                .DIRECTORY_SEPARATOR.'ext'.DIRECTORY_SEPARATOR.'1234';
            File::ensureDirectoryExists($publishDir);
            File::put($publishDir.DIRECTORY_SEPARATOR.'manifest.json', '{}');

            $this->service->applyUpdate($source, null, prune: true, applyList: null);

            $this->assertFileExists(
                $publishDir.DIRECTORY_SEPARATOR.'manifest.json',
                'prune 업데이트가 정적 게시본(public/build/ext)을 orphan 삭제하면 안 된다',
            );
        } finally {
            $restore();
        }
    }

    /**
     * end-to-end 회귀 (#43): `applyUpdate(prune:true)` 가 `public` 타깃을 정리할 때
     * `public/storage` symlink 를 orphan 으로 삭제하지 않고 보존합니다.
     *
     * 릴리즈 소스(`public/`)에는 런타임 symlink 가 없으므로 prune 이 orphan 으로 판정하여
     * `@unlink` 하던 결함으로 업로드 파일이 404 되던 회귀를 차단한다. 층1 화이트리스트
     * 전파(`public` → `preserveLinkPaths: ['storage']`) 합류 지점 검증.
     *
     * @scenario apply_mode=prune
     *
     * @effects applyUpdate_prune_preserves_public_storage_symlink
     *
     * @return void 반환값 없음
     */
    public function test_apply_update_prune_preserves_public_storage_symlink(): void
    {
        [$source, $fakeBase, $restore] = $this->prepareApplyUpdateEnv(['public']);

        try {
            // source(릴리즈): public/ 에 일반 파일만, storage symlink 는 없음
            File::ensureDirectoryExists($source.DIRECTORY_SEPARATOR.'public');
            File::put($source.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'index.php', "<?php // core\n");

            // 활성(mine): public/storage symlink + 업로드 파일 target
            File::ensureDirectoryExists($fakeBase.DIRECTORY_SEPARATOR.'public');
            $linkTarget = $fakeBase.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'public';
            File::ensureDirectoryExists($linkTarget);
            File::put($linkTarget.DIRECTORY_SEPARATOR.'uploaded.txt', 'user upload');

            $linkPath = $fakeBase.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'storage';
            $created = @symlink($linkTarget, $linkPath);
            if (! $created && PHP_OS_FAMILY === 'Windows') {
                exec('cmd /c mklink /J '.escapeshellarg($linkPath).' '.escapeshellarg($linkTarget).' 2>&1', $out, $code);
                $created = $code === 0 && is_dir($linkPath);
            }
            if (! $created) {
                $this->markTestSkipped('symlink/junction 생성 권한 부족 (Windows SeCreateSymbolicLink)');
            }

            $this->service->applyUpdate($source, null, prune: true, applyList: null);

            // symlink/junction 이 orphan 삭제되지 않고 보존되어야 함. Windows junction 은
            // is_dir()/is_link() 이 stat 캐시에 따라 불안정하므로, 링크 존재는 readlink 로,
            // 접근성은 업로드 파일 존재로 검증한다 (#43 — 404 회귀의 실측 지표).
            clearstatcache(true, $linkPath);
            $this->assertNotFalse(
                @readlink($linkPath),
                'public/storage 링크는 prune 후에도 보존되어야 한다 (#43)',
            );
            $this->assertFileExists(
                $linkPath.DIRECTORY_SEPARATOR.'uploaded.txt',
                'symlink 통과 시 업로드 파일에 접근 가능해야 한다 (404 회귀 차단)',
            );

            // 코어 변경분(index.php)은 정상 반영
            $this->assertStringContainsString('core', File::get($fakeBase.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'index.php'));
        } finally {
            // 링크는 target 을 따라가지 않도록 먼저 제거 (tearDown 재귀 삭제 사고 차단).
            // symlink → unlink, Windows junction → rmdir.
            $linkPath = $fakeBase.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'storage';
            if (is_link($linkPath)) {
                @unlink($linkPath);
            } elseif (PHP_OS_FAMILY === 'Windows' && @readlink($linkPath) !== false && ! @rmdir($linkPath)) {
                @unlink($linkPath);
            }
            $restore();
        }
    }

    /**
     * applyList=null (백업 부재 fallback): prune 이 아니어도 전체 덮어쓰기로 회귀.
     *
     * @scenario apply_mode=fallback_no_backup
     *
     * @effects test_apply_update_null_apply_list_falls_back_to_full_overwrite
     *
     * @return void 반환값 없음
     */
    public function test_apply_update_null_apply_list_falls_back_to_full_overwrite(): void
    {
        [$source, $fakeBase, $restore] = $this->prepareApplyUpdateEnv(['app']);

        try {
            File::ensureDirectoryExists($source.DIRECTORY_SEPARATOR.'app');
            File::put($source.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'A.php', '<?php // a');
            File::put($source.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'B.php', '<?php // b');

            File::ensureDirectoryExists($fakeBase.DIRECTORY_SEPARATOR.'app');

            // applyList 미지정(null) → incremental=false → 전체 복사
            $this->service->applyUpdate($source, null, prune: false, applyList: null);

            $this->assertFileExists($fakeBase.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'A.php');
            $this->assertFileExists($fakeBase.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'B.php');
        } finally {
            $restore();
        }
    }

    // ========================================================================
    // applyUpdate() — deploy/ 서버 설정 조각의 운영자 수정본 보존
    // ========================================================================

    /**
     * 운영자가 고친 `deploy/**` 조각은 덮어쓰지 않고 새 판본을 `.dist` 로 옆에 둔다.
     *
     * 조각 머리말은 운영자에게 그 파일을 **직접 고치라**고 지시한다(`fastcgi_pass`·하위 폴더
     * 접두사). 그런데 `deploy` 대상은 디렉토리 통째 복사로 갱신되어 그 수정이 다음 코어
     * 업데이트에서 조용히 사라졌다 — nginx 는 그 파일을 `include` 로 제자리에서 읽으므로
     * 사이트의 PHP 소켓 설정이 릴리즈 기본값으로 되돌아간다. 코어가 마지막으로 쓴 내용의
     * 기록이 없으면(이 기능 이전에 설치된 사이트) 새 판본과 다른 것을 모두 수정본으로 본다 —
     * 잃는 쪽보다 한 번 더 확인시키는 쪽이 낫다.
     *
     * @scenario deploy_fragment_state=operator_modified
     *
     * @effects operator_modified_deploy_fragment_is_kept_and_new_version_lands_beside_it
     *
     * @return void 반환값 없음
     */
    public function test_operator_modified_deploy_fragment_is_preserved_with_dist_sidecar_and_warning(): void
    {
        [$source, $fakeBase, $restore] = $this->prepareApplyUpdateEnv(['deploy']);
        // 원장은 `storage_path()` 아래에 쓰인다 — 격리하지 않으면 실 저장소의 원장이 이 테스트의
        // 픽스처 해시로 덮인다 (형제 `test_unmodified_deploy_fragment_…` 와 같은 격리).
        $originalStoragePath = app()->storagePath();
        app()->useStoragePath($fakeBase.DIRECTORY_SEPARATOR.'storage');

        try {
            $srcFragment = $source.DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR.'nginx'.DIRECTORY_SEPARATOR.'g7-layout2.conf';
            $destFragment = $fakeBase.DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR.'nginx'.DIRECTORY_SEPARATOR.'g7-layout2.conf';
            File::ensureDirectoryExists(dirname($srcFragment));
            File::ensureDirectoryExists(dirname($destFragment));
            File::put($srcFragment, "# release v2\nfastcgi_pass unix:/run/php/php8.2-fpm.sock;\n");
            File::put($destFragment, "# release v1\nfastcgi_pass unix:/run/php/php8.3-fpm.sock; # operator\n");

            $result = $this->service->applyUpdate($source, null, prune: true, applyList: null);

            $this->assertStringContainsString(
                '# operator',
                File::get($destFragment),
                '운영자 수정본이 덮여 사라졌다 — nginx 가 include 로 읽는 소켓 설정이 릴리즈 기본값으로 되돌아간다'
            );
            $this->assertFileExists($destFragment.'.dist', '새 판본이 옆에 놓이지 않으면 운영자는 무엇을 합칠지 알 수 없다');
            $this->assertSame(
                "# release v2\nfastcgi_pass unix:/run/php/php8.2-fpm.sock;\n",
                File::get($destFragment.'.dist')
            );

            $warnings = $this->service->getLastRootFileWarnings();
            $this->assertNotEmpty($warnings, '보존 사실이 통보 통로에 실리지 않으면 운영자는 새 판본이 있는지 알 수 없다');
            $this->assertStringContainsString($destFragment.'.dist', implode("\n", $warnings));
            $this->assertSame($warnings, $result['root_file_warnings'], '반환값에도 같은 경고가 실려야 커맨드가 아닌 소비처도 받는다');
        } finally {
            app()->useStoragePath($originalStoragePath);
            $restore();
        }
    }

    /**
     * 코어가 마지막으로 쓴 그대로인 조각은 정상 갱신되고, 남아 있던 `.dist` 는 걷힌다.
     *
     * 이 대조군이 없으면 「언제나 보존하는」 구현으로도 위 검사가 초록이 되고, 수정하지
     * 않은 사이트의 조각이 영원히 구판본에 머문다(보호 규칙이 갱신되지 않는다).
     *
     * @scenario deploy_fragment_state=unmodified
     *
     * @effects unmodified_deploy_fragment_is_updated_and_stale_dist_removed
     *
     * @return void 반환값 없음
     */
    public function test_unmodified_deploy_fragment_is_updated_and_stale_dist_is_removed(): void
    {
        [$source, $fakeBase, $restore] = $this->prepareApplyUpdateEnv(['deploy']);
        $originalStoragePath = app()->storagePath();
        $storage = $fakeBase.DIRECTORY_SEPARATOR.'storage';
        File::ensureDirectoryExists($storage.DIRECTORY_SEPARATOR.'app');
        app()->useStoragePath($storage);

        try {
            $relative = 'nginx/g7-layout2.conf';
            $srcFragment = $source.DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR.'nginx'.DIRECTORY_SEPARATOR.'g7-layout2.conf';
            $destFragment = $fakeBase.DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR.'nginx'.DIRECTORY_SEPARATOR.'g7-layout2.conf';
            File::ensureDirectoryExists(dirname($srcFragment));
            File::ensureDirectoryExists(dirname($destFragment));

            // 1차 업데이트 — 대상 부재 → 신규 복사 + 코어가 쓴 내용의 해시가 원장에 남는다.
            File::put($srcFragment, "# release v1\n");
            $this->service->applyUpdate($source, null, prune: true, applyList: null);
            $this->assertSame("# release v1\n", File::get($destFragment));
            $this->assertSame([], $this->service->getLastRootFileWarnings(), '신규 복사는 통보 대상이 아니다');

            $ledger = $storage.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'core-update'.DIRECTORY_SEPARATOR.CoreUpdateService::DEPLOY_FRAGMENT_LEDGER;
            $this->assertFileExists($ledger, '코어가 쓴 내용의 기록이 없으면 다음 업데이트가 수정본과 원본을 가릴 수 없다');
            $this->assertSame(hash('sha256', "# release v1\n"), json_decode((string) File::get($ledger), true)[$relative] ?? null);

            // 앞선 실행이 남긴 `.dist` 가 있다고 치자 — 운영자가 합친 뒤 지우지 않은 잔존물.
            File::put($destFragment.'.dist', "# stale sidecar\n");

            // 2차 업데이트 — 디스크가 코어가 쓴 그대로이므로 정상 갱신 + 잔존 `.dist` 제거.
            File::put($srcFragment, "# release v2\n");
            $this->service->applyUpdate($source, null, prune: true, applyList: null);

            $this->assertSame("# release v2\n", File::get($destFragment), '수정하지 않은 조각은 새 판본으로 갱신되어야 한다');
            $this->assertFileDoesNotExist($destFragment.'.dist', '갱신된 뒤에도 옛 .dist 가 남으면 운영자가 그것을 새 판본으로 오인한다');
            $this->assertSame([], $this->service->getLastRootFileWarnings(), '정상 갱신마다 경고가 뜨면 진짜 경고가 묻힌다');

            // 3차 — 운영자가 그 사이 고쳤다. 원장의 해시와 다르므로 보존된다.
            File::put($destFragment, "# release v2\n# operator\n");
            File::put($srcFragment, "# release v3\n");
            $this->service->applyUpdate($source, null, prune: true, applyList: null);

            $this->assertSame("# release v2\n# operator\n", File::get($destFragment));
            $this->assertSame("# release v3\n", File::get($destFragment.'.dist'));
            $this->assertNotEmpty($this->service->getLastRootFileWarnings());
        } finally {
            app()->useStoragePath($originalStoragePath);
            $restore();
        }
    }

    /**
     * 되돌리기는 `.dist` 도 걷는다 — 백업에는 없는 파일이라 복원만으로는 남는다.
     *
     * 반환값에도 경고가 실린다(형제 `applyUpdate` 와 같은 계약) — 무CLI 웹 워커처럼
     * 커맨드가 아닌 소비처가 `getLastRootFileWarnings()` 를 모르면 통보가 통째로 사라진다.
     *
     * @scenario rollback_leftover=deploy_dist_sidecar
     *
     * @effects rollback_removes_deploy_dist_sidecars_and_returns_warnings
     *
     * @return void 반환값 없음
     */
    public function test_restore_from_backup_removes_deploy_dist_sidecars_and_returns_warnings(): void
    {
        $sandbox = storage_path('test_rollback_dist_'.uniqid());
        $backup = storage_path('test_rollback_dist_backup_'.uniqid());
        $this->tempDirs[] = $sandbox;
        $this->tempDirs[] = $backup;
        File::ensureDirectoryExists($sandbox.DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR.'nginx');
        File::ensureDirectoryExists($backup);

        $dist = $sandbox.DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR.'nginx'.DIRECTORY_SEPARATOR.'g7-layout2.conf.dist';
        File::put($sandbox.DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR.'nginx'.DIRECTORY_SEPARATOR.'g7-layout2.conf', "# operator\n");
        File::put($dist, "# new\n");

        // 되돌리기는 `.dist.new`(A5 — 옛 `.dist` 와 충돌한 새 판본)도 함께 걷는다.
        $distNew = $dist.'.new';
        File::put($distNew, "# newer\n");

        $originalBasePath = base_path();
        $originalLangPath = app()->langPath();
        // 원장 삭제는 `storage_path()` 를 본다 — 격리하지 않으면 실 저장소의 원장이 지워진다.
        $originalStoragePath = app()->storagePath();
        app()->setBasePath($sandbox);
        app()->useLangPath($originalLangPath);
        app()->useStoragePath($sandbox.DIRECTORY_SEPARATOR.'storage');
        config([
            'app.update.targets' => [],
            'app.update.backup_only' => [],
            'app.update.excludes' => [],
        ]);

        try {
            $result = $this->service->restoreFromBackup($backup);

            $this->assertFileDoesNotExist($dist, '되돌린 뒤 .dist 가 남으면 되돌린 판본 옆에 미래 판본이 놓인다');
            $this->assertFileDoesNotExist($distNew, '되돌린 뒤 .dist.new 가 남으면 되돌린 판본 옆에 미래 판본이 놓인다');
            $this->assertSame([], $result['failed_targets']);
            $this->assertSame($this->service->getLastRootFileWarnings(), $result['root_file_warnings']);
        } finally {
            app()->setBasePath($originalBasePath);
            app()->useStoragePath($originalStoragePath);
        }
    }

    /**
     * 판정할 수 없어 보존한 조각도 **원장에 새 판본의 해시를 기록**한다 (G3 회차 16 W2 A2/C-2).
     *
     * 종전에는 「원장 부재 → 보존 + `.dist`」 분기가 원장을 씨앗하지 않았다. 그러면 운영자가 안내대로
     * `.dist` 를 합쳐 파일이 새 판본과 바이트 동일해져도, 다음 업데이트는 원장이 여전히 없어 다시
     * 「판정 불가 → 보존 + `.dist`」 로 떨어진다 — 매 업데이트마다 같은 안내가 반복되고 그 조각은
     * **영원히** 자동 갱신되지 않는다. 오류도 없고 안내는 사실처럼 읽힌다.
     *
     * 원장은 「코어가 마지막으로 **제시한** 판본」을 기록한다: 합치면 디스크 == 원장 → 정상 갱신으로
     * 탈출, 합치지 않으면 디스크 ≠ 원장 → 보존 유지(올바른 동작).
     *
     * @scenario deploy_fragment_state=operator_modified, deploy_fragment_ledger=absent, deploy_fragment_merge=merged
     *
     * @effects merged_deploy_fragment_escapes_preservation_on_the_next_update
     *
     * @return void 반환값 없음
     */
    public function test_merged_dist_escapes_preservation_on_the_next_update_even_without_a_prior_ledger(): void
    {
        [$source, $fakeBase, $restore] = $this->prepareApplyUpdateEnv(['deploy']);
        $originalStoragePath = app()->storagePath();
        $storage = $fakeBase.DIRECTORY_SEPARATOR.'storage';
        app()->useStoragePath($storage);

        try {
            $relative = 'nginx/g7-layout2.conf';
            $srcFragment = $source.DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR.'nginx'.DIRECTORY_SEPARATOR.'g7-layout2.conf';
            $destFragment = $fakeBase.DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR.'nginx'.DIRECTORY_SEPARATOR.'g7-layout2.conf';
            File::ensureDirectoryExists(dirname($srcFragment));
            File::ensureDirectoryExists(dirname($destFragment));
            $ledger = $storage.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'core-update'.DIRECTORY_SEPARATOR.CoreUpdateService::DEPLOY_FRAGMENT_LEDGER;

            // 원장 없이 시작 — 이 기능 이전 설치본. 디스크에는 구판이 있다.
            File::put($destFragment, "# release v1\n");
            File::put($srcFragment, "# release v2\n");
            $this->service->applyUpdate($source, null, prune: true, applyList: null);

            $this->assertSame("# release v1\n", File::get($destFragment), '판정 불가면 보존한다');
            $this->assertSame("# release v2\n", File::get($destFragment.'.dist'));
            $this->assertFileExists($ledger, '판정 불가 분기가 원장을 씨앗하지 않으면 합친 뒤에도 영구히 보존 분기다');
            $this->assertSame(
                hash('sha256', "# release v2\n"),
                json_decode((string) File::get($ledger), true)[$relative] ?? null,
                '원장에는 코어가 제시한 새 판본의 해시가 실려야 합친 파일이 다음에 「코어가 쓴 그대로」로 읽힌다'
            );

            // 운영자가 안내대로 합쳤다 — `.dist` 를 그대로 채택하고 지웠다.
            File::put($destFragment, "# release v2\n");
            File::delete($destFragment.'.dist');

            // 다음 업데이트 — 정상 갱신으로 탈출해야 한다.
            File::put($srcFragment, "# release v3\n");
            $this->service->applyUpdate($source, null, prune: true, applyList: null);

            $this->assertSame("# release v3\n", File::get($destFragment), '합친 조각이 다시 「판정 불가」로 떨어졌다 — 안내가 매 업데이트마다 반복된다');
            $this->assertFileDoesNotExist($destFragment.'.dist');
            $this->assertSame([], $this->service->getLastRootFileWarnings());
        } finally {
            app()->useStoragePath($originalStoragePath);
            $restore();
        }
    }

    /**
     * 합치지 않은 조각은 계속 보존되고, 옛 `.dist` 와 다른 새 판본은 `.dist.new` 로 옆에 둔다 (A5).
     *
     * `.dist` 를 덮어쓰면 운영자가 그 파일에 합치던 중간 작업이 사라진다. 그렇다고 새 판본을 버리면
     * 운영자는 최신 보호 규칙을 손에 넣지 못한다 — 둘 다 남기고 사실을 통보한다.
     *
     * @scenario deploy_fragment_state=operator_modified, deploy_fragment_ledger=absent, deploy_fragment_merge=unmerged
     *
     * @effects unmerged_deploy_fragment_stays_preserved_and_conflicting_dist_is_not_overwritten
     *
     * @return void 반환값 없음
     */
    public function test_unmerged_fragment_stays_preserved_and_a_conflicting_dist_is_kept_beside_a_dist_new(): void
    {
        [$source, $fakeBase, $restore] = $this->prepareApplyUpdateEnv(['deploy']);
        $originalStoragePath = app()->storagePath();
        app()->useStoragePath($fakeBase.DIRECTORY_SEPARATOR.'storage');

        try {
            $srcFragment = $source.DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR.'nginx'.DIRECTORY_SEPARATOR.'g7-layout2.conf';
            $destFragment = $fakeBase.DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR.'nginx'.DIRECTORY_SEPARATOR.'g7-layout2.conf';
            File::ensureDirectoryExists(dirname($srcFragment));
            File::ensureDirectoryExists(dirname($destFragment));

            File::put($destFragment, "# release v1 # operator\n");
            File::put($srcFragment, "# release v2\n");
            $this->service->applyUpdate($source, null, prune: true, applyList: null);
            $this->assertSame("# release v2\n", File::get($destFragment.'.dist'));

            // 합치지 않은 채 다음 업데이트.
            File::put($srcFragment, "# release v3\n");
            $this->service->applyUpdate($source, null, prune: true, applyList: null);

            $this->assertSame("# release v1 # operator\n", File::get($destFragment), '합치지 않은 조각은 계속 보존되어야 한다');
            $this->assertSame("# release v2\n", File::get($destFragment.'.dist'), '운영자가 합치던 .dist 를 덮어썼다');
            $this->assertSame("# release v3\n", File::get($destFragment.'.dist.new'), '새 판본이 .dist.new 로 옆에 놓이지 않았다');

            $warnings = implode("\n", $this->service->getLastRootFileWarnings());
            $this->assertStringContainsString($destFragment.'.dist.new', $warnings, '충돌 사실이 통보 통로에 실리지 않았다');
            $this->assertStringNotContainsString('settings.core_update.', $warnings, '문구가 번역되지 않은 키로 나갔다');

            // 같은 상태로 한 번 더 — `.dist.new` 는 코어가 만든 파일이라 자유롭게 갱신한다.
            File::put($srcFragment, "# release v4\n");
            $this->service->applyUpdate($source, null, prune: true, applyList: null);
            $this->assertSame("# release v4\n", File::get($destFragment.'.dist.new'));
            $this->assertSame("# release v2\n", File::get($destFragment.'.dist'));
        } finally {
            app()->useStoragePath($originalStoragePath);
            $restore();
        }
    }

    /**
     * 원장 쓰기 실패는 **통보 통로**와 업그레이드 로그 채널에 실린다 (A4).
     *
     * 원장은 다음 업데이트의 판정 근거다 — 쓰지 못했는데 조용히 넘기면 그 사이트는 다음 업데이트에서
     * 전 조각이 「판정 불가」로 떨어지고, 운영자는 이유를 알 수 없다.
     *
     * @scenario deploy_fragment_state=unmodified, deploy_fragment_ledger=unwritable
     *
     * @effects deploy_fragment_ledger_write_failure_reaches_the_operator
     *
     * @return void 반환값 없음
     */
    public function test_deploy_fragment_ledger_write_failure_is_reported_not_swallowed(): void
    {
        [$source, $fakeBase, $restore] = $this->prepareApplyUpdateEnv(['deploy']);
        $originalStoragePath = app()->storagePath();
        $storage = $fakeBase.DIRECTORY_SEPARATOR.'storage';
        app()->useStoragePath($storage);

        try {
            $srcFragment = $source.DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR.'nginx'.DIRECTORY_SEPARATOR.'g7-layout2.conf';
            File::ensureDirectoryExists(dirname($srcFragment));
            File::put($srcFragment, "# release v1\n");

            // 원장 자리에 **디렉토리**를 둔다 — 파일 쓰기가 반드시 실패하는 형태다.
            $ledger = $storage.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'core-update'.DIRECTORY_SEPARATOR.CoreUpdateService::DEPLOY_FRAGMENT_LEDGER;
            File::ensureDirectoryExists($ledger);

            $result = $this->service->applyUpdate($source, null, prune: true, applyList: null);

            $warnings = implode("\n", $result['root_file_warnings']);
            $this->assertStringContainsString($ledger, $warnings, '원장 쓰기 실패가 통보 통로에 실리지 않았다');
            $this->assertStringNotContainsString('settings.core_update.', $warnings, '문구가 번역되지 않은 키로 나갔다');
        } finally {
            app()->useStoragePath($originalStoragePath);
            $restore();
        }
    }

    /**
     * `seedDeployFragmentLedger()` 는 현재 디스크의 해시로 원장을 만들고, 사이드카가 있는 조각은 건너뛴다 (A2 ②).
     *
     * 인스톨러(설치 완료 시점)와 업그레이드 스텝이 부르는 진입점이다. `.dist`/`.dist.new` 가 옆에 있는
     * 조각은 디스크가 릴리즈 판본이 아니라는 뜻이므로 씨앗하지 않는다 — 씨앗하면 운영자 수정본이
     * 「코어가 쓴 그대로」로 읽혀 다음 업데이트가 그것을 덮어쓴다. 원장이 이미 있으면 손대지 않는다.
     *
     * @scenario deploy_fragment_ledger=absent, deploy_fragment_state=unmodified
     *
     * @effects deploy_fragment_ledger_can_be_seeded_from_disk_without_touching_preserved_fragments
     *
     * @return void 반환값 없음
     */
    public function test_seed_deploy_fragment_ledger_hashes_the_disk_and_skips_fragments_with_sidecars(): void
    {
        $sandbox = storage_path('test_seed_ledger_'.uniqid());
        $this->tempDirs[] = $sandbox;
        File::ensureDirectoryExists($sandbox.DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR.'nginx');
        File::ensureDirectoryExists($sandbox.DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR.'caddy');
        File::put($sandbox.DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR.'nginx'.DIRECTORY_SEPARATOR.'g7-layout2.conf', "# nginx\n");
        File::put($sandbox.DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR.'caddy'.DIRECTORY_SEPARATOR.'g7-layout2.caddyfile', "# caddy operator\n");
        File::put($sandbox.DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR.'caddy'.DIRECTORY_SEPARATOR.'g7-layout2.caddyfile.dist', "# caddy release\n");

        $originalStoragePath = app()->storagePath();
        $storage = $sandbox.DIRECTORY_SEPARATOR.'storage';
        app()->useStoragePath($storage);

        try {
            $seeded = $this->service->seedDeployFragmentLedger($sandbox);

            $ledger = $storage.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'core-update'.DIRECTORY_SEPARATOR.CoreUpdateService::DEPLOY_FRAGMENT_LEDGER;
            $this->assertFileExists($ledger);
            $decoded = json_decode((string) File::get($ledger), true);
            $this->assertSame(['nginx/g7-layout2.conf'], $seeded);
            $this->assertSame(hash('sha256', "# nginx\n"), $decoded['nginx/g7-layout2.conf'] ?? null);
            $this->assertArrayNotHasKey('caddy/g7-layout2.caddyfile', $decoded, '사이드카가 있는 조각을 씨앗하면 운영자 수정본이 다음 업데이트에서 덮인다');
            $this->assertArrayNotHasKey('caddy/g7-layout2.caddyfile.dist', $decoded, '사이드카 자신은 조각이 아니다');

            // 원장이 이미 있으면 손대지 않는다.
            File::put($sandbox.DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR.'nginx'.DIRECTORY_SEPARATOR.'g7-layout2.conf', "# nginx changed\n");
            $this->assertSame([], $this->service->seedDeployFragmentLedger($sandbox));
            $this->assertSame(hash('sha256', "# nginx\n"), json_decode((string) File::get($ledger), true)['nginx/g7-layout2.conf']);
        } finally {
            app()->useStoragePath($originalStoragePath);
        }
    }

    /**
     * 루트 `web.config` 는 정상 targets 분기에서 **마커 블록 갱신기**를 지난다 (C-1).
     *
     * 소스 계약(`RootEntrypointContractTest`)이 배선을 잠그고, 이 검사는 실제 결과를 잰다 — 블록 밖
     * 운영자 요소가 남고 블록 안이 새 판본으로 바뀐다.
     *
     * @scenario server_kind=iis_webconfig, htaccess_operator_state=modified_outside_block, update_targets_state=current
     *
     * @effects root_marker_files_are_updated_by_the_block_updater_in_both_apply_branches
     *
     * @return void 반환값 없음
     */
    public function test_apply_update_preserves_operator_elements_outside_the_web_config_block(): void
    {
        [$source, $fakeBase, $restore] = $this->prepareApplyUpdateEnv(['web.config']);

        try {
            $begin = RootWebConfigUpdater::BEGIN_MARKER;
            $end = RootWebConfigUpdater::END_MARKER;
            $release = "<?xml version=\"1.0\"?>\n<configuration>\n{$begin}\n  <system.webServer><!-- v2 --></system.webServer>\n{$end}\n</configuration>\n";
            $installed = "<?xml version=\"1.0\"?>\n<configuration>\n{$begin}\n  <system.webServer><!-- v1 --></system.webServer>\n{$end}\n  <system.web><customErrors mode=\"Off\" /></system.web>\n</configuration>\n";
            File::put($source.DIRECTORY_SEPARATOR.'web.config', $release);
            File::put($fakeBase.DIRECTORY_SEPARATOR.'web.config', $installed);

            $result = $this->service->applyUpdate($source, null, prune: true, applyList: null);

            $written = File::get($fakeBase.DIRECTORY_SEPARATOR.'web.config');
            $this->assertStringContainsString('<!-- v2 -->', $written, '블록 안이 새 판본으로 바뀌지 않았다');
            $this->assertStringContainsString('<customErrors mode="Off" />', $written, '블록 밖 운영자 요소가 사라졌다 — web.config 가 통째 교체됐다');
            $this->assertSame([], $result['root_file_warnings']);
        } finally {
            $restore();
        }
    }

    /**
     * 자동 발견 폴백 분기(구버전 targets)에서도 `web.config` 는 갱신기를 지난다 (C-1).
     *
     * @scenario server_kind=iis_webconfig, htaccess_operator_state=modified_outside_block, update_targets_state=stale_without_root_files
     *
     * @effects root_marker_files_are_updated_by_the_block_updater_in_both_apply_branches
     *
     * @return void 반환값 없음
     */
    public function test_auto_discovery_fallback_preserves_operator_elements_outside_the_web_config_block(): void
    {
        [$source, $fakeBase, $restore] = $this->prepareApplyUpdateEnv([]);

        try {
            $begin = RootWebConfigUpdater::BEGIN_MARKER;
            $end = RootWebConfigUpdater::END_MARKER;
            File::put($source.DIRECTORY_SEPARATOR.'web.config', "<configuration>\n{$begin}\n<a>v2</a>\n{$end}\n</configuration>\n");
            File::put($fakeBase.DIRECTORY_SEPARATOR.'web.config', "<configuration>\n{$begin}\n<a>v1</a>\n{$end}\n<operator />\n</configuration>\n");

            $this->service->applyUpdate($source, null, prune: false, applyList: null);

            $written = File::get($fakeBase.DIRECTORY_SEPARATOR.'web.config');
            $this->assertStringContainsString('<a>v2</a>', $written);
            $this->assertStringContainsString('<operator />', $written, '자동 발견 폴백이 web.config 를 통째 복사했다');
        } finally {
            $restore();
        }
    }

    /**
     * 콘솔 커맨드는 `applyUpdate()`·`restoreFromBackup()` **반환값**의 경고를 출력한다 (C-6).
     *
     * 게터(`getLastRootFileWarnings()`)는 커맨드의 편의이고 반환값이 계약이다 — 커맨드마저 게터만
     * 쓰면 반환값 축은 소비처가 0 이라 조용히 죽는다(무CLI 웹 워커가 그것을 처음 쓰는 순간 드러난다).
     *
     * @scenario process_shape=standalone, warning_channel=console
     *
     * @effects rollback_removes_deploy_dist_sidecars_and_returns_warnings
     *
     * @return void 반환값 없음
     */
    public function test_update_command_consumes_the_return_values_for_root_file_warnings(): void
    {
        $source = (string) file_get_contents(base_path('app/Console/Commands/Core/CoreUpdateCommand.php'));

        $this->assertMatchesRegularExpression(
            '/\$restoreResult\s*=\s*\$service->restoreFromBackup\(/',
            $source,
            '롤백 호출부가 restoreFromBackup() 의 반환값을 버린다 — 반환값 계약의 소비처가 0 이 된다'
        );
        $this->assertStringContainsString(
            "\$restoreResult['root_file_warnings']",
            $source,
            '롤백 호출부가 반환값의 root_file_warnings 를 출력에 쓰지 않는다'
        );
        $this->assertMatchesRegularExpression(
            '/\$applyResult\s*=\s*\$service->applyUpdate\(/',
            $source,
            '적용 호출부가 applyUpdate() 의 반환값을 버린다'
        );
        $this->assertStringContainsString(
            "\$applyResult['root_file_warnings']",
            $source,
            '적용 호출부가 반환값의 root_file_warnings 를 출력에 쓰지 않는다'
        );
    }

    /**
     * 교체 직전 루트 진입 파일 사본의 디렉토리 이름은 백업 이름 SSoT 가 만든다.
     *
     * 생산자가 `date()` 를 직접 부르면 정리자의 판정 정규식과 갈릴 수 있고, 그 상태는
     * 오류가 아니라 **정리 대상에서 조용히 빠지는 것**으로만 나타난다 — 코어 백업이 실제로
     * 그랬다(`BackupDirectoryName` 독블록). 이름은 SSoT 가 만들고, 정리자의 패턴이 그 이름을
     * 받아들이는지를 여기서 함께 잰다.
     *
     * @effects preserved_root_index_directory_name_comes_from_the_backup_name_ssot
     *
     * @return void 반환값 없음
     */
    public function test_preserved_root_index_directory_name_comes_from_the_backup_name_ssot(): void
    {
        $work = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g7-preserve-stamp-'.bin2hex(random_bytes(4));
        $storage = $work.DIRECTORY_SEPARATOR.'storage';
        File::ensureDirectoryExists($storage);
        $this->tempDirs[] = $work;

        $originalStoragePath = app()->storagePath();
        app()->useStoragePath($storage);

        $service = new class extends CoreUpdateService
        {
            /**
             * 보호된 preserveReplacedRootIndex 를 시험에서 부를 수 있게 드러낸다.
             *
             * @param  string  $contents  보존할 루트 진입 파일 본문
             * @return ?string 보존 사본 경로(실패하면 null)
             */
            public function exposePreserve(string $contents): ?string
            {
                return $this->preserveReplacedRootIndex($contents);
            }
        };

        try {
            $path = $service->exposePreserve('<?php // landing');
            $this->assertNotNull($path);

            $directory = basename(dirname($path));
            $this->assertMatchesRegularExpression(
                BackupDirectoryName::pattern(''),
                $directory,
                '사본 디렉토리 이름이 백업 이름 SSoT 형태가 아니다'
            );
            $this->assertMatchesRegularExpression(
                '/_[0-9a-f]{8}$/',
                $directory,
                '같은 초 충돌 접미사가 없다 — 이름을 SSoT(`stamp()`)가 아니라 `date()` 로 만들고 있다'
            );

            $pruner = new \ReflectionClassConstant(StorageLeftoverPruneService::class, 'CORE_UPDATE_PRESERVED_NAME_PATTERN');
            $this->assertSame(
                1,
                preg_match((string) $pruner->getValue(), $directory),
                '정리자의 이름 패턴이 생산자가 만든 이름을 받아들이지 않는다 — 이 사본은 영구 잔존한다'
            );
        } finally {
            app()->useStoragePath($originalStoragePath);
        }
    }

    /**
     * applyUpdate 환경(격리 base_path + config targets)을 준비합니다.
     *
     * @param  array<int, string>  $targets  config('app.update.targets') 로 설정할 값
     * @return array{0:string, 1:string, 2:\Closure} [source 경로, fakeBase 경로, 복원 클로저]
     */
    // ========================================================================
    // applyUpdate() — 루트 진입·보호 파일 갱신 실패의 도달
    // ========================================================================

    /**
     * 루트 `.htaccess` 를 쓰지 못하면 그 사실이 **호출부까지 올라온다**.
     *
     * 앞선 수정이 갱신기에 실패 판정을 넣었지만 두 호출부가 모두 반환값을 버렸고, 게다가
     * 「블록이 같아 쓸 필요가 없었다」와 「쓰기가 실패했다」가 같은 `false` 여서 소비하려
     * 해도 실패를 가려낼 수 없었다. 콘솔에는 「코어 파일 적용 완료」만 뜨고 보호 규칙은
     * 구버전으로 남는다 — 다른 코어 파일은 전부 신 버전인 채로.
     *
     * @effects root_htaccess_write_failure_is_reported_not_claimed_as_updated
     *
     * @return void 반환값 없음
     */
    public function test_root_htaccess_write_failure_surfaces_to_the_caller(): void
    {
        [$source, $fakeBase, $restore] = $this->prepareApplyUpdateEnv(['.htaccess']);

        try {
            $block = "# BEGIN G7\nRewriteRule ^ public/$1 [L] # %s\n# END G7\n";
            File::put($source.DIRECTORY_SEPARATOR.'.htaccess', sprintf($block, 'new'));

            $dest = $fakeBase.DIRECTORY_SEPARATOR.'.htaccess';
            File::put($dest, sprintf($block, 'old')."# operator rule\n");

            chmod($dest, 0444);
            if (@file_put_contents($dest, 'probe') !== false) {
                chmod($dest, 0644);
                $this->markTestSkipped('읽기 전용 파일에 쓰기가 성공하는 환경 — 실패 경로를 재현할 수 없다');
            }

            try {
                $this->service->applyUpdate($source, null, prune: false, applyList: null);

                $warnings = $this->service->getLastRootFileWarnings();
                $this->assertNotEmpty(
                    $warnings,
                    '실패가 호출부에 도달하지 않으면 운영자는 「완료」만 보고 구버전 보호 규칙을 그대로 쓴다'
                );
                $this->assertStringContainsString('.htaccess', implode("\n", $warnings));

                $this->assertStringContainsString(
                    '# old',
                    (string) File::get($dest),
                    '실패했으면 내용도 그대로여야 한다'
                );
            } finally {
                chmod($dest, 0644);
            }
        } finally {
            $restore();
        }
    }

    /**
     * 갱신에 성공하면 경고가 남지 않는다 — 정상 경로에 거짓 경고를 내지 않는다.
     *
     * 「변경 없음」·「운영자가 블록을 지웠다」도 실패가 아니다. 이 대조군이 없으면 위 테스트는
     * 「항상 경고를 쌓는」 구현으로도 초록이 된다.
     *
     * @effects root_htaccess_write_failure_is_reported_not_claimed_as_updated
     *
     * @return void 반환값 없음
     */
    public function test_successful_root_htaccess_update_records_no_warning(): void
    {
        [$source, $fakeBase, $restore] = $this->prepareApplyUpdateEnv(['.htaccess']);

        try {
            $block = "# BEGIN G7\nRewriteRule ^ public/$1 [L] # %s\n# END G7\n";
            File::put($source.DIRECTORY_SEPARATOR.'.htaccess', sprintf($block, 'new'));

            $dest = $fakeBase.DIRECTORY_SEPARATOR.'.htaccess';
            File::put($dest, sprintf($block, 'old')."# operator rule\n");

            $this->service->applyUpdate($source, null, prune: false, applyList: null);

            $this->assertSame([], $this->service->getLastRootFileWarnings());
            $written = (string) File::get($dest);
            $this->assertStringContainsString('# new', $written);
            $this->assertStringContainsString('# operator rule', $written, '블록 밖 운영자 규칙은 보존된다');
        } finally {
            $restore();
        }
    }

    /**
     * 콘솔이 그 경고를 실제로 **출력하는 배선**을 갖는다.
     *
     * 서비스가 경고를 쌓아도 아무도 읽지 않으면 종전과 같다. 배선은 형제 축
     * (`surfacePermissionWarnings`)과 같은 형태이며, 그 자리가 지워지면 이 단언이 red 다.
     *
     * @scenario process_shape=standalone, warning_channel=console
     *
     * @effects root_file_copy_that_did_not_land_is_reported_not_claimed_as_updated
     *
     * @return void 반환값 없음
     */
    public function test_update_command_surfaces_root_file_warnings(): void
    {
        $command = (string) File::get(base_path('app/Console/Commands/Core/CoreUpdateCommand.php'));

        $this->assertStringContainsString(
            'surfaceRootFileWarnings(',
            $command,
            '경고를 쌓아도 출력하는 자리가 없으면 운영자에게 도달하지 않는다'
        );
        $this->assertStringContainsString(
            'getLastRootFileWarnings()',
            $command,
            '출력 함수가 서비스의 경고를 실제로 읽어야 한다'
        );
    }

    /**
     * 롤백 뒤 루트 보호 파일 소실도 **콘솔에 도달**해야 합니다.
     *
     * 종전에는 `Log::warning` 한 줄이 전부였다 — 업데이트 경로에는 콘솔 배선이 있는데
     * 롤백 경로에만 없었다(형제 불균형). 그 결과 「백업에서 복원되었습니다」만 보고 창을
     * 닫은 운영자는, 문서 루트가 프로젝트 루트인 사이트가 그 순간부터 무방비라는 사실을
     * 로그 파일을 열어야만 알 수 있었다.
     *
     * 판정 자체도 manifest 가 있을 때만 돌던 것을 떼어냈다 — manifest 가 없어 prune 을
     * 건너뛴 경우에도 파일이 없을 수 있다.
     *
     * 대상은 루트 진입·보호 파일 **3종 전부**다. 종전에는 `index.php` 와
     * `.htaccess` 둘만 봤는데, IIS 로 압축을 푼 사이트에서 실제로 보호를 수행하는 것은
     * `web.config` 이고 그 배치에서 `.htaccess` 는 아무 의미가 없다. 즉 그 서버에서는
     * 유일한 보호 수단이 사라져도 경고가 발화하지 않았고, 증상은 「경고가 없다」뿐이라
     * 운영자가 알아챌 방법이 없었다.
     *
     * @scenario process_shape=standalone, warning_channel=console
     *
     * @effects rollback_reports_missing_root_protection_files_to_the_console
     *
     * @return void 반환값 없음
     */
    public function test_rollback_reports_missing_root_protection_files(): void
    {
        $sandbox = storage_path('test_rootprot_'.uniqid());
        $this->tempDirs[] = $sandbox;
        File::ensureDirectoryExists($sandbox);

        // 목록은 SSoT 를 그대로 쓴다 — 여기 다시 적으면 4번째 파일이 늘 때 이 축이
        // 조용히 그 파일을 재지 않게 된다(지금 고치는 결함이 정확히 그 형태였다).
        $this->assertContains(
            'web.config',
            CoreBackupHelper::ROOT_PROTECTION_FILES,
            'IIS 배치의 유일한 보호 파일이 판정 대상에 없다'
        );

        $this->assertSame(
            CoreBackupHelper::ROOT_PROTECTION_FILES,
            CoreBackupHelper::missingRootProtectionFiles($sandbox),
            '세 파일이 없으면 그대로 보고되어야 한다'
        );

        File::put($sandbox.'/index.php', '<?php');
        $this->assertSame(
            ['.htaccess', 'web.config'],
            CoreBackupHelper::missingRootProtectionFiles($sandbox),
            '있는 것은 빼고 없는 것만 보고한다'
        );

        // Apache 축만 복구된 상태 — IIS 축은 여전히 무방비다. 형제 파일 하나가 있다고
        // 침묵하면 그 배치에서 노출이 보고되지 않는다.
        File::put($sandbox.'/.htaccess', '# BEGIN G7');
        $this->assertSame(
            ['web.config'],
            CoreBackupHelper::missingRootProtectionFiles($sandbox),
            '`.htaccess` 가 복구돼도 IIS 배치는 여전히 무방비다 — 그 사실이 보고되어야 한다'
        );

        File::put($sandbox.'/web.config', '<?xml version="1.0"?>');
        $this->assertSame(
            [],
            CoreBackupHelper::missingRootProtectionFiles($sandbox),
            '셋 다 있으면 알릴 것이 없다'
        );

        $command = (string) File::get(base_path('app/Console/Commands/Core/CoreUpdateCommand.php'));
        $rollbackBlock = (string) strstr($command, '$service->restoreFromBackup(');
        $this->assertNotSame('', $rollbackBlock, '롤백 호출부를 찾지 못했다');
        // 롤백 경로는 반환값(`root_file_warnings`)을 같은 출력 통로(`surfaceWarnings`)에 넘긴다 —
        // 게터 경유(`surfaceRootFileWarnings`)는 예외 경로 전용이다.
        $this->assertStringContainsString(
            "surfaceWarnings(\$restoreResult['root_file_warnings']",
            // 복원 뒤 알림·줄바꿈 처리가 그 사이에 들어가 창을 넓혔다 — 같은 롤백 블록 안인지만 본다
            substr($rollbackBlock, 0, 4000),
            '롤백 경로도 업데이트 경로와 같은 통로로 경고를 출력해야 한다'
        );
    }

    /**
     * 롤백이 문서 루트 **안**에 되살린 공개 파일 사본도 콘솔에 도달해야 합니다.
     *
     * 백업은 업데이트 이전 스냅샷이라 그 시점의 `public/storage.broken.*`(공개 업로드 전량
     * 사본)을 그대로 되돌려 놓는다. 7.1.0 스텝이 이미 문서 루트 밖으로 옮긴 뒤라면 같은
     * 파일이 두 벌이 되고 그중 하나는 웹에서 열린다.
     *
     * 형제 축(루트 진입·보호 파일 소실)은 판정을 값으로 돌려주는 `missingRootProtectionFiles()`
     * 를 거쳐 콘솔 통로(`lastRootFileWarnings`)에 실리는데, 이 축만 `Log::warning` 한 줄로
     * 끝나 있었다 — §16.36.5 의 「로그 전용 → 콘솔 통로」가 한쪽에만 적용된 상태. 롤백한
     * 운영자는 「백업에서 복원되었습니다」만 보고, 공개 업로드 사본이 웹에서 열린다는 사실을
     * 로그 파일을 열어야만 알 수 있었다.
     *
     * 콘솔 출력 배선 자체(`surfaceRootFileWarnings()` 가 롤백 catch 에 있음)는 바로 위
     * 형제 테스트가 이미 잠근다 — 여기서는 그 통로에 **실린다**는 것을 잰다.
     *
     * @scenario process_shape=standalone, warning_channel=console
     *
     * @effects rollback_reports_public_file_copies_restored_inside_the_document_root
     *
     * @return void 반환값 없음
     */
    public function test_rollback_reports_public_file_copies_restored_inside_the_document_root(): void
    {
        $sandbox = storage_path('test_docroot_residue_'.uniqid());
        $this->tempDirs[] = $sandbox;
        File::ensureDirectoryExists($sandbox.DIRECTORY_SEPARATOR.'public');

        $this->assertSame(
            [],
            CoreBackupHelper::restoredDocrootResidue($sandbox),
            '되살아난 사본이 없으면 알릴 것이 없다 — 정상 롤백마다 경보가 뜨면 진짜 경고가 묻힌다'
        );

        $residueDir = $sandbox.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'storage.broken.20260101_000000';
        File::ensureDirectoryExists($residueDir);
        File::put($residueDir.DIRECTORY_SEPARATOR.'avatar.png', 'binary');

        $this->assertSame(
            [$residueDir],
            CoreBackupHelper::restoredDocrootResidue($sandbox),
            '되살아난 사본은 판정이 값으로 돌려줘야 호출부가 콘솔에 실을 수 있다 (형제 규약)'
        );

        $backup = storage_path('test_docroot_residue_backup_'.uniqid());
        $this->tempDirs[] = $backup;
        File::ensureDirectoryExists($backup);

        $originalBasePath = base_path();
        $originalLangPath = app()->langPath();
        app()->setBasePath($sandbox);
        // `setBasePath()` 는 언어 경로도 함께 옮긴다 — 격리 트리에는 `lang/` 이 없어
        // 그대로 두면 이 경로의 모든 `__()` 가 **키 문자열 그대로** 돌아온다. 그 상태는
        // 프로덕션에 없다(요청 중 base path 를 옮기지 않는다). 실제 문구가 운영자에게
        // 도달하는지를 재는 것이 이 테스트의 목적이므로 언어 경로는 원래 자리로 고정한다.
        app()->useLangPath($originalLangPath);
        config([
            'app.update.targets' => [],
            'app.update.backup_only' => [],
            'app.update.excludes' => [],
        ]);

        try {
            $this->service->restoreFromBackup($backup);

            $warnings = $this->service->getLastRootFileWarnings();
            $this->assertNotEmpty($warnings, '롤백 결과가 콘솔 통로에 하나도 실리지 않았다');
            $this->assertStringContainsString(
                $residueDir,
                implode("\n", $warnings),
                '되살아난 공개 파일 사본의 경로가 통보에 실리지 않으면 운영자는 노출을 알 수 없다'
            );
        } finally {
            app()->setBasePath($originalBasePath);
        }
    }

    /**
     * 원본과 가짜 기준 경로를 임시로 만들고 기준 경로를 옮기되 언어 경로는 원래 자리에 고정한 뒤, 업데이트 대상 설정을 주입한다.
     *
     * @param  array  $targets  주입할 app.update.targets 목록
     * @return array{0: string, 1: string, 2: Closure} 원본 경로, 가짜 기준 경로, 경로를 되돌리는 클로저
     */
    private function prepareApplyUpdateEnv(array $targets): array
    {
        $source = storage_path('test_apply3_src_'.uniqid());
        $fakeBase = storage_path('test_apply3_base_'.uniqid());
        $this->tempDirs[] = $source;
        $this->tempDirs[] = $fakeBase;
        File::ensureDirectoryExists($source);
        File::ensureDirectoryExists($fakeBase);

        $originalBasePath = base_path();
        $originalLangPath = app()->langPath();
        app()->setBasePath($fakeBase);

        // 기준 경로를 격리 트리로 옮기면 **언어 경로도 함께 옮겨져** 이 흐름의 모든
        // `__()` 가 번역문 대신 키 문자열로 떨어진다. 그러면 「운영자에게 실제로 도달하는
        // 문구인가」를 재는 단언이 조용히 무의미해진다(키에는 파일명이 들어 있지 않다).
        // 언어 경로만 원래 자리로 고정한다 — 프로덕션에는 없는 상태이므로 테스트 쪽 조치다.
        app()->useLangPath($originalLangPath);
        app()->forgetInstance('translator');
        app()->forgetInstance('translation.loader');

        config([
            'app.update.targets' => $targets,
            'app.update.excludes' => [],
            'app.update.protected_paths' => ['.env', 'storage', 'vendor', 'node_modules', '.git'],
        ]);

        $restore = function () use ($originalBasePath, $originalLangPath): void {
            app()->setBasePath($originalBasePath);
            app()->useLangPath($originalLangPath);
            app()->forgetInstance('translator');
            app()->forgetInstance('translation.loader');
        };

        return [$source, $fakeBase, $restore];
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
     *
     * @effects applyUpdate_auto_discovers_new_top_level_directories_from_source, root_entry_files_reach_installs_running_older_targets
     *
     * @return void 반환값 없음
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
     * 자동 발견 폴백은 **점으로 시작하는 최상위 파일**도 본다 (D25 — 루트 `.htaccess`).
     *
     * 이 폴백은 구버전 `targets` 로 도는 첫 업데이트에서 신규 최상위 항목이 도달하는
     * 유일한 통로다. 열거가 숨김 파일을 건너뛰면 루트 `index.php` 는 전달되고 루트
     * `.htaccess` 만 빠져 — 보호 규칙 없는 진입점이 남는 최악의 조합이 된다. 그리고
     * 그 상태는 예외도 로그도 남기지 않는다(폴백은 「없는 항목」과 「안 보이는 항목」을
     * 구분하지 못한다).
     *
     * protected_paths 존중은 같은 열거를 타므로 여기서 함께 잠근다 — 숨김 파일이 보이기
     * 시작하면 `.env` 도 후보에 들어오기 때문이다.
     *
     * @effects root_entry_files_reach_installs_running_older_targets
     *
     * @return void 반환값 없음
     */
    public function test_apply_update_auto_discovers_hidden_top_level_files(): void
    {
        $sourcePath = storage_path('test_apply_hidden_src_'.uniqid());
        $this->tempDirs[] = $sourcePath;
        File::ensureDirectoryExists($sourcePath);

        File::put($sourcePath.DIRECTORY_SEPARATOR.'.htaccess', "# BEGIN G7\n# new release block\n# END G7\n");
        File::put($sourcePath.DIRECTORY_SEPARATOR.'index.php', "<?php require __DIR__.'/public/index.php';\n");
        File::put($sourcePath.DIRECTORY_SEPARATOR.'.env', 'APP_VERSION=hijacked');

        $fakeBase = storage_path('test_apply_hidden_base_'.uniqid());
        $this->tempDirs[] = $fakeBase;
        File::ensureDirectoryExists($fakeBase);
        File::put($fakeBase.DIRECTORY_SEPARATOR.'.env', 'APP_VERSION=local');

        $originalBasePath = base_path();
        app()->setBasePath($fakeBase);

        try {
            // 구버전 targets 시뮬레이션 — 루트 진입 파일 2종이 아직 목록에 없다.
            config([
                'app.update.targets' => [],
                'app.update.excludes' => [],
                'app.update.protected_paths' => ['.env', 'storage', 'vendor', 'node_modules', '.git'],
            ]);

            $this->service->applyUpdate($sourcePath);

            $this->assertFileExists(
                $fakeBase.DIRECTORY_SEPARATOR.'index.php',
                '루트 shim 이 자동 발견 폴백으로 전달되어야 한다 (D25)'
            );
            $this->assertFileExists(
                $fakeBase.DIRECTORY_SEPARATOR.'.htaccess',
                '루트 .htaccess 가 자동 발견 폴백으로 전달되어야 한다 — 숨김 파일을 건너뛰면 보호 규칙 없는 진입점만 남는다 (D25)'
            );

            // 숨김 파일이 열거에 들어와도 protected_paths 는 그대로 지켜져야 한다.
            $this->assertSame('APP_VERSION=local', File::get($fakeBase.DIRECTORY_SEPARATOR.'.env'));
        } finally {
            app()->setBasePath($originalBasePath);
        }
    }

    /**
     * targets 에 `templates/_bundled` 만 있고 source 의 `templates/` 디렉토리에 `_bundled`
     * 만 있을 때, 활성 디렉토리(`templates/sirsoft-basic` 등) 가 자동 발견 폴백의
     * removeOrphans 로 삭제되지 않아야 한다.
     *
     * 회귀 시나리오: beta.4 의 `isCoveredByApplied` 가 단방향 검사만 수행하여
     * applied=['templates/_bundled'] 일 때 source 의 'templates' 자체를 신규 항목으로
     * 오인 → `copyDirectory(removeOrphans:true)` 가 base 의 활성 sirsoft-* 서브디렉토리를
     * orphan 으로 판정하여 삭제.
     *
     * @effects applyDiscoveredTopLevelPaths_does_not_remove_active_subdirs_when_source_only_has_bundled
     *
     * @return void 반환값 없음
     */
    public function test_apply_update_preserves_active_extension_dirs_when_source_only_has_bundled(): void
    {
        $sourcePath = storage_path('test_apply_preserve_src_'.uniqid());
        $this->tempDirs[] = $sourcePath;
        File::ensureDirectoryExists($sourcePath);

        // source: templates/_bundled/{shared-id}/manifest.json 만 보유
        File::ensureDirectoryExists($sourcePath.DIRECTORY_SEPARATOR.'templates'.DIRECTORY_SEPARATOR.'_bundled'.DIRECTORY_SEPARATOR.'sirsoft-basic');
        File::put(
            $sourcePath.DIRECTORY_SEPARATOR.'templates'.DIRECTORY_SEPARATOR.'_bundled'.DIRECTORY_SEPARATOR.'sirsoft-basic'.DIRECTORY_SEPARATOR.'manifest.json',
            '{"identifier":"sirsoft-basic","version":"1.0.0"}'
        );

        // base: templates/_bundled/sirsoft-basic + templates/sirsoft-basic (활성 디렉토리)
        $fakeBase = storage_path('test_apply_preserve_base_'.uniqid());
        $this->tempDirs[] = $fakeBase;
        File::ensureDirectoryExists($fakeBase);

        $originalBasePath = base_path();
        app()->setBasePath($fakeBase);

        File::ensureDirectoryExists($fakeBase.DIRECTORY_SEPARATOR.'templates'.DIRECTORY_SEPARATOR.'_bundled'.DIRECTORY_SEPARATOR.'sirsoft-basic');
        File::put(
            $fakeBase.DIRECTORY_SEPARATOR.'templates'.DIRECTORY_SEPARATOR.'_bundled'.DIRECTORY_SEPARATOR.'sirsoft-basic'.DIRECTORY_SEPARATOR.'manifest.json',
            '{"identifier":"sirsoft-basic","version":"0.9.0"}'
        );
        File::ensureDirectoryExists($fakeBase.DIRECTORY_SEPARATOR.'templates'.DIRECTORY_SEPARATOR.'sirsoft-basic');
        File::put(
            $fakeBase.DIRECTORY_SEPARATOR.'templates'.DIRECTORY_SEPARATOR.'sirsoft-basic'.DIRECTORY_SEPARATOR.'manifest.json',
            '{"identifier":"sirsoft-basic","installed":true}'
        );

        try {
            config([
                'app.update.targets' => ['templates/_bundled'],
                'app.update.excludes' => [],
                'app.update.protected_paths' => ['.env', 'storage', 'vendor', 'node_modules', '.git'],
            ]);

            $this->service->applyUpdate($sourcePath);

            // _bundled 는 source 기준으로 정상 sync
            $this->assertFileExists(
                $fakeBase.DIRECTORY_SEPARATOR.'templates'.DIRECTORY_SEPARATOR.'_bundled'.DIRECTORY_SEPARATOR.'sirsoft-basic'.DIRECTORY_SEPARATOR.'manifest.json'
            );

            // 활성 디렉토리는 source 에 부재하지만 base 에 보존되어야 함 (회귀 차단)
            $this->assertFileExists(
                $fakeBase.DIRECTORY_SEPARATOR.'templates'.DIRECTORY_SEPARATOR.'sirsoft-basic'.DIRECTORY_SEPARATOR.'manifest.json',
                '활성 templates/sirsoft-basic 이 자동 발견 폴백에 의해 삭제되어서는 안 된다'
            );
        } finally {
            app()->setBasePath($originalBasePath);
        }
    }

    /**
     * 회귀: 사용자가 `_bundled/` 바로 아래에 직접 생성한 커스텀 확장 디렉토리가
     * 코어 업데이트의 정상 targets 흐름(removeOrphans:true)에 의해 삭제되지 않아야 한다.
     *
     * 확장 3종 + 언어팩 4개 도메인 전부 검증한다.
     *
     * 회귀 시나리오: targets 의 `{domain}/_bundled` 를 source 기준으로 sync 할 때,
     * source(신버전 코어 배포본)에 없는 사용자 디렉토리(`_bundled/my-project`) 가
     * orphan 으로 판정되어 삭제되던 결함 (공개 gnuboard/g7 #43 제보).
     *
     * @effects test_apply_update_preserves_user_added_bundled_subdir_all_domains
     *
     * @return void 반환값 없음
     */
    public function test_apply_update_preserves_user_added_bundled_subdir_all_domains(): void
    {
        $domains = [
            'modules' => 'sirsoft-ecommerce',
            'plugins' => 'sirsoft-payment',
            'templates' => 'sirsoft-basic',
            'lang-packs' => 'g7-core-ja',
        ];

        $sourcePath = storage_path('test_388_src_'.uniqid());
        $this->tempDirs[] = $sourcePath;
        File::ensureDirectoryExists($sourcePath);

        $fakeBase = storage_path('test_388_base_'.uniqid());
        $this->tempDirs[] = $fakeBase;
        File::ensureDirectoryExists($fakeBase);

        $targets = [];

        foreach ($domains as $domain => $bundledId) {
            $targets[] = $domain.'/_bundled';

            // source: {domain}/_bundled/{bundledId} 만 보유 (코어 번들 확장)
            $srcExt = $sourcePath.DIRECTORY_SEPARATOR.$domain.DIRECTORY_SEPARATOR.'_bundled'.DIRECTORY_SEPARATOR.$bundledId;
            File::ensureDirectoryExists($srcExt);
            File::put($srcExt.DIRECTORY_SEPARATOR.'manifest.json', '{"identifier":"'.$bundledId.'","version":"1.0.0"}');

            // base: 코어 번들 확장 + 사용자 추가 디렉토리(my-project) + 사용자 추가 파일(메모.txt)
            $baseBundled = $fakeBase.DIRECTORY_SEPARATOR.$domain.DIRECTORY_SEPARATOR.'_bundled';
            $baseExt = $baseBundled.DIRECTORY_SEPARATOR.$bundledId;
            File::ensureDirectoryExists($baseExt);
            File::put($baseExt.DIRECTORY_SEPARATOR.'manifest.json', '{"identifier":"'.$bundledId.'","version":"0.9.0"}');

            // 사용자 추가 디렉토리 (source 에 없음)
            $userDir = $baseBundled.DIRECTORY_SEPARATOR.'my-project';
            File::ensureDirectoryExists($userDir);
            File::put($userDir.DIRECTORY_SEPARATOR.'custom.json', '{"mine":true}');

            // 사용자 추가 단일 파일 (source 에 없음)
            File::put($baseBundled.DIRECTORY_SEPARATOR.'memo.txt', 'keep me');
        }

        $originalBasePath = base_path();
        app()->setBasePath($fakeBase);

        try {
            config([
                'app.update.targets' => $targets,
                'app.update.excludes' => [],
                'app.update.protected_paths' => ['.env', 'storage', 'vendor', 'node_modules', '.git'],
            ]);

            $this->service->applyUpdate($sourcePath);

            foreach ($domains as $domain => $bundledId) {
                $baseBundled = $fakeBase.DIRECTORY_SEPARATOR.$domain.DIRECTORY_SEPARATOR.'_bundled';

                // 사용자 추가 디렉토리 보존
                $this->assertFileExists(
                    $baseBundled.DIRECTORY_SEPARATOR.'my-project'.DIRECTORY_SEPARATOR.'custom.json',
                    $domain.'/_bundled/my-project 가 코어 업데이트에 의해 삭제되어서는 안 된다'
                );

                // 사용자 추가 단일 파일 보존
                $this->assertFileExists(
                    $baseBundled.DIRECTORY_SEPARATOR.'memo.txt',
                    $domain.'/_bundled/memo.txt 가 코어 업데이트에 의해 삭제되어서는 안 된다'
                );

                // 코어 번들 확장은 source 기준으로 정상 sync
                $this->assertStringContainsString(
                    '"version":"1.0.0"',
                    File::get($baseBundled.DIRECTORY_SEPARATOR.$bundledId.DIRECTORY_SEPARATOR.'manifest.json'),
                    $domain.'/_bundled/'.$bundledId.' 는 source 기준으로 갱신되어야 한다'
                );
            }
        } finally {
            app()->setBasePath($originalBasePath);
        }
    }

    /**
     * 비회귀: `--prune` 모드에서 `_bundled` 최상위 사용자 항목은 보존하되, source 에
     * 존재하는 코어 번들 확장 디렉토리 *내부* 의 stale 파일 정리는 그대로 유지되어야 한다.
     *
     * 주의: orphan(stale) 정리는 `--prune` 지정 시에만 수행된다 (공개 #64).
     * 기본(증분) 모드는 orphan 을 삭제하지 않으므로 본 회귀 가드는 prune 컨텍스트로 검증한다.
     * 기본 모드에서 stale 파일이 보존되는 동작은 별도 테스트
     * (test_apply_update_incremental_does_not_remove_orphans)가 커버한다.
     *
     * @scenario apply_mode=prune
     *
     * @effects test_apply_update_prune_still_cleans_stale_file_inside_bundled_extension, applyUpdate_targets_branch_continues_to_remove_orphans_within_bundled_subtree
     *
     * @return void 반환값 없음
     */
    public function test_apply_update_prune_still_cleans_stale_file_inside_bundled_extension(): void
    {
        $sourcePath = storage_path('test_388_stale_src_'.uniqid());
        $this->tempDirs[] = $sourcePath;
        File::ensureDirectoryExists($sourcePath);

        // source: modules/_bundled/sirsoft-ecommerce/module.json 만 (old.json 없음)
        $srcExt = $sourcePath.DIRECTORY_SEPARATOR.'modules'.DIRECTORY_SEPARATOR.'_bundled'.DIRECTORY_SEPARATOR.'sirsoft-ecommerce';
        File::ensureDirectoryExists($srcExt);
        File::put($srcExt.DIRECTORY_SEPARATOR.'module.json', '{"version":"1.0.0"}');

        $fakeBase = storage_path('test_388_stale_base_'.uniqid());
        $this->tempDirs[] = $fakeBase;
        File::ensureDirectoryExists($fakeBase);

        // base: 코어 번들 확장 내부에 source 에 없는 stale 파일(old.json) + 사용자 디렉토리
        $baseBundled = $fakeBase.DIRECTORY_SEPARATOR.'modules'.DIRECTORY_SEPARATOR.'_bundled';
        $baseExt = $baseBundled.DIRECTORY_SEPARATOR.'sirsoft-ecommerce';
        File::ensureDirectoryExists($baseExt);
        File::put($baseExt.DIRECTORY_SEPARATOR.'module.json', '{"version":"0.9.0"}');
        File::put($baseExt.DIRECTORY_SEPARATOR.'old.json', 'stale');

        $userDir = $baseBundled.DIRECTORY_SEPARATOR.'my-project';
        File::ensureDirectoryExists($userDir);
        File::put($userDir.DIRECTORY_SEPARATOR.'custom.json', '{"mine":true}');

        $originalBasePath = base_path();
        app()->setBasePath($fakeBase);

        try {
            config([
                'app.update.targets' => ['modules/_bundled'],
                'app.update.excludes' => [],
                'app.update.protected_paths' => ['.env', 'storage', 'vendor', 'node_modules', '.git'],
            ]);

            // --prune 컨텍스트: orphan(stale) 정리 활성화
            $this->service->applyUpdate($sourcePath, null, prune: true);

            // 코어 번들 확장 내부의 stale 파일은 정리되어야 함
            $this->assertFileDoesNotExist(
                $baseExt.DIRECTORY_SEPARATOR.'old.json',
                '--prune 모드에서 코어 번들 확장 내부의 stale 파일은 정리되어야 한다'
            );

            // 사용자 추가 디렉토리는 보존
            $this->assertFileExists(
                $userDir.DIRECTORY_SEPARATOR.'custom.json',
                '사용자 추가 _bundled/my-project 는 보존되어야 한다'
            );
        } finally {
            app()->setBasePath($originalBasePath);
        }
    }

    /**
     * modules/, plugins/ 에도 동일 패턴 보존 확인.
     *
     * @effects applyDiscoveredTopLevelPaths_does_not_remove_active_subdirs_when_source_only_has_bundled
     *
     * @return void 반환값 없음
     */
    public function test_apply_update_preserves_active_module_and_plugin_dirs_same_pattern(): void
    {
        $sourcePath = storage_path('test_apply_preserve_mp_src_'.uniqid());
        $this->tempDirs[] = $sourcePath;
        File::ensureDirectoryExists($sourcePath);

        // source: modules/_bundled, plugins/_bundled 만 보유
        File::ensureDirectoryExists($sourcePath.DIRECTORY_SEPARATOR.'modules'.DIRECTORY_SEPARATOR.'_bundled'.DIRECTORY_SEPARATOR.'sirsoft-ecommerce');
        File::put(
            $sourcePath.DIRECTORY_SEPARATOR.'modules'.DIRECTORY_SEPARATOR.'_bundled'.DIRECTORY_SEPARATOR.'sirsoft-ecommerce'.DIRECTORY_SEPARATOR.'module.json',
            '{"identifier":"sirsoft-ecommerce","version":"1.0.0"}'
        );
        File::ensureDirectoryExists($sourcePath.DIRECTORY_SEPARATOR.'plugins'.DIRECTORY_SEPARATOR.'_bundled'.DIRECTORY_SEPARATOR.'sirsoft-payment');
        File::put(
            $sourcePath.DIRECTORY_SEPARATOR.'plugins'.DIRECTORY_SEPARATOR.'_bundled'.DIRECTORY_SEPARATOR.'sirsoft-payment'.DIRECTORY_SEPARATOR.'plugin.json',
            '{"identifier":"sirsoft-payment","version":"1.0.0"}'
        );

        $fakeBase = storage_path('test_apply_preserve_mp_base_'.uniqid());
        $this->tempDirs[] = $fakeBase;
        File::ensureDirectoryExists($fakeBase);

        $originalBasePath = base_path();
        app()->setBasePath($fakeBase);

        // base 활성 디렉토리 + _bundled 모두 있는 상태
        File::ensureDirectoryExists($fakeBase.DIRECTORY_SEPARATOR.'modules'.DIRECTORY_SEPARATOR.'_bundled'.DIRECTORY_SEPARATOR.'sirsoft-ecommerce');
        File::put(
            $fakeBase.DIRECTORY_SEPARATOR.'modules'.DIRECTORY_SEPARATOR.'_bundled'.DIRECTORY_SEPARATOR.'sirsoft-ecommerce'.DIRECTORY_SEPARATOR.'module.json',
            '{"identifier":"sirsoft-ecommerce","version":"0.9.0"}'
        );
        File::ensureDirectoryExists($fakeBase.DIRECTORY_SEPARATOR.'modules'.DIRECTORY_SEPARATOR.'sirsoft-ecommerce');
        File::put(
            $fakeBase.DIRECTORY_SEPARATOR.'modules'.DIRECTORY_SEPARATOR.'sirsoft-ecommerce'.DIRECTORY_SEPARATOR.'module.json',
            '{"installed":true}'
        );

        File::ensureDirectoryExists($fakeBase.DIRECTORY_SEPARATOR.'plugins'.DIRECTORY_SEPARATOR.'_bundled'.DIRECTORY_SEPARATOR.'sirsoft-payment');
        File::put(
            $fakeBase.DIRECTORY_SEPARATOR.'plugins'.DIRECTORY_SEPARATOR.'_bundled'.DIRECTORY_SEPARATOR.'sirsoft-payment'.DIRECTORY_SEPARATOR.'plugin.json',
            '{"identifier":"sirsoft-payment","version":"0.9.0"}'
        );
        File::ensureDirectoryExists($fakeBase.DIRECTORY_SEPARATOR.'plugins'.DIRECTORY_SEPARATOR.'sirsoft-payment');
        File::put(
            $fakeBase.DIRECTORY_SEPARATOR.'plugins'.DIRECTORY_SEPARATOR.'sirsoft-payment'.DIRECTORY_SEPARATOR.'plugin.json',
            '{"installed":true}'
        );

        try {
            config([
                'app.update.targets' => ['modules/_bundled', 'plugins/_bundled'],
                'app.update.excludes' => [],
                'app.update.protected_paths' => ['.env', 'storage', 'vendor', 'node_modules', '.git'],
            ]);

            $this->service->applyUpdate($sourcePath);

            // 활성 모듈/플러그인 디렉토리 보존
            $this->assertFileExists(
                $fakeBase.DIRECTORY_SEPARATOR.'modules'.DIRECTORY_SEPARATOR.'sirsoft-ecommerce'.DIRECTORY_SEPARATOR.'module.json',
                '활성 modules/sirsoft-ecommerce 이 보존되어야 한다'
            );
            $this->assertFileExists(
                $fakeBase.DIRECTORY_SEPARATOR.'plugins'.DIRECTORY_SEPARATOR.'sirsoft-payment'.DIRECTORY_SEPARATOR.'plugin.json',
                '활성 plugins/sirsoft-payment 이 보존되어야 한다'
            );

            // _bundled sync 정상
            $this->assertStringContainsString(
                '"version":"1.0.0"',
                File::get($fakeBase.DIRECTORY_SEPARATOR.'modules'.DIRECTORY_SEPARATOR.'_bundled'.DIRECTORY_SEPARATOR.'sirsoft-ecommerce'.DIRECTORY_SEPARATOR.'module.json')
            );
        } finally {
            app()->setBasePath($originalBasePath);
        }
    }

    /**
     * 자동 발견 폴백이 protected_paths 와 user excludes 양쪽을 존중하는지 검증합니다.
     *
     * @effects applyUpdate_auto_discovery_skips_protected_paths_and_excludes
     *
     * @return void 반환값 없음
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

    /**
     * 실행 잠금은 **실제로 얻은 쪽만** 푼다 (재진입 소유권).
     *
     * 재진입을 무조건 `true` 로 흡수하고 해제를 무조건 수행하면, **안쪽 호출의
     * `finally` 가 바깥 보유자의 잠금을 푼다.** 그 형태가 실재한다 — 웹 오케스트레이터가
     * 잠금을 잡은 뒤 같은 프로세스에서 `Artisan::call('core:update')` 를 부르면 커맨드가
     * 끝나며 잠금이 풀리고, 이후 백업 삭제·롤백 구간이 무잠금으로 돌아 잠금이 지키려던
     * 것(겹친 실행이 서로의 백업을 파괴)이 그대로 재현된다.
     *
     * @scenario update_concurrency=reentrant
     *
     * @effects run_lock_release_requires_ownership
     *
     * @return void 반환값 없음
     */
    public function test_run_lock_release_is_owned_by_the_acquirer(): void
    {
        $this->assertTrue($this->service->acquireRunLock(), '첫 획득은 성공해야 한다');
        $this->assertSame(1, CoreUpdateService::runLockDepth());

        // 안쪽(재진입) 획득 — 같은 프로세스라 통과하지만 깊이가 는다.
        $this->assertTrue($this->service->acquireRunLock());
        $this->assertSame(2, CoreUpdateService::runLockDepth());

        // 안쪽 해제는 바깥 보유를 풀지 않는다.
        $this->service->releaseRunLock();
        $this->assertSame(
            1,
            CoreUpdateService::runLockDepth(),
            '안쪽 해제가 바깥 잠금을 풀면 이후 구간이 무잠금으로 돈다'
        );

        // 바깥 해제로 완전히 풀린다.
        $this->service->releaseRunLock();
        $this->assertSame(0, CoreUpdateService::runLockDepth());

        // 완전히 푼 뒤에는 다시 얻을 수 있다.
        $this->assertTrue($this->service->acquireRunLock());
        $this->assertSame(1, CoreUpdateService::runLockDepth());
        $this->service->releaseRunLock();
    }
}
