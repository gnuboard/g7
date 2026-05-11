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
        HookManager::resetAll();
        $this->manager = new IdentityVerificationManager();
    }

    protected function tearDown(): void
    {
        HookManager::resetAll();
        parent::tearDown();
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
        HookManager::addFilter('core.identity.registered_providers', function (array $providers) use ($injected) {
            $providers[$injected->getId()] = $injected;

            return $providers;
        });

        $all = $this->manager->all();

        $this->assertArrayHasKey('g7:core.mail', $all);
        $this->assertArrayHasKey('plugin:fake', $all);
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
