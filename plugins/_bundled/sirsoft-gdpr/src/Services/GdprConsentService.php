<?php

namespace Plugins\Sirsoft\Gdpr\Services;

use App\Extension\HookManager;
use App\Services\PluginSettingsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\Gdpr\Models\GdprUserConsent;
use Plugins\Sirsoft\Gdpr\Models\GdprUserConsentHistory;
use Plugins\Sirsoft\Gdpr\Repositories\Contracts\GdprUserConsentHistoryRepositoryInterface;
use Plugins\Sirsoft\Gdpr\Repositories\Contracts\GdprUserConsentRepositoryInterface;

/**
 * GDPR 동의 서비스
 *
 * 회원 동의 status upsert + history append-only INSERT를 일관 처리합니다.
 * 게스트는 history만 기록하고 status 테이블을 사용하지 않습니다.
 */
class GdprConsentService
{
    /**
     * 플러그인 식별자
     */
    private const PLUGIN_ID = 'sirsoft-gdpr';

    /**
     * GdprConsentService 생성자
     *
     * @param GdprUserConsentRepositoryInterface $statusRepository 동의 상태 Repository
     * @param GdprUserConsentHistoryRepositoryInterface $historyRepository 동의 이력 Repository
     * @param PluginSettingsService $pluginSettings 플러그인 설정 서비스
     * @param CookieCategoryService $categoryService 쿠키 카테고리 카탈로그 서비스
     * @param GdprPolicyVersionService|null $policyVersionService 정책 버전 서비스 — null 인 경우 lazy resolve (테스트 호환성)
     */
    public function __construct(
        private readonly GdprUserConsentRepositoryInterface $statusRepository,
        private readonly GdprUserConsentHistoryRepositoryInterface $historyRepository,
        private readonly PluginSettingsService $pluginSettings,
        private readonly CookieCategoryService $categoryService,
        private ?GdprPolicyVersionService $policyVersionService = null,
    ) {}

    /**
     * 현재 발행된 정책 버전 정수의 문자열 형태를 반환합니다.
     *
     * gdpr_policy_versions 테이블이 SSoT — 마이그레이션 시 initial 행 (v1) 자동 시드되어
     * 항상 최소 1 이상의 정수가 반환됩니다.
     *
     * 마이페이지 응답 / Resource 분기 등 다양한 소비자가 사용하므로 public.
     * 사용자가 *자신의 동의 시점 정책 버전* vs *현재 정책 버전* 을 비교할 수 있도록 함 (GDPR Art.7(1)).
     *
     * @return string "1", "2", "3", ... (gdpr_user_consents.policy_version 컬럼이 string 이므로 string 으로 반환)
     */
    public function getCurrentPolicyVersion(): string
    {
        // policyVersionService 가 미주입된 경우 컨테이너에서 lazy resolve (테스트 호환성)
        $this->policyVersionService ??= app(GdprPolicyVersionService::class);

        return (string) $this->policyVersionService->getCurrentVersion();
    }

