<?php

namespace Tests\Feature\Extension\Integrity;

use App\CoreUpdate\CoreUpdateOptions;
use App\CoreUpdate\CoreUpdateRunState;
use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Exceptions\PackageIntegrityException;
use App\Extension\Integrity\PackageIntegrityOptions;
use App\Extension\Integrity\PackageVerificationStatus;
use App\Extension\Integrity\UnsignedPackagePolicy;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CoreUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\PackageSignatureFixtures;
use Tests\TestCase;

/**
 * 코어 업데이트의 패키지 서명 검증 — CLI `downloadUpdate` 와 웹 `fetchVerifiedReleaseArchive`·착수 둘 다
 *
 * 코어는 **내장 키로만** 검증한다 — 제작자 키로 서명한 코어는 미신뢰(422). 서명 자산이 없으면 미서명 정책(409 → 확인 시
 * 태그 zipball). 업데이트 확인 응답에 `signature_available`.
 */
class CoreUpdateSignatureTest extends TestCase
{
    use PackageSignatureFixtures;
    use RefreshDatabase;

    /**
     * 만든 격리 디렉토리
     *
     * @var array<int, string>
     */
    private array $pendingPaths = [];

    /**
     * 가짜 릴리스 응답·자산 바이트 (Http::fake 콜백이 읽는다)
     *
     * @var array<string, mixed>
     */
    private array $fakeRelease = [];

    /**
     * 가짜 ZIP 자산 바이트
     *
     * @var string
     */
    private string $fakeZipBytes = '';

    /**
     * 가짜 서명 자산 바이트
     *
     * @var string
     */
    private string $fakeSigBytes = '';

