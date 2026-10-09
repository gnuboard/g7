<?php

namespace Plugins\Sirsoft\Marketing\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Services\PluginSettingsService;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Marketing\Services\MarketingConsentService;

/**
 * 마케팅 공개 설정 컨트롤러
 *
 * 비로그인 상태에서도 마케팅 동의 항목 활성화 여부를 조회합니다.
 * 회원가입 폼의 조건부 렌더링에 사용됩니다.
 */
class MarketingSettingsController extends PublicBaseController
{
    /**
     * 플러그인 식별자
     */
    private const PLUGIN_ID = 'sirsoft-marketing';

    /**
     * MarketingSettingsController 생성자
     *
     * @param  PluginSettingsService  $pluginSettings  플러그인 설정 서비스
     * @param  MarketingConsentService  $consentService  마케팅 동의 서비스
     */
    public function __construct(
        private PluginSettingsService $pluginSettings,
        private MarketingConsentService $consentService
    ) {
        parent::__construct();
    }

    /**
     * 마케팅 동의 항목 활성화 여부, 약관 slug, 채널 목록 반환
     *
     * 마케팅 전체 동의 설정과 channels JSON 기반 동적 채널 목록을 반환합니다.
     * slug 값은 노출하지 않고 존재 여부(*_terms_slug_set)만 반환합니다.
     * channels 배열은 회원가입/마이페이지 폼의 iteration 렌더링에 사용됩니다.
     *
     * @return JsonResponse
     */
    public function settings(): JsonResponse
    {
        $data = [];

        // 마케팅 전체 동의 설정
        $marketingSlug = $this->pluginSettings->get(self::PLUGIN_ID, 'marketing_consent_terms_slug', '');
        $data['marketing_consent_enabled'] = (bool) $this->pluginSettings->get(self::PLUGIN_ID, 'marketing_consent_enabled', true);
        $data['marketing_consent_terms_slug'] = $marketingSlug ?: null;
        $data['marketing_consent_terms_slug_set'] = ! empty($marketingSlug);

        // 법적 동의 항목 활성화 여부
        $data['third_party_consent_enabled'] = (bool) $this->pluginSettings->get(self::PLUGIN_ID, 'third_party_consent_enabled');
        $thirdPartySlug = $this->pluginSettings->get(self::PLUGIN_ID, 'third_party_consent_terms_slug', '');
        $data['third_party_consent_terms_slug'] = $thirdPartySlug ?: null;
        $data['third_party_consent_terms_slug_set'] = ! empty($thirdPartySlug);

        $data['info_disclosure_enabled'] = (bool) $this->pluginSettings->get(self::PLUGIN_ID, 'info_disclosure_enabled');
        $infoSlug = $this->pluginSettings->get(self::PLUGIN_ID, 'info_disclosure_terms_slug', '');
        $data['info_disclosure_terms_slug'] = $infoSlug ?: null;
        $data['info_disclosure_terms_slug_set'] = ! empty($infoSlug);

        // channels JSON 기반 동적 채널 처리 (활성 채널만)
        $channels = $this->consentService->getRegisteredChannels();
        $locale = app()->getLocale();

        // 라벨은 회원 응답(filterResourceData)과 같은 규칙으로 해석한다 — label_key 번역이 SSoT 이고 저장된 맵은 폴백이다.
        // 저장된 맵만 읽으면 언어팩으로 늘어난 로케일(ja 등)에서 가입·프로필 수정 화면의 채널 이름이 ko 로 떨어진다.
        $data['channels'] = array_map(function (array $channel) use ($locale) {
            $slug = $channel['page_slug'] ?? '';

            return [
                'key' => $channel['key'],
                'label' => $this->consentService->resolveChannelLabel($channel, $locale),
                'label_i18n' => $this->consentService->localizedLabelMap($channel),
                'enabled' => (bool) ($channel['enabled'] ?? true),
                'terms_slug' => $slug ?: null,
                'terms_slug_set' => ! empty($slug),
            ];
        }, $channels);

        return ResponseHelper::success('common.success', $data);
    }
}
