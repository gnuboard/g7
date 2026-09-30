<?php

namespace Plugins\Sirsoft\DaumPostcode;

use App\Extension\AbstractPlugin;

/**
 * Daum 우편번호 서비스 플러그인
 *
 * Daum 우편번호 검색 API를 통한 주소 검색 기능을 제공합니다.
 */
class Plugin extends AbstractPlugin
{
    /**
     * 플러그인 메타데이터 반환
     *
     * @return array 메타데이터
     */
    public function getMetadata(): array
    {
        return [
            'author' => 'Sirsoft',
            'license' => 'MIT',
            'homepage' => 'https://sir.kr',
            'keywords' => ['postcode', 'address', 'daum', 'korea'],
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
            'display_mode' => [
                'type' => 'enum',
                'options' => ['popup', 'layer'],
                'default' => 'layer',
                'label' => [
                    'ko' => '표시 방식',
                    'en' => 'Display Mode',
                ],
                'hint' => [
                    'ko' => '주소 검색 창을 표시하는 방식을 선택합니다.',
                    'en' => 'Select how to display the address search window.',
                ],
                'required' => false,
            ],
            'popup_width' => [
                'type' => 'integer',
                'default' => 500,
                'label' => [
                    'ko' => '팝업 너비 (px)',
                    'en' => 'Popup Width (px)',
                ],
                'hint' => [
                    'ko' => '팝업 모드에서 사용되는 창 너비입니다.',
                    'en' => 'Window width used in popup mode.',
                ],
                'required' => false,
            ],
            'popup_height' => [
                'type' => 'integer',
                'default' => 600,
                'label' => [
                    'ko' => '팝업 높이 (px)',
                    'en' => 'Popup Height (px)',
                ],
                'hint' => [
                    'ko' => '팝업 모드에서 사용되는 창 높이입니다.',
                    'en' => 'Window height used in popup mode.',
                ],
                'required' => false,
            ],
            'theme_color' => [
                'type' => 'string',
                'default' => '#1D4ED8',
                'label' => [
                    'ko' => '테마 색상',
                    'en' => 'Theme Color',
                ],
                'hint' => [
                    'ko' => '우편번호 검색 창의 테마 색상입니다. (예: #1D4ED8)',
                    'en' => 'Theme color for the postcode search window. (e.g., #1D4ED8)',
                ],
                'required' => false,
            ],
        ];
    }

    /**
     * 플러그인 설정 기본값 반환
     *
     * @return array 기본 설정값
     */
    public function getConfigValues(): array
    {
        return [
            'display_mode' => 'layer',
            'popup_width' => 500,
            'popup_height' => 600,
            'theme_color' => '#1D4ED8',
        ];
    }

    /**
     * 플러그인이 제공하는 훅 정보 반환
     *
     * 제공하는 서버 훅이 없다. 주소 선택은 브라우저에서 일어나는 흐름이고 프론트엔드 엔진에는 확장이 끼어드는
     * 훅 체계가 없어, 종전에 선언했던 address.selected·filter_address_data 는 발화처가 한 번도 없었다 —
     * 선언에서 뺐다(#138 후속, 설계 결정). 주소를 받은 뒤의 동작은 레이아웃 액션으로 잇는다.
     *
     * @return array 훅 정의 배열 (없음)
     */
    public function getHooks(): array
    {
        return [];
    }
}
