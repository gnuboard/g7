<?php

namespace Tests\Feature\Validation;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * 전역 validation attributes 라벨 정화 회귀 테스트 (공개이슈 #113)
 *
 * 전역 `validation.attributes` 는 모든 FormRequest 가 공유하는 라벨 사전입니다.
 * 여기에 특정 도메인 문구(예: 'SMTP 비밀번호')를 넣으면, 그 필드명을 쓰는 모든
 * 화면의 오류 문구가 오염됩니다. 이 테스트는 ① 범용 키가 범용 라벨을 유지하고,
 * ② 도메인 전용 키(smtp_*, user_ids 등)가 의도 소비자에서만 쓰이는지 고정합니다.
 */
class GlobalAttributeLabelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * D1: 회원가입 password 타입 오류 문구에 SMTP 라벨이 새지 않는다.
     *
     * @scenario entry_point=register, attribute_label_scope=generic_password, locale=ko
     *
     * @effects smtp_label_never_appears_in_password_setting_paths
     */
    public function test_register_password_type_error_uses_generic_password_label(): void
    {
        $response = $this->withHeaders(['Accept-Language' => 'ko'])
            ->postJson('/api/auth/register', [
                'email' => 'g7fix113.d1@example.com',
                'password' => 12345678,
                'password_confirmation' => 12345678,
                'name' => '테스트',
            ]);

        $response->assertStatus(422);

        $message = json_encode($response->json('errors.password'), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('SMTP', (string) $message);
        $this->assertStringContainsString('비밀번호', (string) $message);
    }

    /**
     * D2: 비밀번호 확인 엔드포인트(messages() 미정의)의 required 문구 — 제보 문구 경로.
     *
     * @scenario entry_point=verify_password, attribute_label_scope=generic_password, locale=ko
     *
     * @effects smtp_label_never_appears_in_password_setting_paths
     */
    public function test_verify_password_required_message_uses_generic_password_label(): void
    {
        $user = User::factory()->create();

        $response = $this->withToken($user->createToken('t')->plainTextToken)
            ->postJson('/api/me/verify-password', []);

        $response->assertStatus(422);

        $message = (string) json_encode($response->json('errors.password'), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('SMTP', $message);
        $this->assertStringContainsString('비밀번호', $message);
    }

    /**
     * D3: PasswordPolicy 규칙 실패 메시지의 :attribute 치환값.
     *
     * @scenario entry_point=register, attribute_label_scope=generic_password, input_state=violates_length, locale=ko
     *
     * @effects policy_message_attribute_uses_generic_password_label
     */
    public function test_password_policy_message_uses_generic_password_label(): void
    {
        $response = $this->withHeaders(['Accept-Language' => 'ko'])
            ->postJson('/api/auth/register', [
                'email' => 'g7fix113.d3@example.com',
                'password' => 'a1',
                'password_confirmation' => 'a1',
                'name' => '테스트',
            ]);

        $response->assertStatus(422);

        $message = (string) json_encode($response->json('errors.password'), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('SMTP', $message);
        // 부정 단언만 두면 라벨이 통째로 빠져도 통과한다 — 실제 라벨이 들어갔는지 함께 본다.
        $this->assertStringContainsString('비밀번호', $message);
    }

    /**
     * D4: SMTP 연결 테스트는 도메인 라벨('SMTP 호스트'/'SMTP 비밀번호')을 유지한다.
     *
     * @scenario attribute_label_scope=smtp_password, locale=ko
     *
     * @effects smtp_screens_keep_their_domain_labels
     */
    public function test_test_mail_request_keeps_smtp_labels(): void
    {
        $admin = $this->createAdmin();

        // host 누락 (required, messages() 경로)
        $response = $this->withToken($admin->createToken('t')->plainTextToken)
            ->postJson('/api/admin/settings/test-mail', [
                'to_email' => 'a@example.com',
                'from_address' => 'b@example.com',
                'from_name' => '발신자',
                'mailer' => 'smtp',
                'port' => 587,
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'SMTP',
            (string) json_encode($response->json('errors.host'), JSON_UNESCAPED_UNICODE)
        );

        // password 배열 전송 (string 규칙, attributes() 경로)
        $response = $this->withToken($admin->createToken('t2')->plainTextToken)
            ->postJson('/api/admin/settings/test-mail', [
                'to_email' => 'a@example.com',
                'from_address' => 'b@example.com',
                'from_name' => '발신자',
                'mailer' => 'smtp',
                'host' => 'smtp.example.com',
                'port' => 587,
                'password' => ['x'],
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'SMTP',
            (string) json_encode($response->json('errors.password'), JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * D5: 사용자 일괄 상태변경은 '사용자 ID 목록', 전역 ids 라벨은 범용 문구.
     *
     * @scenario attribute_label_scope=user_scoped_ids, locale=ko
     *
     * @effects bulk_status_screen_keeps_user_scoped_ids_label, generic_ids_label_stays_generic_on_other_screens
     */
    public function test_bulk_user_status_keeps_user_scoped_ids_label(): void
    {
        $admin = $this->createAdmin();

        $response = $this->withToken($admin->createToken('t')->plainTextToken)
            ->patchJson('/api/admin/users/bulk-status', [
                'status' => 'active',
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString(
            '사용자 ID 목록',
            (string) json_encode($response->json('errors.ids'), JSON_UNESCAPED_UNICODE)
        );

        // 전역 라벨 자체는 범용으로 원복되어 있어야 한다.
        $this->assertSame('ID 목록', __('validation.attributes.ids'));
        $this->assertSame('사용자 ID 목록', __('validation.attributes.user_ids'));

        // 같은 전역 키를 쓰는 다른 화면은 범용 라벨을 받는다 (HTTP 경로로 확인).
        // read-batch 는 ids 에 커스텀 메시지가 없어 전역 attributes 라벨이 그대로 드러난다.
        $other = $this->withHeaders(['Accept-Language' => 'ko'])
            ->withToken($admin->createToken('t2')->plainTextToken)
            ->postJson('/api/admin/notifications/read-batch', []);

        $other->assertStatus(422);

        $otherMessage = (string) json_encode($other->json('errors.ids'), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('사용자 ID 목록', $otherMessage);
        $this->assertStringContainsString('ID 목록', $otherMessage);
    }

    /**
     * D6: en / ja 로케일에서도 범용 키가 범용 라벨을 유지한다.
     *
     * @scenario entry_point=register, attribute_label_scope=generic_password, locale=en
     *
     * @effects generic_labels_hold_in_every_locale
     */
    public function test_generic_labels_are_clean_in_other_locales(): void
    {
        // en 은 HTTP 경로로도 확인한다 — 사전값만 보면 요청 경로의 회귀를 놓친다.
        $response = $this->withHeaders(['Accept-Language' => 'en'])
            ->postJson('/api/auth/register', [
                'email' => 'g7fix113.d6@example.com',
                'password' => 12345678,
                'password_confirmation' => 12345678,
                'name' => 'Matrix',
            ]);

        $response->assertStatus(422);

        $enMessage = (string) json_encode($response->json('errors.password'), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('SMTP', $enMessage);
        $this->assertStringContainsString('password', $enMessage);

        App::setLocale('en');

        $this->assertSame('password', __('validation.attributes.password'));
        $this->assertSame('SMTP password', __('validation.attributes.smtp_password'));
        $this->assertSame('ID list', __('validation.attributes.ids'));
        $this->assertSame('user ID list', __('validation.attributes.user_ids'));

        App::setLocale('ko');

        // ja 는 언어팩(런타임 설치본)이라 테스트 환경 로케일로 해석되지 않는다 —
        // 번들 원본을 직접 읽어 3로케일 동형 적용을 고정한다.
        $ja = require base_path('lang-packs/_bundled/g7-core-ja/backend/ja/validation.php');

        $this->assertSame('パスワード', $ja['attributes']['password']);
        $this->assertSame('SMTPパスワード', $ja['attributes']['smtp_password']);
        $this->assertSame('ID一覧', $ja['attributes']['ids']);
        $this->assertSame('ユーザーID一覧', $ja['attributes']['user_ids']);
        $this->assertSame('言語', $ja['attributes']['language']);
        $this->assertSame('通貨', $ja['attributes']['currency']);
    }

    /**
     * D-extra: 범용 키 전수 — 도메인 문구가 다시 새어 들어오는 회귀를 차단한다.
     *
     * @scenario attribute_label_scope=generic_ids, locale=ko
     *
     * @effects generic_attribute_keys_carry_no_domain_prefix
     */
    public function test_generic_attribute_keys_are_free_of_domain_prefixes(): void
    {
        $expected = [
            'password' => '비밀번호',
            'host' => '호스트',
            'port' => '포트',
            'username' => '사용자명',
            'ids' => 'ID 목록',
            'language' => '언어',
            'currency' => '통화',
            'cache_ttl' => '캐시 유지시간',
            'channels' => '채널',
        ];

        foreach ($expected as $key => $label) {
            $this->assertSame($label, __("validation.attributes.{$key}"), "attributes.{$key}");
        }

        $domain = [
            'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password',
            'user_ids', 'default_language', 'default_currency',
            'seo_page_cache_ttl', 'notification_channels',
        ];

        foreach ($domain as $key) {
            $this->assertNotSame(
                "validation.attributes.{$key}",
                __("validation.attributes.{$key}"),
                "도메인 전용 키 미정의: {$key}"
            );
        }
    }

    /**
     * 관리자 사용자 생성 헬퍼
     */
    private function createAdmin(): User
    {
        $user = User::factory()->create(['email' => 'g7fix113.admin@example.com']);

        $permissionIds = [];
        foreach (['core.settings.update', 'core.users.update', 'core.notifications.update'] as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            );
            $permissionIds[] = $permission->id;
        }

        $role = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'is_active' => true,
            ]
        );
        $role->permissions()->syncWithoutDetaching($permissionIds);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }
}
