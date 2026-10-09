<?php

namespace Plugins\Sirsoft\Ckeditor5\Tests\Unit;

use Plugins\Sirsoft\Ckeditor5\Plugin;
use Plugins\Sirsoft\Ckeditor5\Tests\PluginTestCase;

/**
 * 발행 훅 설명의 언어별 선언 테스트.
 *
 * 관리자 확장 관리의 플러그인 정보 창 「훅」·「필터」 칸은 `getHooks()` 의 설명을 화면 언어로 고른다.
 * 설명이 ko/en 만 선언돼 ja 화면에서 한국어로 보였다(7.1.0 실측 P-11, TOTP 는 설명이 한국어 문자열
 * 하나뿐이라 en 화면에서도 한국어). 선언한 훅마다 언어별 설명이 있고 en·ja 에 한글이 남지 않는지 본다.
 */
class HookDescriptionLocalesTest extends PluginTestCase
{
    /**
     * 선언한 훅마다 ko 설명이 채워져 있다.
     *
     * @scenario locale=ko
     *
     * @effects every_declared_hook_has_description_per_locale
     *
     * @return void 반환값 없음
     */
    public function test_every_declared_hook_has_korean_description(): void
    {
        $this->assertDescriptionsFor('ko');
    }

    /**
     * 선언한 훅마다 en 설명이 채워져 있고 한글이 남지 않는다.
     *
     * @scenario locale=en
     *
     * @effects every_declared_hook_has_description_per_locale, non_korean_description_is_not_korean_text
     *
     * @return void 반환값 없음
     */
    public function test_every_declared_hook_has_english_description(): void
    {
        $this->assertDescriptionsFor('en');
    }

    /**
     * 선언한 훅마다 ja 설명이 채워져 있고 한글이 남지 않는다.
     *
     * @scenario locale=ja
     *
     * @effects every_declared_hook_has_description_per_locale, non_korean_description_is_not_korean_text
     *
     * @return void 반환값 없음
     */
    public function test_every_declared_hook_has_japanese_description(): void
    {
        $this->assertDescriptionsFor('ja');
    }

    /**
     * 선언한 모든 훅의 설명이 언어별 배열이고, 주어진 언어 설명이 비어 있지 않으며 ko 외 언어에 한글이 없는지 본다.
     *
     * @param  string  $locale  확인할 언어(ko|en|ja)
     * @return void 반환값 없음
     */
    private function assertDescriptionsFor(string $locale): void
    {
        $hooks = (new Plugin)->getHooks();
        $this->assertNotEmpty($hooks, '이 플러그인은 발행 훅을 선언한다');

        foreach ($hooks as $key => $hook) {
            $name = is_string($key) ? $key : ($hook['name'] ?? (string) $key);
            $description = $hook['description'] ?? null;
            $this->assertIsArray($description, "{$name} 설명은 언어별 배열이어야 한다(문자열 하나면 모든 화면에 같은 언어로 보인다)");
            $this->assertNotEmpty($description[$locale] ?? null, "{$name} 의 {$locale} 설명");

            if ($locale !== 'ko') {
                $this->assertDoesNotMatchRegularExpression('/[가-힣]/u', $description[$locale], "{$name} 의 {$locale} 설명에 한글이 남으면 안 된다");
            }
        }
    }
}
