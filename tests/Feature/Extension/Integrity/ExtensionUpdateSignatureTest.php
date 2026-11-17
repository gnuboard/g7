<?php

namespace Tests\Feature\Extension\Integrity;

use App\Exceptions\PackageIntegrityException;
use App\Extension\ExtensionManager;
use App\Extension\Integrity\PackageIntegrityOptions;
use App\Extension\Integrity\PackageVerification;
use App\Extension\Integrity\PackageVerificationStatus;
use App\Extension\Integrity\UnsignedPackagePolicy;
use App\Extension\ModuleManager;
use App\Models\Module;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\Concerns\PackageSignatureFixtures;
use Tests\TestCase;

/**
 * 확장 업데이트(GitHub·`--zip`)의 패키지 서명 검증 (#916 ④, D17·D18·D36)
 *
 * - `ExtensionManager::acquireGithubUpdate()`: 태그 릴리스의 서명 자산을 받아 검증하고 풀어 둔다. 자산이 없으면 zipball 을 받기
 *   **전에** 미서명 정책을 묻고(409), 확인하면 태그 zipball. 태그가 없으면 「릴리스를 찾지 못함」(브랜치로 물러서지 않음).
 * - `prepareVerifiedZipSource()`: `--zip` 옆 `.sig.json` 자동 인식·`--signature` 경로·manifest 대조.
 * - Artisan `module:update`: 답할 수 없는 실행은 `--allow-unsigned` 없이 exit 1(`--force` 로 통과 안 함), 대화형은 묻고 다시 실행.
 */
class ExtensionUpdateSignatureTest extends TestCase
{
    use PackageSignatureFixtures;
    use RefreshDatabase;

    /**
     * 스키마를 다시 만들지 않고 트랜잭션으로 격리합니다.
     *
     * @return void 반환값 없음
     */
    public function refreshDatabase(): void
    {
        $this->beginDatabaseTransaction();
    }

    /**
     * 신뢰 키를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->trustVendorKey($this->fixtureKey('glitter'), ['glitter']);
        Http::preventStrayRequests();
    }

    /**
     * 시험 파일을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->cleanupPackageFixtures();
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 서명 자산이 있는 태그 릴리스는 자산을 받아 verified 로 풀어 두고 zipball 을 받지 않는다.
     *
     * @scenario case=update_github_signed_asset
     *
     * @effects github_update_downloads_signed_asset_and_verifies_before_backup
     *
     * @return void 반환값 없음
     */
    public function test_github_update_uses_signed_asset(): void
    {
        $this->fakeRelease(signed: true);

        $acquired = app(ExtensionManager::class)->acquireGithubUpdate('module', 'glitter-upd', 'https://github.com/glitter/upd', '1.1.0', 'module.json', PackageIntegrityOptions::none());

        try {
            $this->assertSame(PackageVerificationStatus::Verified, $acquired['verification']->status);
            $this->assertFileExists($acquired['extracted_dir'].'/module.json');
            Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/zipball/'));
        } finally {
            File::deleteDirectory($acquired['temp_dir']);
        }
    }

    /**
     * 서명 자산이 없으면 zipball 을 받기 전에 409(계속 가능), 확인하면 태그 zipball 을 받아 unsigned 로 풀어 둔다.
     *
     * @scenario case=update_github_unsigned
     *
     * @effects github_update_unsigned_asks_before_zipball_download
     *
     * @return void 반환값 없음
     */
    public function test_github_update_without_assets_asks_before_zipball(): void
    {
        $this->fakeRelease(signed: false);
        $manager = app(ExtensionManager::class);

        try {
            $manager->acquireGithubUpdate('module', 'glitter-upd', 'https://github.com/glitter/upd', '1.1.0', 'module.json', PackageIntegrityOptions::none());
            $this->fail('서명 자산이 없는데 확인 없이 받았다');
        } catch (PackageIntegrityException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertTrue($e->canContinue);
        }
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/zipball/'));

        $acquired = $manager->acquireGithubUpdate('module', 'glitter-upd', 'https://github.com/glitter/upd', '1.1.0', 'module.json', new PackageIntegrityOptions(true));
        try {
            $this->assertSame(PackageVerificationStatus::Unsigned, $acquired['verification']->status);
            $this->assertFileExists($acquired['extracted_dir'].'/module.json');
            Http::assertSent(fn (Request $r) => str_contains($r->url(), '/zipball/v1.1.0'));
        } finally {
            File::deleteDirectory($acquired['temp_dir']);
        }
    }

