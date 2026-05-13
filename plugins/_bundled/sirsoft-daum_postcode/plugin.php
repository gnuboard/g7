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
     * @return array 훅 정의 배열
     */
    public function getHooks(): array
    {
        return [
            [
                'name' => 'sirsoft-daum_postcode.address.selected',
                'type' => 'action',
                'description' => [
                    'ko' => '주소 선택 완료 시 실행되는 액션 훅',
                    'en' => 'Action hook executed when address selection is complete',
                ],
                'parameters' => [
                    'zonecode' => 'string - 우편번호',
                    'address' => 'string - 기본 주소',
                    'roadAddress' => 'string - 도로명 주소',
                    'jibunAddress' => 'string - 지번 주소',
                    'buildingName' => 'string - 건물명',
                ],
            ],
            [
                'name' => 'sirsoft-daum_postcode.filter_address_data',
                'type' => 'filter',
                'description' => [
                    'ko' => '선택된 주소 데이터를 필터링하는 훅',
                    'en' => 'Filter hook to modify selected address data',
                ],
                'parameters' => [
                    'data' => 'array - 주소 데이터',
                ],
                'return' => 'array - 필터링된 주소 데이터',
            ],
        ];
    }
}
