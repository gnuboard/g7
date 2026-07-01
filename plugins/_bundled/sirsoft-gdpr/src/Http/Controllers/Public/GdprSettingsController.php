<?php

namespace Plugins\Sirsoft\Gdpr\Http\Controllers\Public;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Services\PluginSettingsService;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Gdpr\Plugin;
use Plugins\Sirsoft\Gdpr\Services\CookieCategoryService;
use Plugins\Sirsoft\Gdpr\Services\GdprPolicyVersionService;

/**
 * GDPR 공개 설정 컨트롤러
 *
 * 게스트도 접근 가능한 공개 설정을 반환합니다.
 * 배너·마이페이지 카드 렌더링에 필요한 expose 필드만 제공합니다.
 *
 * GET /api/plugins/sirsoft-gdpr/settings
 */
class GdprSettingsController extends PublicBaseController
{
    /**
     * 플러그인 식별자
     */
    private const PLUGIN_ID = 'sirsoft-gdpr';

    /**
     * GdprSettingsController 생성자
     *
     * @param  CookieCategoryService  $categoryService  쿠키 카테고리 서비스
     * @param  PluginSettingsService  $pluginSettings  플러그인 설정 서비스
     * @param  GdprPolicyVersionService  $policyVersionService  정책 버전 서비스 (응답의 cookie_policy_version 출처)
     */
    public function __construct(
        private readonly CookieCategoryService $categoryService,
        private readonly PluginSettingsService $pluginSettings,
        private readonly GdprPolicyVersionService $policyVersionService,
    ) {
        parent::__construct();
    }

    /**
     * 공개 설정 응답을 반환합니다.
     *
     * @return JsonResponse
     */
    public function show(): JsonResponse
    {
        $privacySlug = (string) $this->pluginSettings->get(self::PLUGIN_ID, 'privacy_policy_slug', 'privacy');

        $data = [
            // 정책 버전은 gdpr_policy_versions 테이블이 SSoT — 최신 row 의 version 정수를 string 으로 노출.
            // 회원/게스트 동의 시점 비교 (배너 재노출 판정) + 마이페이지의 자신의 동의 버전 vs 현재 비교에 사용.
            'cookie_policy_version' => (string) $this->policyVersionService->getCurrentVersion(),
            'privacy_policy_slug' => $privacySlug !== '' ? $privacySlug : null,
            'privacy_policy_available' => $privacySlug !== '',
            'legal_entity_name' => (string) $this->pluginSettings->get(self::PLUGIN_ID, 'legal_entity_name', ''),
            'data_storage_location' => (string) $this->pluginSettings->get(self::PLUGIN_ID, 'data_storage_location', ''),

            // 쿠키 배너 + 자동 차단 (F-01 / F-02) — banner_enabled 단일 토글로 통합 제어.
            // 차단 엔진(클라이언트 blocker.ts) 의 활성 조건도 banner_enabled === true 단일.
            'banner_enabled' => (bool) $this->pluginSettings->get(self::PLUGIN_ID, 'banner_enabled', true),
            'banner_position' => (string) $this->pluginSettings->get(self::PLUGIN_ID, 'banner_position', 'bottom_bar'),
            'cookie_categories' => $this->categoryService->getCategories(),

            // F-02 도메인 기반 차단 — 게스트도 차단 동작해야 하므로 공개 응답에 노출.
            // 본 컨트롤러가 응답 노출 SSoT (defaults.json 의 frontend_schema 는 보조).
            'blocked_domains' => $this->normalizeBlockedDomains(
                $this->pluginSettings->get(self::PLUGIN_ID, 'blocked_domains', Plugin::DEFAULT_BLOCKED_DOMAINS_CATALOG)
            ),
            'default_blocked_domains_preview' => Plugin::DEFAULT_BLOCKED_DOMAINS_CATALOG,
        ];

        return ResponseHelper::success('messages.success', $data);
    }

    /**
     * blocked_domains 응답 형식을 정규화합니다.
     *
     * 저장 형식이 native array 임을 가정하지만, JSON 문자열로 저장되어 있는 환경
     * (구버전 호환) 도 안전하게 처리합니다.
     *
     * @param  mixed  $value  설정 저장값
     * @return array<string, array<int, string>> 카테고리 → 도메인 패턴 배열
     */
    private function normalizeBlockedDomains(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $category => $domains) {
            if (! is_string($category)) {
                continue;
            }
            if (! is_array($domains)) {
                $normalized[$category] = [];

                continue;
            }
            $normalized[$category] = array_values(array_filter(
                array_map(
                    fn ($d) => is_string($d) ? trim($d) : '',
                    $domains,
                ),
                fn ($d) => $d !== '',
            ));
        }

        return $normalized;
    }
}
