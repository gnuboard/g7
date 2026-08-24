<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Feature\Webhook;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Cache;
use Plugins\Sirsoft\MessageBizppurio\Enums\DispatchChannel;
use Plugins\Sirsoft\MessageBizppurio\Enums\DispatchStatus;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioDispatch;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * 비즈뿌리오 webhook(URL PUSH) 수신 테스트 (계획서 D13).
 *
 * IP 화이트리스트·refkey 매칭·replay 멱등·리포트 상태 반영·잔액부족 자체알림·
 * 코어 미들웨어 제외(실 POST 200)를 검증한다.
 */
class BizppurioWebhookTest extends PluginTestCase
{
    private const ENDPOINT = '/api/plugins/sirsoft-message_bizppurio/webhook';

    /** 비즈뿌리오 공식 발송 IP 중 하나 */
    private const ALLOWED_IP = '115.71.53.78';

    /** 화이트리스트에 없는 IP */
    private const BLOCKED_IP = '203.0.113.9';

    /**
     * 테스트 환경 캐시 드라이버가 file 이라 잔액부족 쿨다운 캐시가 실행 간 잔존한다.
     * 매 테스트 전 캐시를 비워 잔액부족 알림 중복방지 테스트 간 격리를 보장한다.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * pending 상태 발송 이력 1건 생성.
     *
     * @param  string  $refkey  우리 부여 키
     * @param  string  $channel  채널
     * @param  array<string, mixed>|null  $requestPayload  발송 시점 요청 payload (resend 포함 여부 검증용)
     * @return BizppurioDispatch
     */
    private function seedDispatch(string $refkey, string $channel = 'sms', ?array $requestPayload = null): BizppurioDispatch
    {
        return BizppurioDispatch::create([
            'refkey' => $refkey,
            'channel' => $channel,
            'to_number' => '01011112222',
            'content' => '테스트 본문',
            'notification_type' => 'welcome',
            'status' => DispatchStatus::Sent->value,
            'source' => 'auto',
            'request_payload' => $requestPayload,
            'sent_at' => now(),
        ]);
    }

    /**
     * 허용 IP 로 유효 리포트를 POST 하면 200 + 상태가 success 로 갱신.
     *
     * @scenario source=webhook,ip=allowed,result=success
     *
     * @effects dispatch_status_updated_to_success,reported_at_set
     */
    public function test_allowed_ip_success_report_updates_status(): void
    {
        $dispatch = $this->seedDispatch('ref_success');

        $response = $this->withServerVariables(['REMOTE_ADDR' => self::ALLOWED_IP])
            ->postJson(self::ENDPOINT, [
                'REFKEY' => 'ref_success',
                'RESULT' => '4100',
                'MEDIA' => 'SMS',
            ]);

        $response->assertStatus(200);

        $dispatch->refresh();
        $this->assertSame(DispatchStatus::Success, $dispatch->status);
        $this->assertSame('4100', $dispatch->result_code);
        $this->assertSame('SMS', $dispatch->media);
        $this->assertNotNull($dispatch->reported_at);
    }

    /**
     * 실패 결과코드는 status=failed 로 갱신.
     *
     * @scenario source=webhook,ip=allowed,result=failure
     *
     * @effects dispatch_status_updated_to_failed
     */
    public function test_allowed_ip_failure_report_marks_failed(): void
    {
        $dispatch = $this->seedDispatch('ref_fail');

        $this->withServerVariables(['REMOTE_ADDR' => self::ALLOWED_IP])
            ->postJson(self::ENDPOINT, ['REFKEY' => 'ref_fail', 'RESULT' => '4400'])
            ->assertStatus(200);

        $dispatch->refresh();
        $this->assertSame(DispatchStatus::Failed, $dispatch->status);
        $this->assertSame('4400', $dispatch->result_code);
    }

    /**
     * 차단 IP 는 403 + 이력 미변경.
     *
     * @scenario source=webhook,ip=blocked
     *
     * @effects webhook_blocked_ip_returns_403,dispatch_unchanged
     */
    public function test_blocked_ip_returns_403(): void
    {
        $dispatch = $this->seedDispatch('ref_blocked');

        $this->withServerVariables(['REMOTE_ADDR' => self::BLOCKED_IP])
            ->postJson(self::ENDPOINT, ['REFKEY' => 'ref_blocked', 'RESULT' => '4100'])
            ->assertStatus(403);

        $dispatch->refresh();
        $this->assertSame(DispatchStatus::Sent, $dispatch->status);
        $this->assertNull($dispatch->reported_at);
    }

