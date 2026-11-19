<?php

namespace Tests\Unit\Services;

use App\Notifications\GuestNotifiable;
use App\Services\NotificationRecipientCapability;
use App\Services\NotificationRecipientResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 수신자 규칙 `site_admin_email` — 환경설정 > 일반의 관리자 이메일로 보내기 (#888)
 *
 * 관리자 이메일(`general.admin_email`)은 저장만 되고 어디서도 쓰이지 않았다. 알림 템플릿의 받는 사람에
 * 「사이트 관리자 이메일」을 고르면 그 주소 한 곳이 비회원 수신자(메일만)가 되어야 한다. 값이 비었거나 형식이
 * 틀리면 아무에게도 보내지 않되, 원인을 찾을 수 있게 경고를 남긴다. 인라인 해석(resolve)과 분할 발송용 참조
 * 해석(resolveRefs)이 같은 답을 내야 한다 — 한쪽만 알면 분할 발송에서 수신자가 조용히 빠진다.
 */
class NotificationSiteAdminEmailRecipientTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 관리자 이메일이 있으면 비회원 수신자 1명이 되고, 두 해석 경로가 같은 수신자를 낸다.
     *
     * @scenario case=valid_resolves
     *
     * @effects site_admin_email_resolves_one_guest, site_admin_email_refs_match_inline
     *
     * @return void 반환값 없음
     */
    public function test_configured_admin_email_resolves_one_guest_recipient(): void
    {
        config(['g7_settings.core.general.admin_email' => 'ops@example.com']);
        $resolver = app(NotificationRecipientResolver::class);

        $recipients = $resolver->resolve([['type' => 'site_admin_email']], []);

        $this->assertCount(1, $recipients);
        $guest = $recipients->first();
        $this->assertInstanceOf(GuestNotifiable::class, $guest);
        $this->assertTrue($guest->isGuest());
        $this->assertSame('ops@example.com', $guest->email);

        $refs = $resolver->resolveRefs([['type' => 'site_admin_email']], []);
        $this->assertNotNull($refs);
        $this->assertCount(1, $refs);
        $this->assertSame('guest', $refs[0]['type']);
        $this->assertSame($guest->getKey(), $refs[0]['key']);
        $this->assertSame('ops@example.com', $refs[0]['guest']['email']);
    }

    /**
     * 관리자 이메일이 비었거나 형식이 틀리면 0명이고 경고 로그를 남긴다 (두 해석 경로 모두).
     *
     * @scenario case=empty_or_invalid_resolves_none
     *
     * @effects site_admin_email_empty_resolves_none_with_warning
     *
     * @return void 반환값 없음
     */
    public function test_empty_or_invalid_admin_email_resolves_nobody_and_warns(): void
    {
        // 스파이는 한 번만 건다 — 값마다 다시 걸면 앞 값의 호출이 누적돼 횟수가 어긋난다
        Log::spy();
        $values = ['', 'not-an-email', null];
        foreach ($values as $value) {
            config(['g7_settings.core.general.admin_email' => $value]);
            $resolver = app(NotificationRecipientResolver::class);

            $this->assertCount(0, $resolver->resolve([['type' => 'site_admin_email']], []), var_export($value, true));
            $this->assertSame([], $resolver->resolveRefs([['type' => 'site_admin_email']], []), var_export($value, true));
        }

        // 값마다 resolve·resolveRefs 가 한 번씩 경고한다
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, '사이트 관리자 이메일')
                && ($context['setting'] ?? null) === 'general.admin_email')
            ->times(2 * count($values));
    }

    /**
     * 사이트 관리자 이메일 수신자는 회원이 아니므로 권한 판정이 늘 거짓이다 (관리자 화면 주소를 싣지 않는다).
     *
     * @scenario case=guest_has_no_permission
     *
     * @effects site_admin_email_recipient_has_no_permission
     *
     * @return void 반환값 없음
     */
    public function test_site_admin_recipient_has_no_permission(): void
    {
        $guest = GuestNotifiable::fromSiteAdminEmail('ops@example.com');

        $this->assertNotNull($guest);
        $this->assertFalse(app(NotificationRecipientCapability::class)->can($guest, 'core.settings.read'));
        $this->assertNull(GuestNotifiable::fromSiteAdminEmail(['ops@example.com']));
    }
}
