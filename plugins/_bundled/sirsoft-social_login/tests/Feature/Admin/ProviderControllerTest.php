<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\SensitiveSettingMask;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\SocialLogin\Database\Seeders\ProviderSeeder;
use Plugins\Sirsoft\SocialLogin\Drivers\FacebookDriver;
use Plugins\Sirsoft\SocialLogin\Drivers\GoogleDriver;
use Plugins\Sirsoft\SocialLogin\Drivers\KakaoDriver;
use Plugins\Sirsoft\SocialLogin\Drivers\NaverDriver;
use Plugins\Sirsoft\SocialLogin\Drivers\OidcDriver;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginProvider;
use Plugins\Sirsoft\SocialLogin\Services\ProviderRegistry;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;

/**
 * 관리자 제공자 설정 API — 권한, 내장 키 불변, 비밀값 마스킹 4경우, 일반 OIDC 추가·한도·삭제·정렬, 영향 조회, 활동 로그.
 *
 * @since 1.0.0
 */
class ProviderControllerTest extends PluginTestCase
{
    /** API 접두 */
    private const BASE = '/api/plugins/sirsoft-social_login/admin/providers';

    /** 열람·변경 권한 */
    private const BOTH = ['sirsoft-social_login.settings.view', 'sirsoft-social_login.settings.update'];

    /**
     * 내장 제공자를 시딩합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        (new ProviderSeeder)->run();
        app(ProviderRegistry::class)->flush();
    }

    /**
     * 비로그인 401, 관리자 아님 403, 열람 권한만 있으면 목록 200·변경 403, 변경 권한까지 있으면 변경 200.
     *
     * @scenario actor=guest|member|viewer|editor
     *
     * @effects guest_401, member_403, viewer_reads_list, viewer_cannot_update, editor_updates
     *
     * @return void 반환값 없음
     */
    public function test_permission_matrix(): void
    {
        $this->getJson(self::BASE)->assertStatus(401);

        Sanctum::actingAs(User::factory()->create(['email' => $this->uniqueEmail('member')]));
        $this->getJson(self::BASE)->assertStatus(403);

        Sanctum::actingAs($this->makeAdminUser(['email' => $this->uniqueEmail('viewer')]));
        $list = $this->getJson(self::BASE)->assertOk();
        $this->assertFalse($list->json('data.abilities.can_update'));
        $this->assertSame(['kakao', 'naver', 'google', 'facebook'], array_slice(array_column($list->json('data.providers'), 'key'), 0, 4));
        $this->putJson(self::BASE.'/kakao', ['client_id' => 'x'])->assertStatus(403);
        $this->postJson(self::BASE.'/kakao/test')->assertStatus(403);

        Sanctum::actingAs($this->makeAdminUser(['email' => $this->uniqueEmail('editor')], self::BOTH));
        $this->assertTrue($this->getJson(self::BASE)->json('data.abilities.can_update'));
        $this->putJson(self::BASE.'/kakao', ['client_id' => 'kakao-rest'])->assertOk()->assertJsonPath('data.client_id', 'kakao-rest');
    }

    /**
     * 비밀값은 값 대신 마스크(설정됨)/null(미설정)로만 나가고, 마스크를 돌려보내면 유지, 빈 문자열이면 삭제, 새 값이면 교체된다.
     * 키·드라이버는 요청으로 바뀌지 않고, 내장 제공자의 주소 칸은 무시된다.
     *
     * @scenario secret=unset|set|mask_echo|empty_string
     *
     * @effects secret_unset_is_null, secret_set_is_masked, mask_echo_keeps_value, empty_string_deletes, builtin_key_immutable
     *
     * @return void 반환값 없음
     */
    public function test_secret_masking_four_cases(): void
    {
        Sanctum::actingAs($this->makeAdminUser(['email' => $this->uniqueEmail('mask')], self::BOTH));

        $this->assertNull($this->kakaoRow()['client_secret']);

        $saved = $this->putJson(self::BASE.'/kakao', ['client_id' => 'id-1', 'client_secret' => 'first-secret', 'admin_key' => 'admin-1'])->assertOk();
        $this->assertSame(SensitiveSettingMask::MASK, $saved->json('data.client_secret'));
        $this->assertSame(SensitiveSettingMask::MASK, $saved->json('data.admin_key'));
        $this->assertStringNotContainsString('first-secret', (string) $saved->getContent());
        $this->assertStringNotContainsString('first-secret', (string) $this->getJson(self::BASE)->getContent());

        $this->putJson(self::BASE.'/kakao', ['client_secret' => SensitiveSettingMask::MASK, 'client_id' => 'id-2'])->assertOk();
        $row = SocialLoginProvider::query()->where('key', 'kakao')->firstOrFail();
        $this->assertSame('first-secret', $row->client_secret);
        $this->assertSame('id-2', $row->client_id);

        $this->putJson(self::BASE.'/kakao', ['client_secret' => 'second-secret'])->assertOk();
        $this->assertSame('second-secret', $row->refresh()->client_secret);

        $this->putJson(self::BASE.'/kakao', ['admin_key' => ''])->assertOk()->assertJsonPath('data.admin_key', null);
        $this->assertNull($row->refresh()->admin_key);

        $this->putJson(self::BASE.'/kakao', ['key' => 'renamed', 'driver' => 'oidc', 'authorize_url' => 'https://evil.example/authorize'])->assertOk();
        $row->refresh();
        $this->assertSame(['kakao', 'kakao', null], [$row->key, $row->driver, $row->authorize_url]);
    }

