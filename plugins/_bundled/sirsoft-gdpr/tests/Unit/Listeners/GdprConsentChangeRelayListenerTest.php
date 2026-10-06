<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Listeners;

use App\Extension\Consent\ConsentManager;
use App\Extension\Consent\ConsentSubject;
use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use Mockery;
use Plugins\Sirsoft\Gdpr\Listeners\GdprConsentChangeRelayListener;
use Plugins\Sirsoft\Gdpr\Listeners\RegisterGdprConsentProviderListener;
use Plugins\Sirsoft\Gdpr\Repositories\Contracts\GdprUserConsentHistoryRepositoryInterface;
use Plugins\Sirsoft\Gdpr\Services\GdprConsentService;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * 동의 변경 중계 리스너 테스트 (#639, 결정 D11)
 *
 * 코어는 이 플러그인의 훅 이름을 모른다. 이 리스너가 쿠키 동의 변경을 카테고리 이름으로 바꿔 모으고, 요청이
 * 끝날 때 주체마다 한 번만 코어 액션 `core.consent.changed` 로 알린다 — 배너 「모두 거부」 한 번이 항목 수만큼
 * 알림이 되면 철회 진단 카운터가 부푼다. 알리는 것은 직전 결정과 **달라진** 항목뿐이다 — 한 번도 동의하지 않은
 * 방문자의 첫 「모두 거부」는 철회가 아니다.
 */
class GdprConsentChangeRelayListenerTest extends PluginTestCase
{
    /** @var array<int, array{0: string, 1: ConsentSubject, 2: array<string, bool>}> 받은 코어 알림 */
    private array $relayed = [];

    /** @var callable 코어 액션 구독 콜백 */
    private $spy;

    /** @var array<string, array<int, object>> 주체(`u:{회원}`·`g:{게스트}`)별 동의 이력, 최신순 */
    private array $history = [];

    /**
     * 이 플러그인을 코어 동의 제공자로 등록하고 코어 액션을 엿듣습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        HookManager::clearFilter(ConsentManager::REGISTERED_PROVIDERS_FILTER);
        config(['g7_settings.core.'.ConsentManager::SETTING_KEY => '']);
        HookManager::addFilter(
            ConsentManager::REGISTERED_PROVIDERS_FILTER,
            [new RegisterGdprConsentProviderListener, 'register'],
            20,
        );

        $this->relayed = [];
        $this->spy = function (string $providerId, ConsentSubject $subject, array $changes): void {
            $this->relayed[] = [$providerId, $subject, $changes];
        };
        HookManager::addAction(ConsentManager::CHANGED_ACTION, $this->spy);

        $this->history = [];
        $repository = Mockery::mock(GdprUserConsentHistoryRepositoryInterface::class);
        $repository->shouldReceive('getByUserId')->andReturnUsing(fn (int $id) => collect($this->history['u:'.$id] ?? []));
        $repository->shouldReceive('getBySessionId')->andReturnUsing(fn (string $id) => collect($this->history['g:'.$id] ?? []));
        $this->app->instance(GdprUserConsentHistoryRepositoryInterface::class, $repository);
    }

    /**
     * 동의 서비스가 훅 직전에 남기는 이력을 흉내 냅니다 — 방금 기록한 결정이 맨 앞, 그 앞 결정들이 뒤에 온다.
     *
     * @param  string  $subject  주체 (`u:{회원 ID}`·`g:{게스트 식별자}`)
     * @param  string  $consentKey  동의 키
     * @param  string  ...$actionsNewestFirst  결정(`granted`·`revoked`·`rejected`), 최신순
     * @return void 반환값 없음
     */
    private function recorded(string $subject, string $consentKey, string ...$actionsNewestFirst): void
    {
        foreach ($actionsNewestFirst as $action) {
            $this->history[$subject][] = (object) ['consent_key' => $consentKey, 'action' => $action];
        }
    }

    /**
     * 코어 액션 구독을 떼어 냅니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::removeAction(ConsentManager::CHANGED_ACTION, $this->spy);

        parent::tearDown();
    }

    /**
     * 한 요청 안의 여러 쿠키 동의 변경을 주체마다 한 번으로 묶어 카테고리 이름으로 알린다.
     *
     * @scenario case=batched
     *
     * @effects consent_changes_are_relayed_once_per_subject_per_request
     *
     * @return void 반환값 없음
     */
    public function test_changes_in_one_request_are_relayed_once_per_subject(): void
    {
        $this->recorded('g:guest-uuid', 'cookie_analytics', 'revoked', 'granted');
        $this->recorded('g:guest-uuid', 'cookie_marketing', 'rejected', 'granted');
        $this->recorded('g:guest-uuid', 'cookie_functional', 'granted', 'revoked');
        $this->recorded('u:7', 'cookie_analytics', 'granted', 'revoked');
        $listener = app(GdprConsentChangeRelayListener::class);

        $listener->onRevoked(null, 'banner', 'cookie_analytics', null, 'guest-uuid');
        $listener->onRevoked(null, 'banner', 'cookie_marketing', null, 'guest-uuid');
        $listener->onGranted(null, 'banner', 'cookie_functional', null, 'guest-uuid');
        $listener->onGranted(null, 'mypage', 'cookie_analytics', 7, null);

        $this->assertSame([], $this->relayed, '요청이 끝나기 전에는 알리지 않는다');

        $listener->flush();

        $this->assertCount(2, $this->relayed);
        [$providerId, $subject, $changes] = $this->relayed[0];
        $this->assertSame('sirsoft-gdpr', $providerId);
        $this->assertNull($subject->userId);
        $this->assertSame('guest-uuid', $subject->subjectKey);
        $this->assertSame(['analytics' => false, 'marketing' => false, 'functional' => true], $changes);

        $this->assertSame(7, $this->relayed[1][1]->userId);
        $this->assertSame(['analytics' => true], $this->relayed[1][2]);

        $listener->flush();
        $this->assertCount(2, $this->relayed, '두 번 비워도 다시 알리지 않는다');
    }

    /**
     * 훅마다 리스너 인스턴스가 새로 만들어져도(실제 등록 경로 — `app($listenerClass)`) 한 요청의 변경은 한 번으로 묶인다.
     *
     * @scenario case=batched
     *
     * @effects consent_changes_are_relayed_once_per_subject_per_request
     *
     * @return void 반환값 없음
     */
    public function test_changes_are_batched_across_listener_instances_resolved_per_hook(): void
    {
        $this->recorded('g:guest-uuid', 'cookie_analytics', 'revoked', 'granted');
        $this->recorded('g:guest-uuid', 'cookie_marketing', 'revoked', 'granted');
        app(GdprConsentChangeRelayListener::class)->onRevoked(null, 'banner', 'cookie_analytics', null, 'guest-uuid');
        app(GdprConsentChangeRelayListener::class)->onRevoked(null, 'banner', 'cookie_marketing', null, 'guest-uuid');

        app(GdprConsentChangeRelayListener::class)->flush();

        $this->assertCount(1, $this->relayed);
        $this->assertSame(['analytics' => false, 'marketing' => false], $this->relayed[0][2]);
    }

    /**
     * 쿠키가 아닌 동의(약관 등)와 주체를 알 수 없는 변경은 알리지 않는다.
     *
     * @scenario case=ignored
     *
     * @effects non_cookie_consent_changes_are_not_relayed
     *
     * @return void 반환값 없음
     */
    public function test_non_cookie_keys_and_unknown_subjects_are_ignored(): void
    {
        $listener = app(GdprConsentChangeRelayListener::class);

        $listener->onRevoked(null, 'mypage', 'terms_marketing_email', 7, null);
        $listener->onRevoked(null, 'banner', 'cookie_analytics', null, null);
        $listener->onGranted();
        $listener->flush();

        $this->assertSame([], $this->relayed);
    }

    /**
     * 운영자가 다른 제공자를 골랐으면 코어가 이 플러그인의 알림을 버린다.
     *
     * @scenario case=not_selected
     *
     * @effects unselected_provider_changes_are_dropped
     *
     * @return void 반환값 없음
     */
    public function test_changes_are_dropped_when_another_provider_is_selected(): void
    {
        config(['g7_settings.core.'.ConsentManager::SETTING_KEY => 'other-consent']);
        $this->recorded('g:guest-uuid', 'cookie_analytics', 'revoked', 'granted');

        $listener = app(GdprConsentChangeRelayListener::class);
        $listener->onRevoked(null, 'banner', 'cookie_analytics', null, 'guest-uuid');
        $listener->flush();

        $this->assertSame([], $this->relayed);
    }

    /**
     * 한 번도 동의하지 않은 방문자의 첫 거부는 철회가 아니다 — 직전 결정이 없으면 「동의 안 함」에서 출발한다.
     *
     * @scenario case=unchanged
     *
     * @effects only_changed_categories_are_relayed
     *
     * @return void 반환값 없음
     */
    public function test_first_rejection_without_prior_consent_is_not_relayed(): void
    {
        $this->recorded('g:guest-uuid', 'cookie_analytics', 'rejected');
        $this->recorded('u:7', 'cookie_analytics', 'revoked');

        $listener = app(GdprConsentChangeRelayListener::class);
        $listener->onRevoked(null, 'banner', 'cookie_analytics', null, 'guest-uuid');
        $listener->onRevoked(null, 'banner', 'cookie_analytics', 7, null);
        $listener->flush();

        $this->assertSame([], $this->relayed);
    }

    /**
     * 직전 결정과 같은 값을 다시 저장하면 알리지 않고, 달라진 항목만 알린다.
     *
     * @scenario case=unchanged
     *
     * @effects only_changed_categories_are_relayed
     *
     * @return void 반환값 없음
     */
    public function test_repeated_same_decision_is_not_relayed_but_other_changes_are(): void
    {
        $this->recorded('g:guest-uuid', 'cookie_analytics', 'rejected', 'revoked', 'granted');
        $this->recorded('g:guest-uuid', 'cookie_marketing', 'granted', 'rejected');

        $listener = app(GdprConsentChangeRelayListener::class);
        $listener->onRevoked(null, 'banner', 'cookie_analytics', null, 'guest-uuid');
        $listener->onGranted(null, 'banner', 'cookie_marketing', null, 'guest-uuid');
        $listener->flush();

        $this->assertCount(1, $this->relayed);
        $this->assertSame(['marketing' => true], $this->relayed[0][2]);
    }

    /**
     * 실제 동의 저장 → 플러그인 훅 → 중계 → 코어 액션 체인에서, 게스트의 첫 거부는 알리지 않고 동의 뒤 거부만 알린다.
     *
     * @scenario case=unchanged
     *
     * @effects only_changed_categories_are_relayed
     *
     * @return void 반환값 없음
     */
    public function test_real_consent_saves_relay_only_actual_withdrawal(): void
    {
        $this->app->forgetInstance(GdprUserConsentHistoryRepositoryInterface::class);
        // 실제 등록 경로(sync 지정·훅마다 app() 로 리스너 생성)를 태운다. 플러그인 부팅이 이미 등록했어도 결과는 같다.
        HookListenerRegistrar::register(GdprConsentChangeRelayListener::class, 'sirsoft-gdpr-relay-chain-test');

        $service = app(GdprConsentService::class);
        $save = function (bool $analytics) use ($service): void {
            $service->updateConsents(null, 'guest-chain', ['cookie_analytics' => $analytics], 'banner', ! $analytics);
            app(GdprConsentChangeRelayListener::class)->flush();
        };

        $save(false);
        $save(false);
        $this->assertSame([], $this->relayed, '한 번도 동의하지 않은 게스트의 거부(두 번)는 철회가 아니다');

        $save(true);
        $save(false);
        $this->assertSame([['analytics' => true], ['analytics' => false]], array_column($this->relayed, 2));
    }

    /**
     * 부여·철회 훅을 동기로 구독한다 — 큐로 넘기면 요청 단위 묶음이 깨진다.
     *
     * @scenario case=batched
     *
     * @effects consent_changes_are_relayed_once_per_subject_per_request
     *
     * @return void 반환값 없음
     */
    public function test_subscribes_synchronously_to_grant_and_revoke(): void
    {
        $hooks = GdprConsentChangeRelayListener::getSubscribedHooks();

        $this->assertSame('onGranted', $hooks['sirsoft-gdpr.consent.granted']['method']);
        $this->assertSame('onRevoked', $hooks['sirsoft-gdpr.consent.revoked']['method']);
        $this->assertTrue($hooks['sirsoft-gdpr.consent.granted']['sync']);
        $this->assertTrue($hooks['sirsoft-gdpr.consent.revoked']['sync']);
    }
}
