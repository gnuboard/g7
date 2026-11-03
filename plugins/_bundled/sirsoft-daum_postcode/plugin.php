<?php

namespace Plugins\Sirsoft\DaumPostcode;

use App\Extension\AbstractPlugin;
use Plugins\Sirsoft\DaumPostcode\Listeners\RegisterSettingsAttributeLabelsListener;

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
            // 표시 방식은 기기별로 고른다(1.2.0) — 기기는 클릭 시점 화면 폭으로 가른다(엔진 반응형
            // portable 상한 1023px 이하 = 모바일·태블릿). 1.1.x 의 단일 display_mode 는 1.2.0 업그레이드 스텝이 PC 칸으로 옮긴다.
            'display_mode_pc' => [
                'type' => 'enum',
                'options' => ['popup', 'layer', 'inline'],
                'default' => 'layer',
                'label' => [
                    'ko' => 'PC 표시 방식',
                    'en' => 'Display Mode (PC)',
                ],
                'hint' => [
                    'ko' => 'PC(화면 폭 1024px 이상)에서 주소 검색 창을 띄우는 방식입니다.',
                    'en' => 'How the address search opens on PC (screen width 1024px or more).',
                ],
                'required' => false,
            ],
            'display_mode_mobile' => [
                'type' => 'enum',
                'options' => ['inline', 'fullscreen'],
                'default' => 'inline',
                'label' => [
                    'ko' => '모바일 표시 방식',
                    'en' => 'Display Mode (Mobile)',
                ],
                'hint' => [
                    'ko' => '휴대폰·태블릿(화면 폭 1023px 이하)에서 주소 검색 창을 띄우는 방식입니다.',
                    'en' => 'How the address search opens on phones and tablets (screen width 1023px or less).',
                ],
                'required' => false,
            ],
            // 팝업 크기는 화면에 띄울 수 있는 범위로 묶는다 — 정수 여부만 보던 때는 -10 도 저장됐다(7.1.0 실측).
            // 코어 설정 저장 검증이 min·max 를 그대로 규칙으로 쓰고, 설정 화면 입력칸도 같은 값을 쓴다.
            'popup_width' => [
                'type' => 'integer',
                'default' => 500,
                'min' => 100,
                'max' => 2000,
                'label' => [
                    'ko' => 'PC 레이어·팝업 너비 (px)',
                    'en' => 'PC Layer/Popup Width (px)',
                ],
                'hint' => [
                    'ko' => 'PC 의 레이어·팝업 방식에서 쓰는 창 너비입니다. 레이어는 화면보다 크면 화면에 맞춰 줄어듭니다. (100~2000)',
                    'en' => 'Window width used by the layer and popup modes on PC. A layer larger than the screen shrinks to fit. (100–2000)',
                ],
                'required' => false,
            ],
            'popup_height' => [
                'type' => 'integer',
                'default' => 600,
                'min' => 100,
                'max' => 2000,
                'label' => [
                    'ko' => 'PC 레이어·팝업 높이 (px)',
                    'en' => 'PC Layer/Popup Height (px)',
                ],
                'hint' => [
                    'ko' => 'PC 의 레이어·팝업 방식에서 쓰는 창 높이입니다. 레이어는 화면보다 크면 화면에 맞춰 줄어듭니다. (100~2000)',
                    'en' => 'Window height used by the layer and popup modes on PC. A layer larger than the screen shrinks to fit. (100–2000)',
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
            'display_mode_pc' => 'layer',
            'display_mode_mobile' => 'inline',
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

    /**
     * 구독하는 훅 리스너 목록 반환
     *
     * 환경설정 저장 검증 오류 문구의 항목 이름을 요청 언어로 등록한다(스키마 label 은 ko/en 만 있다).
     *
     * @return array<int, class-string> 리스너 클래스 목록
     */
    public function getHookListeners(): array
    {
        return [
            RegisterSettingsAttributeLabelsListener::class,
        ];
    }
}
