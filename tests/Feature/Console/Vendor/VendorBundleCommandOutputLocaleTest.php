<?php

namespace Tests\Feature\Console\Vendor;

use App\Extension\Vendor\VendorBundler;
use App\Extension\Vendor\VendorIntegrityChecker;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * vendor 번들 빌드·검증 명령의 머리말·결과 줄·요약이 사이트 언어를 따르는지 잠근다.
 *
 * 공용 트레이트(RunsVendorBundleAction)를 쓰는 core:/module:/plugin:/custom: vendor-bundle·vendor-verify
 * 와 vendor-bundle:build-all·verify-all 이 같은 줄을 낸다. 여기서는 실제 확장을 건드리지 않도록
 * 고유 식별자의 가짜 모듈 하나로 module:vendor-bundle·module:vendor-verify 를 돌린다.
 *
 * DB 를 쓰지 않으므로 트랜잭션 트레이트가 필요 없다.
 */
class VendorBundleCommandOutputLocaleTest extends TestCase
{
    /** 번역 키 접두어 */
    private const KEY = 'extensions.commands.vendor_bundle_action.';

    /** 명령이 새로 쓰는 번역 키 */
    private const NEW_KEYS = [
        'title_build',
        'title_check',
        'title_verify',
        'built',
        'skipped_up_to_date',
        'up_to_date',
        'verify_ok',
        'verify_failed_target',
        'summary',
    ];

    /** 수정 전 코드에 박혀 있던 영어 고정 문구 */
    private const HARDCODED_ENGLISH = [
        'Vendor Bundle Build',
        'Vendor Bundle Status Check',
        'Vendor Bundle Integrity Verification',
        'Summary:',
        ' built, ',
        ' packages',
        'up-to-date',
        ': OK (',
        ': FAILED',
    ];

    private string $identifier;

    private string $activePath;

    private string $bundledPath;

    /**
     * 가짜 모듈(활성 + _bundled)을 만들고 composer 실행을 가짜 vendor 생성으로 바꾼다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->identifier = 'test-vendor-locale-mod-'.uniqid();
        $this->activePath = base_path('modules/'.$this->identifier);
        $this->bundledPath = base_path('modules/_bundled/'.$this->identifier);

        $composerJson = json_encode([
            'name' => 'test/fake-locale-module',
            'require' => ['php' => '^8.2', 'test/lib' => '^1.0'],
        ]);

        File::ensureDirectoryExists($this->activePath.'/vendor/test/lib');
        File::put($this->activePath.'/composer.json', $composerJson);
        File::put($this->activePath.'/composer.lock', json_encode([
            'packages' => [['name' => 'test/lib', 'version' => '1.0.0', 'type' => 'library']],
        ]));
        File::put($this->activePath.'/vendor/autoload.php', '<?php // autoload');
        File::put($this->activePath.'/vendor/test/lib/file.php', '<?php // test');

        File::ensureDirectoryExists($this->bundledPath);
        File::put($this->bundledPath.'/composer.json', $composerJson);

        $this->app->bind(VendorBundler::class, function ($app) {
            $bundler = new VendorBundler($app->make(VendorIntegrityChecker::class));
            $bundler->setComposerInstallRunner(function (string $stagingDir): void {
                File::ensureDirectoryExists($stagingDir.'/vendor/test/lib');
                File::put($stagingDir.'/vendor/test/lib/file.php', '<?php // test');
                File::put($stagingDir.'/vendor/autoload.php', '<?php // autoload');
            });

            return $bundler;
        });

        $this->app->setLocale('ko');
    }

    /**
     * 가짜 모듈 디렉토리를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ([$this->activePath, $this->bundledPath] as $path) {
            if (File::isDirectory($path)) {
                File::deleteDirectory($path);
            }
        }

        parent::tearDown();
    }

    /**
     * 명령이 쓰는 새 키가 ko·en 원본 언어 파일에 있는지 확인한다(없으면 출력에 키 문자열이 그대로 찍힌다).
     *
     * @scenario command=module_bundle
     *
     * @effects vendor_command_output_follows_site_locale
     *
     * @return void 반환값 없음
     */
    public function test_new_output_keys_exist_in_ko_and_en(): void
    {
        foreach (['ko', 'en'] as $locale) {
            foreach (self::NEW_KEYS as $key) {
                $this->assertNotSame(
                    self::KEY.$key,
                    trans(self::KEY.$key, [], $locale),
                    "lang/{$locale}/extensions.php 에 commands.vendor_bundle_action.{$key} 가 없다"
                );
            }
        }
    }

