<?php

namespace Plugins\Sirsoft\Gdpr\Consent;

use App\Contracts\Extension\ConsentProviderInterface;
use App\Extension\Consent\ConsentSnapshot;
use App\Extension\Consent\ConsentSubject;
use App\Services\PluginSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Plugins\Sirsoft\Gdpr\Concerns\IssuesGuestSessionCookie;
use Plugins\Sirsoft\Gdpr\Services\CookieCategoryService;
use Plugins\Sirsoft\Gdpr\Services\GdprConsentService;

/**
 * 코어 동의 제공자 계약 구현 — 쿠키 동의 배너의 판단을 코어와 다른 확장에 답합니다 (#639)
 *
 * 코어는 이 플러그인을 모릅니다. `core.consent.registered_providers` 필터로 등록되고, 운영자가 고른(또는 하나뿐이라
 * 자동으로 고른) 제공자일 때만 질문을 받습니다.
 *
 * - 게스트는 서명을 검증한 `gdpr_session` 쿠키의 UUID 로 찾는다(위조 쿠키는 미식별 게스트).
 * - 아직 동의하지 않은 게스트(식별자 없음)는 「모름」이 아니라 「필수만 동의」다(결정 D19).
 * - 정책 버전이 바뀌어 다시 동의를 받아야 하면 `needsRenewal` — 옛 버전 동의는 현재 동의로 세지 않는다.
 * - 배너를 끈 사이트는 동의를 모으지 않으므로 `isAvailable()` 이 false 다(결정 D12).
 */
class GdprConsentProvider implements ConsentProviderInterface
{
    use IssuesGuestSessionCookie;

    /** 제공자 id (플러그인 식별자) */
    public const PROVIDER_ID = 'sirsoft-gdpr';

    /**
     * 제공자를 생성합니다.
     *
     * @param  GdprConsentService  $consentService  동의 서비스
     * @param  CookieCategoryService  $categoryService  쿠키 카테고리 서비스
     * @param  PluginSettingsService  $pluginSettings  플러그인 설정 서비스
     */
    public function __construct(
        private readonly GdprConsentService $consentService,
        private readonly CookieCategoryService $categoryService,
        private readonly PluginSettingsService $pluginSettings,
    ) {}

    /**
     * 제공자 id 를 반환합니다.
     *
     * @return string 제공자 id
     */
    public function getId(): string
    {
        return self::PROVIDER_ID;
    }

    /**
     * 관리자 화면 표시 이름을 반환합니다 (현재 로케일).
     *
     * @return string 표시 이름
     */
    public function getLabel(): string
    {
        return __('sirsoft-gdpr::messages.consent_provider.label');
    }

    /**
     * 배너(동의 수집)가 켜져 있는지 반환합니다.
     *
     * @return bool 수집 여부
     */
    public function isAvailable(): bool
    {
        return (bool) $this->pluginSettings->get(self::PROVIDER_ID, 'banner_enabled', true);
    }

    /**
     * 운영자가 정한 쿠키 카테고리를 계약 모양으로 반환합니다.
     *
     * @return array<int, array{key: string, label: string, required: bool}> 카테고리 목록
     */
    public function getCategories(): array
    {
        $locale = App::getLocale();
        $categories = array_filter(
            $this->categoryService->getCategories(),
            static fn ($category) => is_array($category) && isset($category['key'])
        );

        return array_values(array_map(static function (array $category) use ($locale): array {
            $label = $category['label'] ?? $category['key'];
            if (is_array($label)) {
                $label = $label[$locale] ?? $label['ko'] ?? (reset($label) ?: $category['key']);
            }

            return [
                'key' => (string) $category['key'],
                'label' => (string) $label,
                'required' => (bool) ($category['required'] ?? false),
            ];
        }, $categories));
    }

    /**
     * 요청에서 게스트 동의 식별자를 구합니다 (회원이면 null).
     *
     * @param  Request  $request  HTTP 요청
     * @return string|null 게스트 동의 식별자
     */
    public function resolveSubjectKey(Request $request): ?string
    {
        return $this->resolveGuestSessionId($request);
    }

    /**
     * 주체의 현재 동의 스냅샷을 반환합니다.
     *
     * @param  ConsentSubject  $subject  동의 주체
     * @return ConsentSnapshot 동의 스냅샷
     */
    public function getSnapshot(ConsentSubject $subject): ConsentSnapshot
    {
        $policyVersion = $this->consentService->getCurrentPolicyVersion();

        // 식별되지 않은 게스트 — 아직 아무것에도 동의하지 않았다(필수만).
        if ($subject->userId === null && $subject->subjectKey === null) {
            return ConsentSnapshot::ready([], false, $policyVersion, self::PROVIDER_ID);
        }

        $consents = $this->consentService->getCurrentCookieConsents($subject->userId, $subject->subjectKey);
        $needsRenewal = ! $this->consentService->hasCurrentCookieConsent($subject->userId, $subject->subjectKey)
            && $this->consentService->hasAnyConsentHistory($subject->userId, $subject->subjectKey);

        return ConsentSnapshot::ready($consents, $needsRenewal, $policyVersion, self::PROVIDER_ID);
    }
}
