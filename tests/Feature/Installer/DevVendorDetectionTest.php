<?php

namespace Tests\Feature\Installer;

use App\Support\ComposerInstallInfo;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 인스톨러 vendor 재사용 경로의 dev 패키지 감지·경고·캐시 정리 회귀 테스트 (dev-g7 #658).
 *
 * 배경: `INSTALL.md`/`README.md` 가 인스톨러 **전에** `composer install`(옵션 없음)을 지시했고,
 * 인스톨러는 `vendor/autoload.php` + `composer.lock` 이 있으면 composer 단계를 통째로 건너뛰며
 * 그 vendor 를 아무 표시 없이 그대로 썼다. 그 결과 운영 사이트에 개발용 패키지가 남고,
 * 이후 코어 업데이트가 vendor 를 `--no-dev` 로 교체할 때 이전 매니페스트에만 남은 provider 를
 * 찾다 부팅이 깨졌다(sir.kr 제보, 7.0.9 → 7.0.10).
 *
 * `task-runner.php` 는 진입과 함께 SSE 를 실행하는 파일이라 테스트에서 include 할 수 없다.
 * 그래서 순수 함수(`detectDevVendorInstall`)는 직접 호출해 검증하고, 분기의 배치·순서는
 * 소스 문자열 계약으로 잠근다 — `BootstrapCacheCleanupTest` 와 같은 방식이다.
 */
class DevVendorDetectionTest extends TestCase
{
    private string $testBase;

    private string $vendorPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testBase = storage_path('app/test-dev-vendor-'.uniqid());
        $this->vendorPath = $this->testBase.'/vendor';
        File::ensureDirectoryExists($this->vendorPath.'/composer');

        require_once base_path('public/install/includes/vendor-bundle-installer.php');
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->testBase)) {
            File::deleteDirectory($this->testBase);
        }

        parent::tearDown();
    }

    /**
     * dev 설치본을 감지한다.
     *
     * @effects installer_detects_dev_vendor_from_installed_json
     */
    #[Test]
    public function detect_dev_vendor_install_은_dev_설치본을_감지한다(): void
    {
        $this->writeInstalledJson(['packages' => [], 'dev' => true, 'dev-package-names' => ['laravel/boost', 'laravel/mcp']]);

        $info = detectDevVendorInstall($this->testBase);

        $this->assertTrue($info['dev']);
        $this->assertSame(['laravel/boost', 'laravel/mcp'], $info['packages']);
    }

    /**
     * `--no-dev` 설치본은 경고 대상이 아니다.
     */
    #[Test]
    public function detect_dev_vendor_install_은_운영용_설치본을_경고하지_않는다(): void
    {
        $this->writeInstalledJson(['packages' => [], 'dev' => false, 'dev-package-names' => []]);

        $info = detectDevVendorInstall($this->testBase);

        $this->assertFalse($info['dev']);
        $this->assertSame([], $info['packages']);
    }

    /**
     * installed.json 이 없으면 판정 불가(null) — 경고도 차단도 하지 않는다.
     *
     * @effects installer_treats_missing_installed_json_as_unknown
     */
    #[Test]
    public function detect_dev_vendor_install_은_installed_json_부재를_판정_불가로_다룬다(): void
    {
        $info = detectDevVendorInstall($this->testBase);

        $this->assertNull($info['dev']);
        $this->assertSame([], $info['packages']);
    }

    /**
     * 인스톨러 shim 과 코어 판정기가 같은 답을 낸다 — 갈라지면 화면과 로그가 어긋난다.
     *
     * @effects installer_shim_matches_core_composer_install_info
     */
    #[Test]
    public function 인스톨러_shim_과_코어_판정기의_결과가_같다(): void
    {
        foreach ([
            ['packages' => [], 'dev' => true, 'dev-package-names' => ['laravel/mcp']],
            ['packages' => [], 'dev' => false, 'dev-package-names' => []],
        ] as $payload) {
            $this->writeInstalledJson($payload);

            $this->assertSame(
                ComposerInstallInfo::inspect($this->vendorPath),
                detectDevVendorInstall($this->testBase),
                'shim 은 코어 판정기에 위임하기만 해야 한다'
            );
        }
    }

    /**
     * 재사용 분기가 dev 를 감지해 경고하되 설치를 계속한다 (소스 계약).
     *
     * 감지·경고·"설치 계속" 셋이 한 분기 안에 있어야 한다 — 차단으로 바뀌면 설계 결정(D1) 위반이다.
     *
     * @effects installer_warns_and_keeps_dev_vendor
     */
    #[Test]
    public function 재사용_분기가_dev_를_감지해_경고하고_설치를_계속한다(): void
    {
        $source = File::get(base_path('public/install/includes/task-runner.php'));

        $this->assertStringContainsString('detectDevVendorInstall(BASE_PATH)', $source, '재사용 분기가 dev 판정을 호출해야 한다');
        $this->assertStringContainsString('log_composer_dev_packages_detected', $source, '감지 사실을 설치 로그에 남겨야 한다');
        $this->assertStringContainsString('warning_composer_dev_packages_kept', $source, '정리 명령 안내 경고를 남겨야 한다');

        $branch = $this->reuseBranchSource($source);
        $this->assertStringContainsString("return ['success' => true]", $branch, '경고는 설치를 차단하지 않는다 (D1)');
    }

    /**
     * 재사용 경로도 컴파일 캐시를 정리하고, 그 정리가 완료 표시보다 앞선다 (소스 계약).
     *
     * 종전에는 정리 없이 return 해, `key_generate` 가 이미 완료된 재개 설치에서는 어디서도
     * 정리되지 않았다.
     *
     * @effects installer_clears_compiled_cache_on_reuse_path
     */
    #[Test]
    public function 재사용_분기가_컴파일_캐시를_정리하고_완료_표시보다_앞선다(): void
    {
        $branch = $this->reuseBranchSource(File::get(base_path('public/install/includes/task-runner.php')));

        $clearPos = strpos($branch, 'clearLaravelCompiledCache(BASE_PATH)');
        $completePos = strpos($branch, "markTaskCompleted('composer_install')");

        $this->assertNotFalse($clearPos, '재사용 분기가 컴파일 캐시를 정리해야 한다');
        $this->assertNotFalse($completePos, '전제: 재사용 분기가 작업 완료를 표시한다');
        $this->assertLessThan($completePos, $clearPos, '캐시 정리는 완료 표시보다 앞서야 한다');
    }

    /**
     * 신규 언어 키가 ko/en 양쪽에 있고 치환 자리가 보존되어 있다.
     *
     * 한쪽만 있으면 그 로케일에서 키 문자열이 그대로 화면에 노출되고, 치환 자리가 빠지면
     * 건수·패키지 목록이 사라진 문장이 나간다 — 둘 다 오류 없이 통과한다.
     *
     * @effects installer_lang_keys_exist_in_both_locales
     */
    #[Test]
    public function 신규_언어_키가_ko_와_en_양쪽에_있고_치환_자리를_보존한다(): void
    {
        $ko = require base_path('public/install/lang/ko.php');
        $en = require base_path('public/install/lang/en.php');

        $keys = [
            'vendor_dev_packages',
            'vendor_dev_packages_none_short',
            'vendor_dev_packages_none',
            'vendor_dev_packages_detected_short',
            'vendor_dev_packages_detected_warning',
            'vendor_dev_packages_unknown',
            'vendor_dev_packages_no_vendor',
            'log_composer_dev_packages_detected',
            'warning_composer_dev_packages_kept',
        ];

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $ko, "ko 에 {$key} 가 있어야 한다");
            $this->assertArrayHasKey($key, $en, "en 에 {$key} 가 있어야 한다");
            $this->assertNotSame('', trim((string) $ko[$key]), "ko 의 {$key} 는 비어 있으면 안 된다");
            $this->assertNotSame('', trim((string) $en[$key]), "en 의 {$key} 는 비어 있으면 안 된다");
        }

        foreach (['vendor_dev_packages_detected_short', 'vendor_dev_packages_detected_warning'] as $key) {
            $this->assertStringContainsString(':count', $ko[$key], "ko 의 {$key} 는 :count 치환 자리를 가져야 한다");
            $this->assertStringContainsString(':count', $en[$key], "en 의 {$key} 는 :count 치환 자리를 가져야 한다");
        }

        foreach ([':count', ':packages'] as $placeholder) {
            $this->assertStringContainsString($placeholder, $ko['log_composer_dev_packages_detected']);
            $this->assertStringContainsString($placeholder, $en['log_composer_dev_packages_detected']);
        }
    }

    /**
     * `installComposerDependenciesSSE()` 안의 vendor 재사용 분기 본문을 잘라냅니다.
     *
     * @param  string  $source  task-runner.php 전문
     * @return string 재사용 분기 블록
     */
    private function reuseBranchSource(string $source): string
    {
        $start = strpos($source, 'if ($vendorExists && $lockExists) {');
        $this->assertNotFalse($start, '전제: vendor 재사용 분기가 존재한다');

        $end = strpos($source, 'if ($vendorExists && ! $lockExists) {', $start);
        $this->assertNotFalse($end, '전제: 재사용 분기 다음에 lock 부재 분기가 온다');

        return substr($source, $start, $end - $start);
    }

    /**
     * 인스톨러·코어가 다루는 composer install 명령은 전부 `--no-dev` 를 갖는다.
     *
     * 실행되는 명령만이 아니라 **실패 시 운영자에게 안내하는 명령**도 대상이다. 안내를 옵션 없이
     * 따라 하면 개발용(require-dev) 패키지가 섞인 vendor 가 만들어지고, 그 상태는 이후 코어
     * 업데이트가 vendor 를 교체할 때 이전 패키지 매니페스트와 어긋나 부팅을 깨뜨린다 — 이 저장소가
     * 막으려는 바로 그 상태다. 실제로 `rollback-functions.php` 의 안내가 그렇게 어긋나 있었다.
     *
     * 모집단은 소스에서 정규식으로 파생한다 — **파일 목록도 파생 대상이다.** 검사할 파일을 손으로
     * 열거하면 네 번째 파일이 안내 명령을 갖게 돼도 케이스 없이 통과한다(그 형태가 이 결함을
     * 처음 놓친 원인이었다: 리터럴 `"composer install"` 만 보는 파생식이 `{$composer} install` 을
     * 못 봤다). 그래서 디렉토리 트리를 훑어 매칭되는 파일 자체를 찾는다. 파생이 비면 그 자체로
     * 실패시켜 "검사했으나 0건" 과 "보지 않음" 을 구분한다.
     *
     * @effects installer_composer_commands_all_carry_no_dev
     */
    #[Test]
    public function 안내하는_것까지_포함해_모든_composer_install_명령이_no_dev_를_갖는다(): void
    {
        $found = [];

        foreach ($this->phpSourcesUnder(['public/install', 'app', 'upgrades', 'bootstrap']) as $relative) {
            $source = File::get(base_path($relative));

            preg_match_all(
                '/\$\{?([A-Za-z_][A-Za-z0-9_]*)\}?[^\n]{0,15}install [^\n\'"]*/',
                $source,
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as $match) {
                // 변수명에 composer 가 들어간 것만 — 정규식 안에서 걸러내면 첫 문자 소비 때문에
                // `$composer` 자신이 매칭에서 빠진다.
                if (stripos($match[1], 'composer') === false) {
                    continue;
                }

                $found[] = [$relative, $match[0]];
            }
        }

        $this->assertNotEmpty(
            $found,
            'composer install 명령을 하나도 찾지 못했다 — 파생식이 소스 형태를 따라가지 못하면 이 검사는 공허하게 통과한다'
        );

        foreach ($found as [$relative, $command]) {
            $this->assertStringContainsString(
                '--no-dev',
                $command,
                "{$relative} 의 composer install 명령에 --no-dev 가 있어야 한다: {$command}"
            );
        }
    }

    /**
     * 주어진 디렉토리 트리 아래의 PHP 소스 파일 경로를 저장소에서 파생합니다.
     *
     * 검사 대상 파일을 손으로 열거하지 않기 위한 것이다 — 새 파일이 composer 명령을 갖게 되면
     * 목록을 고치지 않아도 모집단에 들어온다. `vendor/` 는 제3자 코드라 제외한다.
     *
     * @param  array<int, string>  $roots  base_path 기준 상대 디렉토리
     * @return array<int, string> base_path 기준 상대 파일 경로 (POSIX 구분자)
     */
    private function phpSourcesUnder(array $roots): array
    {
        $files = [];

        foreach ($roots as $root) {
            $absoluteRoot = base_path($root);
            if (! is_dir($absoluteRoot)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absoluteRoot, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));

                if (str_contains($relative, '/vendor/')) {
                    continue;
                }

                $files[] = $relative;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * installed.json 픽스처를 씁니다.
     *
     * @param  array<string, mixed>  $payload  JSON 으로 직렬화할 내용
     */
    private function writeInstalledJson(array $payload): void
    {
        File::put($this->vendorPath.'/'.ComposerInstallInfo::INSTALLED_JSON, json_encode($payload));
    }
}
