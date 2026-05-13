<?php

namespace Tests\Unit\Extension\IdentityVerification;

use App\Contracts\Extension\IdentityVerificationInterface;
use App\Extension\HookManager;
use App\Extension\IdentityVerification\DTO\VerificationChallenge;
use App\Extension\IdentityVerification\DTO\VerificationResult;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Models\User;
use Carbon\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * IdentityVerificationManager 테스트.
 *
 * 프로바이더 등록/해제/resolveForPurpose/필터 훅 통과 후 병합 동작을 검증.
 */
class IdentityVerificationManagerTest extends TestCase
{
    private IdentityVerificationManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        // HookManager::resetAll() 호출 금지 — 코어 ServiceProvider 가 부트 시 등록한 listener
        // (CoreActivityLogListener 등) 도 함께 날아가 동일 PHP 프로세스 내 다른 테스트에 누수된다.
        // 본 클래스는 `new IdentityVerificationManager()` 로 독립 인스턴스를 만들고 그 위에서만 동작하므로
        // 정적 hook 전역 상태를 초기화할 필요가 없다.
        $this->manager = new IdentityVerificationManager();
    }

    public function test_register_and_get_provider(): void
    {
        $provider = $this->makeProvider('dummy', true, true);
        $this->manager->register($provider);

        $this->assertTrue($this->manager->has('dummy'));
        $this->assertSame($provider, $this->manager->get('dummy'));
    }

    public function test_unregister_removes_provider(): void
    {
        $provider = $this->makeProvider('dummy', true, true);
        $this->manager->register($provider);
        $this->manager->unregister('dummy');

        $this->assertFalse($this->manager->has('dummy'));
    }

    public function test_get_unknown_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->manager->get('nonexistent');
    }

    public function test_all_runs_through_filter_hook(): void
    {
        $mail = $this->makeProvider('g7:core.mail', true, true);
        $this->manager->register($mail);

        $injected = $this->makeProvider('plugin:fake', true, true);
        $filter = function (array $providers) use ($injected) {
            $providers[$injected->getId()] = $injected;

            return $providers;
        };
        HookManager::addFilter('core.identity.registered_providers', $filter);

        try {
            $all = $this->manager->all();

            $this->assertArrayHasKey('g7:core.mail', $all);
            $this->assertArrayHasKey('plugin:fake', $all);
        } finally {
            // 테스트 격리 — 동일 PHP 프로세스 내 후속 테스트가 본 filter 의 영향을 받지 않도록 정리.
            HookManager::removeFilter('core.identity.registered_providers', $filter);
        }
    }

    public function test_resolve_for_purpose_returns_provider_supporting_purpose(): void
    {
        $mail = $this->makeProvider('g7:core.mail', true, true);
        $this->manager->register($mail);

        $resolved = $this->manager->resolveForPurpose('signup');

        $this->assertSame('g7:core.mail', $resolved->getId());
    }

    public function test_resolve_for_purpose_falls_back_when_default_unsupported(): void
    {
        $unsupported = $this->makeProvider('g7:core.mail', true, false);
        $supporter = $this->makeProvider('plugin:kcp', true, true);

        $this->manager->register($unsupported);
        $this->manager->register($supporter);

        $resolved = $this->manager->resolveForPurpose('signup');

        $this->assertSame('plugin:kcp', $resolved->getId());
    }

    /**
     * 명시 $providerId 가 등록 + purpose 지원 시 settings/default 보다 우선 적용 (0번 우선순위, refs #275).
     */
    public function test_resolve_for_purpose_prefers_explicit_provider_id(): void
    {
        $mail = $this->makeProvider('g7:core.mail', true, true);
        $external = $this->makeProvider('plugin:fake_external', true, true);

        $this->manager->register($mail);
        $this->manager->register($external);

        // settings.identity.purpose_providers.signup = mail 로 강제 — 명시 providerId 가 이걸 덮어야 한다.
        config(['settings.identity.purpose_providers.signup' => 'g7:core.mail']);

        $resolved = $this->manager->resolveForPurpose('signup', 'plugin:fake_external');

        $this->assertSame('plugin:fake_external', $resolved->getId());
    }

    /**
     * 명시 $providerId 가 미등록이면 silent fallback (기존 우선순위 체인 진행, refs #275).
     */
    public function test_resolve_for_purpose_falls_back_when_explicit_provider_unregistered(): void
    {
        $mail = $this->makeProvider('g7:core.mail', true, true);
        $this->manager->register($mail);

        $resolved = $this->manager->resolveForPurpose('signup', 'plugin:nonexistent');

        $this->assertSame('g7:core.mail', $resolved->getId());
    }

    /**
     * 명시 $providerId 가 등록되어 있어도 supportsPurpose() false 면 silent fallback (refs #275).
     */
    public function test_resolve_for_purpose_falls_back_when_explicit_provider_does_not_support_purpose(): void
    {
        $mail = $this->makeProvider('g7:core.mail', true, true);
        $passwordOnly = $this->makeProvider('plugin:password_only', true, false);

        $this->manager->register($mail);
        $this->manager->register($passwordOnly);

        $resolved = $this->manager->resolveForPurpose('signup', 'plugin:password_only');

        $this->assertSame('g7:core.mail', $resolved->getId());
    }

    /**
     * $providerId = null (기본값) 시 기존 우선순위 유지 — BC 회귀 차단 (refs #275).
     */
    public function test_resolve_for_purpose_with_null_provider_id_preserves_legacy_order(): void
    {
        $mail = $this->makeProvider('g7:core.mail', true, true);
        $external = $this->makeProvider('plugin:fake_external', true, true);

        $this->manager->register($mail);
        $this->manager->register($external);

        // default = g7:core.mail (Manager 의 defaultId) — null providerId 이면 default 로 해석되어야 한다.
        $resolved = $this->manager->resolveForPurpose('signup', null);

        $this->assertSame('g7:core.mail', $resolved->getId());
    }

    /**
     * IdentityVerificationInterface 더블을 생성합니다.
     */
    private function makeProvider(string $id, bool $available, bool $supportsAll): IdentityVerificationInterface
    {
        return new class($id, $available, $supportsAll) implements IdentityVerificationInterface
        {
            public function __construct(
                private string $id,
                private bool $available,
                private bool $supportsAll,
            ) {}

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
                return $this->supportsAll;
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function requestChallenge(User|array $target, array $context = []): VerificationChallenge
            {
                return new VerificationChallenge(
                    id: 'dummy-id',
                    providerId: $this->id,
                    purpose: $context['purpose'] ?? 'sensitive_action',
                    channel: 'email',
                    targetHash: str_repeat('0', 64),
                    expiresAt: Carbon::now()->addMinutes(15),
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
