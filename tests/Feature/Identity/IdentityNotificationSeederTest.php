<?php

namespace Tests\Feature\Identity;

use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use App\Notifications\Identity\IdentityNotificationType;
use Database\Seeders\IdentityMessageDefinitionSeeder;
use Database\Seeders\NotificationDefinitionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 본인인증 알림 정의 시딩 테스트 (#699 — 옛 IdentityMessageSeederTest 대체)
 *
 * 본인인증 메시지는 config/core.php 의 notification_definitions 에 `identity.*` type 으로 선언되고
 * NotificationDefinitionSeeder 가 함께 동기화한다. 점(.)이 든 type 을 config 연관 배열 키로 둬도
 * 전량 로드가 안전한지, 재시딩이 운영자 수정을 보존하는지, 옛 시더가 무동작 호환 심인지 잠근다.
 */
class IdentityNotificationSeederTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 코어 선언 6건(기본 + 목적 5)이 category=identity 로 등록된다 — login 정의 신설 포함.
     *
     * @scenario surface=seeding
     *
     * @effects identity_definitions_seeded_with_category
     *
     * @return void 반환값 없음
     */
    public function test_seeder_registers_identity_definitions(): void
    {
        $this->seed(NotificationDefinitionSeeder::class);

        $types = NotificationDefinition::where('category', IdentityNotificationType::CATEGORY)
            ->where('extension_identifier', 'core')->orderBy('type')->pluck('type')->all();

        $this->assertSame([
            'identity.default',
            'identity.purpose.login',
            'identity.purpose.password_reset',
            'identity.purpose.self_update',
            'identity.purpose.sensitive_action',
            'identity.purpose.signup',
        ], $types);

        $login = NotificationDefinition::where('type', 'identity.purpose.login')->firstOrFail();
        $this->assertSame([], $login->hooks);
        $this->assertSame('core.identity', $login->hook_prefix);
        $this->assertSame(
            array_column(IdentityNotificationType::commonVariables(), 'key'),
            array_column($login->variables, 'key')
        );
    }

    /**
     * 점(.)이 든 type 키를 config 연관 배열로 둬도 전량 로드·개별 조회가 된다.
     *
     * @scenario surface=seeding
     *
     * @effects dotted_type_config_keys_safe
     *
     * @return void 반환값 없음
     */
    public function test_dotted_type_keys_load_from_config(): void
    {
        $all = (array) config('core.notification_definitions');

        $this->assertArrayHasKey('identity.purpose.login', $all);
        $this->assertSame('identity', $all['identity.purpose.login']['category']);
        $this->assertArrayNotHasKey('identity_messages', (array) config('core'));
    }

    /**
     * 비밀번호 재설정은 링크({action_url}), 회원가입은 인증번호({code}), 로그인은 전용 문구다.
     *
     * @scenario surface=seeding
     *
     * @effects identity_template_render_hints
     *
     * @return void 반환값 없음
     */
    public function test_templates_follow_render_hints(): void
    {
        $this->seed(NotificationDefinitionSeeder::class);

        $body = fn (string $type) => NotificationTemplate::whereHas('definition', fn ($q) => $q->where('type', $type))
            ->where('channel', 'mail')->firstOrFail();

        $this->assertStringContainsString('{action_url}', $body('identity.purpose.password_reset')->body['ko']);
        $this->assertStringContainsString('{code}', $body('identity.purpose.signup')->body['ko']);
        $login = $body('identity.purpose.login');
        $this->assertStringContainsString('로그인 인증번호 안내', $login->subject['ko']);
        $this->assertStringContainsString('Login Verification Code', $login->subject['en']);
        $this->assertStringContainsString('{code}', $login->body['en']);
    }

    /**
     * 재시딩은 운영자가 고친 템플릿 제목을 덮어쓰지 않는다.
     *
     * @scenario surface=seeding
     *
     * @effects identity_user_overrides_preserved_on_reseed
     *
     * @return void 반환값 없음
     */
    public function test_reseed_preserves_operator_edits(): void
    {
        $this->seed(NotificationDefinitionSeeder::class);

        $template = NotificationTemplate::whereHas('definition', fn ($q) => $q->where('type', 'identity.purpose.signup'))
            ->where('channel', 'mail')->firstOrFail();
        $template->update(['subject' => ['ko' => '[테스트] 운영자 제목', 'en' => '[Test] Operator subject']]);

        $this->seed(NotificationDefinitionSeeder::class);

        $this->assertSame('[테스트] 운영자 제목', $template->fresh()->subject['ko']);
    }

    /**
     * 옛 IDV 메시지 시더는 무동작 호환 심이다 — 예외 없이 끝나고 알림 정의를 건드리지 않는다.
     *
     * @scenario surface=seeding
     *
     * @effects legacy_seeder_is_noop_shim
     *
     * @return void 반환값 없음
     */
    public function test_legacy_seeder_is_noop(): void
    {
        $before = DB::table('notification_definitions')->count();

        (new IdentityMessageDefinitionSeeder)->run();

        $this->assertSame($before, DB::table('notification_definitions')->count());
    }
}
