<?php

namespace Tests\Feature\Identity;

use App\Enums\IdentityVerificationStatus;
use App\Exceptions\IdentityVerificationRequiredException;
use App\Extension\HookManager;
use App\Listeners\Identity\EnforceIdentityPolicyListener;
use App\Models\IdentityPolicy;
use App\Models\IdentityVerificationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 비회원 훅 정책이 인증 결과를 대상(주문자 이메일 등)에 묶어 확인하는지 검증한다 (KVE 동형 B4).
 *
 * 수정 전: 훅 리스너가 대상 이메일을 문맥에 싣지 않아, 비회원은 남의(또는 아무) 인증 결과로 정책을 통과했다.
 */
class IdentityGuestTargetBindingTest extends TestCase
{
    use RefreshDatabase;

    private const HOOK = 'kve-test.guest.before_action';

    /**
     * 비회원 대상 훅 정책을 만들고 구독을 맞춥니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        IdentityPolicy::create([
            'key' => 'kve-test.guest_action',
            'scope' => 'hook',
            'target' => self::HOOK,
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => true,
            'priority' => 100,
            'applies_to' => 'both',
            'fail_mode' => 'block',
            'source_type' => 'admin',
            'source_identifier' => 'admin',
        ]);
        Cache::flush();
        EnforceIdentityPolicyListener::syncDynamicHookSubscriptions();
    }

    /**
     * 테스트가 등록한 동적 구독을 해제합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        EnforceIdentityPolicyListener::resetDynamicSubscriptions();
        parent::tearDown();
    }

    /**
     * 주어진 대상으로 확인 완료된 결과를 만듭니다.
     *
     * @param  string  $email  인증한 대상 이메일
     * @return IdentityVerificationLog 결과 로그
     */
    private function verifiedFor(string $email): IdentityVerificationLog
    {
        return IdentityVerificationLog::create([
            'id' => (string) Str::uuid(),
            'provider_id' => 'g7:core.mail',
            'purpose' => 'sensitive_action',
            'channel' => 'email',
            'target_hash' => hash('sha256', mb_strtolower($email)),
            'status' => IdentityVerificationStatus::Verified->value,
            'verification_token' => 'tok-'.Str::random(24),
            'verified_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);
    }

    /**
     * 요청에 토큰을 싣고 훅을 발화합니다.
     *
     * @param  string  $token  확인 토큰
     * @param  array<int, mixed>  $args  훅 인자
     * @return void 반환값 없음
     */
    private function fire(string $token, array $args): void
    {
        $this->app->instance('request', Request::create('/api/guest-action', 'POST', ['verification_token' => $token]));
        HookManager::doAction(self::HOOK, ...$args);
    }

    /**
     * 다른 대상으로 받은 인증 결과로는 대상이 있는 비회원 정책을 통과하지 못하고, 428 은 대상 이메일을 되돌려준다.
     *
     * @effects guest_target_bound, guest_required_payload_has_target
     *
     * @return void 반환값 없음
     */
    public function test_other_target_token_is_rejected(): void
    {
        $other = $this->verifiedFor('someone-else@example.test');

        try {
            $this->fire($other->verification_token, [['target_email' => 'buyer@example.test']]);
            $this->fail('다른 대상의 인증 결과로 비회원 정책이 통과했습니다.');
        } catch (IdentityVerificationRequiredException $e) {
            $this->assertSame('buyer@example.test', $e->getPayload()['target']['email'] ?? null);
        }

        $this->assertNull($other->fresh()->consumed_at);
    }

    /**
     * 같은 대상으로 받은 결과는 통과한다.
     *
     * @effects guest_target_bound
     *
     * @return void 반환값 없음
     */
    public function test_same_target_token_passes(): void
    {
        $own = $this->verifiedFor('buyer@example.test');

        $this->fire($own->verification_token, [['target_email' => 'Buyer@example.test']]);

        $this->assertNotNull($own->fresh()->consumed_at);
    }

    /**
     * 대상 개념이 없는 비회원 정책은 수단 + 1회 사용으로만 결박한다 — 한 번은 통과, 다음 요청은 다시 요구한다.
     *
     * @effects targetless_guest_policy_single_use
     *
     * @return void 반환값 없음
     */
    public function test_targetless_guest_policy_accepts_once(): void
    {
        $log = $this->verifiedFor('anyone@example.test');

        $this->fire($log->verification_token, [['post_id' => 1]]);
        $this->assertNotNull($log->fresh()->consumed_at);

        $this->expectException(IdentityVerificationRequiredException::class);
        $this->fire($log->verification_token, [['post_id' => 1]]);
    }
}
