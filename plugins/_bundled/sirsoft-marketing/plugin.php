<?php

namespace Plugins\Sirsoft\Marketing;

use App\Extension\AbstractPlugin;

class Plugin extends AbstractPlugin
{
    /**
     * 플러그인이 제공하는 훅 정보 반환
     *
     * @return array 훅 정의 배열
     */
    public function getHooks(): array
    {
        return [
            [
                'name' => 'sirsoft-marketing.user.consent_changed',
                'type' => 'action',
                'description' => [
                    'ko' => '사용자 마케팅 동의 변경 시 실행되는 액션 훅',
                    'en' => 'Action hook executed when user marketing consent changes',
                ],
                'parameters' => [
                    'consent' => 'Model - 마케팅 동의 모델 (MarketingConsent)',
                    'context' => "array - 변경 컨텍스트 ['source' => string, 'data' => array]",
                ],
            ],
            [
                'name' => 'sirsoft-marketing.user.subscribed',
                'type' => 'action',
                'description' => [
                    'ko' => '사용자 마케팅 동의 필드가 동의(granted)로 변경될 때 실행되는 액션 훅',
                    'en' => 'Action hook executed when a marketing consent field is granted',
                ],
                'parameters' => [
                    'consent' => 'Model - 마케팅 동의 모델 (MarketingConsent)',
                    'field' => 'string - 변경된 동의 필드 (email_subscription, marketing_consent 등)',
                ],
            ],
            [
                'name' => 'sirsoft-marketing.user.unsubscribed',
                'type' => 'action',
                'description' => [
                    'ko' => '사용자 마케팅 동의 필드가 철회(revoked)로 변경될 때 실행되는 액션 훅',
                    'en' => 'Action hook executed when a marketing consent field is revoked',
                ],
                'parameters' => [
                    'consent' => 'Model - 마케팅 동의 모델 (MarketingConsent)',
                    'field' => 'string - 변경된 동의 필드 (email_subscription, marketing_consent 등)',
                ],
            ],
            [
                'name' => 'sirsoft-marketing.filter_consent_data',
                'type' => 'filter',
                'description' => [
                    'ko' => '마케팅 동의 데이터를 필터링하는 훅',
                    'en' => 'Filter hook to modify marketing consent data',
                ],
                'parameters' => [
                    'data' => 'array - 동의 데이터',
                    'user' => 'Model - 사용자 모델',
                ],
                'return' => 'array - 필터링된 동의 데이터',
            ],
        ];
    }

    /**
     * 플러그인 설정 값 반환
     *
     * @return array 설정 값 배열
     */
    public function getConfigValues(): array
    {
        return [
            // 마케팅 전체 동의 사용 여부 및 약관 slug
            'marketing_consent_enabled'    => true,
            'marketing_consent_terms_slug' => 'marketing-terms',
            // 법적 동의 항목 사용 여부 및 약관 slug
            'third_party_consent_enabled'    => true,
            'third_party_consent_terms_slug' => '',
            'info_disclosure_enabled'        => true,
            'info_disclosure_terms_slug'     => '',
            // 채널 목록 (JSON 배열 문자열)
            'channels' => json_encode([
                [
                    'key'       => 'email_subscription',
                    'label'     => ['ko' => '광고성 이메일 수신', 'en' => 'Email Marketing'],
                    'page_slug' => '',
                    'enabled'   => true,
                    'is_system' => true,
                ],
            ]),
        ];
    }

    /**
     * 플러그인 설정 스키마 반환
     *
     * @return array 설정 스키마
     */
    public function getSettingsSchema(): array
    {
        return [
            'marketing_consent_enabled' => [
                'type'     => 'boolean',
                'default'  => true,
                'label'    => ['ko' => '마케팅 동의 사용', 'en' => 'Enable Marketing Consent'],
                'hint'     => ['ko' => '비활성화 시 모든 화면에서 마케팅 동의 항목이 숨겨집니다.', 'en' => 'When disabled, the marketing consent item is hidden from all screens.'],
                'required' => false,
            ],
            'marketing_consent_terms_slug' => [
                'type'     => 'string',
                'default'  => 'marketing-terms',
                'label'    => ['ko' => '마케팅 동의 약관 페이지 Slug', 'en' => 'Marketing Consent Terms Page Slug'],
                'hint'     => ['ko' => '페이지 모듈에서 관리하는 약관 페이지의 SLUG를 입력하세요. 비워두면 약관 보기 버튼이 숨겨집니다.', 'en' => 'Enter the SLUG of the terms page managed by the Page module. Leave empty to hide the view terms button.'],
                'required' => false,
            ],
            'channels' => [
                'type'     => 'json',
                'default'  => '[]',
                'label'    => ['ko' => '채널 목록', 'en' => 'Channel List'],
                'hint'     => ['ko' => '마케팅 동의 채널 목록 (JSON). 관리자 UI에서 편집합니다.', 'en' => 'Marketing consent channel list (JSON). Edit via admin UI.'],
                'required' => false,
            ],
            'third_party_consent_enabled' => [
                'type'     => 'boolean',
                'default'  => true,
                'label'    => ['ko' => '제3자 제공 동의 사용', 'en' => 'Enable Third Party Consent'],
                'required' => false,
            ],
            'third_party_consent_terms_slug' => [
                'type'     => 'string',
                'default'  => '',
                'label'    => ['ko' => '제3자 제공 동의 약관 페이지 Slug', 'en' => 'Third Party Consent Terms Page Slug'],
                'required' => false,
            ],
            'info_disclosure_enabled' => [
                'type'     => 'boolean',
                'default'  => true,
                'label'    => ['ko' => '정보 공개 동의 사용', 'en' => 'Enable Info Disclosure Consent'],
                'required' => false,
            ],
            'info_disclosure_terms_slug' => [
                'type'     => 'string',
                'default'  => '',
                'label'    => ['ko' => '정보 공개 동의 약관 페이지 Slug', 'en' => 'Info Disclosure Terms Page Slug'],
                'required' => false,
            ],
        ];
    }

    /**
     * 플러그인 메타데이터 반환
     *
     * @return array 메타데이터 배열
     */
    public function getMetadata(): array
    {
        return [
                'author' => 'Sirsoft',
                'license' => 'MIT',
                'homepage' => 'https://sir.kr',
                'keywords' => ['marketing', 'consent', 'subscription', 'gdpr'],
        ];
    }
    
    /**
     * 플러그인이 동적으로 생성한 테이블 목록 반환
     *
     * 언인스톨 시 Manager가 자동으로 삭제합니다.
     * FK 순서에 맞게 histories 먼저, consents 나중에 반환합니다.
     *
     * @return array 테이블명 배열
     */
    public function getDynamicTables(): array
    {
        return [
            'user_marketing_consent_histories',
            'user_marketing_consents',
        ];
    }

    /**
     * 훅 리스너 목록 반환
     *
     * @return array 훅 리스너 클래스 배열
     */
    public function getHookListeners(): array
    {
        return [
            \Plugins\Sirsoft\Marketing\Listeners\MarketingConsentListener::class,
        ];
    }

}