    /**
     * 미매칭 refkey(위조)는 200 흡수 + 아무 이력도 변경 안 됨.
     *
     * @scenario source=webhook,ip=allowed,refkey=unknown
     *
     * @effects webhook_unknown_refkey_absorbed_200
     */
    public function test_unknown_refkey_absorbed_with_200(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => self::ALLOWED_IP])
            ->postJson(self::ENDPOINT, ['REFKEY' => 'nope', 'RESULT' => '4100'])
            ->assertStatus(200);

        $this->assertDatabaseCount('bizppurio_dispatches', 0);
    }

    /**
     * replay(이미 reported_at 있는 이력)는 멱등 — 상태를 다시 뒤집지 않음.
     *
     * @scenario source=webhook,ip=allowed,replay=true
     *
     * @effects webhook_replay_is_idempotent
     */
    public function test_replay_report_is_idempotent(): void
    {
        $dispatch = $this->seedDispatch('ref_replay');
        $dispatch->update([
            'status' => DispatchStatus::Success->value,
            'result_code' => '4100',
            'reported_at' => now()->subMinute(),
        ]);
        $firstReportedAt = $dispatch->fresh()->reported_at;

        // 두 번째(중복) 리포트가 실패 코드로 와도 성공 상태를 유지해야 함
        $this->withServerVariables(['REMOTE_ADDR' => self::ALLOWED_IP])
            ->postJson(self::ENDPOINT, ['REFKEY' => 'ref_replay', 'RESULT' => '4400'])
            ->assertStatus(200);

        $dispatch->refresh();
        $this->assertSame(DispatchStatus::Success, $dispatch->status);
        $this->assertSame('4100', $dispatch->result_code);
        $this->assertEquals($firstReportedAt->timestamp, $dispatch->reported_at->timestamp);
    }

    /**
     * 잔액부족(9070 문자)은 failed + 잔액부족 자체알림 훅 1회 발화.
     *
     * @scenario source=webhook,ip=allowed,result=balance_low_sms
     *
     * @effects webhook_balance_low_marks_failed,balance_low_hook_fired
     */
    public function test_balance_low_sms_marks_failed_and_fires_hook(): void
    {
        $dispatch = $this->seedDispatch('ref_balance', DispatchChannel::Sms->value);

        $fired = [];
        HookManager::addAction('sirsoft-message_bizppurio.balance.low', function (...$args) use (&$fired) {
            $fired[] = $args;
        });

        $this->withServerVariables(['REMOTE_ADDR' => self::ALLOWED_IP])
            ->postJson(self::ENDPOINT, ['REFKEY' => 'ref_balance', 'RESULT' => '9070'])
            ->assertStatus(200);

        $dispatch->refresh();
        $this->assertSame(DispatchStatus::Failed, $dispatch->status);
        $this->assertCount(1, $fired, '잔액부족 훅은 1회만 발화해야 함');
        $this->assertSame(['9070', 'sms'], $fired[0]);
    }

    /**
     * 잔액부족 알림은 쿨다운 동안 채널별 1회만 발송된다 (D3 중복 방지).
     *
     * 서로 다른 발송건이 연속으로 9070(문자 잔액부족)을 받아도, 쿨다운 내에서는
     * 관리자 알림이 1회만 발화한다. 단, 발송 이력의 실패 기록은 두 건 모두 남는다.
     *
     * @scenario source=webhook,ip=allowed,result=balance_low_sms,repeat=true
     *
     * @effects balance_low_notification_deduplicated_within_cooldown,both_dispatches_marked_failed
     */
    public function test_balance_low_notification_is_deduplicated_within_cooldown(): void
    {
        $first = $this->seedDispatch('ref_bal_1', DispatchChannel::Sms->value);
        $second = $this->seedDispatch('ref_bal_2', DispatchChannel::Sms->value);

        $fired = [];
        HookManager::addAction('sirsoft-message_bizppurio.balance.low', function (...$args) use (&$fired) {
            $fired[] = $args;
        });

        // 두 발송건이 각각 9070(문자 잔액부족) 리포트를 받음
        foreach (['ref_bal_1', 'ref_bal_2'] as $refkey) {
            $this->withServerVariables(['REMOTE_ADDR' => self::ALLOWED_IP])
                ->postJson(self::ENDPOINT, ['REFKEY' => $refkey, 'RESULT' => '9070'])
                ->assertStatus(200);
        }

        // 알림은 1회만 (쿨다운 중복 방지)
        $this->assertCount(1, $fired, '쿨다운 동안 잔액부족 알림은 1회만 발화해야 함');

        // 그러나 실패 이력은 두 건 모두 기록됨
        $this->assertSame(DispatchStatus::Failed, $first->fresh()->status);
        $this->assertSame(DispatchStatus::Failed, $second->fresh()->status);
        $this->assertSame('9070', $first->fresh()->result_code);
        $this->assertSame('9070', $second->fresh()->result_code);
    }

    /**
     * webhook 은 코어 토큰/IDV 미들웨어 제외 — 비인증 실 POST 가 401 이 아닌 200.
     *
     * @scenario source=webhook,ip=allowed,auth=none
     *
     * @effects webhook_bypasses_auth_middleware_returns_200
     */
    public function test_webhook_bypasses_auth_middleware(): void
    {
        $this->seedDispatch('ref_noauth');

        // Authorization 헤더 없이(비인증) POST — 미들웨어 제외로 200 이어야 함
        $this->withServerVariables(['REMOTE_ADDR' => self::ALLOWED_IP])
            ->postJson(self::ENDPOINT, ['REFKEY' => 'ref_noauth', 'RESULT' => '4100'])
            ->assertStatus(200);
    }

    /**
     * 대체발송(SMS 대체발송)을 요청하지 않은 발송건(request_payload 에 resend 없음)은,
     * webhook 응답에 TELRES/KAORES 값이 채워져 와도 fallback_status 가 null 로 남아야 한다.
     *
     * 비즈뿌리오는 대체발송 미요청 건에도 TELRES="0" 같은 값을 채워 보내므로(실서버 관측:
     * alimtalk 대체발송 OFF 인데 결과 화면에 SMS 대체발송 뱃지가 표시된 회귀), webhook 값
     * 존재 여부만으로 판단하면 안 되고 우리가 실제로 요청했는지(request_payload)를 봐야 한다.
     *
     * @scenario source=webhook,ip=allowed,result=success,fallback_requested=false
     *
     * @effects fallback_status_remains_null_when_not_requested
     */
    public function test_fallback_status_stays_null_when_resend_was_not_requested(): void
    {
        $dispatch = $this->seedDispatch(
            'ref_no_resend_requested',
            DispatchChannel::Alimtalk->value,
            ['account' => 'sirsoft', 'content' => ['at' => ['templatecode' => 'tpl_1']]],
        );

        $this->withServerVariables(['REMOTE_ADDR' => self::ALLOWED_IP])
            ->postJson(self::ENDPOINT, [
                'REFKEY' => 'ref_no_resend_requested',
                'RESULT' => '7000',
                'MEDIA' => 'KAT',
                'TELRES' => '0',
                'KAORES' => '0',
            ])
            ->assertStatus(200);

        $dispatch->refresh();
        $this->assertSame(DispatchStatus::Success, $dispatch->status);
        $this->assertNull($dispatch->fallback_status);
    }

    /**
     * 대체발송을 실제로 요청한 발송건(request_payload 에 resend 있음)은 webhook TELRES 값을
     * fallback_status 에 정상 반영한다.
     *
     * @scenario source=webhook,ip=allowed,result=balance_low,fallback_requested=true
     *
     * @effects fallback_status_set_when_resend_was_requested
     */
    public function test_fallback_status_set_when_resend_was_requested(): void
    {
        $dispatch = $this->seedDispatch(
            'ref_resend_requested',
            DispatchChannel::Alimtalk->value,
            [
                'account' => 'sirsoft',
                'content' => ['at' => ['templatecode' => 'tpl_1']],
                'resend' => ['first' => 'sms'],
            ],
        );

        $this->withServerVariables(['REMOTE_ADDR' => self::ALLOWED_IP])
            ->postJson(self::ENDPOINT, [
                'REFKEY' => 'ref_resend_requested',
                'RESULT' => '7436',
                'MEDIA' => 'KAT',
                'TELRES' => '4100',
            ])
            ->assertStatus(200);

        $dispatch->refresh();
        $this->assertSame('4100', $dispatch->fallback_status);
    }
}