    /**
     * 가짜 응답을 이미 등록했는지
     *
     * @var bool
     */
    private bool $fakeRegistered = false;

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
     * 저장소 주소·격리 경로를 시험용으로 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.update.github_url', 'https://github.com/sirsoft-test/g7');
        Config::set('app.update.github_token', '');
        Config::set('app.update.pending_path', $this->packageFixtureDir().DIRECTORY_SEPARATOR.'core_pending');
        Http::preventStrayRequests();
        $this->clearRunState();
    }

    /**
     * 격리 디렉토리·실행 상태·시험 파일을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearRunState();
        $this->cleanupPackageFixtures();

        parent::tearDown();
    }

    /**
     * CLI — 내장 키로 서명한 코어 자산은 검증되고 풀린다. 제작자 키로 서명한 코어는 미신뢰(422).
     *
     * @scenario case=core_cli_signed_builtin
     *
     * @effects core_update_requires_builtin_key_signature
     *
     * @return void 반환값 없음
     */
    public function test_cli_download_verifies_with_builtin_key_only(): void
    {
        $builtin = $this->fixtureKey('sirsoft');
        $this->useBuiltinTestKey($builtin);
        $this->fakeCoreRelease($builtin);

        $source = app(CoreUpdateService::class)->downloadUpdate('7.3.0', null, PackageIntegrityOptions::none());
        $this->assertFileExists($source.'/config/app.php');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/zipball/'));

        // 제작자 키(glitter)로 서명한 코어 — 신뢰 목록에 있어도 코어는 받지 않는다
        $glitter = $this->fixtureKey('glitter');
        $this->trustVendorKey($glitter, ['glitter']);
        $this->fakeCoreRelease($glitter);

        try {
            app(CoreUpdateService::class)->downloadUpdate('7.3.0', null, new PackageIntegrityOptions(true));
            $this->fail('제작자 키로 서명한 코어가 통과했다');
        } catch (PackageIntegrityException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('core_requires_builtin_key', $e->verification->reason);
        }
    }

    /**
     * CLI — 서명 자산이 없으면 확인 없이는 받지 않고(409), 확인하면 태그 zipball 을 받는다.
     *
     * @scenario case=core_cli_unsigned
     *
     * @effects core_update_unsigned_needs_confirmation
     *
     * @return void 반환값 없음
     */
    public function test_cli_download_unsigned_needs_confirmation(): void
    {
        $this->fakeCoreRelease(null);

        try {
            app(CoreUpdateService::class)->downloadUpdate('7.3.0', null, PackageIntegrityOptions::none());
            $this->fail('미서명 코어를 확인 없이 받았다');
        } catch (PackageIntegrityException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/zipball/'));

        $source = app(CoreUpdateService::class)->downloadUpdate('7.3.0', null, new PackageIntegrityOptions(true));
        $this->assertFileExists($source.'/config/app.php');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/zipball/v7.3.0'));
    }

    /**
     * 웹 — 내려받기 단계는 해제 전에 검증하고, 다 푼 뒤 config/app.php 버전을 서명과 대조한다.
     *
     * @scenario case=core_web_fetch_verified
     *
     * @effects web_core_update_verifies_before_chunked_extract
     *
     * @return void 반환값 없음
     */
    public function test_web_fetch_verifies_and_confirms_version(): void
    {
        $builtin = $this->fixtureKey('sirsoft');
        $this->useBuiltinTestKey($builtin);
        $this->fakeCoreRelease($builtin);
        $service = app(CoreUpdateService::class);

        $root = $service->createPendingDirectory();
        $this->pendingPaths[] = $root;
        $acquired = $service->fetchVerifiedReleaseArchive('7.3.0', $root, PackageIntegrityOptions::none());
        $this->assertSame(PackageVerificationStatus::Verified, $acquired->verification->status);

        $chunk = $service->extractArchiveChunk($acquired->zipPath, $root);
        $this->assertTrue($chunk['done']);
        $this->assertSame(PackageVerificationStatus::Verified, $service->confirmCoreIntegrity($acquired->verification, (string) $chunk['source_path'], PackageIntegrityOptions::none())->status);

        // 풀린 배포본의 버전이 서명과 다르면 거절
        File::put($chunk['source_path'].'/config/app.php', "<?php\nreturn ['version' => env('APP_VERSION', '7.9.9')];\n");
        try {
            $service->confirmCoreIntegrity($acquired->verification, (string) $chunk['source_path'], PackageIntegrityOptions::none());
            $this->fail('버전이 다른 배포본이 통과했다');
        } catch (PackageIntegrityException $e) {
            $this->assertSame('manifest_version_mismatch', $e->verification->reason);
        }
    }

    /**
     * 웹 착수 — 서명 자산이 없는 새 버전은 착수 전에 409(유지보수 모드 전), 확인 응답에 signature_available.
     *
     * @scenario case=core_web_start_unsigned
     *
     * @effects web_core_update_start_409_and_signature_available_flag
     *
     * @return void 반환값 없음
     */
    public function test_web_start_unsigned_is_409_and_check_reports_signature_availability(): void
    {
        $this->fakeCoreRelease(null);
        $token = $this->adminToken(['core.settings.read', 'core.system.update']);

        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->postJson('/api/admin/core-update/check')
            ->assertOk()
            ->assertJsonPath('data.latest_version', '7.3.0')
            ->assertJsonPath('data.signature_available', false);

        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->postJson('/api/admin/core-update/start', ['integrity_aware' => true])
            ->assertStatus(409)
            ->assertJsonPath('errors.package_unsigned', true)
            ->assertJsonPath('errors.integrity.can_continue', true);
        $this->assertFalse(app(CoreUpdateRunState::class)->exists(), '미서명 거절인데 실행 상태가 만들어졌다');

        $builtin = $this->fixtureKey('sirsoft');
        $this->useBuiltinTestKey($builtin);
        $this->fakeCoreRelease($builtin);
        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->postJson('/api/admin/core-update/check')
            ->assertOk()
            ->assertJsonPath('data.signature_available', true);
    }

    /**
     * 옛 화면(무결성 표식 없음)의 웹 착수 선택은 서명 자산이 없는 릴리스도 착수 전 판정을 통과한다(7.1 동작).
     * 차단 스위치가 켜져 있거나 새 화면(표식)이면 그대로 409 다.
     *
     * 착수 API 를 끝까지 돌리면 실행 상태가 저장소에 남으므로, 착수가 쓰는 같은 판정(`preflightReleaseIntegrity`)을
     * 요청 검증 결과로 만든 선택(`CoreUpdateOptions::fromValidated`)으로 직접 부른다.
     *
     * @scenario case=install_legacy_screen_compat
     *
     * @effects legacy_screen_without_marker_installs_unsigned_but_keeps_hard_blocks
     *
     * @return void 반환값 없음
     */
    public function test_web_start_legacy_screen_passes_unsigned_preflight(): void
    {
        $this->fakeCoreRelease(null);
        $service = app(CoreUpdateService::class);

        // 옛 화면 — 표식 없음
        $service->preflightReleaseIntegrity('7.3.0', CoreUpdateOptions::fromValidated([])->integrityOptions());

        // 상태에서 되살려도 옛 화면 판정이 유지된다(웹 단계는 요청을 건너 이어진다)
        $restored = CoreUpdateOptions::fromArray(CoreUpdateOptions::fromValidated([])->toArray());
        $this->assertTrue($restored->integrityOptions()->legacyClient);

        // 새 화면 — 표식이 있으면 409
        try {
            $service->preflightReleaseIntegrity('7.3.0', CoreUpdateOptions::fromValidated(['integrity_aware' => true])->integrityOptions());
            $this->fail('새 화면 미서명 착수가 확인 없이 통과했다');
        } catch (PackageIntegrityException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        // 차단 스위치 — 옛 화면도 409(계속 불가)
        Config::set('g7_settings.core.'.UnsignedPackagePolicy::BLOCK_SETTING, true);
        try {
            $service->preflightReleaseIntegrity('7.3.0', CoreUpdateOptions::fromValidated([])->integrityOptions());
            $this->fail('차단 스위치가 켜졌는데 옛 화면 미서명 착수가 통과했다');
        } catch (PackageIntegrityException $e) {
            $this->assertTrue($e->blockedByPolicy);
        }
    }

    /**
     * 코어 7.3.0 릴리스를 가짜로 등록합니다 (`$key` 가 null 이면 서명 자산 없음).
     *
     * @param  array<string, mixed>|null  $key  서명 키
     * @return void 반환값 없음
     */
    private function fakeCoreRelease(?array $key): void
    {
        $files = [
            'composer.json' => '{"name":"gnuboard/g7"}',
            'app/Placeholder.php' => "<?php\n",
            'config/app.php' => "<?php\nreturn ['version' => env('APP_VERSION', '7.3.0')];\n",
        ];
        $zip = $this->makeCoreZip('g7-7.3.0.zip', $files);
        $zipball = File::get($this->makeCoreZip('core-zipball.zip', $files));
        $assets = [];
        $sig = null;
        if ($key !== null) {
            $sig = $this->signPackage($zip, $key, 'core', 'g7', '7.3.0');
            $assets = [
                ['name' => 'g7-7.3.0.zip', 'size' => filesize($zip), 'url' => 'https://api.github.com/repos/sirsoft-test/g7/releases/assets/1'],
                ['name' => 'g7-7.3.0.zip.sig.json', 'size' => filesize($sig), 'url' => 'https://api.github.com/repos/sirsoft-test/g7/releases/assets/2'],
            ];
        }

        // Http::fake 는 먼저 등록한 응답이 이기므로 한 번만 등록하고, 응답 내용은 속성으로 바꾼다
        $this->fakeRelease = ['tag_name' => 'v7.3.0', 'zipball_url' => 'https://api.github.com/repos/sirsoft-test/g7/zipball/v7.3.0', 'assets' => $assets];
        $this->fakeZipBytes = File::get($zip);
        $this->fakeSigBytes = $sig !== null ? File::get($sig) : '';

        if ($this->fakeRegistered) {
            return;
        }
        $this->fakeRegistered = true;

        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_ends_with($url, '/repos/sirsoft-test/g7/releases/latest'),
                str_ends_with($url, '/repos/sirsoft-test/g7/releases/tags/v7.3.0') => Http::response($this->fakeRelease, 200),
                str_ends_with($url, '/repos/sirsoft-test/g7/git/refs/tags/v7.3.0') => Http::response(['ref' => 'refs/tags/v7.3.0'], 200),
                str_ends_with($url, '/releases/assets/1') => Http::response($this->fakeZipBytes, 200),
                str_ends_with($url, '/releases/assets/2') => Http::response($this->fakeSigBytes, 200),
                str_ends_with($url, '/zipball/v7.3.0') => Http::response($this->fakeZipBytes, 200),
                str_contains($url, 'raw.githubusercontent.com') => Http::response('# Changelog', 200),
                default => Http::response(['message' => 'Not Found'], 404),
            };
        });
    }

    /**
     * 코어 배포본 모양의 ZIP 을 만듭니다 (루트 `g7-7.3.0/`).
     *
     * @param  string  $name  파일 이름
     * @param  array<string, string>  $files  파일
     * @return string ZIP 경로
     */
    private function makeCoreZip(string $name, array $files): string
    {
        $path = $this->packageFixtureDir().DIRECTORY_SEPARATOR.$name;
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($files as $relative => $content) {
            $zip->addFromString('g7-7.3.0/'.$relative, $content);
        }
        $zip->close();

        return $path;
    }

    /**
     * 웹 업데이트 실행 상태 파일을 지웁니다.
     *
     * @return void 반환값 없음
     */
    private function clearRunState(): void
    {
        $dir = storage_path(str_replace('/', DIRECTORY_SEPARATOR, CoreUpdateRunState::DIRECTORY));
        foreach ([CoreUpdateRunState::FILENAME, CoreUpdateRunState::APPLY_LIST_FILENAME] as $file) {
            @unlink($dir.DIRECTORY_SEPARATOR.$file);
        }
    }

    /**
     * 권한을 가진 관리자 토큰을 만듭니다.
     *
     * @param  array<int, string>  $permissions  권한 식별자
     * @return string API 토큰
     */
    private function adminToken(array $permissions): string
    {
        $user = User::factory()->create();
        $ids = [];
        foreach ($permissions as $identifier) {
            $ids[] = Permission::firstOrCreate(['identifier' => $identifier], [
                'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => PermissionType::Admin,
            ])->id;
        }
        $role = Role::create([
            'identifier' => 'core_updater_'.uniqid(),
            'name' => json_encode(['ko' => '업데이트', 'en' => 'Updater']),
            'description' => json_encode(['ko' => '시험', 'en' => 'Test']),
            'is_active' => true,
        ]);
        $role->permissions()->sync($ids);
        $admin = Role::firstOrCreate(['identifier' => 'admin'], [
            'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
            'description' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'is_active' => true,
        ]);
        $user->roles()->attach($admin->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->createToken('t')->plainTextToken;
    }
}
