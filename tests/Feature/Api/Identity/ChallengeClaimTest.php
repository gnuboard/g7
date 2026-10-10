<?php

namespace Tests\Feature\Api\Identity;

use App\Models\IdentityVerificationLog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\CapturesSentMail;
use Tests\TestCase;

/**
 * 링크 방식 본인인증의 완료를 시작한 화면이 받아 가는 경로를 검증한다.
 *
 * 비밀번호 재설정 목적은 인증번호 대신 확인 링크를 메일로 보낸다. 링크는 다른 기기에서 열릴 수 있고, 확인 결과의
 * 토큰은 링크를 연 쪽에만 돌아가므로 인증을 시작한 화면(428 을 받은 재설정 화면)은 완료를 알 길이 없어 비밀번호
 * 재설정이 끝나지 않았다(#699 브라우저 실측). 시작 응답에 그 화면만 아는 일회용 비밀값을 주고, 확인이 끝나면 그
 * 비밀값으로 토큰을 한 번 받아 가게 한다. 누구나 부를 수 있는 상태 조회에는 토큰도 비밀값도 싣지 않는다.
 */
class ChallengeClaimTest extends TestCase
{
    use CapturesSentMail;
    use RefreshDatabase;

    /**
     * 권한과 본인인증 알림을 준비하고 메일을 배열 메일러로 받습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->prepareIdentityNotifications();
        $this->useArrayMailer();
    }

    /**
     * 링크 방식 challenge 만 시작 응답에 일회용 비밀값을 주고, 상태 조회에는 싣지 않는다.
     *
     * @scenario surface=challenge_claim
     *
     * @effects link_challenge_start_returns_claim_secret_only_to_starter
     *
     * @return void 반환값 없음
     */
    public function test_link_challenge_start_returns_claim_secret_only_to_starter(): void
    {
        $link = $this->startLinkChallenge();
        $this->assertIsString($link['claim_secret']);
        $this->assertGreaterThanOrEqual(40, strlen($link['claim_secret']));

        $status = $this->getJson('/api/identity/challenges/'.$link['id'])->assertOk()->json('data');
        $this->assertArrayNotHasKey('claim_secret', $status);
        $this->assertArrayNotHasKey('verification_token', $status);
        $this->assertStringNotContainsString($link['claim_secret'], json_encode(IdentityVerificationLog::query()->find($link['id'])?->metadata));

        $code = $this->postJson('/api/identity/challenges', [
            'purpose' => 'sensitive_action',
            'target' => ['email' => 'guest-claim@example.test'],
        ])->assertCreated()->json('data');
        $this->assertArrayNotHasKey('claim_secret', $code, '인증번호 방식은 시작 화면이 직접 확인하므로 비밀값이 필요 없다');
    }

    /**
     * 링크를 열기 전에는 대기 상태만 답하고 토큰을 주지 않는다.
     *
     * @scenario surface=challenge_claim
     *
     * @effects claim_before_link_opened_is_pending
     *
     * @return void 반환값 없음
     */
    public function test_claim_before_link_opened_is_pending(): void
    {
        $link = $this->startLinkChallenge();

        $response = $this->postJson('/api/identity/challenges/'.$link['id'].'/claim', ['claim_secret' => $link['claim_secret']]);

        $response->assertStatus(202);
        $this->assertSame('pending', $response->json('data.status'));
        $this->assertNull($response->json('data.verification_token'));
    }

    /**
     * 링크로 확인이 끝나면 비밀값을 가진 쪽이 토큰을 한 번 받아 가고, 두 번째는 거절된다.
     *
     * @scenario surface=challenge_claim
     *
     * @effects claim_after_link_verified_returns_token_once
     *
     * @return void 반환값 없음
     */
    public function test_claim_after_link_verified_returns_token_once(): void
    {
        $link = $this->startLinkChallenge();
        $this->postJson('/api/identity/challenges/'.$link['id'].'/verify', ['token' => $this->mailedLinkToken()])->assertOk();

        $first = $this->postJson('/api/identity/challenges/'.$link['id'].'/claim', ['claim_secret' => $link['claim_secret']]);
        $first->assertOk();
        $this->assertSame(IdentityVerificationLog::query()->find($link['id'])?->verification_token, $first->json('data.verification_token'));
        $this->assertNotEmpty($first->json('data.verification_token'));

        $this->postJson('/api/identity/challenges/'.$link['id'].'/claim', ['claim_secret' => $link['claim_secret']])->assertStatus(409);
    }

    /**
     * 비밀값이 틀리면 확인이 끝났어도 토큰을 주지 않는다.
     *
     * @scenario surface=challenge_claim
     *
     * @effects claim_with_wrong_secret_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_claim_with_wrong_secret_is_rejected(): void
    {
        $link = $this->startLinkChallenge();
        $this->postJson('/api/identity/challenges/'.$link['id'].'/verify', ['token' => $this->mailedLinkToken()])->assertOk();

        $response = $this->postJson('/api/identity/challenges/'.$link['id'].'/claim', ['claim_secret' => str_repeat('x', 48)]);

        $response->assertStatus(403);
        $this->assertNull($response->json('data.verification_token'));
    }

    /**
     * 비회원 대상으로 비밀번호 재설정(링크 방식) challenge 를 시작합니다.
     *
     * @return array<string, mixed> 시작 응답 data
     */
    private function startLinkChallenge(): array
    {
        $data = $this->postJson('/api/identity/challenges', [
            'purpose' => 'password_reset',
            'target' => ['email' => 'guest-claim@example.test'],
        ])->assertCreated()->json('data');
        $this->assertSame('link', $data['render_hint']);

        return $data;
    }

    /**
     * 마지막으로 보낸 메일의 확인 링크에서 토큰을 꺼냅니다.
     *
     * @return string 링크 토큰
     */
    private function mailedLinkToken(): string
    {
        $mails = $this->sentMails();
        $html = html_entity_decode((string) (end($mails)['html'] ?? ''));
        $this->assertSame(1, preg_match('#/identity/verify\?challenge=[^&"\s]+&token=([^&"\s<]+)#', $html, $m), '메일에 확인 링크가 있어야 한다');

        return rawurldecode($m[1]);
    }
}