    /**
     * 단일 동의 항목을 업데이트합니다.
     *
     * 회원: status 테이블 upsert + history INSERT.
     * 게스트: history만 INSERT (status 미사용).
     * 동일 상태 재요청은 noop으로 처리하여 중복 이력 방지.
     *
     * @param int|null $userId 회원 ID (게스트면 NULL)
     * @param string|null $sessionId 게스트 세션 ID (회원이면 NULL)
     * @param string $consentKey 동의 항목 키
     * @param bool $value 동의 여부
     * @param string $source 변경 경로 (banner/preference_center/register/mypage/order/withdraw)
     * @param array|null $categories 카테고리 스냅샷 (배너 일괄 변경 시)
     * @return void
     */
    public function updateConsent(
        ?int $userId,
        ?string $sessionId,
        string $consentKey,
        bool $value,
        string $source,
        ?array $categories = null,
    ): void {
        $policyVersion = $this->getCurrentPolicyVersion();
        $now = now();

        $consent = null;

        if ($userId !== null) {
            $existing = $this->statusRepository->findByUserAndKey($userId, $consentKey);

            // 동일 상태 중복 처리 방지 (이력 중복 INSERT 방지).
            // policy_version 동일 조건 추가 — 정책 bump 후 같은 값 재요청은 신정책 적용
            // (status.policy_version 갱신 + history INSERT) 으로 이어져야 needs_renewal 이 해소되고
            // GDPR Art.7(1) 입증 책임 (신정책 동의 기록) 도 충족됨.
            if ($existing
                && (bool) $existing->is_consented === $value
                && (string) $existing->policy_version === $policyVersion) {
                return;
            }

            $data = [
                'is_consented' => $value,
                'consented_at' => $value ? $now : ($existing?->consented_at),
                'revoked_at' => $value ? null : $now,
                'policy_version' => $policyVersion,
                'last_source' => $source,
                'consent_category' => $this->resolveCategory($consentKey),
            ];

            if ($value) {
                $data['consent_count'] = ($existing?->consent_count ?? 0) + 1;
            }

            HookManager::doAction(self::PLUGIN_ID . '.consent.before_update', $userId, $consentKey, $data);
            $data = HookManager::applyFilters(self::PLUGIN_ID . '.consent.filter_update_data', $data, $userId, $consentKey);

            $consent = $this->statusRepository->upsert($userId, $consentKey, $data);
        }

        // 호출자가 매트릭스를 명시 전달하지 않은 경우 (마이페이지 grant/revoke 등 단건 변경),
        // 변경 직후 시점의 회원 동의 매트릭스를 자동 구성하여 history 에 보존합니다.
        // GDPR Art.7(1) 입증 책임 — 모든 동의 변경 시점에 카테고리 전체 의사를 immutable 기록.
        $snapshotCategories = $categories ?? ($userId !== null ? $this->buildCategoriesSnapshotForUser($userId) : null);

        // 회원·게스트 모두 history INSERT (불변 append-only)
        $this->historyRepository->record([
            'user_id' => $userId,
            'session_id' => $sessionId,
            'consent_key' => $consentKey,
            'action' => $value ? 'granted' : 'revoked',
            'source' => $source,
            'policy_version' => $policyVersion,
            'categories' => $snapshotCategories,
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 500),
        ]);

