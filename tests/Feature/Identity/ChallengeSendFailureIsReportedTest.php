<?php

namespace Tests\Feature\Identity;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Concerns\CapturesSentMail;
use Tests\TestCase;

/**
 * 인증 코드를 **보내지 못했으면 그 자리에서 말한다** (SA-D12)
 *
 * 민감 작업(회원 탈퇴 등)은 6자리 코드로 본인을 확인한다. 그 코드를 보내지 못하면 사용자는
 * 아무것도 할 수 없다 — 그런데 화면은 「전송된 6자리 코드를 입력해주세요」와 함께 남은 시간
 * 14:20 · 남은 시도 5회 카운트다운을 돌린다. 사용자는 **영영 오지 않을 코드**를 기다린다.
 *
 * 실측(#599 세션A 2026-09-22 · `/mypage/profile` → [회원 탈퇴]): 챌린지
 * `01a0c4c9-6dc7-739b-a096-4a87d65d5039` 가 생성 2초 뒤 `status=failed` 로 끝났는데
 * API 는 201 + 「인증 요청이 전송되었습니다」를 돌려줬다. 코드는 bcrypt 해시로만 남아
 * 복구할 수 없어, 그 환경에서는 **회원이 스스로 탈퇴할 방법이 없다**.
 *
 * 계약: 발송이 실패로 끝난 챌린지는 성공으로 응답하지 않는다. 화면이 실패를 알아야
 * 재시도·대체 채널을 안내할 수 있다.
 */
class ChallengeSendFailureIsReportedTest extends TestCase
{
    use CapturesSentMail;
    use DatabaseTransactions;

    /**
     * 컨트롤러 소스를 읽어 돌려줍니다.
     *
     * @return string 파일 내용
     */
    private function controller(): string
    {
        return file_get_contents(
            app_path('Http/Controllers/Api/Identity/IdentityVerificationController.php')
        );
    }

    /**
     * 챌린지 생성 응답이 발송 실패를 성공으로 포장하지 않는다.
     *
     * @scenario dispatch=failed
     *
     * @effects failed_dispatch_is_not_reported_as_success
     *
     * @return void 반환값 없음
     */
    public function test_failed_dispatch_is_not_answered_with_success(): void
    {
        $source = $this->controller();

        $start = strpos($source, 'public function request(');
        $this->assertNotFalse($start, 'request() 를 찾지 못했습니다.');

        $end = strpos($source, 'public function verify(', $start);
        $body = substr($source, $start, $end - $start);

        // 발송 결과를 확인하지 않고 곧장 201 을 돌려주면 사용자는 오지 않을 코드를 기다린다.
        $this->assertMatchesRegularExpression(
            '/failed|Failed/',
            $body,
            '챌린지 생성 응답은 발송 실패 여부를 확인해야 합니다.'
        );
        $this->assertStringContainsString(
            'identity.errors.challenge_dispatch_failed',
            $body,
            '발송 실패는 전용 사유로 응답해야 합니다.'
        );
    }

    /**
     * 발송 실패 문구가 ko·en 에 있고 원문 키가 새지 않는다.
     *
     * @scenario dispatch=failed
     *
     * @effects failed_dispatch_message_exists_in_all_locales
     *
     * @return void 반환값 없음
     */
    public function test_dispatch_failure_message_exists(): void
    {
        foreach (['ko', 'en'] as $locale) {
            $messages = require base_path("lang/{$locale}/identity.php");
            $text = $messages['errors']['challenge_dispatch_failed'] ?? '';

            $this->assertNotSame('', $text, "[{$locale}] 발송 실패 문구가 있어야 합니다.");
            $this->assertStringNotContainsString(
                'identity.errors',
                $text,
                "[{$locale}] 원문 키가 그대로 보이면 안 됩니다."
            );
        }
    }

    /**
     * 「알림 채널 관리」에서 메일을 끄면 챌린지 요청은 502 로 답한다 (#699).
     *
     * 본인인증 메일은 7.1.0 부터 알림 시스템을 거쳐 채널 토글을 따른다. 메일을 꺼서 보내지 못한 것도
     * 「보내지 못했으면 그 자리에서 말한다」 계약의 실패다 — 201 로 포장하지 않는다.
     *
     * @scenario dispatch=failed
     *
     * @effects mail_channel_off_answers_502
     *
     * @return void 반환값 없음
     */
    public function test_mail_channel_off_is_answered_with_502(): void
    {
        $this->seed(RolePermissionSeeder::class);
        Mail::fake();
        $this->prepareIdentityNotifications();
        $this->setCoreMailChannelActive(false);

        $response = $this->postJson('/api/identity/challenges', [
            'purpose' => 'sensitive_action',
            'target' => ['email' => 'channel-off@example.test'],
        ]);

        $response->assertStatus(502);
        $this->assertSame(__('identity.errors.challenge_dispatch_failed'), $response->json('message'));

        $this->setCoreMailChannelActive(true);
        $this->postJson('/api/identity/challenges', [
            'purpose' => 'sensitive_action',
            'target' => ['email' => 'channel-on@example.test'],
        ])->assertStatus(201);
    }

    /**
     * 성공 경로는 그대로 201 로 남는다.
     *
     * 실패 확인이 과하게 걸리면 정상 발송까지 오류로 떨어진다.
     *
     * @scenario dispatch=succeeded
     *
     * @effects successful_dispatch_still_returns_created
     *
     * @return void 반환값 없음
     */
    public function test_successful_dispatch_still_returns_created(): void
    {
        $source = $this->controller();

        $start = strpos($source, 'public function request(');
        $end = strpos($source, 'public function verify(', $start);
        $body = substr($source, $start, $end - $start);

        $this->assertStringContainsString(
            'identity.messages.challenge_requested',
            $body,
            '정상 발송은 기존 성공 응답을 유지해야 합니다.'
        );
        $this->assertStringContainsString(
            '201',
            $body,
            '정상 발송은 201 로 남아야 합니다.'
        );
    }
}
