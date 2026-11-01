<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;

/**
 * 설치기 문구의 자리표시자는 `:이름` 형식이다 — `lang()` 은 `:이름` 만 바꾼다.
 *
 * 회귀 성격: 기존 테이블 정리 로그 두 줄이 `{count}` 로 적혀 있어 「{count}개 테이블 삭제」가 그대로 출력됐다.
 * 터미널 설치는 같은 로그를 화면에 그대로 내므로 눈에 띄는 결함이 된다.
 */
class InstallerLangPlaceholderTest extends TestCase
{
    /**
     * ko·en 설치기 문구에 `{이름}` 자리표시자가 없고, ko·en 의 자리표시자 집합이 키마다 같다.
     *
     * @effects installer_lang_uses_colon_placeholders_only
     *
     * @return void 반환값 없음
     */
    public function test_installer_messages_use_colon_placeholders(): void
    {
        $dir = dirname(__DIR__, 3).'/public/install/lang';
        // 일부 항목은 배열(목록 문구)이다 — 점 표기 키로 펼쳐 문자열만 본다.
        $flatten = static function (array $messages, string $prefix = '') use (&$flatten): array {
            $flat = [];
            foreach ($messages as $key => $value) {
                $flat += is_array($value) ? $flatten($value, $prefix.$key.'.') : [$prefix.$key => (string) $value];
            }

            return $flat;
        };
        $ko = $flatten(require $dir.'/ko.php');
        $en = $flatten(require $dir.'/en.php');

        foreach (['ko' => $ko, 'en' => $en] as $locale => $messages) {
            foreach ($messages as $key => $message) {
                $this->assertDoesNotMatchRegularExpression('/\{[a-z_]+\}/', (string) $message, "{$locale}.{$key} 에 {이름} 자리표시자 — lang() 이 바꾸지 않는다");
            }
        }

        $names = static function (string $message): array {
            preg_match_all('/(?<![:\w]):([a-z_]+)/', $message, $m);
            $found = array_values(array_unique($m[1]));
            sort($found);

            return $found;
        };
        foreach ($ko as $key => $message) {
            if (isset($en[$key])) {
                $this->assertSame($names((string) $message), $names((string) $en[$key]), "{$key} 의 ko·en 자리표시자가 다르다");
            }
        }
    }
}
