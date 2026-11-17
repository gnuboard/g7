<?php

namespace Tests\Unit\Extension\IdentityVerification;

use App\Contracts\Extension\IdentityVerificationInterface;
use App\Exceptions\IdentityProviderUnavailableException;
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

    /**
     * 테스트마다 독립된 Manager 인스턴스를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        // HookManager::resetAll() 호출 금지 — 코어 ServiceProvider 가 부트 시 등록한 listener
        // (CoreActivityLogListener 등) 도 함께 날아가 동일 PHP 프로세스 내 다른 테스트에 누수된다.
        // 본 클래스는 `new IdentityVerificationManager()` 로 독립 인스턴스를 만들고 그 위에서만 동작하므로
        // 정적 hook 전역 상태를 초기화할 필요가 없다.
        $this->manager = new IdentityVerificationManager;
    }

    /**
     * 등록한 provider 가 has/get 으로 같은 인스턴스로 조회되는지 확인합니다.
     *
     * @effects provider_registry_registers_and_unregisters_by_id
     *
     * @return void 반환값 없음
     */
    public function test_register_and_get_provider(): void
    {
        $provider = $this->makeProvider('dummy', true, true);
        $this->manager->register($provider);

        $this->assertTrue($this->manager->has('dummy'));
        $this->assertSame($provider, $this->manager->get('dummy'));
    }

    /**
     * 해제한 provider 는 더 이상 등록돼 있지 않은지 확인합니다.
     *
     * @effects provider_registry_registers_and_unregisters_by_id
     *
     * @return void 반환값 없음
     */
    public function test_unregister_removes_provider(): void
    {
        $provider = $this->makeProvider('dummy', true, true);
        $this->manager->register($provider);
        $this->manager->unregister('dummy');

        $this->assertFalse($this->manager->has('dummy'));
    }

    /**
     * 등록되지 않은 ID 를 조회하면 InvalidArgumentException 을 던지는지 확인합니다.
     *
     * @effects unknown_provider_lookup_throws
     *
     * @return void 반환값 없음
     */
    public function test_get_unknown_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->manager->get('nonexistent');
    }

    /**
     * all() 이 등록 provider 에 필터 훅이 주입한 provider 를 합쳐 돌려주는지 확인합니다.
     *
     * @effects registered_providers_merged_through_filter_hook
     *
     * @return void 반환값 없음
     */
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

    /**
     * provider_id 를 명시하지 않으면 목적을 지원하는 기본 provider(메일)로 해석되는지 확인합니다.
     *
     * @scenario call_mode=mode_a_explicit, provider_id_state=null_unspecified, purpose=signup
     *
     * @effects null_provider_id_preserves_legacy_priority_chain
     *
     * @return void 반환값 없음
     */
    public function test_resolve_for_purpose_returns_provider_supporting_purpose(): void
    {
        $mail = $this->makeProvider('g7:core.mail', true, true);
        $this->manager->register($mail);

        $resolved = $this->manager->resolveForPurpose('signup');

        $this->assertSame('g7:core.mail', $resolved->getId());
    }

    /**
     * provider_id 를 명시하지 않았고 기본 provider 가 목적을 지원하지 않으면 목적을 지원하는 다른 provider 로 넘어가는지 확인합니다.
     *
     * @scenario call_mode=mode_a_explicit, provider_id_state=null_unspecified, purpose=signup
     *
     * @effects null_provider_id_preserves_legacy_priority_chain
     *
     * @return void 반환값 없음
     */
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
     *
     * @scenario call_mode=mode_a_explicit, provider_id_state=valid_registered_supported, purpose=signup
     *
     * @effects explicit_provider_id_takes_precedence_over_settings_purpose_providers
     *
     * @return void 반환값 없음
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
     * 명시 $providerId 가 미등록이면 다른 수단으로 바꾸지 않고 거부한다 (7.1.6, KVE 동형 B9 — 종전 silent fallback).
     *
     * @scenario call_mode=mode_a_explicit, provider_id_state=unregistered, purpose=signup
     *
     * @effects unregistered_explicit_provider_id_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_resolve_for_purpose_rejects_unregistered_explicit_provider(): void
    {
        $mail = $this->makeProvider('g7:core.mail', true, true);
        $this->manager->register($mail);

        $this->expectException(IdentityProviderUnavailableException::class);
        $this->manager->resolveForPurpose('signup', 'plugin:nonexistent');
    }

    /**
     * 명시 $providerId 가 등록되어 있어도 supportsPurpose() false 면 거부한다 (7.1.6, KVE 동형 B9).
     *
     * @scenario call_mode=mode_a_explicit, provider_id_state=registered_not_supporting_purpose, purpose=signup
     *
     * @effects explicit_provider_id_not_supporting_purpose_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_resolve_for_purpose_rejects_explicit_provider_not_supporting_purpose(): void
    {
        $mail = $this->makeProvider('g7:core.mail', true, true);
        $passwordOnly = $this->makeProvider('plugin:password_only', true, false);

        $this->manager->register($mail);
        $this->manager->register($passwordOnly);

        $this->expectException(IdentityProviderUnavailableException::class);
        $this->manager->resolveForPurpose('signup', 'plugin:password_only');
    }

    /**
     * $providerId = null (기본값) 시 기존 우선순위 유지 — BC 회귀 차단 (refs #275).
     *
     * @scenario call_mode=mode_a_explicit, provider_id_state=null_unspecified, purpose=signup
     *
     * @effects null_provider_id_preserves_legacy_priority_chain
     *
     * @return void 반환값 없음
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
     *
     * @param  string  $id  provider 식별자
     * @param  bool  $available  사용 가능 여부
     * @param  bool  $supportsAll  모든 목적 지원 여부(false 면 어떤 목적도 지원하지 않음)
     * @return IdentityVerificationInterface 테스트용 provider 더블
     */
    private function makeProvider(string $id, bool $available, bool $supportsAll): IdentityVerificationInterface
    {
        return new class($id, $available, $supportsAll) implements IdentityVerificationInterface
        {
            /**
             * 더블의 식별자·가용성·목적 지원 여부를 받습니다.
             *
             * @param  string  $id  provider 식별자
             * @param  bool  $available  사용 가능 여부
             * @param  bool  $supportsAll  모든 목적 지원 여부
             */
            public function __construct(
                private string $id,
                private bool $available,
                private bool $supportsAll,
            ) {}

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
             * 목적 지원 여부를 생성자 값으로 돌려줍니다.
             *
             * @param  string  $purpose  인증 목적
             * @return bool 지원 여부
             */
            public function supportsPurpose(string $purpose): bool
            {
                return $this->supportsAll;
            }

            /**
             * 사용 가능 여부를 생성자 값으로 돌려줍니다.
             *
             * @return bool 사용 가능 여부
             */
            public function isAvailable(): bool
            {
                return $this->available;
            }

            /**
             * 고정 값의 challenge 를 만듭니다.
             *
             * @param  User|array  $target  인증 대상
             * @param  array  $context  요청 문맥
             * @return VerificationChallenge 발급된 challenge
             */
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
