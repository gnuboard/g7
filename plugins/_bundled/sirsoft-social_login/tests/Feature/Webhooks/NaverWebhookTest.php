<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Webhooks;

use Plugins\Sirsoft\SocialLogin\Drivers\NaverDriver;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginWebhookEvent;

/**
 * 네이버 연동 해제 웹훅 — 서명 검증, 고유 ID 복호화(고정 픽스처), 204 응답, 멱등, 검증 실패 무처리.
 *
 * @since 1.0.0
 */
class NaverWebhookTest extends WebhookTestCase
{
    /** 웹훅 주소 */
    private const URL = '/plugins/sirsoft-social_login/webhooks/naver';

    /** @var array<string, mixed> 고정 픽스처 */
    private array $fixture;

    /**
     * 픽스처의 자격증명으로 네이버를 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/fixtures/webhooks/naver_unlink.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->configureProvider('naver', ['client_id' => $this->fixture['client_id'], 'client_secret' => $this->fixture['client_secret']]);
    }

    /**
     * 고정 픽스처의 암호화 고유 ID 가 원래 값으로 풀리고, 서명이 맞으면 204 로 답하며 연결을 지운다. 다시 보내도 한 번만 처리한다.
     * 이벤트 본문에는 서명·암호문을 남기지 않는다.
     *
     * @scenario provider=naver, signature=valid
     *
     * @effects naver_fixture_decrypts, naver_204, webhook_processed, link_removed_without_revoke, resend_deduplicated, payload_strips_secrets
     *
     * @return void 반환값 없음
     */
    public function test_valid_notice(): void
    {
        $this->assertSame($this->fixture['unique_id'], NaverDriver::decryptUniqueId($this->fixture['request']['encryptUniqueId'], $this->fixture['client_secret']));
        $user = $this->linkedMember('naver', $this->fixture['unique_id']);

        $this->post(self::URL, $this->fixture['request'])->assertStatus(204);
        $this->post(self::URL, $this->fixture['request'])->assertStatus(204);

        $events = SocialLoginWebhookEvent::query()->where('provider_key', 'naver')->where('provider_user_id', $this->fixture['unique_id'])->get();
        $this->assertCount(1, $events);
        $this->assertSame('processed', $events[0]->status);
        $this->assertSame(['timestamp'], array_keys($events[0]->payload));
        $this->assertSame(0, SocialAccount::query()->where('user_id', $user->id)->count());
    }

    /**
     * 서명 변조·다른 클라이언트·풀 수 없는 고유 ID 는 204 로 답하되 처리하지 않고 사유를 남긴다.
     *
     * @scenario signature=tampered|other_client|undecryptable
     *
     * @effects naver_bad_signature_ignored, naver_client_mismatch_ignored, naver_undecryptable_ignored
     *
     * @return void 반환값 없음
     */
    public function test_invalid_notices_are_ignored(): void
    {
        $this->linkedMember('naver', $this->fixture['unique_id']);
        $request = $this->fixture['request'];

        $this->post(self::URL, ['signature' => base64_encode(random_bytes(32))] + $request)->assertStatus(204);
        $this->post(self::URL, ['clientId' => 'other-client'] + $request)->assertStatus(204);

        $garbage = base64_encode(random_bytes(40));
        $signed = ['encryptUniqueId' => $garbage, 'signature' => NaverDriver::webhookSignature($request['clientId'], $garbage, $request['timestamp'], $this->fixture['client_secret'])] + $request;
        $this->post(self::URL, $signed)->assertStatus(204);

        $errors = SocialLoginWebhookEvent::query()->where('provider_key', 'naver')->where('status', 'ignored')->orderBy('id')->pluck('error')->all();
        $this->assertSame(['webhook_signature_invalid', 'webhook_app_mismatch', 'webhook_payload_invalid'], array_slice($errors, -3));
        $this->assertSame(1, SocialAccount::query()->where('provider_user_id', $this->fixture['unique_id'])->count());
    }

    /**
     * 형식이 틀린 요청(길이 초과)도 422 가 아니라 204 로 답하고 `webhook_payload_invalid` 로 남긴다.
     *
     * @scenario signature=malformed_payload
     *
     * @effects naver_malformed_ignored_204
     *
     * @return void 반환값 없음
     */
    public function test_malformed_request_is_ignored_with_204(): void
    {
        $this->post(self::URL, ['timestamp' => str_repeat('1', 30)] + $this->fixture['request'])->assertStatus(204);

        $this->assertSame('webhook_payload_invalid', SocialLoginWebhookEvent::query()->where('provider_key', 'naver')->latest('id')->value('error'));
    }
}
