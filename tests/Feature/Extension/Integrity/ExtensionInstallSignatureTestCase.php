<?php

namespace Tests\Feature\Extension\Integrity;

use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Extension\Integrity\UnsignedPackagePolicy;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\Concerns\PackageSignatureFixtures;
use Tests\TestCase;

/**
 * 확장 수동 설치(ZIP·GitHub)의 패키지 서명 검증 공통 시나리오 (#916 ④, D16~D19·D36)
 *
 * 매니저 설치 단계(`install*`)는 목으로 바꾼다 — 검증은 서비스가 `_pending` 으로 옮기기 **전에** 하므로, 거절된 요청이
 * 매니저 설치를 부르지 않고 `_pending`·활성 디렉터리에 파일을 남기지 않는 것을 직접 잰다. 통과한 요청은 `_pending` 에
 * 옮겨진 뒤 목 설치가 불리고, 응답 리소스에 검증 결과(`integrity_status`·`integrity_key_id`)가 실린다.
 */
abstract class ExtensionInstallSignatureTestCase extends TestCase
{
    use PackageSignatureFixtures;
    use RefreshDatabase;

    /**
     * 매니저 설치가 불린 횟수
     *
     * @var int
     */
    protected int $installCalls = 0;

    /**
     * 관리자 API 토큰
     *
     * @var string
     */
    private string $token = '';

    /**
     * 확장 종류 (module|plugin|template)
     *
     * @return string 종류
     */
    abstract protected function type(): string;

    /**
     * 시험 확장 식별자 (glitter vendor)
     *
     * @return string 식별자
     */
    abstract protected function identifier(): string;

