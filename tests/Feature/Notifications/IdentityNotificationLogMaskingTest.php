<?php

namespace Tests\Feature\Notifications;

use App\Models\NotificationLog;
use App\Models\User;
use App\Notifications\GenericNotification;
use App\Services\IdentityMessageDispatcher;
use App\Services\NotificationDefinitionService;
use Database\Seeders\NotificationDefinitionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\Concerns\CapturesSentMail;
use Tests\TestCase;

/**
 * 본인인증 알림 발송 로그 마스킹 테스트 (#699 W3)
 *
 * 통합 전에는 본인인증 메일이 발송 로그에 남지 않았다. 알림 시스템을 거치면서 로그에 남게 되므로,
 * 인증번호·검증 링크(토큰)가 관리자 로그 화면에 평문으로 노출되지 않는지 잠근다. 수신자가 받는
 * 메일 자체는 가리지 않는다.
 */
class IdentityNotificationLogMaskingTest extends TestCase
{
    use CapturesSentMail;
    use DatabaseTransactions;

    /**
     * 알림 정의를 시딩하고 array 메일러를 켭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(NotificationDefinitionSeeder::class);
        app(NotificationDefinitionService::class)->invalidateAllCache();
        $this->useArrayMailer();
    }

    /**
     * 인증번호(text_code) 메일 — 로그 제목·본문에 코드 평문 0, ****** 있음, 실제 메일에는 코드가 있다.
     *
     * @scenario surface=log_masking
     *
     * @effects log_masks_code
     *
     * @return void 반환값 없음
     */
    public function test_code_is_masked_in_log_but_not_in_mail(): void
    {
        $this->dispatch('signup', 'text_code', ['code' => '493817']);

        $log = $this->latestLog('identity.purpose.signup');
        $this->assertStringNotContainsString('493817', (string) $log->body);
        $this->assertStringNotContainsString('493817', (string) $log->subject);
        $this->assertStringContainsString('******', (string) $log->body);
        $this->assertStringContainsString('493817', $this->sentMails()[0]['html']);
    }

    /**
     * 검증 링크(link) 메일 — 토큰이 든 링크(HTML 이스케이프 형태 포함)가 로그에 남지 않는다.
     *
     * @scenario surface=log_masking
     *
     * @effects log_masks_action_url
     *
     * @return void 반환값 없음
     */
    public function test_action_url_is_masked_in_log(): void
    {
        $url = 'https://example.test/identity/verify?challenge=abc-123&token=SECRETTOKEN0123456789';

        $this->dispatch('password_reset', 'link', ['action_url' => $url]);

        $log = $this->latestLog('identity.purpose.password_reset');
        $this->assertStringNotContainsString('SECRETTOKEN0123456789', (string) $log->body);
        $this->assertStringContainsString('******', (string) $log->body);
        $this->assertStringContainsString('SECRETTOKEN0123456789', $this->sentMails()[0]['html']);
    }

    /**
     * 일반 알림은 마스킹하지 않는다 — 같은 변수 이름(action_url)이라도 원문 그대로 기록된다.
     *
     * @scenario surface=log_masking
     *
     * @effects general_notification_not_masked
     *
     * @return void 반환값 없음
     */
    public function test_general_notification_is_not_masked(): void
    {
        $user = User::factory()->create(['email' => 'plain@example.test']);

        $user->notifyNow(new GenericNotification(
            type: 'reset_password',
            hookPrefix: 'core.auth',
            data: ['name' => '홍길동', 'action_url' => 'https://example.test/reset/PLAINTOKEN42', 'expire_minutes' => 60],
            channel: 'mail',
        ));

        $log = $this->latestLog('reset_password');
        $this->assertStringContainsString('PLAINTOKEN42', (string) $log->body);
        $this->assertStringNotContainsString('******', (string) $log->body);
    }

    /**
     * 본인인증 디스패처로 보냅니다.
     *
     * @param  string  $purpose  인증 목적
     * @param  string  $renderHint  렌더 힌트
     * @param  array  $data  변수 (code 또는 action_url)
     * @return void 반환값 없음
     */
    private function dispatch(string $purpose, string $renderHint, array $data): void
    {
        $sent = app(IdentityMessageDispatcher::class)->dispatch(
            providerId: 'g7:core.mail',
            purpose: $purpose,
            policyKey: null,
            renderHint: $renderHint,
            channel: 'mail',
            target: 'masked@example.test',
            data: array_merge(['code' => null, 'action_url' => null, 'expire_minutes' => 15, 'app_name' => 'G7'], $data),
        );

        $this->assertTrue($sent);
    }

    /**
     * 해당 type 의 가장 최근 발송 로그를 반환합니다.
     *
     * @param  string  $type  알림 type
     * @return NotificationLog 로그
     */
    private function latestLog(string $type): NotificationLog
    {
        $log = NotificationLog::where('notification_type', $type)->where('channel', 'mail')->latest('id')->first();
        $this->assertNotNull($log, "{$type} 발송 로그가 없다");

        return $log;
    }
}
