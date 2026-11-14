<?php

namespace Tests\Feature\Identity;

use App\Contracts\Extension\IdentityVerificationInterface;
use App\Enums\IdentityPolicySourceType;
use App\Enums\IdentityVerificationStatus;
use App\Exceptions\IdentityProviderUnavailableException;
use App\Extension\IdentityVerification\DTO\VerificationChallenge;
use App\Extension\IdentityVerification\DTO\VerificationResult;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Listeners\Identity\InitiateIdentityChallengeAfterRegister;
use App\Models\IdentityPolicy;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use App\Services\IdentityPolicyService;
use App\Services\IdentityVerificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * IDV provider 해석 우선순위 매트릭스 (refs #275).
 *
 * Mode A (Controller / Service::start providerId 명시) 와
 * Mode B (signup_after_create 정책 → Listener → Service::start) 양쪽에서
 * 호출자 명시 provider_id 가 settings/default 보다 우선되는지 검증.
 *
 * Manager 단위 우선순위는 Unit 테스트 (IdentityVerificationManagerTest) 가 커버하며,
 * 본 Feature 테스트는 Service / Listener / 정책을 거치는 통합 흐름을 검증한다.
 *
 * 시나리오 매니페스트: tests/scenarios/identity-provider-resolution.yaml
 */
class IdentityProviderResolutionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 정책 흐름 통합 검증을 위한 fake provider 들.
     * 매 테스트마다 등록 후 tearDown 에서 정리.
     */
    private IdentityVerificationInterface $mailProvider;

    private IdentityVerificationInterface $externalProvider;

    /**
     * 메일·외부 fake provider 를 Manager 에 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        // HookManager::resetAll() 호출 금지 — 코어 ServiceProvider 가 부트 시 등록한
        // listener (CoreActivityLogListener 등) 도 함께 날아가 다른 테스트에 누수된다.
        // provider 등록/해제만으로 본 매트릭스 격리는 충분하다.

        $this->mailProvider = $this->makeFakeProvider('g7:core.mail');
        $this->externalProvider = $this->makeFakeProvider('plugin:fake_external');

        /** @var IdentityVerificationManager $manager */
        $manager = $this->app->make(IdentityVerificationManager::class);
        $manager->register($this->mailProvider);
        $manager->register($this->externalProvider);
    }

    /**
     * 등록한 fake provider 를 Manager 에서 해제합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        /** @var IdentityVerificationManager $manager */
        $manager = $this->app->make(IdentityVerificationManager::class);
        $manager->unregister('g7:core.mail');
        $manager->unregister('plugin:fake_external');

        parent::tearDown();
    }

    /**
     * Mode A: Service::start 에 명시한 providerId 가 Manager 해석에 그대로 반영.
     *
     * @scenario call_mode=mode_a_explicit, provider_id_state=valid_registered_supported, purpose=signup
     *
     * @effects explicit_provider_id_takes_precedence_over_default_provider
     *
     * @return void 반환값 없음
     */
    public function test_mode_a_explicit_provider_id_is_used_for_challenge(): void
    {
        /** @var IdentityVerificationService $service */
        $service = $this->app->make(IdentityVerificationService::class);

        $challenge = $service->start(
            purpose: 'signup',
            target: ['email' => 'a@example.com'],
            context: ['ip_address' => '127.0.0.1'],
            providerId: 'plugin:fake_external',
        );

        $this->assertSame('plugin:fake_external', $challenge->providerId);
    }

    /**
     * Mode A: providerId = null 이면 Manager fallback (default = g7:core.mail).
     *
     * @scenario call_mode=mode_a_explicit, provider_id_state=null_unspecified, purpose=signup
     *
     * @effects null_provider_id_preserves_legacy_priority_chain
     *
     * @return void 반환값 없음
     */
    public function test_mode_a_null_provider_id_falls_back_to_default(): void
    {
        /** @var IdentityVerificationService $service */
        $service = $this->app->make(IdentityVerificationService::class);

        $challenge = $service->start(
            purpose: 'signup',
            target: ['email' => 'a@example.com'],
            context: ['ip_address' => '127.0.0.1'],
        );

        $this->assertSame('g7:core.mail', $challenge->providerId);
    }

    /**
     * Mode A: 미등록 providerId 는 메일로 바꾸지 않고 거부한다 (7.1.6, KVE 동형 B9).
     *
     * 종전에는 조용히 메일로 발급했다 — 정책이 지정한 수단 대신 메일로 발급되면 정책 결박이 무력화된다.
     *
     * @scenario call_mode=mode_a_explicit, provider_id_state=unregistered, purpose=signup
     *
     * @effects unregistered_explicit_provider_id_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_mode_a_unregistered_provider_id_is_rejected(): void
    {
        /** @var IdentityVerificationService $service */
        $service = $this->app->make(IdentityVerificationService::class);

        $this->expectException(IdentityProviderUnavailableException::class);

        $service->start(
            purpose: 'signup',
            target: ['email' => 'a@example.com'],
            context: ['ip_address' => '127.0.0.1'],
            providerId: 'plugin:nonexistent',
        );
    }

    /**
     * Mode A: 등록되었지만 해당 purpose 미지원인 provider 도 거부한다 (7.1.6, KVE 동형 B9).
     *
     * @scenario call_mode=mode_a_explicit, provider_id_state=registered_not_supporting_purpose, purpose=signup
     *
     * @effects explicit_provider_id_not_supporting_purpose_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_mode_a_provider_not_supporting_purpose_is_rejected(): void
    {
        // signup 은 지원하지 않고 password_reset 만 지원하는 가짜 provider 추가 등록.
        $passwordOnly = $this->makeFakeProvider('plugin:password_only', supportedPurposes: ['password_reset']);
        /** @var IdentityVerificationManager $manager */
        $manager = $this->app->make(IdentityVerificationManager::class);
        $manager->register($passwordOnly);

        try {
            /** @var IdentityVerificationService $service */
            $service = $this->app->make(IdentityVerificationService::class);

            $this->expectException(IdentityProviderUnavailableException::class);

            $service->start(
                purpose: 'signup',
                target: ['email' => 'a@example.com'],
                context: ['ip_address' => '127.0.0.1'],
                providerId: 'plugin:password_only',
            );
        } finally {
            $manager->unregister('plugin:password_only');
        }
    }

    /**
     * Mode B: signup_after_create 정책의 provider_id 가 Listener → Service::start → Manager 로 전파.
     *
     * AuthService 전체 가입 흐름 대신 Listener::handle 을 직접 호출하여
     * provider_id 전파만 검증한다 (가입 흐름 검증은 Auth 도메인 테스트 책임).
     *
     * @scenario call_mode=mode_b_policy, provider_id_state=valid_registered_supported, purpose=signup
     *
     * @effects policy_provider_id_propagates_through_listener_to_manager, log_provider_id_matches_explicit_provider_when_resolution_succeeds
     *
     * @return void 반환값 없음
     */
    public function test_mode_b_policy_provider_id_propagates_through_listener(): void
    {
        $policy = $this->createSignupAfterCreatePolicy('plugin:fake_external');
        $user = $this->createUser();

        /** @var IdentityVerificationService $service */
        $service = $this->app->make(IdentityVerificationService::class);
        /** @var IdentityPolicyService $policyService */
        $policyService = $this->app->make(IdentityPolicyService::class);

        $listener = new InitiateIdentityChallengeAfterRegister($service, $policyService);
        $listener->handle($user, [
            'signup_stage' => 'after_create',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
        ]);

        $log = IdentityVerificationLog::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($log, '정책이 enabled 면 challenge log 가 생성되어야 함');
        $this->assertSame('plugin:fake_external', $log->provider_id);

        unset($policy);
    }

    /**
     * Mode B: 정책 provider_id = null 이면 Manager fallback (BC).
     *
     * @scenario call_mode=mode_b_policy, provider_id_state=null_unspecified, purpose=signup
     *
     * @effects null_provider_id_preserves_legacy_priority_chain
     *
     * @return void 반환값 없음
     */
    public function test_mode_b_null_policy_provider_id_falls_back_to_default(): void
    {
        $this->createSignupAfterCreatePolicy(null);
        $user = $this->createUser();

        /** @var IdentityVerificationService $service */
        $service = $this->app->make(IdentityVerificationService::class);
        /** @var IdentityPolicyService $policyService */
        $policyService = $this->app->make(IdentityPolicyService::class);

        $listener = new InitiateIdentityChallengeAfterRegister($service, $policyService);
        $listener->handle($user, [
            'signup_stage' => 'after_create',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
        ]);

        $log = IdentityVerificationLog::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('g7:core.mail', $log->provider_id);
    }

    /**
     * Mode B: 정책 disabled 면 challenge 발행 자체 미수행 (BC 회귀 차단).
     *
     * @effects disabled_after_register_policy_skips_challenge
     *
     * @return void 반환값 없음
     */
    public function test_mode_b_disabled_policy_skips_challenge(): void
    {
        $this->createSignupAfterCreatePolicy('plugin:fake_external', enabled: false);
        $user = $this->createUser();

        /** @var IdentityVerificationService $service */
        $service = $this->app->make(IdentityVerificationService::class);
        /** @var IdentityPolicyService $policyService */
        $policyService = $this->app->make(IdentityPolicyService::class);

        $listener = new InitiateIdentityChallengeAfterRegister($service, $policyService);
        $listener->handle($user, [
            'signup_stage' => 'after_create',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
        ]);

        $count = IdentityVerificationLog::query()
            ->where('user_id', $user->id)
            ->count();

        $this->assertSame(0, $count);
    }

    /**
     * signup_after_create 정책을 DB 에 생성.
     *
     * @param  string|null  $providerId  정책이 지정할 수단 ID (null 이면 미지정)
     * @param  bool  $enabled  정책 활성 여부
     * @return IdentityPolicy 저장된 정책
     */
    private function createSignupAfterCreatePolicy(?string $providerId, bool $enabled = true): IdentityPolicy
    {
        return IdentityPolicy::query()->updateOrCreate(
            ['key' => 'core.auth.signup_after_create'],
            [
                'scope' => 'hook',
                'target' => 'core.auth.after_register',
                'purpose' => 'signup',
                'provider_id' => $providerId,
                'enabled' => $enabled,
                'applies_to' => 'self',
                'fail_mode' => 'block',
                'grace_minutes' => 0,
                'priority' => 100,
                'conditions' => null,
                'source_type' => IdentityPolicySourceType::Core,
                'source_identifier' => 'core',
            ],
        );
    }

    /**
     * 테스트용 활성 사용자를 생성합니다.
     *
     * @return User 생성된 사용자
     */
    private function createUser(): User
    {
        return User::factory()->create([
            'email' => 'a@example.com',
        ]);
    }

    /**
     * 모든 purpose (또는 일부 purpose) 를 지원하는 간단한 fake provider.
     *
     * @param  string  $id  provider 식별자
     * @param  array<int, string>|null  $supportedPurposes  null 이면 모든 purpose 지원, 배열이면 해당 항목만 지원
     * @return IdentityVerificationInterface 테스트용 fake provider
     */
    private function makeFakeProvider(string $id, ?array $supportedPurposes = null): IdentityVerificationInterface
    {
        return new class($id, $supportedPurposes) implements IdentityVerificationInterface
        {
            /**
             * fake provider 의 식별자와 지원 목적을 받습니다.
             *
             * @param  string  $id  provider 식별자
             * @param  array<int, string>|null  $supportedPurposes  지원 목적 목록 (null 이면 모든 목적)
             */
            public function __construct(private string $id, private ?array $supportedPurposes = null) {}

            /**
             * provider 식별자를 돌려줍니다.
             *
             * @return string provider 식별자
             */
            public function getId(): string
            {
                return $this->id;
            }

            /**
             * 표시 이름을 돌려줍니다(식별자와 같음).
             *
             * @return string 표시 이름
             */
            public function getLabel(): string
            {
                return $this->id;
            }

            /**
             * 지원 채널 목록을 돌려줍니다.
             *
             * @return array<int, string> 채널 목록(email 만)
             */
            public function getChannels(): array
            {
                return ['email'];
            }

            /**
             * 채널별 표시 이름을 돌려줍니다.
             *
             * @return array<string, string> 채널 표시 이름
             */
            public function getChannelLabels(): array
            {
                return ['email' => 'Email'];
            }

            /**
             * 프론트 렌더 힌트를 돌려줍니다.
             *
             * @return string 렌더 힌트(text_code)
             */
            public function getRenderHint(): string
            {
                return 'text_code';
            }

            /**
             * 지원 목적 목록에 든 목적인지 판정합니다.
             *
             * @param  string  $purpose  인증 목적
             * @return bool 지원 여부 (목록이 null 이면 항상 true)
             */
            public function supportsPurpose(string $purpose): bool
            {
                if ($this->supportedPurposes === null) {
                    return true;
                }

                return in_array($purpose, $this->supportedPurposes, true);
            }

            /**
             * 언제나 사용 가능하다고 답합니다.
             *
             * @return bool 항상 true
             */
            public function isAvailable(): bool
            {
                return true;
            }

            /**
             * 발송 상태의 challenge 로그를 만들고 그 challenge 를 돌려줍니다.
             *
             * @param  User|array  $target  인증 대상(회원 또는 이메일 배열)
             * @param  array  $context  요청 문맥(purpose·origin 등)
             * @return VerificationChallenge 발급된 challenge
             */
            public function requestChallenge(User|array $target, array $context = []): VerificationChallenge
            {
                $targetHash = is_array($target) && ! empty($target['email'])
                    ? hash('sha256', mb_strtolower((string) $target['email']))
                    : ($target instanceof User
                        ? hash('sha256', mb_strtolower((string) $target->email))
                        : str_repeat('0', 64));

                $userId = $target instanceof User ? $target->id : null;

                $log = IdentityVerificationLog::query()->create([
                    'provider_id' => $this->id,
                    'purpose' => $context['purpose'] ?? 'signup',
                    'channel' => 'email',
                    'target_hash' => $targetHash,
                    'user_id' => $userId,
                    'status' => IdentityVerificationStatus::Sent->value,
                    'expires_at' => Carbon::now()->addMinutes(15),
                    'origin_type' => $context['origin_type'] ?? null,
                    'origin_identifier' => $context['origin_identifier'] ?? null,
                    'origin_policy_key' => $context['origin_policy_key'] ?? null,
                    'metadata' => [],
                    'properties' => [],
                ]);

                return new VerificationChallenge(
                    id: (string) $log->id,
                    providerId: $this->id,
                    purpose: $context['purpose'] ?? 'signup',
                    channel: 'email',
                    targetHash: $targetHash,
                    expiresAt: $log->expires_at,
                    renderHint: 'text_code',
                );
            }

            /**
             * 언제나 성공 결과를 돌려줍니다.
             *
             * @param  string  $challengeId  확인할 challenge 의 ID
             * @param  array  $input  제출 입력
             * @param  array  $context  요청 문맥
             * @return VerificationResult 성공 결과
             */
            public function verify(string $challengeId, array $input, array $context = []): VerificationResult
            {
                return VerificationResult::success(
                    challengeId: $challengeId,
                    providerId: $this->id,
                    verifiedAt: Carbon::now(),
                );
            }

            /**
             * 취소 요청을 언제나 성공으로 처리합니다.
             *
             * @param  string  $challengeId  취소할 challenge 의 ID
             * @return bool 항상 true
             */
            public function cancel(string $challengeId): bool
            {
                return true;
            }

            /**
             * 설정 스키마를 돌려줍니다.
             *
             * @return array 빈 스키마
             */
            public function getSettingsSchema(): array
            {
                return [];
            }

            /**
             * 설정을 받아도 자기 자신을 그대로 돌려줍니다.
             *
             * @param  array  $config  provider 설정
             * @return static 자기 자신
             */
            public function withConfig(array $config): static
            {
                return $this;
            }
        };
    }
}