    /**
     * 매니저 인터페이스를 목으로 바꿉니다 (설치가 불리면 `$this->installCalls` 를 올린다).
     *
     * @return void 반환값 없음
     */
    abstract protected function mockManager(): void;

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
     * 관리자·매니저 목·신뢰 키를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->mockManager();
        $this->token = $this->makeInstaller()->createToken('t')->plainTextToken;
        $this->trustVendorKey($this->fixtureKey('glitter'), ['glitter']);
        Http::preventStrayRequests();
    }

    /**
     * `_pending`·활성 디렉터리와 시험 파일을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ([$this->pendingPath(), $this->activePath()] as $path) {
            if (File::isDirectory($path)) {
                File::deleteDirectory($path);
            }
        }
        $this->cleanupPackageFixtures();
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 신뢰 키로 서명한 ZIP + 서명 파일 → 201, 검증 결과가 응답에 실린다.
     *
     * @scenario case=install_zip_signed_verified
     *
     * @effects signed_zip_install_verified_and_recorded
     *
     * @return void 반환값 없음
     */
    public function test_signed_zip_installs_as_verified(): void
    {
        $key = $this->fixtureKey('glitter');
        $zip = $this->makePackageZip($this->type(), $this->identifier(), '1.0.0');
        $sig = $this->signPackage($zip, $key, $this->type(), $this->identifier(), '1.0.0');

        $this->postFile($zip, $sig)
            ->assertStatus(201)
            ->assertJsonPath('data.integrity_status', 'verified')
            ->assertJsonPath('data.integrity_key_id', $key['key_id']);

        $this->assertSame(1, $this->installCalls);
    }

    /**
     * 미서명 ZIP → 409(계속 가능) — 설치 안 됨·파일 0. 같은 요청 + allow_unsigned=1 → 201 unsigned (D36).
     *
     * @scenario case=install_zip_unsigned_confirm
     *
     * @effects unsigned_zip_install_409_then_allow_unsigned_installs
     *
     * @return void 반환값 없음
     */
    public function test_unsigned_zip_needs_confirmation_then_installs(): void
    {
        $zip = $this->makePackageZip($this->type(), $this->identifier(), '1.0.0');

        $this->postFile($zip)
            ->assertStatus(409)
            ->assertJsonPath('errors.package_unsigned', true)
            ->assertJsonPath('errors.integrity.status', 'unsigned')
            ->assertJsonPath('errors.integrity.can_continue', true);
        $this->assertSame(0, $this->installCalls);
        $this->assertDirectoryDoesNotExist($this->pendingPath());

        $this->postFile($zip, null, ['allow_unsigned' => '1'])
            ->assertStatus(201)
            ->assertJsonPath('data.integrity_status', 'unsigned')
            ->assertJsonPath('data.integrity_key_id', null);
        $this->assertSame(1, $this->installCalls);
    }

    /**
     * 「미서명 패키지 차단」이 켜져 있으면 allow_unsigned=1 이어도 409 고정(계속 불가).
     *
     * @scenario case=install_zip_block_switch
     *
     * @effects block_unsigned_switch_overrides_allow_unsigned
     *
     * @return void 반환값 없음
     */
    public function test_block_switch_overrides_allow_unsigned(): void
    {
        Config::set('g7_settings.core.'.UnsignedPackagePolicy::BLOCK_SETTING, true);
        $zip = $this->makePackageZip($this->type(), $this->identifier(), '1.0.0');

        $this->postFile($zip, null, ['allow_unsigned' => '1'])
            ->assertStatus(409)
            ->assertJsonPath('errors.integrity.can_continue', false)
            ->assertJsonPath('errors.integrity.blocked_by_policy', true);
        $this->assertSame(0, $this->installCalls);
    }

    /**
     * 변조 ZIP 은 422 서명 불일치, 다른 vendor 를 glitter 키로 서명하면 422 미신뢰 — 둘 다 파일 0·설치 0.
     *
     * @scenario case=install_zip_invalid_untrusted
     *
     * @effects invalid_or_untrusted_zip_rejected_without_files
     *
     * @return void 반환값 없음
     */
    public function test_tampered_or_untrusted_zip_is_rejected_without_files(): void
    {
        $key = $this->fixtureKey('glitter');
        $zip = $this->makePackageZip($this->type(), $this->identifier(), '1.0.0');
        $sig = $this->signPackage($zip, $key, $this->type(), $this->identifier(), '1.0.0');
        File::append($zip, 'x');

        $this->postFile($zip, $sig, ['allow_unsigned' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('errors.package_signature_invalid', true)
            ->assertJsonPath('errors.integrity.can_continue', false);

        $otherId = 'acme-'.substr($this->identifier(), strlen('glitter-'));
        $otherZip = $this->makePackageZip($this->type(), $otherId, '1.0.0');
        $otherSig = $this->signPackage($otherZip, $key, $this->type(), $otherId, '1.0.0');

        $this->postFile($otherZip, $otherSig, ['allow_unsigned' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('errors.package_signer_untrusted', true)
            ->assertJsonPath('errors.integrity.reason', 'vendor_out_of_scope');

        $this->assertSame(0, $this->installCalls);
        $this->assertDirectoryDoesNotExist($this->pendingPath());
        $this->assertDirectoryDoesNotExist($this->activePath());
    }

    /**
     * GitHub 설치 — 서명 자산이 있으면 자산을 받아 verified, 없으면 zipball 을 받기 전에 409 → allow 시 unsigned.
     *
     * @scenario case=install_github_signature
     *
     * @effects github_install_uses_signed_asset_or_asks_before_zipball
     *
     * @return void 반환값 없음
     */
    public function test_github_install_uses_signed_asset_or_asks_before_zipball(): void
    {
        $key = $this->fixtureKey('glitter');
        $zip = $this->makePackageZip($this->type(), $this->identifier(), '1.0.0');
        $sig = $this->signPackage($zip, $key, $this->type(), $this->identifier(), '1.0.0');
        $repo = 'glitter/'.$this->identifier();
        $zipball = File::get($this->makePackageZip($this->type(), $this->identifier(), '1.0.0', [], [], 'zipball.zip'));
        $assets = [
            ['name' => basename($zip), 'size' => filesize($zip), 'url' => "https://api.github.com/repos/{$repo}/releases/assets/1"],
            ['name' => basename($sig), 'size' => filesize($sig), 'url' => "https://api.github.com/repos/{$repo}/releases/assets/2"],
        ];
        $withAssets = true;

        Http::fake(function (Request $request) use ($repo, &$withAssets, $assets, $zip, $sig, $zipball) {
            $url = $request->url();

            return match (true) {
                $url === "https://api.github.com/repos/{$repo}" => Http::response(['full_name' => $repo], 200),
                str_ends_with($url, "/repos/{$repo}/releases/latest") => Http::response([
                    'tag_name' => 'v1.0.0',
                    'zipball_url' => "https://api.github.com/repos/{$repo}/zipball/v1.0.0",
                    'assets' => $withAssets ? $assets : [],
                ], 200),
                str_ends_with($url, '/releases/assets/1') => Http::response(File::get($zip), 200),
                str_ends_with($url, '/releases/assets/2') => Http::response(File::get($sig), 200),
                str_ends_with($url, '/zipball/v1.0.0') => Http::response($zipball, 200),
                default => Http::response(['message' => 'Not Found'], 404),
            };
        });

        $this->postGithub("https://github.com/{$repo}")
            ->assertStatus(201)
            ->assertJsonPath('data.integrity_status', 'verified');
        $this->assertSame(1, $this->installCalls);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/zipball/'));

        // 다음 시나리오를 위해 정리 — 서명 자산 없는 릴리스
        foreach ([$this->pendingPath(), $this->activePath()] as $path) {
            File::deleteDirectory($path);
        }
        $withAssets = false;

        $this->postGithub("https://github.com/{$repo}")
            ->assertStatus(409)
            ->assertJsonPath('errors.package_unsigned', true);
        $this->assertSame(1, $this->installCalls);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/zipball/'));

        $this->postGithub("https://github.com/{$repo}", ['allow_unsigned' => true])
            ->assertStatus(201)
            ->assertJsonPath('data.integrity_status', 'unsigned');
        $this->assertSame(2, $this->installCalls);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/zipball/v1.0.0'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/archive/refs/heads/'));
    }

    /**
     * 옛 화면(무결성 표식 없음) 요청은 7.1 동작대로 미서명 패키지를 설치하고 unsigned 로 기록하며 호환 경고 로그를 남긴다.
     * 차단 스위치·서명 불일치·미신뢰 키는 옛 화면도 그대로 막는다.
     *
     * @scenario case=install_legacy_screen_compat
     *
     * @effects legacy_screen_without_marker_installs_unsigned_but_keeps_hard_blocks
     *
     * @return void 반환값 없음
     */
    public function test_legacy_screen_without_marker_installs_unsigned_but_keeps_hard_blocks(): void
    {
        $key = $this->fixtureKey('glitter');

        // 차단 스위치 — 옛 화면도 409(계속 불가)
        Config::set('g7_settings.core.'.UnsignedPackagePolicy::BLOCK_SETTING, true);
        $zip = $this->makePackageZip($this->type(), $this->identifier(), '1.0.0');
        $this->postFile($zip, legacyScreen: true)
            ->assertStatus(409)
            ->assertJsonPath('errors.integrity.blocked_by_policy', true);
        Config::set('g7_settings.core.'.UnsignedPackagePolicy::BLOCK_SETTING, false);

        // 서명 불일치 — 옛 화면도 422
        $tampered = $this->makePackageZip($this->type(), $this->identifier(), '1.0.0', [], [], 'tampered.zip');
        $sig = $this->signPackage($tampered, $key, $this->type(), $this->identifier(), '1.0.0');
        File::append($tampered, 'x');
        $this->postFile($tampered, $sig, legacyScreen: true)
            ->assertStatus(422)
            ->assertJsonPath('errors.package_signature_invalid', true);
        $this->assertSame(0, $this->installCalls);

        // 미서명 — 옛 화면은 확인 없이 설치, unsigned 기록, 호환 경고 로그
        Log::spy();
        $this->postFile($zip, legacyScreen: true)
            ->assertStatus(201)
            ->assertJsonPath('data.integrity_status', 'unsigned');
        $this->assertSame(1, $this->installCalls);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => str_contains($message, '옛 화면 호환')
            && ($context['status'] ?? null) === 'unsigned')->atLeast()->once();
    }

    /**
     * 옛 화면(무결성 표식 없음)의 GitHub 설치는 서명 자산이 없어도 zipball 을 받아 unsigned 로 설치한다.
     *
     * @scenario case=install_legacy_screen_compat
     *
     * @effects legacy_screen_without_marker_installs_unsigned_but_keeps_hard_blocks
     *
     * @return void 반환값 없음
     */
    public function test_legacy_screen_github_install_without_assets_installs_unsigned(): void
    {
        $repo = 'glitter/'.$this->identifier();
        $zipball = File::get($this->makePackageZip($this->type(), $this->identifier(), '1.0.0', [], [], 'zipball.zip'));

        Http::fake(fn (Request $request) => match (true) {
            $request->url() === "https://api.github.com/repos/{$repo}" => Http::response(['full_name' => $repo], 200),
            str_ends_with($request->url(), "/repos/{$repo}/releases/latest") => Http::response([
                'tag_name' => 'v1.0.0',
                'zipball_url' => "https://api.github.com/repos/{$repo}/zipball/v1.0.0",
                'assets' => [],
            ], 200),
            str_ends_with($request->url(), '/zipball/v1.0.0') => Http::response($zipball, 200),
            default => Http::response(['message' => 'Not Found'], 404),
        });

        $this->postGithub("https://github.com/{$repo}", legacyScreen: true)
            ->assertStatus(201)
            ->assertJsonPath('data.integrity_status', 'unsigned');
        $this->assertSame(1, $this->installCalls);
    }

    /**
     * ZIP 설치 요청을 보냅니다 (기본은 무결성을 아는 새 화면 — 표식 `integrity_aware=1` 을 싣는다).
     *
     * @param  string  $zip  ZIP 경로
     * @param  string|null  $sig  서명 파일 경로
     * @param  array<string, mixed>  $extra  추가 필드
     * @param  bool  $legacyScreen  옛 화면(표식 없음)으로 보낼지
     * @return TestResponse 응답
     */
    protected function postFile(string $zip, ?string $sig = null, array $extra = [], bool $legacyScreen = false): TestResponse
    {
        $payload = ['file' => new UploadedFile($zip, basename($zip), 'application/zip', null, true)] + $extra
            + ($legacyScreen ? [] : ['integrity_aware' => '1']);
        if ($sig !== null) {
            $payload['signature'] = new UploadedFile($sig, basename($sig), 'application/json', null, true);
        }

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'])
            ->post('/api/admin/'.$this->type().'s/install-from-file', $payload);
    }

    /**
     * GitHub 설치 요청을 보냅니다 (기본은 무결성을 아는 새 화면 — 표식 `integrity_aware=1` 을 싣는다).
     *
     * @param  string  $url  저장소 주소
     * @param  array<string, mixed>  $extra  추가 필드
     * @param  bool  $legacyScreen  옛 화면(표식 없음)으로 보낼지
     * @return TestResponse 응답
     */
    protected function postGithub(string $url, array $extra = [], bool $legacyScreen = false): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'])
            ->postJson('/api/admin/'.$this->type().'s/install-from-github', ['github_url' => $url] + $extra + ($legacyScreen ? [] : ['integrity_aware' => 1]));
    }

    /**
     * 시험 확장의 `_pending` 경로
     *
     * @return string 경로
     */
    protected function pendingPath(): string
    {
        return base_path($this->type().'s/_pending/'.$this->identifier());
    }

    /**
     * 시험 확장의 활성 경로
     *
     * @return string 경로
     */
    protected function activePath(): string
    {
        return base_path($this->type().'s/'.$this->identifier());
    }

    /**
     * 목 설치가 돌려줄 확장 정보
     *
     * @return array<string, mixed> 정보
     */
    protected function installedInfo(): array
    {
        return [
            'identifier' => $this->identifier(),
            'vendor' => 'glitter',
            'name' => $this->identifier(),
            'version' => '1.0.0',
            'status' => 'inactive',
            'is_installed' => true,
        ];
    }

    /**
     * 설치 권한을 가진 관리자를 만듭니다.
     *
     * @return User 관리자
     */
    private function makeInstaller(): User
    {
        $user = User::factory()->create();
        $ids = [];
        foreach (['core.'.$this->type().'s.read', 'core.'.$this->type().'s.install'] as $identifier) {
            $ids[] = Permission::firstOrCreate(['identifier' => $identifier], [
                'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => PermissionType::Admin,
            ])->id;
        }
        $role = Role::create([
            'identifier' => 'installer_'.uniqid(),
            'name' => json_encode(['ko' => '설치', 'en' => 'Installer']),
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

        return $user->fresh();
    }
}