    /**
     * 제공자 편집 칸은 드라이버별 고정 칸이다 — 드라이버 5종의 `credentialSchema()` 키가 모두 편집 칸 입력(name)으로 있어야 한다.
     * 드라이버에 자격증명 칸을 더하고 화면을 잊으면 red.
     *
     * @scenario credentials=schema_fields
     *
     * @effects editor_covers_credential_schema
     *
     * @return void 반환값 없음
     */
    public function test_editor_fields_cover_credential_schema(): void
    {
        $editor = (string) file_get_contents(dirname(__DIR__, 3).'/resources/layouts/admin/partials/plugin_settings/_provider_editor.json');
        $drivers = [new KakaoDriver, new NaverDriver, new GoogleDriver, new FacebookDriver, new OidcDriver];

        $missing = [];
        foreach ($drivers as $driver) {
            foreach ($driver->credentialSchema() as $field) {
                if (! str_contains($editor, '"name": "'.$field['key'].'"')) {
                    $missing[] = $driver->driverName().'.'.$field['key'];
                }
            }
        }

        $this->assertSame([], $missing);
    }

    /**
     * 카카오 웹훅 앱 ID 는 관리자 화면에서 넣고(숫자만) 응답에 그대로 보이며 `extra.app_id` 로 저장된다. 비우면 대조하지 않는다.
     * 카카오가 아닌 제공자에는 저장하지 않는다.
     *
     * @scenario provider=kakao, action=webhook_app_id
     *
     * @effects kakao_webhook_app_id_editable
     *
     * @return void 반환값 없음
     */
    public function test_kakao_webhook_app_id_is_editable(): void
    {
        Sanctum::actingAs($this->makeAdminUser(['email' => $this->uniqueEmail('appid')], self::BOTH));

        $this->putJson(self::BASE.'/kakao', ['webhook_app_id' => '1234567'])->assertOk()->assertJsonPath('data.webhook_app_id', '1234567');
        $this->assertSame('1234567', SocialLoginProvider::query()->where('key', 'kakao')->first()->extra['app_id'] ?? null);

        $this->putJson(self::BASE.'/kakao', ['webhook_app_id' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('webhook_app_id');

        $this->putJson(self::BASE.'/kakao', ['webhook_app_id' => ''])->assertOk()->assertJsonPath('data.webhook_app_id', null);
        $this->assertArrayNotHasKey('app_id', SocialLoginProvider::query()->where('key', 'kakao')->first()->extra ?? []);

        $this->putJson(self::BASE.'/naver', ['webhook_app_id' => '999'])->assertOk();
        $this->assertArrayNotHasKey('app_id', SocialLoginProvider::query()->where('key', 'naver')->first()->extra ?? []);
    }

    /**
     * 자격증명 없이 켜면 422, 넣고 켜면 200 — 켠 제공자는 공개 버튼 목록에 나온다. 모의 표지·주소 덮어쓰기(`extra`)는 받지 않는다.
     *
     * @scenario enable=without_credentials|with_credentials
     *
     * @effects enable_without_credentials_refused, enabled_provider_listed_publicly, extra_mock_flag_ignored
     *
     * @return void 반환값 없음
     */
    public function test_enable_requires_credentials(): void
    {
        Sanctum::actingAs($this->makeAdminUser(['email' => $this->uniqueEmail('enable')], self::BOTH));

        $this->putJson(self::BASE.'/naver', ['enabled' => true])->assertStatus(422)->assertJsonPath('errors.code', 'credentials_required');

        $this->putJson(self::BASE.'/naver', [
            'enabled' => true, 'client_id' => 'n-id', 'client_secret' => 'n-secret',
            'extra' => ['mock' => true, 'endpoint_override' => ['token' => 'https://evil.example']],
        ])->assertOk()->assertJsonPath('data.enabled', true);

        $this->assertNull(SocialLoginProvider::query()->where('key', 'naver')->value('extra'));
        $this->assertContains('naver', array_column($this->getJson('/api/plugins/sirsoft-social_login/providers')->json('data'), 'key'));
    }

    /**
     * 설정 화면은 요청 범위를 한 줄 문자열(`scopes_text`)로 보낸다 — 서버가 공백·쉼표로 나눠 배열로 저장한다(화면 표현식은 정규식을
     * 쓸 수 없다). 화면은 편집 칸을 통째로 보내 옛 `scopes` 배열도 함께 오므로 `scopes_text` 가 이긴다. 문자열 없이 배열만 오면 그대로 받는다.
     *
     * @scenario scopes=text|array
     *
     * @effects scopes_text_split_by_server, scopes_array_kept
     *
     * @return void 반환값 없음
     */
    public function test_scopes_text_is_split_by_server(): void
    {
        Sanctum::actingAs($this->makeAdminUser(['email' => $this->uniqueEmail('scopes')], self::BOTH));

        $this->putJson(self::BASE.'/google', ['scopes_text' => ' openid, email  profile ,'])->assertOk()
            ->assertJsonPath('data.scopes', ['openid', 'email', 'profile']);
        $this->assertSame(['openid', 'email', 'profile'], SocialLoginProvider::query()->where('key', 'google')->value('scopes'));

        $this->putJson(self::BASE.'/google', ['scopes' => [], 'scopes_text' => 'openid profile'])->assertOk()
            ->assertJsonPath('data.scopes', ['openid', 'profile']);

        $this->putJson(self::BASE.'/google', ['scopes' => ['openid']])->assertOk()->assertJsonPath('data.scopes', ['openid']);
    }

    /**
     * 일반 OIDC 제공자: 예약 키 거절 → 추가(꺼진 상태) → 한도에서 422 → 정렬 → 연결이 남으면 삭제 409 → 연결을 지우면 삭제.
     * 내장 제공자는 삭제할 수 없다. 추가·수정·삭제는 활동 로그에 남는다(비밀값 없음).
     *
     * @scenario custom=reserved_key|create|limit|reorder|delete_with_links|delete
     *
     * @effects reserved_key_refused, custom_created_disabled, custom_limit_enforced, reorder_saved, delete_blocked_by_links, builtin_not_deletable, provider_activity_logged
     *
     * @return void 반환값 없음
     */
    public function test_custom_provider_lifecycle(): void
    {
        $admin = $this->makeAdminUser(['email' => $this->uniqueEmail('custom')], self::BOTH);
        Sanctum::actingAs($admin);
        $this->setPluginSettings(['custom_provider_limit' => 1]);

        $payload = [
            'key' => 'corp', 'label' => ['ko' => '사내 계정', 'en' => 'Company Account'],
            'client_id' => 'corp-id', 'client_secret' => 'corp-secret', 'discovery_url' => 'https://idp.example.test/.well-known/openid-configuration',
        ];

        foreach (['kakao', 'mock_corp', 'oidc'] as $reserved) {
            $this->postJson(self::BASE, ['key' => $reserved] + $payload)->assertStatus(422)->assertJsonPath('errors.code', 'key_taken');
        }

        $created = $this->postJson(self::BASE, $payload)->assertStatus(201);
        $this->assertSame(['custom', 'oidc', false], [$created->json('data.type'), $created->json('data.driver'), $created->json('data.enabled')]);
        $this->assertSame(SensitiveSettingMask::MASK, $created->json('data.client_secret'));

        $this->postJson(self::BASE, ['key' => 'corp2'] + $payload)->assertStatus(422)->assertJsonPath('errors.code', 'custom_limit_reached');

        $order = $this->postJson(self::BASE.'/reorder', ['keys' => ['corp', 'google']])->assertOk()->json('data.keys');
        $this->assertSame(['corp', 'google', 'kakao', 'naver', 'facebook'], $order);
        $this->assertSame('corp', array_column($this->getJson(self::BASE)->json('data.providers'), 'key')[0]);

        $member = User::factory()->create(['email' => $this->uniqueEmail('linked')]);
        $account = SocialAccount::query()->create(['user_id' => $member->id, 'provider_key' => 'corp', 'provider_user_id' => 'sub-1', 'linked_at' => now()]);
        $this->deleteJson(self::BASE.'/corp')->assertStatus(409)->assertJsonPath('errors.code', 'provider_has_links');
        $account->delete();
        $this->deleteJson(self::BASE.'/corp')->assertOk();
        $this->assertNull(SocialLoginProvider::query()->where('key', 'corp')->first());

        $this->deleteJson(self::BASE.'/kakao')->assertStatus(422)->assertJsonPath('errors.code', 'builtin_not_deletable');

        foreach (['provider_created', 'provider_deleted'] as $action) {
            $this->assertDatabaseHas('activity_logs', ['user_id' => $admin->id, 'action' => $action]);
        }
        $this->assertSame(0, ActivityLog::query()->where('properties', 'like', '%corp-secret%')->count());
    }

    /**
     * discovery 조회는 문서에서 주소를 읽어 돌려주고(저장하지 않음), 필수 키가 없거나 받지 못하면 422 와 코드다.
     *
     * @scenario discovery=ok|incomplete|failed
     *
     * @effects discovery_fills_endpoints, discovery_incomplete_refused, discovery_failure_refused
     *
     * @return void 반환값 없음
     */
    public function test_discover(): void
    {
        Sanctum::actingAs($this->makeAdminUser(['email' => $this->uniqueEmail('disc')], self::BOTH));
        Http::fake([
            'ok.example.test/*' => Http::response([
                'issuer' => 'https://ok.example.test', 'authorization_endpoint' => 'https://ok.example.test/auth',
                'token_endpoint' => 'https://ok.example.test/token', 'jwks_uri' => 'https://ok.example.test/jwks',
                'userinfo_endpoint' => 'https://ok.example.test/userinfo',
            ]),
            'partial.example.test/*' => Http::response(['issuer' => 'https://partial.example.test']),
            'down.example.test/*' => Http::response('', 503),
        ]);

        $this->postJson(self::BASE.'/discover', ['discovery_url' => 'https://ok.example.test/.well-known/openid-configuration'])
            ->assertOk()
            ->assertJsonPath('data.token_url', 'https://ok.example.test/token')
            ->assertJsonPath('data.jwks_url', 'https://ok.example.test/jwks');
        $this->postJson(self::BASE.'/discover', ['discovery_url' => 'https://partial.example.test/.well-known/openid-configuration'])
            ->assertStatus(422)->assertJsonPath('errors.code', 'discovery_incomplete');
        $this->postJson(self::BASE.'/discover', ['discovery_url' => 'https://down.example.test/.well-known/openid-configuration'])
            ->assertStatus(422)->assertJsonPath('errors.code', 'discovery_failed')
            // 관리자 화면은 원인을 알려 주는 관리자 문구 — 회원 흐름의 같은 코드는 공통 문구다(SocialFlowExceptionTest).
            ->assertJsonPath('message', __('sirsoft-social_login::exceptions.admin.discovery_failed'));
        $this->postJson(self::BASE.'/discover', ['discovery_url' => 'http://plain.example.test/x'])->assertStatus(422);
    }

    /**
     * 영향 조회는 「이 제공자로만 로그인하는 회원」(비밀번호 없음 + 연결 1개)과 연결 회원 수를 센다.
     *
     * @scenario member=social_only|password_and_social|two_providers
     *
     * @effects impact_counts_social_only, impact_counts_linked
     *
     * @return void 반환값 없음
     */
    public function test_impact_counts(): void
    {
        Sanctum::actingAs($this->makeAdminUser(['email' => $this->uniqueEmail('impact')]));

        $only = User::factory()->passwordless()->create(['email' => $this->uniqueEmail('only')]);
        $withPassword = User::factory()->create(['email' => $this->uniqueEmail('pw')]);
        $two = User::factory()->passwordless()->create(['email' => $this->uniqueEmail('two')]);
        foreach ([[$only, 'kakao', 'k1'], [$withPassword, 'kakao', 'k2'], [$two, 'kakao', 'k3'], [$two, 'naver', 'n3']] as [$user, $provider, $uid]) {
            SocialAccount::query()->create(['user_id' => $user->id, 'provider_key' => $provider, 'provider_user_id' => $uid, 'linked_at' => now()]);
        }

        $impact = $this->getJson(self::BASE.'/kakao/impact')->assertOk();
        $this->assertSame(1, $impact->json('data.social_only_users'));
        $this->assertSame(3, $impact->json('data.linked_users'));
        $this->getJson(self::BASE.'/unknown/impact')->assertStatus(404);
    }

    /**
     * 목록의 카카오 행.
     *
     * @return array<string, mixed> 행
     */
    private function kakaoRow(): array
    {
        $rows = collect($this->getJson(self::BASE)->assertOk()->json('data.providers'));

        return $rows->firstWhere('key', 'kakao');
    }
}
