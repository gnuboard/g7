<?php

namespace Tests\Unit\Traits;

use App\Traits\NormalizesSettingsData;
use Tests\TestCase;

/**
 * 설정 정규화 — 문자표기·숫자 지역 로케일 키의 다국어 기본값 인식 (#148)
 *
 * 종전 키 패턴(`[a-z]{2,3}(-[A-Z]{2})?`)이 `zh-Hant`·`es-419` 를 거부해 다국어 변환이 `[$value]` 로 깨졌다.
 */
class NormalizesSettingsDataLocaleKeyTest extends TestCase
{
    /**
     * 트레이트의 protected 정규화 메서드를 노출하는 객체를 만듭니다.
     *
     * @return object normalizeCategory(array, array) 를 가진 객체
     */
    private function normalizer(): object
    {
        return new class
        {
            use NormalizesSettingsData;

            /**
             * 카테고리 설정을 정규화합니다.
             *
             * @param  array<string, mixed>  $settings  저장값
             * @param  array<string, mixed>  $defaults  기본값 스키마
             * @return array<string, mixed> 정규화 결과
             */
            public function normalizeCategory(array $settings, array $defaults): array
            {
                return $this->normalizeCategoryData($settings, $defaults);
            }
        };
    }

    /**
     * 문자 표기·숫자 지역 로케일 키가 든 다국어 기본값도 다국어로 인식한다.
     *
     * @scenario shape=script_tagged
     *
     * @effects settings_multilingual_default_recognizes_script_key
     *
     * @return void 반환값 없음
     */
    public function test_multilingual_default_with_script_tagged_key_is_recognized(): void
    {
        $result = $this->normalizer()->normalizeCategory(
            ['shop_name' => '내 쇼핑몰'],
            ['shop_name' => ['ko' => '쇼핑몰', 'zh-Hant' => '商店', 'es-419' => 'Tienda']],
        );

        $this->assertSame(['ko' => '내 쇼핑몰', 'zh-Hant' => '내 쇼핑몰', 'es-419' => '내 쇼핑몰'], $result['shop_name']);
    }
}
