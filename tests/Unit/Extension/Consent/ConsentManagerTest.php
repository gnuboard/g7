<?php

namespace Tests\Unit\Extension\Consent;

use App\Contracts\Extension\ConsentProviderInterface;
use App\Extension\Consent\ConsentManager;
use App\Extension\Consent\ConsentSelection;
use App\Extension\Consent\ConsentSnapshot;
use App\Extension\Consent\ConsentSubject;
use App\Extension\HookManager;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\FakesConsentProviders;
use Tests\TestCase;

/**
 * 동의 제공자 매니저 — 선택 규칙과 fail-closed 판정 (#639, 결정 P3·P4·P5·D1·D12)
 *
 * 제공자 수와 운영자 선택값으로 선택 상태가 정해지고, 판단할 수 없는 모든 경우는 `unknown` 이다.
 * 이 판정이 틀리면 동의하지 않은 방문자의 추적 스크립트가 열리거나(열림 쪽 오류), 동의 관리가 없는
 * 사이트가 「확인 불가」로 막힌다(닫힘 쪽 오류). 둘 다 화면에는 증상이 없다.
 */
class ConsentManagerTest extends TestCase
{
    use FakesConsentProviders;

    private ConsentManager $manager;

    /**
     * 등록·선택값을 비운 매니저를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->resetConsentProviders();
        $this->manager = app(ConsentManager::class);
    }

    /**
     * 분석만 동의한 회원 답을 돌려주는 제공자를 등록합니다.
     *
     * @param  string  $id  제공자 id
     * @param  bool  $available  수집 여부
     * @return ConsentProviderInterface 제공자
     */
    private function analyticsOnly(string $id, bool $available = true): ConsentProviderInterface
    {
        return $this->fakeConsentProvider($id, fn (ConsentSubject $s): array => ['analytics' => true], $available);
    }

    /**
     * 제공자가 없으면 none 이고 스냅샷도 none 이다.
     *
     * @scenario selection=none, visitor=guest, status=none
     *
     * @effects no_provider_yields_none_snapshot
     *
     * @return void 반환값 없음
     */
    public function test_no_provider_is_none(): void
    {
        $selection = $this->manager->selection();

        $this->assertSame(ConsentSelection::STATE_NONE, $selection->state);
        $this->assertSame(ConsentSelection::REASON_NO_PROVIDER, $selection->reason);
        $this->assertSame('none', $this->manager->snapshotFor(ConsentSubject::guest(null))->status);
    }

    /**
     * 선택이 비어 있고 제공자가 하나면 그 제공자가 자동으로 답한다.
     *
     * @scenario selection=auto, visitor=member, status=ready
     *
     * @effects single_provider_is_selected_automatically
     *
     * @return void 반환값 없음
     */
    public function test_single_provider_is_auto(): void
    {
        $this->analyticsOnly('alpha');

        $selection = $this->manager->selection();
        $snapshot = $this->manager->snapshotFor(ConsentSubject::member(7));

        $this->assertSame(ConsentSelection::STATE_AUTO, $selection->state);
        $this->assertSame('alpha', $selection->provider?->getId());
        $this->assertSame('ready', $snapshot->status);
        $this->assertTrue($snapshot->categories['analytics']);
        $this->assertSame('alpha', $snapshot->providerId);
    }

    /**
     * 선택이 비어 있고 제공자가 둘 이상이면 고를 수 없어 unknown 이다.
     *
     * @scenario selection=ambiguous, visitor=guest, status=unknown
     *
     * @effects ambiguous_or_missing_selection_fails_closed
     *
     * @return void 반환값 없음
     */
    public function test_two_providers_without_selection_are_ambiguous(): void
    {
        $this->analyticsOnly('alpha');
        $this->analyticsOnly('beta');

        $this->assertSame(ConsentSelection::STATE_AMBIGUOUS, $this->manager->selection()->state);
        $this->assertSame('unknown', $this->manager->snapshotFor(ConsentSubject::guest('g-1'))->status);
    }

