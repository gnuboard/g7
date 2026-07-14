<?php

namespace Tests\Unit\Extension\Vendor;

use App\Extension\Vendor\EnvironmentDetector;
use App\Extension\Vendor\Exceptions\VendorInstallException;
use App\Extension\Vendor\VendorBundler;
use App\Extension\Vendor\VendorBundleResult;
use App\Extension\Vendor\VendorIntegrityChecker;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class VendorBundlerTest extends TestCase
{
    private VendorBundler $bundler;

    private VendorIntegrityChecker $checker;

    private string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checker = new VendorIntegrityChecker;
        $this->bundler = new VendorBundler($this->checker);

        // 테스트는 실제 composer 실행 없이 가짜 vendor/ 구조를 스테이징에 생성.
        // 이는 실제 빌드 흐름(스테이징 → composer install → zip)을 시뮬레이션하되,
        // 외부 네트워크/composer 의존 없이 단위 테스트 속도를 유지하기 위함.
        $this->bundler->setComposerInstallRunner(function (string $stagingDir): void {
            File::ensureDirectoryExists($stagingDir.'/vendor/test/lib');
            File::put($stagingDir.'/vendor/test/lib/file.php', '<?php // test');
            File::put($stagingDir.'/vendor/autoload.php', '<?php // autoload');
        });

        $this->testDir = storage_path('app/test-vendor-bundler-'.uniqid());
        File::ensureDirectoryExists($this->testDir);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->testDir)) {
            File::deleteDirectory($this->testDir);
        }
        parent::tearDown();
    }

    /**
     * 테스트용 composer 프로젝트 구조 생성.
     *
     * 스테이징 기반 빌드에서는 소스 디렉토리에 vendor/ 가 없어도 되고,
     * composer install 은 setUp() 의 주입된 runner 가 대신 처리한다.
     * 따라서 소스에는 composer.json + composer.lock 만 배치한다.
     */
    private function createFakeProject(): void
    {
        File::put($this->testDir.'/composer.json', json_encode([
            'name' => 'test/project',
            // VendorBundler 는 php/ext-* 만 있는 확장을 skip 하므로 외부 의존성 선언 필수
            'require' => ['php' => '^8.2', 'test/lib' => '^1.0'],
        ]));
        File::put($this->testDir.'/composer.lock', json_encode([
            'packages' => [
                ['name' => 'test/lib', 'version' => '1.0.0', 'type' => 'library'],
                ['name' => 'test/other', 'version' => '2.0.0', 'type' => 'library'],
            ],
        ]));
    }

    public function test_build_throws_when_composer_json_missing(): void
    {
        $this->expectException(VendorInstallException::class);

        // buildForCore의 내부 build()에 도달하는 private 테스트는 어려우므로
        // buildForExtension 을 통해 간접적으로 검증
        $this->bundler->buildForExtension('module', 'nonexistent-module-test');
    }

    /**
     * build() private 메서드 호출 헬퍼.
     *
     * 테스트는 단일 testDir 을 소스/출력 동일하게 사용 (코어 케이스와 유사).
     */
    private function invokeBuild(string $target, bool $force = false, ?string $sourcePath = null, ?string $outputPath = null): VendorBundleResult
    {
        $reflection = new \ReflectionClass($this->bundler);
        $method = $reflection->getMethod('build');
        $method->setAccessible(true);

        return $method->invoke(
            $this->bundler,
            $sourcePath ?? $this->testDir,
            $outputPath ?? $this->testDir,
            $target,
            $force,
        );
    }

    public function test_build_creates_zip_and_manifest(): void
    {
        $this->createFakeProject();

        $result = $this->invokeBuild('test:project');

        $this->assertFalse($result->skipped);
        $this->assertFileExists($this->testDir.'/vendor-bundle.zip');
        $this->assertFileExists($this->testDir.'/vendor-bundle.json');
        $this->assertSame(2, $result->packageCount);
        $this->assertGreaterThan(0, $result->zipSize);
    }

    public function test_build_skips_when_up_to_date(): void
    {
        $this->createFakeProject();

        // 1차 빌드
        $first = $this->invokeBuild('test:project');
        $this->assertFalse($first->skipped);

        // 2차 빌드 — composer.json 변경 없음 → 스킵
        $second = $this->invokeBuild('test:project');
        $this->assertTrue($second->skipped);
        $this->assertSame('up-to-date', $second->reason);
    }

    public function test_build_force_rebuilds_even_when_up_to_date(): void
    {
        $this->createFakeProject();

        $this->invokeBuild('test:project');
        $second = $this->invokeBuild('test:project', force: true);

        $this->assertFalse($second->skipped);
    }

    public function test_is_stale_returns_true_when_no_bundle(): void
    {
        $this->createFakeProject();
        $this->assertTrue($this->bundler->isStale($this->testDir));
    }

    public function test_is_stale_returns_false_after_build(): void
    {
        $this->createFakeProject();
        $this->invokeBuild('test:project');

        $this->assertFalse($this->bundler->isStale($this->testDir));
    }

    public function test_is_stale_returns_true_when_composer_json_changed(): void
    {
        $this->createFakeProject();
        $this->invokeBuild('test:project');

        // composer.json 변경
        File::put($this->testDir.'/composer.json', json_encode([
            'name' => 'test/project',
            'require' => ['php' => '^8.2', 'new/pkg' => '^1.0'],
        ]));

        $this->assertTrue($this->bundler->isStale($this->testDir));
    }

    public function test_built_manifest_has_valid_sha256_for_integrity_check(): void
    {
        $this->createFakeProject();
        $this->invokeBuild('test:project');

        $integrity = $this->checker->verify($this->testDir);
        $this->assertTrue($integrity->valid, 'errors: '.implode(',', $integrity->errors));
    }

    /**
     * php/ext-* 런타임 제약만 있는 확장은 skip 처리되고 예외가 발생하지 않아야 합니다.
     */
    public function test_build_skips_when_no_external_dependencies(): void
    {
        // 외부 의존성 없는 composer.json (php 버전만)
        File::put($this->testDir.'/composer.json', json_encode([
            'name' => 'test/no-deps',
            'require' => ['php' => '^8.2'],
        ]));

        $result = $this->invokeBuild('test:no-deps');

        $this->assertTrue($result->skipped);
        $this->assertSame('no-external-dependencies', $result->reason);
        $this->assertFileDoesNotExist($this->testDir.'/vendor-bundle.zip');
    }

    /**
     * ext-* 확장만 요구하는 경우도 외부 의존성 없음으로 간주됩니다.
     */
    public function test_build_skips_when_only_php_extensions_required(): void
    {
        File::put($this->testDir.'/composer.json', json_encode([
            'name' => 'test/ext-only',
            'require' => ['php' => '^8.2', 'ext-json' => '*', 'ext-zip' => '*'],
        ]));

        $result = $this->invokeBuild('test:ext-only');

        $this->assertTrue($result->skipped);
        $this->assertSame('no-external-dependencies', $result->reason);
    }

    /**
     * 분리 구조 — sourcePath 와 outputPath 가 다른 경우 (확장 케이스).
     *
     * _bundled 디렉토리에는 composer.json 만 있고, vendor/ 는 활성 디렉토리에만 있는 상황을 재현.
     */
    public function test_build_writes_output_to_different_path_than_source(): void
    {
        $sourceDir = $this->testDir.'/active';
        $outputDir = $this->testDir.'/bundled';
        File::ensureDirectoryExists($sourceDir);
        File::ensureDirectoryExists($outputDir);

        // 활성 디렉토리 — composer.json + composer.lock (vendor/ 는 스테이징에서 생성)
        File::put($sourceDir.'/composer.json', json_encode([
            'name' => 'test/project',
            'require' => ['php' => '^8.2', 'test/lib' => '^1.0'],
        ]));
        File::put($sourceDir.'/composer.lock', json_encode([
            'packages' => [
                ['name' => 'test/lib', 'version' => '1.0.0', 'type' => 'library'],
            ],
        ]));

        // _bundled 디렉토리 — composer.json 만 (vendor/ 없음)
        File::put($outputDir.'/composer.json', File::get($sourceDir.'/composer.json'));

        $result = $this->invokeBuild('test:split', sourcePath: $sourceDir, outputPath: $outputDir);

        $this->assertFalse($result->skipped);

        // 출력이 outputDir 에만 존재해야 함 (sourceDir 오염 없음)
        $this->assertFileExists($outputDir.'/vendor-bundle.zip');
        $this->assertFileExists($outputDir.'/vendor-bundle.json');
        $this->assertFileDoesNotExist($sourceDir.'/vendor-bundle.zip');
        $this->assertFileDoesNotExist($sourceDir.'/vendor-bundle.json');
    }

    /**
     * 분리 구조에서 manifest 의 composer 해시는 **출력(_bundled)** 의 composer.json 기준이어야 합니다.
     *
     * 런타임 검증(VendorIntegrityChecker::verify)은 manifest 와 같은 디렉토리(_bundled 또는 그 복사본인
     * staging)의 composer.json 을 대조한다. 해시를 활성 디렉토리에서 계산하면, _bundled 의 composer.json
     * 만 변경된 상태(예: 버전 bump 직후)에서 manifest 가 영구히 불일치하여 module:update 가 막힌다.
     */
    public function test_build_hashes_output_composer_json_not_source(): void
    {
        $sourceDir = $this->testDir.'/active';
        $outputDir = $this->testDir.'/bundled';
        File::ensureDirectoryExists($sourceDir);
        File::ensureDirectoryExists($outputDir);

        // 활성 — 구버전 (아직 update 가 반영되지 않은 상태)
        File::put($sourceDir.'/composer.json', json_encode([
            'name' => 'test/project',
            'version' => '1.0.2',
            'require' => ['php' => '^8.2', 'test/lib' => '^1.0'],
        ]));
        File::put($sourceDir.'/composer.lock', json_encode([
            'packages' => [
                ['name' => 'test/lib', 'version' => '1.0.0', 'type' => 'library'],
            ],
        ]));

        // _bundled — 신버전 (작업자가 version bump 한 상태)
        File::put($outputDir.'/composer.json', json_encode([
            'name' => 'test/project',
            'version' => '1.0.3',
            'require' => ['php' => '^8.2', 'test/lib' => '^1.0'],
        ]));

        $this->invokeBuild('test:split-hash', sourcePath: $sourceDir, outputPath: $outputDir);

        $manifest = json_decode(File::get($outputDir.'/vendor-bundle.json'), true);

        $this->assertSame(
            $this->checker->computeFileHash($outputDir.'/composer.json'),
            $manifest['composer_json_sha256'],
            'manifest 의 composer_json_sha256 은 출력(_bundled) 의 composer.json 해시여야 합니다.'
        );

        // 검증자가 outputDir 을 sourceDir 로 verify 할 때 통과해야 한다 (module:update 경로 재현)
        $this->assertTrue(
            $this->checker->verify($outputDir)->valid,
            'build 직후 _bundled 를 verify 하면 무결성 검증을 통과해야 합니다.'
        );
    }

    /**
     * 분리 구조에서 composer install 의 입력(staging 에 복사되는 composer.json/lock)도
     * **출력(_bundled)** 기준이어야 합니다.
     *
     * 개발자는 규정상 `_bundled` 에서만 작업하므로, 새 패키지를 추가하면 `_bundled/composer.json`
     * 과 `_bundled/composer.lock` 에만 반영되고 활성 디렉토리는 구버전으로 남는다. 이때 설치 소스를
     * 활성에서 가져오면 **구버전 lock 으로 install 한 zip** 에 **신버전 해시**를 붙인 manifest 가
     * 만들어져, 무결성 검증은 통과하는데 정작 새 패키지가 번들에 빠지는 상태가 된다
     * (런타임에 클래스 not found). 설치 입력과 해시 기준이 같은 디렉토리를 보아야 한다.
     */
    public function test_build_installs_from_output_composer_lock_not_source(): void
    {
        $sourceDir = $this->testDir.'/active';
        $outputDir = $this->testDir.'/bundled';
        File::ensureDirectoryExists($sourceDir);
        File::ensureDirectoryExists($outputDir);

        // 활성 — 구버전: 패키지 1개 (아직 update 미반영)
        File::put($sourceDir.'/composer.json', json_encode([
            'name' => 'test/project',
            'require' => ['php' => '^8.2', 'test/lib' => '^1.0'],
        ]));
        File::put($sourceDir.'/composer.lock', json_encode([
            'packages' => [
                ['name' => 'test/lib', 'version' => '1.0.0', 'type' => 'library'],
            ],
        ]));

        // _bundled — 신버전: 개발자가 새 패키지(test/newpkg)를 추가한 상태
        File::put($outputDir.'/composer.json', json_encode([
            'name' => 'test/project',
            'require' => ['php' => '^8.2', 'test/lib' => '^1.0', 'test/newpkg' => '^2.0'],
        ]));
        File::put($outputDir.'/composer.lock', json_encode([
            'packages' => [
                ['name' => 'test/lib', 'version' => '1.0.0', 'type' => 'library'],
                ['name' => 'test/newpkg', 'version' => '2.0.0', 'type' => 'library'],
            ],
        ]));

        $this->invokeBuild('test:new-package', sourcePath: $sourceDir, outputPath: $outputDir);

        $manifest = json_decode(File::get($outputDir.'/vendor-bundle.json'), true);
        $names = array_column($manifest['packages'], 'name');

        // manifest 의 packages 는 staging 에 복사된 lock 에서 추출되므로,
        // 이 단언이 곧 "어느 lock 으로 install 했는가" 를 판정한다.
        $this->assertContains(
            'test/newpkg',
            $names,
            '_bundled 에 추가한 새 패키지가 번들에 포함되어야 합니다 (설치 소스가 활성이면 누락됨).'
        );
        $this->assertSame(
            2,
            $manifest['package_count'],
            'package_count 는 _bundled/composer.lock 기준(2)이어야 합니다.'
        );

        // 해시 기준과 설치 입력이 같은 디렉토리를 보므로 무결성도 정합해야 한다.
        $this->assertTrue($this->checker->verify($outputDir)->valid);
    }

    /**
     * 출력 디렉토리에 composer.lock 이 없으면 소스(활성) 의 lock 으로 폴백해야 합니다.
     *
     * 코어는 source == output 이므로 항상 같은 파일이고, 확장도 _bundled 에 lock 이 없는
     * 과도기 상태에서 빌드가 깨지지 않아야 한다 (resolveHashTarget 과 동일한 폴백 규칙).
     */
    public function test_build_falls_back_to_source_lock_when_output_lock_missing(): void
    {
        $sourceDir = $this->testDir.'/active';
        $outputDir = $this->testDir.'/bundled';
        File::ensureDirectoryExists($sourceDir);
        File::ensureDirectoryExists($outputDir);

        File::put($sourceDir.'/composer.json', json_encode([
            'name' => 'test/project',
            'require' => ['php' => '^8.2', 'test/lib' => '^1.0'],
        ]));
        File::put($sourceDir.'/composer.lock', json_encode([
            'packages' => [
                ['name' => 'test/lib', 'version' => '1.0.0', 'type' => 'library'],
            ],
        ]));

        // _bundled 에 composer.json 만 있고 lock 은 없음
        File::put($outputDir.'/composer.json', File::get($sourceDir.'/composer.json'));

        $this->invokeBuild('test:lock-fallback', sourcePath: $sourceDir, outputPath: $outputDir);

        $manifest = json_decode(File::get($outputDir.'/vendor-bundle.json'), true);
        $names = array_column($manifest['packages'], 'name');

        $this->assertContains('test/lib', $names, '출력에 lock 이 없으면 소스 lock 으로 폴백해야 합니다.');
    }

    /**
     * 분리 구조에서 isStale 은 **출력(_bundled)** 의 composer.json 변경을 감지해야 합니다.
     *
     * 활성 디렉토리만 검사하면 _bundled 의 stale 을 up-to-date 로 오보하여, --check 가 재빌드 필요를
     * 놓치고 이후 module:update 가 무결성 오류로 실패한다.
     */
    public function test_is_stale_detects_output_composer_json_change(): void
    {
        $sourceDir = $this->testDir.'/active';
        $outputDir = $this->testDir.'/bundled';
        File::ensureDirectoryExists($sourceDir);
        File::ensureDirectoryExists($outputDir);

        $composerJson = json_encode([
            'name' => 'test/project',
            'version' => '1.0.2',
            'require' => ['php' => '^8.2', 'test/lib' => '^1.0'],
        ]);
        File::put($sourceDir.'/composer.json', $composerJson);
        File::put($sourceDir.'/composer.lock', json_encode([
            'packages' => [
                ['name' => 'test/lib', 'version' => '1.0.0', 'type' => 'library'],
            ],
        ]));
        File::put($outputDir.'/composer.json', $composerJson);

        $this->invokeBuild('test:split-stale', sourcePath: $sourceDir, outputPath: $outputDir);
        $this->assertFalse(
            $this->bundler->isStale($sourceDir, $outputDir),
            '빌드 직후에는 stale 이 아니어야 합니다.'
        );

        // _bundled 의 composer.json 만 변경 (활성은 그대로) — 버전 bump 시나리오
        File::put($outputDir.'/composer.json', json_encode([
            'name' => 'test/project',
            'version' => '1.0.3',
            'require' => ['php' => '^8.2', 'test/lib' => '^1.0'],
        ]));

        $this->assertTrue(
            $this->bundler->isStale($sourceDir, $outputDir),
            '_bundled 의 composer.json 이 변경되면 stale 로 판정해야 합니다.'
        );
    }

    /**
     * 재빌드가 실패하면 기존 번들 파일이 보존되어야 합니다.
     *
     * 기존 zip/manifest 를 staging 빌드 이전에 삭제하면, composer install 실패 시
     * 새 파일이 생성되지 못한 채 원본만 유실되어 다음 module:update 가 번들 부재로
     * 실패한다 (Windows 등에서 composer 실행이 불안정할 때 재현). 삭제는 재생성
     * 성공이 확정된 뒤에 이뤄져야 한다.
     */
    public function test_failed_rebuild_preserves_existing_bundle(): void
    {
        $this->createFakeProject();

        // 1차 빌드 성공 — 정상 번들 생성
        $this->invokeBuild('test:project');
        $this->assertFileExists($this->testDir.'/vendor-bundle.zip');
        $this->assertFileExists($this->testDir.'/vendor-bundle.json');

        $originalZip = File::get($this->testDir.'/vendor-bundle.zip');
        $originalManifest = File::get($this->testDir.'/vendor-bundle.json');

        // composer install 이 실패하도록 러너 교체
        $this->bundler->setComposerInstallRunner(function (): void {
            throw new VendorInstallException(
                errorKey: 'bundle_build_composer_failed',
                context: ['exit' => 1, 'message' => 'simulated composer failure'],
            );
        });

        // 2차 빌드(force) — 실패해야 하지만 기존 번들은 보존되어야 함
        try {
            $this->invokeBuild('test:project', force: true);
            $this->fail('composer install 실패 시 빌드는 예외를 던져야 합니다.');
        } catch (VendorInstallException) {
            // 예상된 실패
        }

        $this->assertFileExists(
            $this->testDir.'/vendor-bundle.zip',
            '재빌드 실패 시 기존 zip 이 보존되어야 합니다.'
        );
        $this->assertFileExists(
            $this->testDir.'/vendor-bundle.json',
            '재빌드 실패 시 기존 manifest 가 보존되어야 합니다.'
        );
        $this->assertSame(
            $originalZip,
            File::get($this->testDir.'/vendor-bundle.zip'),
            '보존된 zip 은 원본과 동일해야 합니다.'
        );
        $this->assertSame(
            $originalManifest,
            File::get($this->testDir.'/vendor-bundle.json'),
            '보존된 manifest 는 원본과 동일해야 합니다.'
        );
    }

    /**
     * 외부 의존성 없는 확장은 isStale 이 항상 false 를 반환해야 합니다.
     */
    public function test_is_stale_returns_false_when_no_external_dependencies(): void
    {
        File::put($this->testDir.'/composer.json', json_encode([
            'name' => 'test/no-deps',
            'require' => ['php' => '^8.2'],
        ]));

        $this->assertFalse($this->bundler->isStale($this->testDir));
    }

    /**
     * 스테이징 기반 빌드 — 개발 vendor/ 가 dev 의존성을 포함해도 번들은 깨끗함.
     *
     * 회귀 방지: autoload_files.php 가 존재하지 않는 dev 패키지를 require 하는 버그
     * (244 브랜치 shared hosting 실전 테스트에서 발견된 이슈).
     *
     * 주입된 runner 는 "clean --no-dev vendor/" 를 시뮬레이션하여 번들 내용이
     * 스테이징 vendor/ 와 일치하는지 검증한다.
     */
    public function test_build_uses_staging_vendor_not_source_vendor(): void
    {
        $this->createFakeProject();

        // 소스 디렉토리에 dev 파일/디렉토리를 생성 — 번들에 포함되지 않아야 함
        File::ensureDirectoryExists($this->testDir.'/vendor/fakerphp/faker');
        File::put($this->testDir.'/vendor/fakerphp/faker/README.md', '# Faker dev package');
        File::ensureDirectoryExists($this->testDir.'/vendor/myclabs/deep-copy');
        File::put($this->testDir.'/vendor/myclabs/deep-copy/deep_copy.php', '<?php');

        $this->invokeBuild('test:staging');

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($this->testDir.'/vendor-bundle.zip') === true);
        $fileList = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $fileList[] = $zip->getNameIndex($i);
        }
        $zip->close();

        // 스테이징 runner 가 생성한 test/lib 만 포함되어야 함
        $this->assertTrue(
            collect($fileList)->contains(fn ($f) => str_contains($f, 'vendor/test/lib')),
            'runner 가 생성한 test/lib 은 포함되어야 합니다'
        );

        // 소스의 dev 디렉토리는 번들에 절대 포함되지 않아야 함
        $this->assertFalse(
            collect($fileList)->contains(fn ($f) => str_contains($f, 'fakerphp')),
            '소스의 dev 패키지 fakerphp 는 번들에 포함되면 안 됩니다 (스테이징이 아닌 소스 사용 금지)'
        );
        $this->assertFalse(
            collect($fileList)->contains(fn ($f) => str_contains($f, 'myclabs')),
            '소스의 dev 패키지 myclabs 는 번들에 포함되면 안 됩니다'
        );
    }

    /**
     * composer 미설치 환경에서 명확한 예외 발생.
     *
     * EnvironmentDetector 가 composer 미실행으로 보고하도록 mock 을 주입하고,
     * composerInstallRunner 도 null 로 초기화한 뒤 빌드 시도.
     */
    public function test_build_throws_when_composer_not_available(): void
    {
        $detector = \Mockery::mock(EnvironmentDetector::class);
        $detector->shouldReceive('canExecuteComposer')->andReturn(false);

        $bundler = new VendorBundler($this->checker, $detector);
        // 기본 runner (proc_open) 사용하도록 override 안 함

        $this->createFakeProject();

        $this->expectException(VendorInstallException::class);

        $reflection = new \ReflectionClass($bundler);
        $method = $reflection->getMethod('build');
        $method->setAccessible(true);
        $method->invoke($bundler, $this->testDir, $this->testDir, 'test:no-composer', false);
    }

    /**
     * composer install 의 stdout/stderr 는 파이프가 아닌 파일 descriptor 로 열려야 한다.
     *
     * (Windows 회귀 가드) stdout/stderr 를 파이프로 열면 composer autoload dump 단계의
     * async `cmd.exe` 손자 프로세스가 부모 파이프 핸들을 상속·점유해 proc_close 가
     * 자식 완료 신호를 받지 못하고 무한 대기(행)한다. 실측: 파이프=행, 파일=140s 완주.
     * descriptor 구조를 파이프로 되돌리면 이 테스트가 red 로 회귀를 차단한다.
     */
    public function test_composer_output_descriptors_use_files_not_pipes(): void
    {
        $descriptors = VendorBundler::composerOutputDescriptors('/tmp/out.log', '/tmp/err.log');

        // stdin 은 파이프(입력 없음 즉시 닫힘) 로 유지.
        $this->assertSame(['pipe', 'r'], $descriptors[0]);

        // stdout/stderr 는 파일 descriptor 여야 한다 (파이프 금지 — 손자 핸들 상속 차단).
        $this->assertSame('file', $descriptors[1][0], 'stdout 은 파일 descriptor 여야 함 (파이프 시 Windows 행 회귀)');
        $this->assertSame('/tmp/out.log', $descriptors[1][1]);
        $this->assertSame('w', $descriptors[1][2]);

        $this->assertSame('file', $descriptors[2][0], 'stderr 은 파일 descriptor 여야 함 (파이프 시 Windows 행 회귀)');
        $this->assertSame('/tmp/err.log', $descriptors[2][1]);
        $this->assertSame('w', $descriptors[2][2]);

        // 어떤 채널도 'pipe','w' 로 열리지 않아야 함 (손자 상속 가능한 열린 파이프 부재).
        $this->assertNotSame('pipe', $descriptors[1][0]);
        $this->assertNotSame('pipe', $descriptors[2][0]);
    }
}
