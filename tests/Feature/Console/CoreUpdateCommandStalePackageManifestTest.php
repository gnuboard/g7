<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Core\CoreUpdateCommand;
use App\Exceptions\UpgradeHandoffException;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * [case:backend-31] stale 패키지 매니페스트로 인한 spawn 자식 부팅 실패 회귀 테스트 (dev-g7 #658).
 *
 * 회귀 시나리오 (sir.kr 제보, 7.0.9 → 7.0.10):
 *   운영 사이트가 `composer install`(옵션 없음)로 깔린 dev 설치본이면
 *   `bootstrap/cache/packages.php` 에 require-dev 전이 의존성의 provider(`laravel/mcp` 의
 *   `McpServiceProvider` 등)가 등재된다. 코어 업데이트는 Step 6/8 에서 vendor 를 `--no-dev`
 *   로 교체하지만 그 두 파일은 Step 11 까지 남고, Laravel 은 `packages.php` 가 **없을 때만**
 *   다시 만든다. Step 10 spawn 자식은 그 목록을 읽어 새 vendor 에 없는 provider 를 `new`
 *   하다 부팅 단계에서 죽고, 부모는 핸드오프(수동 재개 안내)로 중단한다.
 *
 * 세 계층을 각각 잠근다:
 *   - 계층 ① 부모가 spawn 직전에 두 파일을 비운다 (R1·R2)
 *   - 계층 ② 신버전 자식이 `G7_UPDATE_IN_PROGRESS=1` 이면 스스로 비우고 부팅한다 (R3)
 *     — 7.0.11 미만 부모는 비우지 않으므로 그 부모 아래에서는 이것이 유일한 방어다
 *   - 대조군: 업데이트 트리 밖 프로세스는 매니페스트를 건드리지 않는다 (R4)
 *
 * 격리: 실제 `bootstrap/cache/*` 를 백업하지 않고 `APP_PACKAGES_CACHE`/`APP_SERVICES_CACHE`
 * 를 base_path 상대 POSIX 경로로 재지정한다(`Application::normalizeCachePath` 는 `/`·`\` 로
 * 시작하지 않는 값을 basePath 상대로 해석하므로 Windows 절대 경로는 어긋난다). 자식도 같은
 * env 를 물려받아 같은 파일을 가리킨다 — `CoreUpdateCommandSpawnFailureTest` 선례와 동일.
 */
class CoreUpdateCommandStalePackageManifestTest extends TestCase
{
    /** @var array<string, string|null> 테스트가 덮어쓴 env 의 원값 (3채널 복원용) */
    private array $originalEnv = [];

    /** @var string base_path 상대 — packages.php 캐시 경로 */
    private string $relativePackagesPath;

    /** @var string base_path 상대 — services.php 캐시 경로 */
    private string $relativeServicesPath;

    private string $packagesPath;

    private string $servicesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $uniq = uniqid();
        $this->relativePackagesPath = "storage/framework/testing/stale-manifest-{$uniq}/packages.php";
        $this->relativeServicesPath = "storage/framework/testing/stale-manifest-{$uniq}/services.php";
        $this->packagesPath = base_path($this->relativePackagesPath);
        $this->servicesPath = base_path($this->relativeServicesPath);

        File::ensureDirectoryExists(dirname($this->packagesPath));

        $this->setEnv('APP_PACKAGES_CACHE', $this->relativePackagesPath);
        $this->setEnv('APP_SERVICES_CACHE', $this->relativeServicesPath);

        // 부모 PHPUnit 프로세스에 다른 테스트가 남긴 플래그가 있으면 대조군(R4)이 무의미해진다.
        $this->setEnv('G7_UPDATE_IN_PROGRESS', null);
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
                putenv($key.'='.$value);
            }
        }
        $this->originalEnv = [];

        $dir = dirname($this->packagesPath);
        if (File::isDirectory($dir)) {
            File::deleteDirectory($dir);
        }

        parent::tearDown();
    }

    /**
     * 계층 ①: 부모는 자식이 부팅에 실패하더라도 그 전에 두 매니페스트를 비운다.
     *
     * 자식을 확실히 실패시키려고 `php_binary` 를 존재하지 않는 경로로 둔다 — 정리는
     * `proc_open` 앞에서 일어나므로 자식의 성패와 무관하게 관측된다.
     *
     * @effects spawnUpgradeStepsProcess_clears_package_manifests_before_proc_open
     */
    #[Test]
    public function spawn_직전에_패키지_매니페스트를_비운다_자식이_부팅하지_못해도(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open 미지원 환경');
        }

        config(['app.update.spawn_failure_mode' => 'abort']);
        config(['process.php_binary' => base_path('storage/framework/testing/no-such-php-binary')]);

        $this->writeStaleManifests();

        $this->assertSame($this->packagesPath, $this->app->getCachedPackagesPath(), '전제: APP_PACKAGES_CACHE 가 경로를 정한다');
        $this->assertFileExists($this->packagesPath, '전제: 부모 시점에 packages.php 가 존재한다');
        $this->assertFileExists($this->servicesPath, '전제: 부모 시점에 services.php 가 존재한다');

        try {
            $this->invokeSpawn();
            $this->fail('자식이 부팅할 수 없으므로 abort 모드에서 UpgradeHandoffException 이 발생해야 한다');
        } catch (UpgradeHandoffException) {
            // 기대된 경로 — 아래 단언이 본 검증이다.
        }

        $this->assertFileDoesNotExist($this->packagesPath, 'spawn 전에 packages.php 를 비워야 자식이 새 vendor 기준으로 다시 만든다');
        $this->assertFileDoesNotExist($this->servicesPath, 'spawn 전에 services.php 도 함께 비워야 한다');
    }

    /**
     * 계층 ①: stale dev 매니페스트가 있어도 자식은 정상 부팅해 스텝 0건을 통과한다.
     *
     * 계층 ①②가 모두 없으면 자식이 `Class ... not found` 로 죽어 abort 예외가 난다.
     *
     * @effects spawn_child_boots_with_regenerated_package_manifest_when_parent_left_stale_dev_manifest
     */
    #[Test]
    public function stale_dev_매니페스트가_있어도_spawn_자식은_정상_부팅해_스텝_0건을_통과한다(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open 미지원 환경');
        }

        config(['app.update.spawn_failure_mode' => 'abort']);

        $this->writeStaleManifests();

        $result = $this->invokeSpawn();

        $this->assertTrue($result, 'stale 매니페스트가 정리되면 자식은 정상 부팅해 스텝 0건으로 성공해야 한다');
        $this->assertStringNotContainsString(
            'NonExistentServiceProvider',
            (string) @file_get_contents($this->packagesPath),
            '재생성된 매니페스트에 존재하지 않는 provider 가 남아 있으면 안 된다'
        );
    }

    /**
     * 계층 ②: 업데이트 플래그를 물려받은 자식은 부모가 비우지 않았어도 스스로 비우고 부팅한다.
     *
     * 7.0.11 미만 부모(이미 배포된 7.0.9·7.0.10)는 spawn 직전에 비우지 않으므로, 그 부모
     * 아래에서 도는 신버전 자식에게는 이것이 유일한 방어다. 부모 역할은 `proc_open` 으로
     * 직접 재현한다 — 정리 없이 stale 매니페스트를 남긴 채 자식을 띄운다.
     *
     * @effects bootstrap_app_unlinks_stale_package_manifest_when_update_flag_present
     */
    #[Test]
    public function 업데이트_플래그가_있는_자식은_stale_매니페스트를_스스로_지우고_부팅한다(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open 미지원 환경');
        }

        $this->writeStaleManifests();

        [$exitCode, $output] = $this->runArtisanChild(['G7_UPDATE_IN_PROGRESS' => '1']);

        $this->assertSame(0, $exitCode, "업데이트 플래그가 있는 자식은 stale 매니페스트를 스스로 지우고 부팅해야 한다. 출력:\n{$output}");
        $this->assertStringNotContainsString('NonExistentServiceProvider', $output, '자가 치유 후에는 없는 provider 를 참조하지 않는다');
    }

    /**
     * 대조군: 업데이트 트리 밖 프로세스(웹 요청·큐 워커·운영자 셸)는 매니페스트를 건드리지 않는다.
     *
     * 자가 치유가 트리 밖으로 새면 평상시 모든 부팅이 매니페스트를 지웠다 다시 만들게 된다.
     * 이 테스트는 계층 ② 도입 전후로 항상 green 이어야 한다.
     *
     * @effects bootstrap_app_leaves_package_manifest_untouched_without_update_flag
     */
    #[Test]
    public function 업데이트_플래그가_없는_프로세스는_매니페스트를_건드리지_않는다(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open 미지원 환경');
        }

        $this->writeStaleManifests();
        $before = md5_file($this->packagesPath);

        [$exitCode, $output] = $this->runArtisanChild(['G7_UPDATE_IN_PROGRESS' => null]);

        $this->assertNotSame(0, $exitCode, '플래그 없는 자식은 stale 매니페스트 그대로 부팅해 실패해야 한다 (자가 치유가 트리 밖으로 새지 않았다는 증거)');
        $this->assertStringContainsString('NonExistentServiceProvider', $output, '실패 원인이 stale provider 여야 한다');
        $this->assertFileExists($this->packagesPath, '트리 밖 프로세스는 매니페스트를 지우지 않는다');
        $this->assertSame($before, md5_file($this->packagesPath), '매니페스트 내용이 변경되지 않아야 한다');
    }

    /**
     * 계층 ①: spawn 직전에 매니페스트를 지우지 못하면 그 파일을 업그레이드 로그에 남긴다.
     *
     * 권한·소유권 불일치로 삭제가 막히면 자식의 자가 치유도 같은 권한으로 같은 이유로 실패해
     * 증상은 이전 설치본 provider 의 「Class not found」 그대로다 — 이 경고가 원인이 권한이라는
     * 유일한 흔적이다. 삭제 실패는 실제로 만든다(Windows: 열린 핸들 / POSIX: 부모 디렉토리 쓰기
     * 권한 제거). 자식은 존재하지 않는 php 바이너리로 부팅 불가하게 두어 `proc_open` 앞의 동작만
     * 관측한다.
     *
     * @effects spawnUpgradeStepsProcess_logs_manifest_files_it_could_not_remove
     */
    #[Test]
    public function spawn_직전_매니페스트를_지우지_못하면_업그레이드_로그에_남은_파일을_경고한다(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open 미지원 환경');
        }

        config(['app.update.spawn_failure_mode' => 'abort']);
        config(['process.php_binary' => base_path('storage/framework/testing/no-such-php-binary')]);

        $this->writeStaleManifests();

        $release = $this->makeUndeletable($this->packagesPath);
        $logged = [];

        try {
            try {
                $this->invokeSpawn(function (string $line) use (&$logged): void {
                    $logged[] = $line;
                });
                $this->fail('자식이 부팅할 수 없으므로 abort 모드에서 UpgradeHandoffException 이 발생해야 한다');
            } catch (UpgradeHandoffException) {
                // 기대된 경로 — 아래 단언이 본 검증이다.
            }
        } finally {
            $release();
        }

        $this->assertFileExists($this->packagesPath, '삭제가 막힌 packages.php 는 남는다');
        $this->assertFileDoesNotExist($this->servicesPath, '지울 수 있는 services.php 는 지운다');

        $warnings = array_values(array_filter($logged, static fn (string $line): bool => str_contains($line, '패키지 매니페스트 삭제 실패')));
        $this->assertCount(1, $warnings, '지우지 못한 파일이 있으면 업그레이드 로그에 경고 한 줄을 남긴다');
        $this->assertStringContainsString($this->packagesPath, $warnings[0], '경고는 지우지 못한 파일의 경로를 지목한다');
        $this->assertStringNotContainsString($this->servicesPath, $warnings[0], '지운 파일은 경고에 싣지 않는다');
    }

    /**
     * 파일을 현재 프로세스가 삭제할 수 없는 상태로 만들고, 되돌리는 클로저를 반환합니다.
     *
     * Windows 는 읽기 전용 속성(0444)이 삭제를 막고(PHP 7.3+ 는 파일을 `FILE_SHARE_DELETE` 로 열어
     * 열린 핸들로는 막히지 않는다 — 실측), POSIX 는 부모 디렉토리의 쓰기 권한이 삭제를 막는다(root 는
     * 권한을 우회하므로 이 방법으로 실패를 만들 수 없다).
     *
     * @param  string  $path  삭제를 막을 파일
     * @return \Closure 원상 복구 클로저
     */
    private function makeUndeletable(string $path): \Closure
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertTrue(chmod($path, 0444), '전제: 읽기 전용 속성을 건다');

            return static function () use ($path): void {
                if (is_file($path)) {
                    chmod($path, 0644);
                }
            };
        }

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root 는 디렉토리 권한으로 삭제 실패를 만들 수 없다 — 비-root 로 실행해야 이 축이 측정된다');
        }

        $dir = dirname($path);
        $this->assertTrue(chmod($dir, 0555), '전제: 부모 디렉토리의 쓰기 권한을 뺀다');

        return static function () use ($dir): void {
            chmod($dir, 0755);
        };
    }

    /**
     * 존재하지 않는 provider 를 등재한 stale 매니페스트 두 개를 만든다.
     */
    private function writeStaleManifests(): void
    {
        File::ensureDirectoryExists(dirname($this->packagesPath));

        File::put($this->packagesPath, "<?php return ['tests/stale-fixture' => ['providers' => ['Tests\\\\Fixtures\\\\Stale\\\\NonExistentServiceProvider']]];\n");
        File::put($this->servicesPath, "<?php return ['providers' => ['Tests\\\\Fixtures\\\\Stale\\\\NonExistentServiceProvider'], 'eager' => ['Tests\\\\Fixtures\\\\Stale\\\\NonExistentServiceProvider'], 'deferred' => [], 'when' => []];\n");

        clearstatcache();
    }

    /**
     * `spawnUpgradeStepsProcess` 를 리플렉션으로 호출합니다.
     *
     * @param  \Closure|null  $log  업그레이드 로그 클로저. 생략하면 버린다
     * @return bool spawn 성공 여부
     */
    private function invokeSpawn(?\Closure $log = null): bool
    {
        $command = app(CoreUpdateCommand::class);

        $input = new ArrayInput([]);
        $output = new BufferedOutput;
        $style = new OutputStyle($input, $output);

        $reflection = new \ReflectionClass($command);
        $property = $reflection->getProperty('output');
        $property->setAccessible(true);
        $property->setValue($command, $style);

        if ($reflection->hasProperty('input')) {
            $inputProp = $reflection->getProperty('input');
            $inputProp->setAccessible(true);
            $inputProp->setValue($command, $input);
        }

        $method = new \ReflectionMethod(CoreUpdateCommand::class, 'spawnUpgradeStepsProcess');
        $method->setAccessible(true);

        return (bool) $method->invoke($command, '9.9.8', '9.9.9', true, $log ?? fn () => null);
    }

    /**
     * `php artisan --version` 을 별도 프로세스로 실행합니다.
     *
     * 운영 코드(`spawnUpgradeStepsProcess`)와 동형으로 `getenv()` 기반 env 합집합을 넘긴다 —
     * `variables_order` 에 `E` 가 없으면 `$_ENV` 만으로는 자식이 캐시 경로 재지정을 못 받는다.
     *
     * @param  array<string, string|null>  $overrides  덮어쓸 env (null 이면 자식 env 에서 제거)
     * @return array{0: int, 1: string} 종료 코드와 stdout+stderr 합본
     */
    private function runArtisanChild(array $overrides): array
    {
        $env = array_merge(getenv(), $_ENV);
        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($env[$key]);
            } else {
                $env[$key] = $value;
            }
        }

        $commandLine = sprintf('%s %s --version', escapeshellarg(PHP_BINARY), escapeshellarg(base_path('artisan')));

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($commandLine, $descriptors, $pipes, base_path(), $env);

        if (! is_resource($process)) {
            $this->fail('proc_open 자원 생성 실패');
        }

        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out."\n".$err];
    }

    /**
     * env 를 세 채널($_ENV/$_SERVER/putenv)에 세우고 원값을 복원용으로 보관합니다.
     *
     * @param  string  $key  환경변수 이름
     * @param  string|null  $value  세울 값. null 이면 제거
     */
    private function setEnv(string $key, ?string $value): void
    {
        if (! array_key_exists($key, $this->originalEnv)) {
            $current = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
            $this->originalEnv[$key] = ($current === false || $current === null) ? null : (string) $current;
        }

        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            return;
        }

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key.'='.$value);
    }
}
