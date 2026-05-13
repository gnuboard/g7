<?php

namespace Tests\Unit\Extension\Vendor;

use App\Extension\Vendor\Exceptions\VendorInstallException;
use App\Extension\Vendor\VendorBundler;
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
    private function invokeBuild(string $target, bool $force = false, ?string $sourcePath = null, ?string $outputPath = null): \App\Extension\Vendor\VendorBundleResult
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
        $detector = \Mockery::mock(\App\Extension\Vendor\EnvironmentDetector::class);
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
}