        $hookName = $value
            ? self::PLUGIN_ID . '.consent.granted'
            : self::PLUGIN_ID . '.consent.revoked';
        HookManager::doAction($hookName, $consent, $source);
    }

    /**
     * 여러 동의 항목을 일괄 업데이트합니다.
     *
     * @param int|null $userId 회원 ID
     * @param string|null $sessionId 게스트 세션 ID
     * @param array<string, bool> $consents 동의 데이터 [key => bool]
     * @param string $source 변경 경로
     * @return void
     */
    public function updateConsents(?int $userId, ?string $sessionId, array $consents, string $source): void
    {
        $categories = $consents;

        foreach ($consents as $consentKey => $value) {
            $this->updateConsent($userId, $sessionId, $consentKey, (bool) $value, $source, $categories);
        }
    }

    /**
     * 회원의 활성 동의(is_consented=true) 목록을 반환합니다.
     *
     * 로그인 후 클라이언트 동기화에 사용됩니다.
     *
     * @param int $userId 회원 ID
     * @return Collection<int, GdprUserConsent>
     */
    public function getActiveConsents(int $userId): Collection
    {
        return $this->statusRepository->getActiveByUserId($userId);
    }

    /**
     * 회원의 동의 매트릭스를 반환합니다 — 카탈로그의 모든 카테고리 ∪ 회원 status.
     *
     * 마이페이지 「내 동의 현황」 표가 카테고리 전체를 보여주고 철회/재동의/신규 동의를
     * 한 화면에서 처리할 수 있게 함 (Art.7(3) 대칭성 + EDPB Guidelines 05/2020 §5.2 dynamic consent).
     *
     * 합성 규칙:
     *  - 카탈로그에 있고 status row 도 있음 → 실제 status 그대로 (실제 모델 인스턴스)
     *  - 카탈로그에 있지만 status row 없음 → Repository::buildVirtualStatus() 로 합성된 비활성 모델
     *    (consent_count=0, is_consented=false)
     *  - 카탈로그에 없는 status row (예: 운영자가 카테고리 삭제) → 결과에서 제외 (UI 노출 부적합)
     *
     * @param int $userId 회원 ID
     * @return Collection<int, GdprUserConsent>
     */
    public function getMyConsentMatrix(int $userId): Collection
    {
        $statusByKey = $this->statusRepository->getAllByUserId($userId)
            ->keyBy(fn (GdprUserConsent $row) => (string) $row->consent_key);

        $rows = collect();
        foreach ($this->categoryService->getCategories() as $cat) {
            $bareKey = (string) ($cat['key'] ?? '');
            if ($bareKey === '') {
                continue;
            }

            $consentKey = 'cookie_' . $bareKey;

            if ($statusByKey->has($consentKey)) {
                $rows->push($statusByKey->get($consentKey));

                continue;
            }

            $rows->push($this->statusRepository->buildVirtualStatus(
                userId: $userId,
                consentKey: $consentKey,
                consentCategory: $bareKey,
            ));
        }

        return $rows;
    }

    /**
     * 회원의 모든 동의 상태를 반환합니다 (활성·철회 포함).
     *
     * @param int $userId 회원 ID
     * @return Collection<int, GdprUserConsent>
     */
    public function getAllConsents(int $userId): Collection
    {
        return $this->statusRepository->getAllByUserId($userId);
    }

    /**
     * 회원의 동의 이력을 반환합니다.
     *
     * @param int $userId 회원 ID
     * @return Collection<int, GdprUserConsentHistory>
     */
    public function getHistories(int $userId): Collection
    {
        return $this->historyRepository->getByUserId($userId);
    }

    /**
     * 게스트 세션의 동의 이력을 반환합니다.
     *
     * @param string $sessionId 게스트 세션 ID
     * @return Collection<int, GdprUserConsentHistory>
     */
    public function getGuestHistories(string $sessionId): Collection
    {
        return $this->historyRepository->getBySessionId($sessionId);
    }

    /**
     * 사용자 회원탈퇴 시 모든 활성 동의를 철회 처리합니다.
     *
     * status 테이블의 is_consented=true 행을 false로 UPDATE +
     * 각 항목별 history에 source=withdraw로 revoked 행 INSERT.
     *
     * @param int $userId 회원 ID
     * @return void
     */
    public function revokeAllOnWithdraw(int $userId): void
    {
        $activeConsents = $this->statusRepository->getActiveByUserId($userId);

        foreach ($activeConsents as $consent) {
            $this->updateConsent($userId, null, $consent->consent_key, false, 'withdraw');
        }
    }

    /**
     * 사용자 완전 삭제 시 status 행 삭제 + history 익명화 처리.
     *
     * status 테이블은 cascadeOnDelete가 있지만 명시적 삭제로 일관성 보장.
     * history 테이블은 user_id·ip_address·user_agent를 NULL로 익명화하여
     * 감사 추적용 행을 보존합니다 (GDPR Art.17 + Art.7(1) 양립).
     *
     * @param int $userId 회원 ID
     * @return void
     */
    public function purgeOnUserDelete(int $userId): void
    {
        $this->statusRepository->deleteByUserId($userId);
        $this->historyRepository->anonymizeForUser($userId);
    }

    /**
     * 정책 버전이 갱신되었는지 확인합니다.
     *
     * 회원의 가장 최근 동의가 현재 정책 버전과 다르면 재동의 트리거 대상.
     *
     * @param int $userId 회원 ID
     * @return bool 재동의 필요 여부
     */
    public function needsRenewal(int $userId): bool
    {
        $current = $this->getCurrentPolicyVersion();
        $consents = $this->statusRepository->getAllByUserId($userId);

        if ($consents->isEmpty()) {
            return false;
        }

        // 필수 쿠키는 ePrivacy Art.5(3) 면제 — 정책 버전 갱신과 무관 (계약 이행 / 정당한 이익 근거).
        // 사용자에게 재동의 액션을 요구하지 않으므로 needs_renewal 판정에서도 제외 (선택형만 검사).
        // 또한 *철회 상태* row 도 제외 — 사용자가 명시적으로 철회한 의사이므로 *재동의가 필요한
        // 상태* 가 아님. renewAllForCurrentPolicy 가 활성 동의만 갱신하므로 needsRenewal 도 같은
        // 기준으로 좁혀야 *전체 항목 다시 동의* 후 amber 박스가 정상적으로 사라짐 (회귀 가드).
        foreach ($consents as $consent) {
            $consentKey = (string) $consent->consent_key;
            if ($this->categoryService->isRequired($consentKey)) {
                continue;
            }
            if (! (bool) $consent->is_consented) {
                continue;
            }
            if ((string) $consent->policy_version !== $current) {
                return true;
            }
        }

        return false;
    }

    /**
     * 회원의 *활성 선택형 동의* 의 policy_version 만 현재 버전으로 일괄 bump.
     *
     * "전체 항목 다시 동의" (#19) 버튼에 사용. 의사 변경은 없고 정책 버전만 갱신:
     *  - 필수 쿠키 (ePrivacy Art.5(3) 면제) 제외
     *  - 비활성 (철회 상태) 제외 — 사용자 의사 왜곡 방지 (revoked → granted 자동 전환 금지)
     *  - 활성 + 옛 버전 → policy_version = current, last_source = 'mypage_renew_all'
     *  - 각 갱신 행마다 history append (action=granted, source=mypage_renew_all) — Art.7(1) 입증 트레일
     *
     * @param int $userId 회원 ID
     * @return int 갱신된 동의 행 수
     */
    public function renewAllForCurrentPolicy(int $userId): int
    {
        $current = $this->getCurrentPolicyVersion();
        $consents = $this->statusRepository->getAllByUserId($userId);
        $renewed = 0;

        foreach ($consents as $consent) {
            $consentKey = (string) $consent->consent_key;

            // 필수 쿠키 제외 (Art.5(3) 면제).
            if ($this->categoryService->isRequired($consentKey)) {
                continue;
            }

            // 활성 동의만 대상 — 철회 상태는 사용자 명시적 의사이므로 자동 grant 금지.
            if (! (bool) $consent->is_consented) {
                continue;
            }

            // 이미 현재 버전이면 skip.
            if ((string) $consent->policy_version === $current) {
                continue;
            }

            $data = [
                'is_consented' => true,
                'policy_version' => $current,
                'last_source' => 'mypage_renew_all',
                'consent_category' => $this->resolveCategory($consentKey),
                'consent_count' => (int) $consent->consent_count + 1,
            ];

            HookManager::doAction(self::PLUGIN_ID . '.consent.before_update', $userId, $consentKey, $data);
            $data = HookManager::applyFilters(self::PLUGIN_ID . '.consent.filter_update_data', $data, $userId, $consentKey);

            $updated = $this->statusRepository->upsert($userId, $consentKey, $data);

            $this->historyRepository->record([
                'user_id' => $userId,
                'session_id' => null,
                'consent_key' => $consentKey,
                'action' => 'granted',
                'source' => 'mypage_renew_all',
                'policy_version' => $current,
                'categories' => $this->buildCategoriesSnapshotForUser($userId),
                'ip_address' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 500),
            ]);

            HookManager::doAction(self::PLUGIN_ID . '.consent.granted', $updated, 'mypage_renew_all');

            $renewed++;
        }

        return $renewed;
    }

    /**
     * 현재 정책 버전으로 쿠키 동의가 완료되었는지 반환합니다 (회원/게스트 통합).
     *
     * 배너 표시 여부 결정용. 회원 또는 게스트 식별자 한쪽만 제공.
     * 회원: status 테이블에서 cookie_ 접두사 동의 중 정책 버전 일치하는 항목이 1개 이상이면 동의 완료.
     * 게스트: history 의 가장 최근 항목 중 정책 버전 일치하는 cookie_ 접두사 동의가 1개 이상이면 동의 완료.
     *
     * @param int|null $userId 회원 ID (게스트면 NULL)
     * @param string|null $sessionId 게스트 세션 ID (회원이면 NULL)
     * @return bool 현재 정책 버전으로 동의 완료 여부
     */
    public function hasCurrentCookieConsent(?int $userId, ?string $sessionId): bool
    {
        $currentVersion = $this->getCurrentPolicyVersion();

        if ($userId !== null) {
            $consents = $this->statusRepository->getAllByUserId($userId);
            foreach ($consents as $consent) {
                if (str_starts_with((string) $consent->consent_key, 'cookie_')
                    && (string) $consent->policy_version === $currentVersion) {
                    return true;
                }
            }

            return false;
        }

        if ($sessionId === null || $sessionId === '') {
            return false;
        }

        $histories = $this->historyRepository->getBySessionId($sessionId);
        foreach ($histories as $h) {
            if (str_starts_with((string) $h->consent_key, 'cookie_')
                && (string) $h->policy_version === $currentVersion) {
                return true;
            }
        }

        return false;
    }

    /**
     * 회원/게스트가 cookie_ 접두사 동의 이력을 1건 이상 가지고 있는지 반환합니다.
     *
     * `needs_renewal` 산출용 — `hasCurrentCookieConsent=false` 이면서 본 메서드가
     * true 면 "옛 정책 동의는 있는데 현재 정책 버전 미동의" 상태 (= 재확인 필요).
     * 신규 게스트 (이력 0건) 와 옛 동의 게스트 (이력 있음 + 옛 버전) 를 구분하기 위해 필요.
     *
     * 회원: status 테이블에서 cookie_ 접두사 1건 이상 → true
     * 게스트: history 테이블에서 session_id + cookie_ 접두사 1건 이상 → true
     *
     * @param int|null $userId 회원 ID (게스트면 NULL)
     * @param string|null $sessionId 게스트 세션 ID (회원이면 NULL)
     * @return bool 동의 이력 존재 여부
     */
    public function hasAnyConsentHistory(?int $userId, ?string $sessionId): bool
    {
        if ($userId !== null) {
            $consents = $this->statusRepository->getAllByUserId($userId);
            foreach ($consents as $consent) {
                if (str_starts_with((string) $consent->consent_key, 'cookie_')) {
                    return true;
                }
            }

            return false;
        }

        if ($sessionId === null || $sessionId === '') {
            return false;
        }

        $histories = $this->historyRepository->getBySessionId($sessionId);
        foreach ($histories as $h) {
            if (str_starts_with((string) $h->consent_key, 'cookie_')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 현재 정책 버전으로 카테고리별 쿠키 동의 상태를 반환합니다 (회원/게스트 통합).
     *
     * 자동 차단 엔진(blocker)이 사용. 응답 키는 cookie_ 접두사를 제거한 카테고리 키
     * (예: 'cookie_analytics' → 'analytics'). 정책 버전이 일치하지 않거나 동의가 없으면
     * 빈 배열 반환.
     *
     * 회원: status 테이블의 cookie_ 접두사 + 정책 버전 일치 행을 카테고리별로 매핑.
     * 게스트: history 의 정책 버전 일치 cookie_ 접두사 항목 중 가장 최근 action 을 사용.
     *
     * @param int|null $userId 회원 ID (게스트면 NULL)
     * @param string|null $sessionId 게스트 세션 ID (회원이면 NULL)
     * @return array<string, bool> 카테고리 키 → 동의 여부 (예: ['necessary' => true, 'analytics' => false])
     */
    public function getCurrentCookieConsents(?int $userId, ?string $sessionId): array
    {
        $currentVersion = $this->getCurrentPolicyVersion();
        $result = [];

        if ($userId !== null) {
            $consents = $this->statusRepository->getAllByUserId($userId);
            foreach ($consents as $consent) {
                $key = (string) $consent->consent_key;
                if (! str_starts_with($key, 'cookie_')) {
                    continue;
                }
                if ((string) $consent->policy_version !== $currentVersion) {
                    continue;
                }
                $category = substr($key, strlen('cookie_'));
                $result[$category] = (bool) $consent->is_consented;
            }

            return $result;
        }

        if ($sessionId === null || $sessionId === '') {
            return [];
        }

        // 게스트: history 는 append-only 이므로 가장 최근 action 만 사용
        $histories = $this->historyRepository->getBySessionId($sessionId);
        foreach ($histories as $h) {
            $key = (string) $h->consent_key;
            if (! str_starts_with($key, 'cookie_')) {
                continue;
            }
            if ((string) $h->policy_version !== $currentVersion) {
                continue;
            }
            $category = substr($key, strlen('cookie_'));
            // history 가 최신순일 수도, 오래된 순일 수도 있으므로 created_at 으로 결정
            if (! isset($result[$category]['_at']) || $h->created_at > $result[$category]['_at']) {
                $result[$category] = [
                    '_at' => $h->created_at,
                    'value' => $h->action === 'granted',
                ];
            }
        }

        return array_map(fn ($entry) => $entry['value'], $result);
    }

    /**
     * consent_key로부터 category 분류를 추정합니다.
     *
     * @param string $consentKey 동의 항목 키
     * @return string|null
     */
    private function resolveCategory(string $consentKey): ?string
    {
        if (str_starts_with($consentKey, 'cookie_')) {
            return 'cookie';
        }

        return null;
    }

    /**
     * 회원의 모든 동의 행을 카테고리 매트릭스로 변환합니다.
     *
     * history 의 `categories` 컬럼은 동의 변경 시점의 회원 의사 전체를 immutable 보존하는
     * GDPR Art.7(1) 입증 자료입니다. 마이페이지 단건 변경처럼 호출자가 매트릭스를 명시
     * 전달하지 않는 경로에서는 변경 직후 시점의 status repository 를 재조회하여 자동 구성합니다.
     *
     * 동의 row 가 한 건도 없는 회원은 null 반환 — history.categories null 과 동일 의미.
     *
     * @param int $userId 회원 ID
     * @return array<string, bool>|null 카테고리 키 → 동의 여부 매트릭스
     */
    private function buildCategoriesSnapshotForUser(int $userId): ?array
    {
        $consents = $this->statusRepository->getAllByUserId($userId);

        if ($consents->isEmpty()) {
            return null;
        }

        $snapshot = [];
        foreach ($consents as $consent) {
            $snapshot[(string) $consent->consent_key] = (bool) $consent->is_consented;
        }

        return $snapshot;
    }
}
