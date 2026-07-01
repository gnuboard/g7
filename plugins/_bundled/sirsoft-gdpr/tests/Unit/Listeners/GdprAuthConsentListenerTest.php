<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Listeners;

use App\Models\User;
use App\Services\PluginSettingsService;
use Plugins\Sirsoft\Gdpr\Listeners\GdprAuthConsentListener;
use Plugins\Sirsoft\Gdpr\Repositories\GdprUserConsentHistoryRepository;
use Plugins\Sirsoft\Gdpr\Repositories\GdprUserConsentRepository;
use Plugins\Sirsoft\Gdpr\Services\CookieCategoryService;
use Plugins\Sirsoft\Gdpr\Services\GdprConsentService;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * GdprAuthConsentListener 테스트
 *
 * core.auth.record_consents 훅 발화 시 회원가입 폼 동의가 새 회원에게 정상 기록되는지
 * 실제 훅 체인으로 검증한다. 게스트→회원 자동 승계는 의도적으로 제공하지 않으므로
 * 본 listener 는 회원가입 폼 동의 기록만 책임진다.
 */
class GdprAuthConsentListenerTest extends PluginTestCase
{
    private GdprAuthConsentListener $listener;

    private GdprConsentService $consentService;

    /** @var PluginSettingsService&\PHPUnit\Framework\MockObject\MockObject */
    private PluginSettingsService $pluginSettings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pluginSettings = $this->createMock(PluginSettingsService::class);
        $this->pluginSettings->method('get')->willReturnCallback(
            fn (string $id, string $key, mixed $default = null) => match ($key) {
                'cookie_policy_version' => '1.0',
                default => $default,
            }
        );

        $categoryService = new CookieCategoryService($this->pluginSettings);

        $this->consentService = new GdprConsentService(
            new GdprUserConsentRepository(),
            new GdprUserConsentHistoryRepository(),
            $this->pluginSettings,
            $categoryService,
        );

        $this->listener = new GdprAuthConsentListener(
            $this->consentService,
            $categoryService,
        );
    }

    /**
     * Listener 가 core.auth.record_consents 훅만 구독한다 (회귀 가드).
     *
     * 게스트→회원 자동 승계 (after_register / after_login) 는 의도적으로 제거되었다.
     * 글로벌 CMP 대부분이 자동 승계를 기본 동작으로 제공하지 않으며, 회원가입 시점의
     * 폼 동의로 Art.7(1) 입증 책임은 충족된다.
     */
    public function test_subscribed_hooks_include_only_record_consents(): void
    {
        $hooks = GdprAuthConsentListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.auth.record_consents', $hooks);
        $this->assertArrayNotHasKey('core.auth.after_register', $hooks);
        $this->assertArrayNotHasKey('core.auth.after_login', $hooks);
    }

    /**
     * 회귀 가드 — string $agreedAt 로 호출되는 코어 SSoT 와 호환되어야 한다.
     *
     * 실제 훅 체인: HookManager::doAction('core.auth.record_consents', User, array, string $agreedAt, string $ip)
     * 를 발화했을 때 listener 가 TypeError 없이 받고 폼 동의를 status 에 반영해야 함.
     * 회귀 발견 시점: 2026-05-08 PO 가입 시 Argument #3 ($agreedAt) must be of type Carbon\Carbon, string given.
     *
     * @return void
     */
    public function test_record_register_consents_accepts_string_agreed_at_via_real_hook_chain(): void
    {
        // 회원가입 폼 동의 (cookie_analytics 부여)
        \App\Extension\HookManager::addAction(
            'core.auth.record_consents',
            fn (User $user, array $data, ?string $agreedAt = null, ?string $ip = null)
                => $this->listener->recordRegisterConsents($user, $data, $agreedAt, $ip),
            10
        );

        $user = User::factory()->create();
        // 코어 AuthService 가 보내는 형식대로: Carbon → toIso8601String() 결과
        \App\Extension\HookManager::doAction(
            'core.auth.record_consents',
            $user,
            ['agree_cookie_analytics' => true],
            now()->toIso8601String(),
            '127.0.0.1'
        );

        $this->assertDatabaseHas('gdpr_user_consents', [
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'is_consented' => true,
            'last_source' => 'register',
        ]);
    }

    /**
     * 회귀 가드 — recordRegisterConsents 시그니처는 코어 SSoT 와 일치해야 한다.
     *
     * 실제 회귀: AuthService::recordConsents 가 `$agreedAt->toIso8601String()` 로 변환해
     * string 으로 전달하므로 listener 가 Carbon 으로 받으면 TypeError 발생 (sync 모드와 무관).
     * 코어 SSoT: CoreActivityLogListener::handleAuthRecordConsents 의 시그니처
     *   `(User, array, ?string $agreedAt = null, ?string $ip = null)`.
     *
     * @return void
     */
    public function test_record_register_consents_signature_matches_core_ssot(): void
    {
        $rm = new \ReflectionMethod(GdprAuthConsentListener::class, 'recordRegisterConsents');
        $params = $rm->getParameters();

        $this->assertSame('agreedAt', $params[2]->getName(), '세 번째 인수는 agreedAt 이어야 함');

        $type = $params[2]->getType();
        $this->assertNotNull($type, 'agreedAt 타입힌트가 정의되어야 함');
        $this->assertInstanceOf(\ReflectionNamedType::class, $type, 'agreedAt 는 단일 타입이어야 함');

        // 코어 SSoT 와 동일하게 ?string 으로 받아야 함 (Carbon 직접 받기 금지)
        $this->assertSame('string', $type->getName(), 'agreedAt 는 string 으로 받아야 함 (Carbon 직접 사용 금지 — AuthService 가 toIso8601String 으로 직렬화 후 전달)');
        $this->assertTrue($type->allowsNull(), 'agreedAt 는 nullable (?string) 이어야 함 — 코어 SSoT 일관');
    }

    /**
     * record_consents 훅은 sync => true 로 등록되어야 한다 (회귀 가드).
     *
     * Listener 메서드는 현재 HTTP 요청 컨텍스트 (`request()->ip()`) + Carbon 인스턴스에
     * 의존한다. HookListenerRegistrar 의 기본 동작은 큐 디스패치인데, 큐 잡은 별도 컨텍스트라
     * 쿠키 소실 + Carbon → string 직렬화 mismatch 발생 → 폼 동의 기록 실패
     * (실제 회귀 — 2026-05-08 PO 가입 시도 시 발견).
     */
    public function test_subscribed_hook_forces_sync_to_preserve_request_context(): void
    {
        $hooks = GdprAuthConsentListener::getSubscribedHooks();

        $this->assertTrue(
            $hooks['core.auth.record_consents']['sync'] ?? false,
            'core.auth.record_consents 는 sync => true 여야 함 (큐 디스패치 시 request 컨텍스트 소실 회귀 차단)'
        );
    }
}
