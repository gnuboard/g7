<?php

namespace Tests\Feature\Notifications;

use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use Database\Seeders\NotificationDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 비밀번호 만료 사전 안내 사이트 알림 본문 테스트 (#736)
 *
 * 사이트 알림(database 채널) 본문은 받는 회원 이름으로 시작한다 — 「{name}님, 비밀번호가 {expires_at}에
 * 만료됩니다…」(2026-10-04 결정, 디자인 캔버스 NotificationsUser·NotificationsAdmin). 선언(config)과
 * 시더가 설치한 템플릿 양쪽을 본다 — 선언만 바꾸고 시더 동기화가 빠지면 설치본은 옛 문구로 남는다.
 */
class PasswordExpiryNoticeBodyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 선언의 사이트 알림 본문이 ko·en 모두 이름 변수로 시작한다.
     *
     * @effects notice_site_body_starts_with_member_name
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_declared_site_notice_body_starts_with_the_member_name(): void
    {
        $templates = config('core.notification_definitions.password_expiry_notice.templates');
        $database = collect($templates)->firstWhere('channel', 'database');

        $this->assertStringStartsWith('{name}님, 비밀번호가 {expires_at}에 만료됩니다.', $database['body']['ko']);
        $this->assertStringStartsWith('{name}, your password expires on {expires_at}.', $database['body']['en']);
    }

    /**
     * 시더가 설치한 사이트 알림 템플릿도 같은 본문이다(설치본 동기화).
     *
     * @effects notice_site_body_starts_with_member_name
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_seeded_site_notice_template_uses_the_new_body(): void
    {
        $this->seed(NotificationDefinitionSeeder::class);

        $definition = NotificationDefinition::where('type', 'password_expiry_notice')->firstOrFail();
        $template = NotificationTemplate::where('definition_id', $definition->id)->where('channel', 'database')->firstOrFail();

        $this->assertStringStartsWith('{name}님, ', $template->body['ko']);
        $this->assertStringStartsWith('{name}, ', $template->body['en']);
    }
}
