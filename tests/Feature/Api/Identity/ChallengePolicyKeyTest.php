<?php

namespace Tests\Feature\Api\Identity;

use App\Models\IdentityPolicy;
use App\Models\IdentityVerificationLog;
use App\Services\NotificationDefinitionService;
use Database\Seeders\IdentityPolicySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\Concerns\CapturesSentMail;
use Tests\TestCase;

/**
 * 428 로 시작한 본인인증이 정책별 문구를 쓰는지 검증한다.
 *
 * 라우트·훅 정책은 428 응답에 정책 키를 싣고, 화면은 그 응답으로 `POST /api/identity/challenges` 를 부른다.
 * 이 API 가 정책 키를 받지 않아 메일 발송부에 정책이 전달되지 않았고, 운영자가 알림 설정에서 정책별 문구를
 * 달아 둬도 늘 목적 공통 문구가 나갔다(#699 브라우저 실측 — 게시글 작성 정책). 정책 키는 그 정책이 있고
 * 켜져 있으며 요청 목적과 같을 때만 받아들인다 — 아니면 종전처럼 목적 문구로 보낸다.
 */
class ChallengePolicyKeyTest extends TestCase
{
    use CapturesSentMail;
    use RefreshDatabase;

    private const POLICY_KEY = 'admin.probe.board_write';

    /**
     * 권한·정책·본인인증 알림을 준비하고 운영자 정책과 그 정책별 문구를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(IdentityPolicySeeder::class);
        $this->prepareIdentityNotifications();
        $this->useArrayMailer();

        IdentityPolicy::query()->create([
            'key' => self::POLICY_KEY,
            'scope' => 'hook',
            'target' => 'sirsoft-board.post.before_create',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => true,
            'priority' => 100,
            'applies_to' => 'self',
            'fail_mode' => 'block',
            'source_type' => 'admin',
            'source_identifier' => 'admin',
        ]);

        app(NotificationDefinitionService::class)->createIdentityDefinition([
            'policy_key' => self::POLICY_KEY,
            'name' => ['ko' => '게시글 작성 인증', 'en' => 'Post verification'],
            'templates' => ['mail' => [
                'subject' => ['ko' => '[정책문구] 게시글 작성 인증번호', 'en' => '[정책문구] Post code'],
                'body' => ['ko' => '<p>정책별 문구 {code}</p>', 'en' => '<p>Policy text {code}</p>'],
            ]],
        ]);
    }

    /**
     * 428 이 알려 준 정책 키를 실어 보내면 그 정책별 문구로 메일이 나가고 이력에 출처 정책이 남는다.
     *
     * @scenario surface=challenge_api_policy_key
     *
     * @effects challenge_with_policy_key_uses_policy_text
     *
     * @return void 반환값 없음
     */
    public function test_policy_key_selects_policy_text(): void
    {
        $response = $this->requestChallenge(['policy_key' => self::POLICY_KEY]);

        $response->assertCreated();
        $this->assertStringContainsString('[정책문구]', $this->sentMails()[0]['subject'] ?? '');
        $this->assertSame(self::POLICY_KEY, IdentityVerificationLog::query()->find($response->json('data.id'))?->origin_policy_key);
    }

    /**
     * 요청 목적과 다른 정책·꺼진 정책·없는 정책의 키는 받아들이지 않고 목적 문구로 보낸다.
     *
     * @scenario surface=challenge_api_policy_key
     *
     * @effects challenge_with_unusable_policy_key_falls_back_to_purpose_text
     *
     * @return void 반환값 없음
     */
    public function test_unusable_policy_key_falls_back_to_purpose_text(): void
    {
        IdentityPolicy::query()->where('key', 'core.admin.user_delete')->update(['enabled' => false]);

        foreach ([
            'purpose_mismatch' => 'core.auth.signup_after_create',
            'disabled' => 'core.admin.user_delete',
            'unknown' => 'admin.not.exists',
        ] as $case => $key) {
            $response = $this->requestChallenge(['policy_key' => $key]);

            $response->assertCreated();
            $mails = $this->sentMails();
            $subject = (string) (end($mails)['subject'] ?? '');
            $this->assertNotSame('', $subject, "{$case}: 메일이 나가야 한다");
            $this->assertStringNotContainsString('[정책문구]', $subject, "{$case}: 목적 문구로 보내야 한다");
            $this->assertNull(IdentityVerificationLog::query()->find($response->json('data.id'))?->origin_policy_key, "{$case}: 출처 정책을 남기지 않는다");
        }
    }

    /**
     * 비회원 대상으로 중요 작업 인증 challenge 를 요청합니다.
     *
     * @param  array<string, mixed>  $extra  추가 요청 값
     * @return TestResponse 응답
     */
    private function requestChallenge(array $extra): TestResponse
    {
        return $this->postJson('/api/identity/challenges', [
            'purpose' => 'sensitive_action',
            'provider_id' => 'g7:core.mail',
            'target' => ['email' => 'guest-policy@example.test'],
        ] + $extra);
    }
}
