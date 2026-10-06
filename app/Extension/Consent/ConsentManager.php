<?php

namespace App\Extension\Consent;

use App\Contracts\Extension\ConsentProviderInterface;
use App\Extension\HookManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 동의 제공자 레지스트리 / 매니저
 *
 * 본인인증 매니저와 같은 레시피(인터페이스 + 필터 훅 등록 + 싱글톤 매니저)를 따릅니다. 동의를 관리하는
 * 확장은 `core.consent.registered_providers` 필터로 제공자를 등록하고, 코어와 다른 확장은 이 매니저를 통해
 * 「운영자가 고른 하나」에게만 묻습니다.
 *
 * 판단할 수 없는 모든 경우(제공자 모호, 고른 제공자 사라짐, 제공자 예외)는 `unknown` 입니다. 소비자는
 * `unknown` 을 동의하지 않은 것으로 다룹니다.
 *
 * 코어는 어떤 확장이 제공자인지 모릅니다 — 이 클래스에 특정 확장 이름이 나오면 안 됩니다.
 *
 * @since 7.1.0
 */
class ConsentManager
{
    /** 제공자 등록 필터 훅 */
    public const REGISTERED_PROVIDERS_FILTER = 'core.consent.registered_providers';

    /** 동의 변경 알림 액션 훅 — 인자: 제공자 id, ConsentSubject, 바뀐 카테고리 [key => bool] */
    public const CHANGED_ACTION = 'core.consent.changed';

    /** 운영자 선택 설정 키 (빈 문자열 = 자동) */
    public const SETTING_KEY = 'privacy.consent_provider';

    /**
     * 등록된 제공자 전부를 반환합니다 (수집을 꺼 둔 제공자 포함).
     *
     * 인터페이스를 구현하지 않은 값은 버리고, 같은 id 가 둘이면 먼저 온 것만 남기고 경고를 남깁니다.
     *
     * @return array<string, ConsentProviderInterface> id → 제공자
     */
    public function registered(): array
    {
        try {
            $merged = HookManager::applyFilters(self::REGISTERED_PROVIDERS_FILTER, []);
        } catch (\Throwable $e) {
            Log::warning('동의 제공자 등록 필터 실패', ['error' => $e->getMessage()]);

            return [];
        }

        if (! is_array($merged)) {
            return [];
        }

        $providers = [];
        foreach ($merged as $provider) {
            if (! $provider instanceof ConsentProviderInterface) {
                continue;
            }

            $id = $provider->getId();
            if (isset($providers[$id])) {
                Log::warning('동의 제공자 id 중복 — 먼저 등록된 제공자만 사용합니다', ['provider' => $id]);

                continue;
            }

            $providers[$id] = $provider;
        }

        return $providers;
    }

    /**
     * 지금 동의를 모으고 있는 제공자(자동 선택 후보)를 반환합니다.
     *
     * @return array<string, ConsentProviderInterface> id → 제공자
     */
    public function providers(): array
    {
        return array_filter($this->registered(), fn (ConsentProviderInterface $p): bool => $this->isAvailable($p));
    }

    /**
     * 운영자 선택값을 반환합니다 (빈 문자열 = 자동).
     *
     * @return string 선택한 제공자 id
     */
    public function configuredProviderId(): string
    {
        $value = g7_core_settings(self::SETTING_KEY, '');

        return is_string($value) ? trim($value) : '';
    }

    /**
     * 선택 상태를 판정합니다.
     *
     * @return ConsentSelection 선택 상태
     */
    public function selection(): ConsentSelection
    {
        $registered = $this->registered();
        $candidates = array_values(array_filter($registered, fn (ConsentProviderInterface $p): bool => $this->isAvailable($p)));
        $configured = $this->configuredProviderId();

        if ($configured === '') {
            return match (count($candidates)) {
                0 => new ConsentSelection(ConsentSelection::STATE_NONE, null, $candidates, ConsentSelection::REASON_NO_PROVIDER),
                1 => new ConsentSelection(ConsentSelection::STATE_AUTO, $candidates[0], $candidates),
                default => new ConsentSelection(ConsentSelection::STATE_AMBIGUOUS, null, $candidates),
            };
        }

        if (! isset($registered[$configured])) {
            return new ConsentSelection(ConsentSelection::STATE_MISSING, null, $candidates);
        }

        $provider = $registered[$configured];
        if (! $this->isAvailable($provider)) {
            return new ConsentSelection(ConsentSelection::STATE_NONE, null, $candidates, ConsentSelection::REASON_PROVIDER_UNAVAILABLE);
        }

        return new ConsentSelection(ConsentSelection::STATE_SELECTED, $provider, $candidates);
    }

