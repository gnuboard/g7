<?php

namespace Tests\Feature\Identity;

use App\Contracts\Extension\IdentityVerificationInterface;
use App\Enums\IdentityPolicySourceType;
use App\Extension\IdentityVerification\DTO\VerificationChallenge;
use App\Extension\IdentityVerification\DTO\VerificationResult;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Listeners\Identity\InitiateIdentityChallengeAfterRegister;
use App\Models\IdentityPolicy;
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
     * Mode A: 미등록 providerId 도 silent fallback (예외 throw 없음).
     */
    public function test_mode_a_unregistered_provider_id_falls_back_silently(): void
    {
        /** @var IdentityVerificationService $service */
        $service = $this->app->make(IdentityVerificationService::class);

        $challenge = $service->start(
            purpose: 'signup',
            target: ['email' => 'a@example.com'],
            context: ['ip_address' => '127.0.0.1'],
            providerId: 'plugin:nonexistent',
        );

        $this->assertSame('g7:core.mail', $challenge->providerId);
    }

    /**
     * Mode A: 등록되었지만 해당 purpose 미지원인 provider 도 silent fallback.
     *
     * 운영자가 정책/요청에 plugin:password_only 를 지정했지만 그 provider 가 signup 을 지원하지
     * 않는 경우, 예외 대신 mail (코어 기본) 로 fallback 되어야 한다.
     */
    public function test_mode_a_provider_not_supporting_purpose_falls_back_silently(): void
    {
        // signup 은 지원하지 않고 password_reset 만 지원하는 가짜 provider 추가 등록.
        $passwordOnly = $this->makeFakeProvider('plugin:password_only', supportedPurposes: ['password_reset']);
        /** @var IdentityVerificationManager $manager */
        $manager = $this->app->make(IdentityVerificationManager::class);
        $manager->register($passwordOnly);

        try {
            /** @var IdentityVerificationService $service */
            $service = $this->app->make(IdentityVerificationService::class);

            $challenge = $service->start(
                purpose: 'signup',
                target: ['email' => 'a@example.com'],
                context: ['ip_address' => '127.0.0.1'],
                providerId: 'plugin:password_only',
            );

            $this->assertSame(
                'g7:core.mail',
                $challenge->providerId,
                'signup 을 지원하지 않는 명시 provider 는 silent fallback 되어 mail 이 선택되어야 한다',
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

        $log = \App\Models\IdentityVerificationLog::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($log, '정책이 enabled 면 challenge log 가 생성되어야 함');
        $this->assertSame('plugin:fake_external', $log->provider_id);

        unset($policy);
    }

    /**
     * Mode B: 정책 provider_id = null 이면 Manager fallback (BC).
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

        $log = \App\Models\IdentityVerificationLog::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('g7:core.mail', $log->provider_id);
    }

    /**
     * Mode B: 정책 disabled 면 challenge 발행 자체 미수행 (BC 회귀 차단).
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

        $count = \App\Models\IdentityVerificationLog::query()
            ->where('user_id', $user->id)
            ->count();

        $this->assertSame(0, $count);
    }

    /**
     * signup_after_create 정책을 DB 에 생성.
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
     */
    private function makeFakeProvider(string $id, ?array $supportedPurposes = null): IdentityVerificationInterface
    {
        return new class($id, $supportedPurposes) implements IdentityVerificationInterface
        {
            /**
             * @param  array<int, string>|null  $supportedPurposes
             */
            public function __construct(private string $id, private ?array $supportedPurposes = null) {}

            public function getId(): string
            {
                return $this->id;
            }

            public function getLabel(): string
            {
                return $this->id;
            }

            public function getChannels(): array
            {
                return ['email'];
            }

            public function getRenderHint(): string
            {
                return 'text_code';
            }

            public function supportsPurpose(string $purpose): bool
            {
                if ($this->supportedPurposes === null) {
                    return true;
                }

                return in_array($purpose, $this->supportedPurposes, true);
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function requestChallenge(User|array $target, array $context = []): VerificationChallenge
            {
                $targetHash = is_array($target) && ! empty($target['email'])
                    ? hash('sha256', mb_strtolower((string) $target['email']))
                    : ($target instanceof User
                        ? hash('sha256', mb_strtolower((string) $target->email))
                        : str_repeat('0', 64));

                $userId = $target instanceof User ? $target->id : null;

                $log = \App\Models\IdentityVerificationLog::query()->create([
                    'provider_id' => $this->id,
                    'purpose' => $context['purpose'] ?? 'signup',
                    'channel' => 'email',
                    'target_hash' => $targetHash,
                    'user_id' => $userId,
                    'status' => \App\Enums\IdentityVerificationStatus::Sent->value,
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

            public function verify(string $challengeId, array $input, array $context = []): VerificationResult
            {
                return VerificationResult::success(
                    challengeId: $challengeId,
                    providerId: $this->id,
                    verifiedAt: Carbon::now(),
                );
            }

            public function cancel(string $challengeId): bool
            {
                return true;
            }

            public function getSettingsSchema(): array
            {
                return [];
            }

            public function withConfig(array $config): static
            {
                return $this;
            }
        };
    }
}