    /**
     * 태그가 없으면 「릴리스를 찾지 못함」이고, 서명이 다른 판을 가리키면 422 다.
     *
     * @scenario case=update_github_missing_or_mismatch
     *
     * @effects github_update_missing_release_or_version_mismatch_rejected
     *
     * @return void 반환값 없음
     */
    public function test_github_update_missing_tag_and_version_mismatch(): void
    {
        $this->fakeRelease(signed: true, signedVersion: '1.0.9');

        try {
            app(ExtensionManager::class)->acquireGithubUpdate('module', 'glitter-upd', 'https://github.com/glitter/upd', '1.1.0', 'module.json', new PackageIntegrityOptions(true));
            $this->fail('다른 판의 서명이 통과했다');
        } catch (PackageIntegrityException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('version_mismatch', $e->verification->reason);
        }

        try {
            app(ExtensionManager::class)->acquireGithubUpdate('module', 'glitter-upd', 'https://github.com/glitter/upd', '9.9.9', 'module.json', new PackageIntegrityOptions(true));
            $this->fail('없는 태그를 받았다');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(PackageIntegrityException::class, $e);
            $this->assertSame(__('settings.core_update.extension_release_not_found', ['repository' => 'glitter/upd', 'version' => '9.9.9']), $e->getMessage());
        }
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/archive/refs/heads/'));
    }