    /**
     * 요청에서 게스트 동의 식별자를 구합니다 (선택된 제공자에게 묻는다).
     *
     * @param  Request  $request  HTTP 요청
     * @return string|null 게스트 동의 식별자 (제공자가 없거나 실패하면 null)
     */
    public function subjectKey(Request $request): ?string
    {
        $provider = $this->selection()->provider;
        if ($provider === null) {
            return null;
        }

        try {
            $key = $provider->resolveSubjectKey($request);
        } catch (\Throwable $e) {
            Log::warning('동의 제공자 게스트 식별 실패', ['provider' => $provider->getId(), 'error' => $e->getMessage()]);

            return null;
        }

        return is_string($key) && $key !== '' ? mb_substr($key, 0, 100) : null;
    }

    /**
     * 요청 주체를 만듭니다 (회원이면 회원 ID, 아니면 제공자가 구한 게스트 식별자).
     *
     * @param  Request  $request  HTTP 요청
     * @return ConsentSubject 동의 주체
     */
    public function subjectFor(Request $request): ConsentSubject
    {
        $user = $request->user();
        $userId = $user?->getAuthIdentifier();

        if ($userId !== null) {
            return ConsentSubject::member((int) $userId);
        }

        return ConsentSubject::guest($this->subjectKey($request));
    }

    /**
     * 주체의 동의 스냅샷을 반환합니다.
     *
     * @param  ConsentSubject  $subject  동의 주체
     * @return ConsentSnapshot 동의 스냅샷
     */
    public function snapshotFor(ConsentSubject $subject): ConsentSnapshot
    {
        $selection = $this->selection();

        if ($selection->state === ConsentSelection::STATE_NONE) {
            return ConsentSnapshot::none();
        }

        $provider = $selection->provider;
        if ($provider === null) {
            return ConsentSnapshot::unknown();
        }

        try {
            $snapshot = $provider->getSnapshot($subject);
        } catch (\Throwable $e) {
            Log::warning('동의 제공자 스냅샷 조회 실패', ['provider' => $provider->getId(), 'error' => $e->getMessage()]);

            return ConsentSnapshot::unknown();
        }

        if ($snapshot->status !== ConsentSnapshot::STATUS_READY) {
            return ConsentSnapshot::unknown();
        }

        return ConsentSnapshot::ready($snapshot->categories, $snapshot->needsRenewal, $snapshot->policyVersion, $provider->getId());
    }

    /**
     * 제공자가 동의 변경을 알립니다 — 코어 액션 `core.consent.changed` 를 발행합니다.
     *
     * 지금 선택된 제공자가 아니면 발행하지 않습니다(답하지 않는 제공자의 변경은 판정에 영향이 없다).
     *
     * @param  string  $providerId  알리는 제공자 id
     * @param  ConsentSubject  $subject  동의 주체
     * @param  array<string, bool>  $changes  바뀐 카테고리 → 새 동의 여부
     * @return void 반환값 없음
     */
    public function reportChange(string $providerId, ConsentSubject $subject, array $changes): void
    {
        if ($this->selection()->provider?->getId() !== $providerId) {
            return;
        }

        $normalized = [];
        foreach ($changes as $key => $value) {
            if (is_string($key) && $key !== '' && is_bool($value)) {
                $normalized[$key] = $value;
            }
        }

        if ($normalized === []) {
            return;
        }

        HookManager::doAction(self::CHANGED_ACTION, $providerId, $subject, $normalized);
    }

    /**
     * 제공자가 수집 중인지 안전하게 확인합니다 (예외면 false).
     *
     * @param  ConsentProviderInterface  $provider  제공자
     * @return bool 수집 여부
     */
    private function isAvailable(ConsentProviderInterface $provider): bool
    {
        try {
            return $provider->isAvailable();
        } catch (\Throwable $e) {
            Log::warning('동의 제공자 수집 여부 확인 실패', ['provider' => $provider->getId(), 'error' => $e->getMessage()]);

            return false;
        }
    }
}
