<?php

namespace Tests\Feature\Settings;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Rules\CspSourceHost;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 보안 탭 「콘텐츠 보안 정책」 저장·검증·응답 메타 (#158)
 *
 * 추가 호스트는 정책 문자열에 그대로 이어 붙으므로 저장 시점에 형식을 막아야 한다 — 따옴표·세미콜론·공백·
 * 키워드·스킴 소스·`*` 단독·`http://` 가 하나라도 들어가면 정책이 무력해지거나 다른 지시어가 끼어든다.
 * 응답 `_meta.csp` 는 화면의 잠금·미리보기·자동 호스트 표시가 읽는다(조회·저장 두 응답이 같은 모양).
 *
 * DB 는 관리자 계정·역할만 쓰므로 트랜잭션으로 격리한다.
 */
class SaveSettingsCspTest extends TestCase
{
    use DatabaseTransactions;

    /** 관리자 토큰 */
    private string $token;

    /**
     * 설정 권한을 가진 관리자 토큰을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['core.csp.mode_override' => null]);
        $this->token = $this->createAdminUser()->createToken('csp-settings-test')->plainTextToken;
    }

    /**
     * 설정 읽기·저장 권한을 가진 관리자를 만듭니다.
     *
     * @return User 생성된 관리자
     */
    private function createAdminUser(): User
    {
        $user = User::factory()->create([
            'email' => 'csp-settings-'.Str::lower(Str::random(10)).'@example.com',
            'password' => Hash::make('password123'),
        ]);

        $permissionIds = [];

        foreach (['core.settings.read', 'core.settings.update'] as $identifier) {
            $permissionIds[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier.' 권한', 'en' => $identifier.' permission']),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            )->id;
        }

        $role = Role::create([
            'identifier' => 'csp_settings_test_'.Str::lower(Str::random(8)),
            'name' => json_encode(['ko' => 'CSP 설정 테스트 관리자', 'en' => 'CSP settings test admin']),
            'description' => json_encode(['ko' => '테스트 전용', 'en' => 'Test only']),
            'is_active' => true,
        ]);
        $role->permissions()->sync($permissionIds);

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * 보안 탭 저장 요청을 보냅니다 (필수 필드는 기본값으로 채운다).
     *
     * @param  array<string, mixed>  $security  제출할 security 키
     * @return TestResponse 응답
     */
    private function saveSecurity(array $security): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/admin/settings', [
                '_tab' => 'security',
                'security' => array_merge([
                    'force_https' => false,
                    'login_attempt_enabled' => true,
                ], $security),
            ]);
    }

    /**
     * 허용되는 호스트 형식.
     *
     * @return array<string, array{0: string}> 케이스
     */
    public static function validHosts(): array
    {
        return [
            'plain' => ['cdn.example.com'],
            'wildcard' => ['*.example.com'],
            'https' => ['https://cdn.example.com'],
            'port' => ['cdn.example.com:8443'],
            'https+port' => ['https://cdn.example.com:8443'],
            'uppercase' => ['CDN.Example.COM'],
            'hyphen' => ['my-cdn.example-site.co.kr'],
            'digits' => ['1cdn.example.com'],
            'deep' => ['a.b.c.d.example.com'],
            'wildcard-https' => ['https://*.example.com'],
            'pg' => ['stdpay.inicis.com'],
        ];
    }

    /**
     * 거부되는 호스트 형식.
     *
     * @return array<string, array{0: mixed}> 케이스
     */
    public static function invalidHosts(): array
    {
        return [
            'unsafe-inline' => ["'unsafe-inline'"],
            'unsafe-eval' => ["'unsafe-eval'"],
            'space' => ['a b'],
            'semicolon' => ['evil.com; script-src *'],
            'javascript' => ['javascript:x'],
            'http' => ['http://x.com'],
            'data' => ['data:'],
            'star' => ['*'],
            'https-scheme-only' => ['https:'],
            'no-dot' => ['localhost'],
            'path' => ['cdn.example.com/js'],
            'quote' => ['cdn.example.com"'],
            'non-string' => [['x']],
        ];
    }

    /**
     * 규칙 단위 판정 — 허용 형식.
     *
     * @scenario case=input_valid_host
     *
     * @effects csp_host_rule_accepts_host_sources
     *
     * @param  string  $host  허용돼야 하는 호스트
     * @return void 반환값 없음
     */
    #[DataProvider('validHosts')]
    public function test_호스트_규칙_통과(string $host): void
    {
        $this->assertTrue(CspSourceHost::isValid($host), $host);
    }

    /**
     * 규칙 단위 판정 — 거부 형식.
     *
     * @scenario case=input_invalid_host
     *
     * @effects csp_host_rule_rejects_injection
     *
     * @param  mixed  $host  거부돼야 하는 값
     * @return void 반환값 없음
     */
    #[DataProvider('invalidHosts')]
    public function test_호스트_규칙_거부(mixed $host): void
    {
        $this->assertFalse(CspSourceHost::isValid($host), is_string($host) ? $host : 'non-string');
    }

    /**
     * 모드·호스트 저장 — 정규화(trim·소문자·중복 제거)되어 저장되고 응답 `_meta.csp` 가 저장값을 반영한다.
     *
     * @scenario case=input_valid_host
     *
     * @effects csp_settings_persisted_normalized, csp_meta_reflects_saved_policy
     *
     * @return void 반환값 없음
     */
    public function test_저장과_응답_메타(): void
    {
        $response = $this->saveSecurity([
            'csp_mode' => 'report_only',
            'csp_extra_script_hosts' => ['  CDN.Example.com ', 'cdn.example.com', '', '*.analytics.example.net'],
        ])->assertOk();

        $stored = app(ConfigRepositoryInterface::class)->getCategory('security');
        $this->assertSame('report_only', $stored['csp_mode']);
        $this->assertSame(['cdn.example.com', '*.analytics.example.net'], $stored['csp_extra_script_hosts']);

        $meta = $response->json('data.settings._meta.csp');
        $this->assertNull($meta['override']);
        $this->assertIsArray($meta['auto_hosts']);
        $this->assertStringContainsString('cdn.example.com', $meta['preview']);
        $this->assertStringContainsString('*.analytics.example.net', $meta['preview']);

        $index = $this->withHeader('Authorization', 'Bearer '.$this->token)->getJson('/api/admin/settings')->assertOk();
        $this->assertSame($meta['preview'], $index->json('data._meta.csp.preview'), '조회·저장 두 응답의 미리보기가 다르다');
    }

    /**
     * 잘못된 모드·호스트·개수 초과는 422 — 잘못된 값이 문구에 담긴다.
     *
     * @scenario case=input_invalid_host
     *
     * @effects csp_settings_rejects_invalid_input
     *
     * @return void 반환값 없음
     */
    public function test_잘못된_입력은_422(): void
    {
        $this->saveSecurity(['csp_mode' => 'strict'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['security.csp_mode']);

        $response = $this->saveSecurity(['csp_extra_script_hosts' => ['ok.example.com', "'unsafe-inline'"]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['security.csp_extra_script_hosts.1']);
        $this->assertStringContainsString("'unsafe-inline'", (string) $response->json('errors')['security.csp_extra_script_hosts.1'][0]);

        $hosts = array_map(fn ($i) => "h{$i}.example.com", range(1, 51));
        $this->saveSecurity(['csp_extra_script_hosts' => $hosts])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['security.csp_extra_script_hosts']);
    }

    /**
     * `.env` 탈출구가 있으면 응답 메타 `override` 에 그 값이 실린다(화면이 모드 선택을 잠근다).
     *
     * @scenario case=input_valid_host
     *
     * @effects csp_meta_reflects_saved_policy
     *
     * @return void 반환값 없음
     */
    public function test_탈출구는_응답_메타에_실린다(): void
    {
        config(['core.csp.mode_override' => 'off']);

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/admin/settings')
            ->assertOk()
            ->assertJsonPath('data._meta.csp.override', 'off');
    }
}
