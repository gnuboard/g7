<?php

namespace Tests\Unit\Installer;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 영어 문구에 괄호 복수 표기(`(s)`)가 남지 않았는지 잠근다.
 *
 * 7.1.5·7.1.6 의 정리는 코어 `lang/en` 만 대상으로 해, 설치기 `public/install/lang/en.php` 의 「the server is missing
 * the PHP extension(s) :extensions」 같은 문구가 그대로 남았다.
 */
class EnglishPluralNotationTest extends TestCase
{
    /**
     * 설치기 영어 문구에 `(s)` 가 없다.
     *
     * @scenario surface=installer_lang
     *
     * @effects english_strings_avoid_parenthesized_plural
     *
     * @return void 반환값 없음
     */
    public function test_installer_english_strings_have_no_parenthesized_plural(): void
    {
        $strings = require base_path('public/install/lang/en.php');

        $this->assertIsArray($strings);
        $this->assertSame([], $this->offenders($strings, 'install/en'));
    }

    /**
     * 코어 `lang/en` 문구에 `(s)` 가 없다.
     *
     * @scenario surface=core_lang
     *
     * @effects english_strings_avoid_parenthesized_plural
     *
     * @return void 반환값 없음
     */
    public function test_core_english_strings_have_no_parenthesized_plural(): void
    {
        $found = [];
        foreach (File::allFiles(base_path('lang/en')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $strings = require $file->getPathname();
            if (is_array($strings)) {
                $found = [...$found, ...$this->offenders($strings, 'lang/en/'.$file->getRelativePathname())];
            }
        }

        $this->assertSame([], $found);
    }

    /**
     * 코어 업데이트의 개수 문구(번들 확장 실패·끊김, 되돌리며 거둔 안내)는 괄호 없이 늘 복수로 적지 않고 `단수|복수` 두 문장이다 —
     * 개수가 1 일 수 있다(「1 bundled extensions … were not updated」 금지). 괄호 검사로는 잡히지 않는 형태라 키를 짚어 잰다.
     *
     * @scenario surface=core_update_count_messages
     *
     * @effects count_messages_choose_singular_or_plural
     *
     * @return void 반환값 없음
     */
    public function test_core_update_count_messages_choose_singular_or_plural(): void
    {
        $settings = require base_path('lang/en/settings.php');
        $keys = [
            'core_update.rollback_apply_warnings_withdrawn',
            'core_update.web.bundled_partial_failure',
            'core_update.web.bundled_partial_failure_rolled_back',
            'core_update.web.bundled_interrupted_unconfirmed',
        ];

        foreach ($keys as $key) {
            $value = data_get($settings, $key);
            $this->assertIsString($value, $key.' 문구가 없다');
            $this->assertCount(2, explode('|', $value), $key.' 는 개수가 1 일 수 있어 「단수|복수」 두 문장이어야 한다');
            // 1개일 때 고르는 문장이 첫 문장(단수)인지 — 복수형 동사(were·apply)를 쓰지 않는다
            $one = trans_choice('settings.'.$key, 1, ['count' => 1, 'failed' => 1, 'identifiers' => 'x'], 'en');
            $this->assertDoesNotMatchRegularExpression('/\b(were not updated|no longer apply:)/', $one, $key.' 의 1개 문장이 복수형이다');
        }
    }

    /**
     * 문구 배열에서 `(s)` 를 품은 값을 「출처:키」 목록으로 모읍니다.
     *
     * @param  array<mixed>  $strings  문구 배열(중첩 가능)
     * @param  string  $prefix  출처 표시
     * @return list<string> 걸린 키 목록
     */
    private function offenders(array $strings, string $prefix): array
    {
        $found = [];
        foreach ($strings as $key => $value) {
            if (is_array($value)) {
                $found = [...$found, ...$this->offenders($value, $prefix.'.'.$key)];
            } elseif (is_string($value) && str_contains($value, '(s)')) {
                $found[] = $prefix.':'.$key;
            }
        }

        return $found;
    }
}