    /**
     * 운영자가 고른 제공자가 등록돼 있지 않으면(비활성·삭제) missing 이고 unknown 이다.
     *
     * @scenario selection=missing, visitor=member, status=unknown
     *
     * @effects ambiguous_or_missing_selection_fails_closed
     *
     * @return void 반환값 없음
     */
    public function test_selected_but_unregistered_provider_is_missing(): void
    {
        $this->analyticsOnly('alpha');
        config(['g7_settings.core.privacy.consent_provider' => 'gone']);

        $this->assertSame(ConsentSelection::STATE_MISSING, $this->manager->selection()->state);
        $this->assertSame('unknown', $this->manager->snapshotFor(ConsentSubject::member(1))->status);
    }

    /**
     * 운영자가 고른 제공자가 수집을 꺼 두었으면 동의 관리 없음(none)이다.
     *
     * @scenario selection=none, visitor=guest, status=none
     *
     * @effects selected_unavailable_provider_yields_none
     *
     * @return void 반환값 없음
     */
    public function test_selected_unavailable_provider_is_none(): void
    {
        $this->analyticsOnly('alpha', available: false);
        config(['g7_settings.core.privacy.consent_provider' => 'alpha']);

        $selection = $this->manager->selection();

        $this->assertSame(ConsentSelection::STATE_NONE, $selection->state);
        $this->assertSame(ConsentSelection::REASON_PROVIDER_UNAVAILABLE, $selection->reason);
        $this->assertSame('none', $this->manager->snapshotFor(ConsentSubject::guest(null))->status);
    }

    /**
     * 운영자가 고른 제공자가 수집 중이면 selected 다.
     *
     * @scenario selection=selected, visitor=member, status=ready
     *
     * @effects explicit_selection_wins_over_auto
     *
     * @return void 반환값 없음
     */
    public function test_explicit_selection_picks_that_provider(): void
    {
        $this->analyticsOnly('alpha');
        $this->fakeConsentProvider('beta', fn (): array => ['marketing' => true]);
        config(['g7_settings.core.privacy.consent_provider' => 'beta']);

        $selection = $this->manager->selection();
        $snapshot = $this->manager->snapshotFor(ConsentSubject::member(3));

        $this->assertSame(ConsentSelection::STATE_SELECTED, $selection->state);
        $this->assertTrue($snapshot->categories['marketing']);
        $this->assertFalse($snapshot->categories['analytics']);
    }

    /**
     * 수집을 꺼 둔 제공자는 자동 선택 후보에서 빠진다.
     *
     * @scenario selection=auto, visitor=guest, status=ready
     *
     * @effects unavailable_provider_is_excluded_from_auto
     *
     * @return void 반환값 없음
     */
    public function test_unavailable_provider_is_excluded_from_auto(): void
    {
        $this->analyticsOnly('alpha', available: false);
        $this->analyticsOnly('beta');

        $selection = $this->manager->selection();

        $this->assertSame(ConsentSelection::STATE_AUTO, $selection->state);
        $this->assertSame('beta', $selection->provider?->getId());
        $this->assertArrayHasKey('alpha', $this->manager->registered(), '선택 목록에는 남는다');
        $this->assertArrayNotHasKey('alpha', $this->manager->providers());
    }

