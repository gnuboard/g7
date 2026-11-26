<?php

namespace Tests\Feature\Extension\Integrity;

use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Exceptions\PackageIntegrityException;
use App\Extension\Integrity\PackageIntegrityOptions;
use App\Extension\Integrity\UnsignedPackagePolicy;
use App\Models\LanguagePack;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\LanguagePackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\PackageSignatureFixtures;
use Tests\TestCase;

/**
 * 외부 언어팩 설치의 패키지 서명 검증 — 업로드 signature·URL(checksum + signature_url)·GitHub
 *
 * 언어팩 vendor 는 식별자가 아니라 manifest `vendor` 칸이라, 제작자 키의 vendor 범위는 해제 뒤(복사 전) 대조한다.
 * 설치 행에는 검증 결과(`integrity_status`·`integrity_key_id`)가 남는다.
 */
class LanguagePackSignatureTest extends TestCase
{
    use PackageSignatureFixtures;
    use RefreshDatabase;

    /**
     * 시험 언어팩 식별자
     */
    private const PACK = 'glitter-core-ja';

    /**
     * 시험 전에 있던 `_pending` 하위 디렉토리 (이 시험이 만든 것만 정리)
     *
     * @var array<int, string>
     */
    private array $pendingBefore = [];

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
        $this->pendingBefore = $this->pendingDirectories();
        Config::set('app.update.github_token', '');
    }

    /**
     * 활성 디렉토리·_pending·시험 파일을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('lang-packs/'.self::PACK));
        foreach (array_diff($this->pendingDirectories(), $this->pendingBefore) as $created) {
            File::deleteDirectory($created);
        }
        $this->cleanupPackageFixtures();

        parent::tearDown();
    }

    /**
     * 업로드 — 서명 파일을 함께 올리면 verified 로 기록되고, 없으면 409 → 확인하면 unsigned 로 기록된다.
     *
     * @scenario case=language_pack_upload_signature
     *
     * @effects language_pack_upload_signature_verified_or_confirmed
     *
     * @return void 반환값 없음
     */
    public function test_upload_with_and_without_signature(): void
    {
        $key = $this->fixtureKey('glitter');
        $zip = $this->makePackZip('glitter');
        $sig = $this->signPackage($zip, $key, 'language_pack', self::PACK, '1.0.0');

        $pack = app(LanguagePackService::class)->installFromFile($this->upload($zip), false, null, new PackageIntegrityOptions(false, $sig));
        $this->assertSame('verified', $pack->fresh()->integrity_status);
        $this->assertSame($key['key_id'], $pack->fresh()->integrity_key_id);
        File::deleteDirectory(base_path('lang-packs/'.self::PACK));
        $pack->delete();

        try {
            app(LanguagePackService::class)->installFromFile($this->upload($zip), false, null);
            $this->fail('서명 없는 언어팩이 확인 없이 설치됐다');
        } catch (PackageIntegrityException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDirectoryDoesNotExist(base_path('lang-packs/'.self::PACK));

        $pack = app(LanguagePackService::class)->installFromFile($this->upload($zip), false, null, new PackageIntegrityOptions(true));
        $this->assertSame('unsigned', $pack->fresh()->integrity_status);
        $this->assertNull($pack->fresh()->integrity_key_id);
    }

    /**
     * manifest vendor 가 제작자 키 범위 밖이면 해제 뒤 대조에서 미신뢰(422)로 막히고 활성 디렉토리가 생기지 않는다.
     *
     * @scenario case=language_pack_vendor_scope
     *
     * @effects language_pack_manifest_vendor_checked_against_key_scope
     *
     * @return void 반환값 없음
     */
    public function test_manifest_vendor_outside_key_scope_is_untrusted(): void
    {
        $zip = $this->makePackZip('acme');
        $sig = $this->signPackage($zip, $this->fixtureKey('glitter'), 'language_pack', self::PACK, '1.0.0');

        try {
            app(LanguagePackService::class)->installFromFile($this->upload($zip), false, null, new PackageIntegrityOptions(true, $sig));
            $this->fail('범위 밖 vendor 언어팩이 설치됐다');
        } catch (PackageIntegrityException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('vendor_out_of_scope', $e->verification->reason);
        }

        $this->assertDirectoryDoesNotExist(base_path('lang-packs/'.self::PACK));
        $this->assertSame(0, LanguagePack::query()->where('identifier', self::PACK)->count());
    }

    /**
     * URL — 체크섬과 서명 파일 주소를 함께 주면 둘 다 확인해 verified. 서명 파일 주소를 주지 않으면 서명 파일을 추측해 요청하지 않는다.
     *
     * @scenario case=language_pack_url_signature
     *
     * @effects language_pack_url_install_checks_checksum_and_signature_url
     *
     * @return void 반환값 없음
     */
    public function test_url_install_with_checksum_and_signature_url(): void
    {
        $key = $this->fixtureKey('glitter');
        $zip = $this->makePackZip('glitter');
        $sig = $this->signPackage($zip, $key, 'language_pack', self::PACK, '1.0.0');
        $zipBytes = File::get($zip);
        $sigBytes = File::get($sig);
        Http::fake([
            'packs.example.com/glitter.zip' => Http::response($zipBytes, 200),
            'packs.example.com/glitter.zip.sig.json' => Http::response($sigBytes, 200),
        ]);

        $pack = app(LanguagePackService::class)->installFromUrl(
            'https://packs.example.com/glitter.zip', hash('sha256', $zipBytes), false, null, false,
            'https://packs.example.com/glitter.zip.sig.json', PackageIntegrityOptions::none()
        );
        $this->assertSame('verified', $pack->fresh()->integrity_status);

        File::deleteDirectory(base_path('lang-packs/'.self::PACK));
        $pack->delete();

        try {
            app(LanguagePackService::class)->installFromUrl('https://packs.example.com/glitter.zip', null, false, null);
            $this->fail('서명 파일 주소 없이 확인 없이 설치됐다');
        } catch (PackageIntegrityException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        Http::assertSentCount(3);
    }

    /**
     * GitHub — 릴리스의 서명 자산을 받아 verified 로 기록하고, 관리자 API 로는 서명 없는 릴리스가 409 페이로드로 답한다.
     *
     * @scenario case=language_pack_github_signature
     *
     * @effects language_pack_github_install_uses_signed_release_asset
     *
     * @return void 반환값 없음
     */
    public function test_github_install_and_api_409(): void
    {
        $key = $this->fixtureKey('glitter');
        $zip = $this->makePackZip('glitter');
        $sig = $this->signPackage($zip, $key, 'language_pack', self::PACK, '1.0.0');
        $signed = true;
        $zipBytes = File::get($zip);
        $sigBytes = File::get($sig);

        Http::fake(function (Request $request) use (&$signed, $zip, $sig, $zipBytes, $sigBytes) {
            $url = $request->url();
            $assets = $signed ? [
                ['name' => basename($zip), 'size' => strlen($zipBytes), 'url' => 'https://api.github.com/repos/glitter/core-ja/releases/assets/1'],
                ['name' => basename($sig), 'size' => strlen($sigBytes), 'url' => 'https://api.github.com/repos/glitter/core-ja/releases/assets/2'],
            ] : [];

            return match (true) {
                str_ends_with($url, '/repos/glitter/core-ja/releases/latest') => Http::response(['tag_name' => 'v1.0.0', 'zipball_url' => 'https://api.github.com/repos/glitter/core-ja/zipball/v1.0.0', 'assets' => $assets], 200),
                str_ends_with($url, '/releases/assets/1') => Http::response($zipBytes, 200),
                str_ends_with($url, '/releases/assets/2') => Http::response($sigBytes, 200),
                default => Http::response(['message' => 'Not Found'], 404),
            };
        });

        $pack = app(LanguagePackService::class)->installFromGithub('https://github.com/glitter/core-ja');
        $this->assertSame('verified', $pack->fresh()->integrity_status);
        File::deleteDirectory(base_path('lang-packs/'.self::PACK));
        $pack->delete();

        $signed = false;
        // 새 화면은 무결성 표식(integrity_aware)을 늘 싣는다 — 미서명이면 409 로 확인을 묻는다
        $this->withHeaders(['Authorization' => 'Bearer '.$this->installerToken(), 'Accept' => 'application/json'])
            ->postJson('/api/admin/language-packs/install-from-github', ['github_url' => 'https://github.com/glitter/core-ja', 'integrity_aware' => 1])
            ->assertStatus(409)
            ->assertJsonPath('errors.package_unsigned', true)
            ->assertJsonPath('errors.integrity.can_continue', true);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/zipball/') || str_contains($r->url(), '/archive/refs/heads/'));
    }

    /**
     * 태그 릴리스가 없는 저장소를 GitHub 로 설치하면 422 와 「태그 릴리스가 없습니다」 문구로 답합니다.
     *
     * 브라우저 실측(2026-11-16, 릴리스 0·태그 0 공개 저장소)에서 플러그인·모듈·템플릿 창은 422 와 사유를 보였지만
     * 언어팩 창만 500 「언어팩 설치에 실패했습니다.」로 사유를 숨겼다 — 서비스가 릴리스 없음 예외를 안내성 실패로 바꾸지 않아
     * 컨트롤러의 마지막 Throwable 분기로 떨어졌다. 브랜치 ZIP 요청은 여전히 0 이어야 한다.
     *
     * @scenario case=language_pack_github_signature
     *
     * @effects language_pack_github_release_not_found_is_422
     *
     * @return void 반환값 없음
     */
    public function test_github_install_without_release_answers_422_with_reason(): void
    {
        Http::fake(fn (Request $request) => Http::response(['message' => 'Not Found'], 404));

        $this->withHeaders(['Authorization' => 'Bearer '.$this->installerToken(), 'Accept' => 'application/json'])
            ->postJson('/api/admin/language-packs/install-from-github', ['github_url' => 'https://github.com/glitter/no-release'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('common.errors.github_release_not_found', ['repository' => 'glitter/no-release']));

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/zipball/') || str_contains($r->url(), '/archive/refs/heads/'));
        $this->assertSame(0, LanguagePack::query()->count());
    }

    /**
     * 옛 화면(무결성 표식 없음)의 언어팩 업로드·GitHub 설치는 7.1 동작대로 미서명을 설치해 unsigned 로 기록하고 호환 경고 로그를 남긴다.
     * 차단 스위치가 켜져 있으면 옛 화면도 409 다.
     *
     * @scenario case=install_legacy_screen_compat
     *
     * @effects legacy_screen_without_marker_installs_unsigned_but_keeps_hard_blocks
     *
     * @return void 반환값 없음
     */
    public function test_legacy_screen_upload_and_github_install_unsigned(): void
    {
        $zip = $this->makePackZip('glitter');
        $zipBytes = File::get($zip);
        $headers = ['Authorization' => 'Bearer '.$this->installerToken(), 'Accept' => 'application/json'];

        // 차단 스위치 — 옛 화면도 막힌다
        Config::set('g7_settings.core.'.UnsignedPackagePolicy::BLOCK_SETTING, true);
        $this->withHeaders($headers)
            ->post('/api/admin/language-packs/install-from-file', ['file' => $this->upload($zip)])
            ->assertStatus(409)
            ->assertJsonPath('errors.integrity.blocked_by_policy', true);
        Config::set('g7_settings.core.'.UnsignedPackagePolicy::BLOCK_SETTING, false);

        Log::spy();
        $this->withHeaders($headers)
            ->post('/api/admin/language-packs/install-from-file', ['file' => $this->upload($zip)])
            ->assertStatus(201);
        $this->assertSame('unsigned', LanguagePack::query()->where('identifier', self::PACK)->value('integrity_status'));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => str_contains($message, '옛 화면 호환'))->atLeast()->once();

        File::deleteDirectory(base_path('lang-packs/'.self::PACK));
        LanguagePack::query()->where('identifier', self::PACK)->delete();

        Http::fake(fn (Request $request) => match (true) {
            str_ends_with($request->url(), '/repos/glitter/core-ja/releases/latest') => Http::response(['tag_name' => 'v1.0.0', 'zipball_url' => 'https://api.github.com/repos/glitter/core-ja/zipball/v1.0.0', 'assets' => []], 200),
            str_ends_with($request->url(), '/zipball/v1.0.0') => Http::response($zipBytes, 200),
            default => Http::response(['message' => 'Not Found'], 404),
        });

        $this->withHeaders($headers)
            ->postJson('/api/admin/language-packs/install-from-github', ['github_url' => 'https://github.com/glitter/core-ja'])
            ->assertStatus(201);
        $this->assertSame('unsigned', LanguagePack::query()->where('identifier', self::PACK)->value('integrity_status'));
    }

    /**
     * 시험 언어팩 ZIP 을 만듭니다 (루트 `{identifier}-1.0.0/`).
     *
     * @param  string  $vendor  manifest 에 적을 제작사(vendor)
     * @return string ZIP 경로
     */
    private function makePackZip(string $vendor): string
    {
        return $this->makePackageZip('language_pack', self::PACK, '1.0.0', [
            'namespace' => 'glitter',
            'vendor' => $vendor,
            'name' => ['ko' => '시험 일본어', 'en' => 'Test JA'],
            'scope' => 'core',
            'target_identifier' => null,
            'locale' => 'ja',
            'locale_native_name' => '日本語',
            'g7_version' => '>=7.0.0',
        ], ['backend/ja/messages.php' => "<?php\nreturn ['hello' => 'こんにちは'];\n"]);
    }

    /**
     * ZIP 을 업로드 파일로 감쌉니다.
     *
     * @param  string  $zip  ZIP 경로
     * @return UploadedFile 업로드 파일
     */
    private function upload(string $zip): UploadedFile
    {
        return new UploadedFile($zip, basename($zip), 'application/zip', null, true);
    }

    /**
     * `lang-packs/_pending` 하위 디렉토리 목록
     *
     * @return array<int, string> 디렉토리
     */
    private function pendingDirectories(): array
    {
        $root = base_path('lang-packs/_pending');

        return File::isDirectory($root) ? File::directories($root) : [];
    }

    /**
     * 언어팩 설치 권한을 가진 관리자 토큰을 만듭니다.
     *
     * @return string API 토큰
     */
    private function installerToken(): string
    {
        $user = User::factory()->create();
        $permission = Permission::firstOrCreate(['identifier' => 'core.language_packs.install'], [
            'name' => json_encode(['ko' => '언어팩 설치', 'en' => 'Install language packs']),
            'description' => json_encode(['ko' => '시험', 'en' => 'Test']),
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'type' => PermissionType::Admin,
        ]);
        $role = Role::create([
            'identifier' => 'lp_installer_'.uniqid(),
            'name' => json_encode(['ko' => '언어팩', 'en' => 'Packs']),
            'description' => json_encode(['ko' => '시험', 'en' => 'Test']),
            'is_active' => true,
        ]);
        $role->permissions()->sync([$permission->id]);
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