    /**
     * 사이트 언어 ko 에서 빌드 출력의 머리말·결과 줄·요약이 한국어로 나오고 영어 고정 문구가 없는지 검증한다.
     *
     * @scenario command=module_bundle
     *
     * @effects vendor_command_output_follows_site_locale
     *
     * @return void 반환값 없음
     */
    public function test_build_output_is_localized_in_ko(): void
    {
        $exitCode = Artisan::call('module:vendor-bundle', ['identifier' => $this->identifier]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString(__(self::KEY.'title_build'), $output);
        $this->assertStringContainsString('✓ '.__(self::KEY.'built', [
            'label' => 'modules/'.$this->identifier,
            'size' => $this->bundleSizeLabel(),
            'count' => 1,
        ]), $output);
        $this->assertStringContainsString(__(self::KEY.'summary', [
            'built' => 1,
            'skipped' => 0,
            'size' => $this->bundleSizeLabel(),
        ]), $output);
        $this->assertNoHardcodedEnglish($output);
    }

    /**
     * 작은 묶음의 요약 크기가 `0.0 MB` 로 뭉개지지 않고 KB·B 단위로 나오는지 검증한다.
     *
     * @scenario command=module_bundle
     *
     * @effects vendor_command_output_follows_site_locale
     *
     * @return void 반환값 없음
     */
    public function test_build_summary_size_uses_readable_unit_for_small_bundle(): void
    {
        Artisan::call('module:vendor-bundle', ['identifier' => $this->identifier]);
        $output = Artisan::output();

        $this->assertLessThan(1024 * 1024, filesize($this->bundledPath.'/vendor-bundle.zip'));
        $this->assertStringNotContainsString('0.0 MB', $output);
        $this->assertMatchesRegularExpression('/\d+\.\d (B|KB)\b/', $output);
    }

    /**
     * 사이트 언어 ko 에서 이미 최신이라 다시 빌드하지 않을 때의 스킵 줄에 사유 코드(`up-to-date`)가 그대로 찍히지 않는지 검증한다.
     *
     * @scenario command=module_bundle
     *
     * @effects vendor_command_output_follows_site_locale
     *
     * @return void 반환값 없음
     */
    public function test_rebuild_skip_output_is_localized_in_ko(): void
    {
        $this->assertSame(0, Artisan::call('module:vendor-bundle', ['identifier' => $this->identifier]));

        $exitCode = Artisan::call('module:vendor-bundle', ['identifier' => $this->identifier]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('○ '.__(self::KEY.'skipped_up_to_date', [
            'label' => 'modules/'.$this->identifier,
            'size' => $this->bundleSizeLabel(),
            'count' => 1,
        ]), $output);
        $this->assertStringContainsString(__(self::KEY.'summary', [
            'built' => 0,
            'skipped' => 1,
            'size' => $this->bundleSizeLabel(),
        ]), $output);
        $this->assertNoHardcodedEnglish($output);
    }

    /**
     * 사이트 언어 ko 에서 --check 출력의 머리말과 최신 판정 줄이 한국어인지 검증한다.
     *
     * @scenario command=module_bundle
     *
     * @effects vendor_command_output_follows_site_locale
     *
     * @return void 반환값 없음
     */
    public function test_check_output_is_localized_in_ko(): void
    {
        $this->assertSame(0, Artisan::call('module:vendor-bundle', ['identifier' => $this->identifier]));

        $exitCode = Artisan::call('module:vendor-bundle', ['identifier' => $this->identifier, '--check' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString(__(self::KEY.'title_check'), $output);
        $this->assertStringContainsString(
            '✓ '.__(self::KEY.'up_to_date', ['label' => 'modules/'.$this->identifier]),
            $output
        );
        $this->assertNoHardcodedEnglish($output);
    }

    /**
     * 사이트 언어 ko 에서 무결성 검증 통과 출력의 머리말과 결과 줄이 한국어인지 검증한다.
     *
     * @scenario command=module_verify
     *
     * @effects vendor_command_output_follows_site_locale
     *
     * @return void 반환값 없음
     */
    public function test_verify_ok_output_is_localized_in_ko(): void
    {
        $this->assertSame(0, Artisan::call('module:vendor-bundle', ['identifier' => $this->identifier]));

        $exitCode = Artisan::call('module:vendor-verify', ['identifier' => $this->identifier]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString(__(self::KEY.'title_verify'), $output);
        $this->assertStringContainsString('✓ '.__(self::KEY.'verify_ok', [
            'label' => 'modules/'.$this->identifier,
            'size' => $this->bundleSizeLabel(),
            'count' => 1,
        ]), $output);
        $this->assertStringNotContainsString('0.0 MB', $output);
        $this->assertNoHardcodedEnglish($output);
    }

    /**
     * 사이트 언어 ko 에서 무결성 검증 실패 줄이 한국어인지 검증한다.
     *
     * @scenario command=module_verify
     *
     * @effects vendor_command_output_follows_site_locale
     *
     * @return void 반환값 없음
     */
    public function test_verify_failed_output_is_localized_in_ko(): void
    {
        $this->assertSame(0, Artisan::call('module:vendor-bundle', ['identifier' => $this->identifier]));
        file_put_contents($this->bundledPath.'/vendor-bundle.zip', 'tampered content');

        $exitCode = Artisan::call('module:vendor-verify', ['identifier' => $this->identifier]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString(
            '✗ '.__(self::KEY.'verify_failed_target', ['label' => 'modules/'.$this->identifier]),
            $output
        );
        $this->assertNoHardcodedEnglish($output);
    }

    /**
     * 출력에 수정 전 영어 고정 문구가 하나도 없는지 단언한다.
     *
     * @param  string  $output  명령 출력
     * @return void 반환값 없음
     */
    private function assertNoHardcodedEnglish(string $output): void
    {
        foreach (self::HARDCODED_ENGLISH as $english) {
            $this->assertStringNotContainsString($english, $output, "영어 고정 문구 「{$english}」 가 출력에 남아 있다");
        }
    }

    /**
     * 빌드된 가짜 묶음 zip 크기의 표기(B·KB·MB 자동 단위).
     *
     * @return string 크기 표기
     */
    private function bundleSizeLabel(): string
    {
        $size = (float) filesize($this->bundledPath.'/vendor-bundle.zip');
        $units = ['B', 'KB', 'MB', 'GB'];
        $unit = 0;
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return sprintf('%.1f %s', $size, $units[$unit]);
    }
}