    /**
     * 같은 id 가 둘이면 먼저 온 것만 남기고 경고를 남긴다.
     *
     * @scenario selection=auto, visitor=member, status=ready
     *
     * @effects duplicate_provider_id_keeps_first
     *
     * @return void 반환값 없음
     */
    public function test_duplicate_id_keeps_first_and_warns(): void
    {
        Log::spy();
        $this->fakeConsentProvider('alpha', fn (): array => ['analytics' => true]);
        $this->fakeConsentProvider('alpha', fn (): array => ['analytics' => false]);

        $this->assertCount(1, $this->manager->registered());
        $this->assertTrue($this->manager->snapshotFor(ConsentSubject::member(1))->categories['analytics']);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, '중복'))->atLeast()->once();
    }

    /**
     * 제공자가 예외를 던지면 unknown 이고 경고를 남긴다.
     *
     * @scenario selection=auto, visitor=member, status=unknown
     *
     * @effects provider_exception_fails_closed
     *
     * @return void 반환값 없음
     */
    public function test_provider_exception_is_unknown(): void
    {
        Log::spy();
        $this->fakeConsentProvider('alpha', function (): array {
            throw new \RuntimeException('db down');
        });

        $this->assertSame('unknown', $this->manager->snapshotFor(ConsentSubject::member(1))->status);
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    /**
     * 인터페이스를 구현하지 않은 값은 무시한다.
     *
     * @scenario selection=none, visitor=guest, status=none
     *
     * @effects non_provider_values_are_ignored
     *
     * @return void 반환값 없음
     */
    public function test_non_provider_values_are_ignored(): void
    {
        HookManager::addFilter(ConsentManager::REGISTERED_PROVIDERS_FILTER, fn (array $p): array => [...$p, new \stdClass, 'text']);

        $this->assertSame([], $this->manager->registered());
        $this->assertSame(ConsentSelection::STATE_NONE, $this->manager->selection()->state);
    }

    /**
     * necessary 는 제공자가 false 로 답해도 true 다.
     *
     * @scenario selection=auto, visitor=member, status=ready
     *
     * @effects necessary_is_always_true
     *
     * @return void 반환값 없음
     */
    public function test_necessary_is_forced_true(): void
    {
        $this->fakeConsentProvider('alpha', fn (): array => ['necessary' => false]);

        $snapshot = $this->manager->snapshotFor(ConsentSubject::member(1));

        $this->assertTrue($snapshot->categories['necessary']);
        $this->assertSame(['necessary', 'functional', 'analytics', 'marketing'], array_keys($snapshot->categories));
        $this->assertTrue(ConsentSnapshot::unknown()->categories['necessary']);
    }

    /**
     * 선택된 제공자의 변경 알림은 core.consent.changed 로 발행된다.
     *
     * @scenario selection=auto, visitor=guest, status=ready
     *
     * @effects report_change_emits_core_action
     *
     * @return void 반환값 없음
     */
    public function test_report_change_emits_core_action(): void
    {
        $this->analyticsOnly('alpha');
        $seen = [];
        HookManager::addAction(ConsentManager::CHANGED_ACTION, function (...$args) use (&$seen): void {
            $seen[] = $args;
        });

        $subject = ConsentSubject::guest('g-1');
        $this->manager->reportChange('alpha', $subject, ['analytics' => false, 'bogus' => 'x']);
        $this->manager->reportChange('not-selected', $subject, ['analytics' => false]);

        $this->assertCount(1, $seen, '선택되지 않은 제공자의 알림은 발행하지 않는다');
        $this->assertSame('alpha', $seen[0][0]);
        $this->assertSame($subject, $seen[0][1]);
        $this->assertSame(['analytics' => false], $seen[0][2]);
    }

    /**
     * 게스트 식별자는 선택된 제공자가 요청에서 구하고, 예외면 null 이다.
     *
     * @scenario selection=auto, visitor=guest, status=ready
     *
     * @effects guest_subject_key_comes_from_selected_provider
     *
     * @return void 반환값 없음
     */
    public function test_subject_key_comes_from_selected_provider(): void
    {
        $this->fakeConsentProvider('alpha', null, true, fn () => 'guest-uuid-9');

        $this->assertSame('guest-uuid-9', $this->manager->subjectKey(request()));

        $this->resetConsentProviders();
        $this->fakeConsentProvider('alpha', null, true, function (): string {
            throw new \RuntimeException('bad cookie');
        });

        $this->assertNull($this->manager->subjectKey(request()));
    }
}