    /**
     * `--zip` 업데이트 — 옆 `.sig.json` 자동 인식, `--signature` 경로, 서명 없으면 409, manifest 식별자 불일치는 422.
     *
     * @scenario case=update_zip_signature
     *
     * @effects zip_update_auto_detects_sidecar_signature
     *
     * @return void 반환값 없음
     */
    public function test_zip_update_sidecar_and_signature_option(): void
    {
        $key = $this->fixtureKey('glitter');
        $manager = app(ExtensionManager::class);

        $zip = $this->makePackageZip('module', 'glitter-upd', '1.1.0');
        $this->signPackage($zip, $key, 'module', 'glitter-upd', '1.1.0');
        $prepared = $manager->prepareVerifiedZipSource('module', $zip, 'glitter-upd', 'module.json', PackageIntegrityOptions::none());
        $this->assertSame(PackageVerificationStatus::Verified, $prepared['verification']->status);
        $this->assertSame('1.1.0', $prepared['to_version']);
        File::deleteDirectory($prepared['temp_dir']);

        // 서명 파일을 다른 곳에 두고 경로로 지정
        $moved = $this->packageFixtureDir().'/elsewhere.sig.json';
        File::move($zip.'.sig.json', $moved);
        try {
            $manager->prepareVerifiedZipSource('module', $zip, 'glitter-upd', 'module.json', PackageIntegrityOptions::none());
            $this->fail('서명 파일 없이 확인 없이 진행했다');
        } catch (PackageIntegrityException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $prepared = $manager->prepareVerifiedZipSource('module', $zip, 'glitter-upd', 'module.json', new PackageIntegrityOptions(false, $moved));
        $this->assertSame(PackageVerificationStatus::Verified, $prepared['verification']->status);
        File::deleteDirectory($prepared['temp_dir']);

        // 서명은 glitter-upd 인데 manifest 는 glitter-evil
        $evil = $this->makePackageZip('module', 'glitter-evil', '1.1.0', [], [], 'glitter-upd-1.1.0-evil.zip');
        $this->signPackage($evil, $key, 'module', 'glitter-upd', '1.1.0');
        try {
            $manager->prepareVerifiedZipSource('module', $evil, 'glitter-upd', 'module.json', PackageIntegrityOptions::none());
            $this->fail('manifest 가 다른데 통과했다');
        } catch (\RuntimeException $e) {
            // prepareZipSource 의 식별자 확인이 먼저 막거나, 서명 대조가 막는다 — 어느 쪽이든 설치 원본은 남지 않는다
            $this->assertTrue($e instanceof PackageIntegrityException || str_contains($e->getMessage(), 'glitter-evil'));
        }
    }

    /**
     * Artisan `module:update` — 비대화형은 `--force` 가 있어도 `--allow-unsigned` 없이 exit 1, 옵션이 있으면 진행, 대화형은 묻고 다시 실행.
     *
     * @scenario case=update_command_unsigned_noninteractive
     *
     * @effects noninteractive_unsigned_update_exits_1_and_force_does_not_bypass
     *
     * @return void 반환값 없음
     */
    public function test_update_command_unsigned_flow(): void
    {
        Module::factory()->create(['identifier' => 'glitter-upd', 'version' => '1.0.0', 'status' => 'active']);
        $zip = $this->makePackageZip('module', 'glitter-upd', '1.1.0');
        $calls = [];

        $manager = Mockery::mock(ModuleManager::class)->shouldIgnoreMissing();
        $manager->shouldReceive('updateModule')->andReturnUsing(function (...$args) use (&$calls) {
            /** @var PackageIntegrityOptions $integrity */
            $integrity = $args[8];
            $calls[] = $integrity->allowUnsigned;
            app(UnsignedPackagePolicy::class)->decide(PackageVerification::unsigned(), $integrity->allowUnsigned);

            return ['success' => true, 'from_version' => '1.0.0', 'to_version' => '1.1.0', 'integrity' => PackageVerification::unsigned()->toArray()];
        });
        $this->app->instance(ModuleManager::class, $manager);

        $this->artisan('module:update', ['identifier' => 'glitter-upd', '--zip' => $zip, '--force' => true, '--no-interaction' => true])
            ->expectsOutputToContain('--allow-unsigned')
            ->assertExitCode(1);
        $this->assertSame([false], $calls, '--force 가 미서명 확인을 대신했다');

        $calls = [];
        $this->artisan('module:update', ['identifier' => 'glitter-upd', '--zip' => $zip, '--force' => true, '--no-interaction' => true, '--allow-unsigned' => true])
            ->assertExitCode(0);
        $this->assertSame([true], $calls);

        $calls = [];
        $this->artisan('module:update', ['identifier' => 'glitter-upd', '--zip' => $zip, '--force' => true])
            ->expectsQuestion(__('extension_integrity.commands.unsigned_confirm', ['reason' => __('extension_integrity.reasons.unsigned')]).' (yes/no) [no]', 'yes')
            ->assertExitCode(0);
        $this->assertSame([false, true], $calls);

        $this->artisan('module:update', ['identifier' => 'glitter-upd', '--zip' => $zip, '--signature' => $this->packageFixtureDir().'/missing.sig.json'])
            ->assertExitCode(1);
    }

    /**
     * 가짜 GitHub 릴리스를 등록합니다 (glitter/upd v1.1.0).
     *
     * @param  bool  $signed  서명 자산을 올릴지
     * @param  string  $signedVersion  서명 파일이 말하는 버전
     * @return void 반환값 없음
     */
    private function fakeRelease(bool $signed, string $signedVersion = '1.1.0'): void
    {
        $key = $this->fixtureKey('glitter');
        $zip = $this->makePackageZip('module', 'glitter-upd', '1.1.0');
        $sig = $this->signPackage($zip, $key, 'module', 'glitter-upd', $signedVersion);
        $zipball = File::get($this->makePackageZip('module', 'glitter-upd', '1.1.0', [], [], 'zipball.zip'));
        $assets = $signed ? [
            ['name' => basename($zip), 'size' => filesize($zip), 'url' => 'https://api.github.com/repos/glitter/upd/releases/assets/1'],
            ['name' => basename($sig), 'size' => filesize($sig), 'url' => 'https://api.github.com/repos/glitter/upd/releases/assets/2'],
        ] : [];

        Http::fake(function (Request $request) use ($assets, $zip, $sig, $zipball) {
            $url = $request->url();

            return match (true) {
                str_ends_with($url, '/repos/glitter/upd/releases/tags/v1.1.0') => Http::response(['tag_name' => 'v1.1.0', 'assets' => $assets], 200),
                str_ends_with($url, '/repos/glitter/upd/git/refs/tags/v1.1.0') => Http::response(['ref' => 'refs/tags/v1.1.0'], 200),
                str_ends_with($url, '/releases/assets/1') => Http::response(File::get($zip), 200),
                str_ends_with($url, '/releases/assets/2') => Http::response(File::get($sig), 200),
                str_ends_with($url, '/zipball/v1.1.0') => Http::response($zipball, 200),
                default => Http::response(['message' => 'Not Found'], 404),
            };
        });
    }
}
