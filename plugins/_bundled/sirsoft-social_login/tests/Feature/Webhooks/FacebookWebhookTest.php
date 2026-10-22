<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Webhooks;

use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginWebhookEvent;
use Plugins\Sirsoft\SocialLogin\Support\Jwt\Base64Url;

/**
 * 페이스북 콜백 — `signed_request` 서명 검증, 앱 권한 해제 200, 데이터 삭제 요청 `{url, confirmation_code}` 와 상태 페이지,
 * 서명 오류 400, 멱등.
 *
 * @since 1.0.0
 */
class FacebookWebhookTest extends WebhookTestCase
{
    /** 앱 비밀값 */
    private const SECRET = 'fb-app-secret';

    /**
     * 페이스북을 앱 비밀값과 함께 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->configureProvider('facebook', ['client_id' => 'fb-app', 'client_secret' => self::SECRET]);
    }

    /**
     * `signed_request` 를 만듭니다.
     *
     * @param  array<string, mixed>  $payload  본문
     * @param  string  $secret  서명 비밀값
     * @return string 서명된 요청 값(서명.본문)
     */
    private function signed(array $payload, string $secret = self::SECRET): string
    {
        $body = Base64Url::encode((string) json_encode($payload));

        return Base64Url::encode(hash_hmac('sha256', $body, $secret, true)).'.'.$body;
    }

    /**
     * 앱 권한 해제는 200 이고 연결을 지운다. 같은 요청을 다시 보내도 한 번만 처리한다.
     *
     * @scenario provider=facebook, kind=deauthorize
     *
     * @effects facebook_deauthorize_200, webhook_processed, resend_deduplicated
     *
     * @return void 반환값 없음
     */
    public function test_deauthorize(): void
    {
        $user = $this->linkedMember('facebook', 'fb-1');
        $request = ['signed_request' => $this->signed(['algorithm' => 'HMAC-SHA256', 'user_id' => 'fb-1', 'issued_at' => 1760900000])];

        $this->post('/plugins/sirsoft-social_login/webhooks/facebook/deauthorize', $request)->assertOk();
        $this->post('/plugins/sirsoft-social_login/webhooks/facebook/deauthorize', $request)->assertOk();

        $this->assertSame(1, SocialLoginWebhookEvent::query()->where('provider_user_id', 'fb-1')->count());
        $this->assertSame('deauthorize', SocialLoginWebhookEvent::query()->where('provider_user_id', 'fb-1')->value('event_type'));
        $this->assertSame(0, SocialAccount::query()->where('user_id', $user->id)->count());
    }

    /**
     * 형식이 틀린 요청(길이 초과)은 두 콜백 모두 422 가 아니라 서명 오류와 같은 400 `{error}` 로 답하고 `ignored` 로 남긴다.
     *
     * @scenario kind=deauthorize|data_deletion, signature=malformed_payload
     *
     * @effects facebook_malformed_400
     *
     * @return void 반환값 없음
     */
    public function test_malformed_request_is_rejected_with_400(): void
    {
        $request = ['signed_request' => str_repeat('a', 9000)];

        $this->post('/plugins/sirsoft-social_login/webhooks/facebook/deauthorize', $request)->assertStatus(400)->assertExactJson(['error' => 'webhook_payload_invalid']);
        $this->post('/plugins/sirsoft-social_login/webhooks/facebook/data-deletion', $request)->assertStatus(400)->assertExactJson(['error' => 'webhook_payload_invalid']);

        $this->assertSame(2, SocialLoginWebhookEvent::query()->where('provider_key', 'facebook')->where('error', 'webhook_payload_invalid')->count());
    }

    /**
     * 데이터 삭제 요청은 상태 페이지 주소와 확인 코드를 돌려주고(다시 보내면 같은 코드), 상태 페이지는 처리 완료를 보인다.
     * 없는 코드는 404, 서명이 틀리거나 알고리즘이 다르면 400.
     *
     * @scenario kind=data_deletion, signature=valid|wrong_secret|wrong_algorithm
     *
     * @effects data_deletion_returns_url_and_code, data_deletion_same_code_on_resend, deletion_status_page, deletion_unknown_code_404, facebook_bad_signature_400
     *
     * @return void 반환값 없음
     */
    public function test_data_deletion_and_status_page(): void
    {
        $this->linkedMember('facebook', 'fb-2');
        $request = ['signed_request' => $this->signed(['algorithm' => 'HMAC-SHA256', 'user_id' => 'fb-2', 'issued_at' => 1760900100])];

        $first = $this->postJson('/plugins/sirsoft-social_login/webhooks/facebook/data-deletion', $request)->assertOk();
        $code = (string) $first->json('confirmation_code');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{20}$/', $code);
        $this->assertStringEndsWith('/plugins/sirsoft-social_login/data-deletion/'.$code, (string) $first->json('url'));
        $this->assertSame($code, $this->postJson('/plugins/sirsoft-social_login/webhooks/facebook/data-deletion', $request)->json('confirmation_code'));
        $this->assertSame(0, SocialAccount::query()->where('provider_user_id', 'fb-2')->count());

        $page = $this->get('/plugins/sirsoft-social_login/data-deletion/'.$code)->assertOk();
        $this->assertStringContainsString($code, (string) $page->getContent());
        $this->get('/plugins/sirsoft-social_login/data-deletion/'.str_repeat('Z', 20))->assertStatus(404);

        $this->postJson('/plugins/sirsoft-social_login/webhooks/facebook/data-deletion', [
            'signed_request' => $this->signed(['algorithm' => 'HMAC-SHA256', 'user_id' => 'fb-2'], 'other-secret'),
        ])->assertStatus(400)->assertJsonPath('error', 'webhook_signature_invalid');
        $this->postJson('/plugins/sirsoft-social_login/webhooks/facebook/deauthorize', [
            'signed_request' => $this->signed(['algorithm' => 'none', 'user_id' => 'fb-2']),
        ])->assertStatus(400)->assertJsonPath('error', 'webhook_payload_invalid');
    }
}
