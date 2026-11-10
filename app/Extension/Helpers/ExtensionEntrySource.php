<?php

namespace App\Extension\Helpers;

use App\Extension\ExtensionManager;

/**
 * 확장 진입 파일(module.php·plugin.php)을 새 클래스명으로 eval 재로드할 소스를 만든다.
 *
 * 같은 프로세스에서 이미 선언된 진입 클래스를 다시 읽을 때(미설치·업데이트 대상 `_bundled` 사본 상세, 확장 문서 생성)
 * 클래스명만 바꿔 eval 한다. eval 된 코드의 `__DIR__`·`__FILE__` 은 eval 을 부른 코어 파일 위치라, 진입 파일이 자기
 * 디렉터리를 기준으로 파일을 찾으면 엉뚱한 경로를 본다. 토큰 단위로 두 상수를 진입 파일 위치로 바꾼다 — 문자열 리터럴
 * 안의 `__DIR__` 은 다른 토큰이라 건드리지 않는다.
 */
class ExtensionEntrySource
{
    /**
     * eval 할 진입 파일 소스를 돌려줍니다 (여는 태그 제거, 클래스명 치환, `__DIR__`·`__FILE__` 보정).
     *
     * 예외: 진입 파일 옆 `vendor/autoload.php` 의 Composer 오토로더 클래스가 이미 선언돼 있으면 `__DIR__`·`__FILE__` 을
     * 바꾸지 않는다(종전 동작). 활성 사본이 vendor 를 이미 선언한 상태에서 `_bundled` 사본을 eval 할 때 고친 `__DIR__` 이
     * `_bundled` vendor 를 불러 같은 이름의 오토로더를 다시 선언하면 잡을 수 없는 치명 오류로 죽기 때문이다(#159).
     *
     * @param  string  $file  진입 파일 절대경로
     * @param  string  $fromClass  원래 짧은 클래스명 (예: Plugin)
     * @param  string  $toClass  바꿀 짧은 클래스명 (예: Plugin_fresh_…)
     * @return string|null eval 할 소스 (파일을 읽지 못하면 null)
     */
    public static function forEval(string $file, string $fromClass, string $toClass): ?string
    {
        $content = @file_get_contents($file);
        if ($content === false) {
            return null;
        }

        $fixLocation = ! ExtensionManager::isVendorAutoloaderDeclared(dirname($file).'/vendor/autoload.php');

        $source = '';
        $openTagRemoved = false;
        foreach (token_get_all($content) as $token) {
            if (! is_array($token)) {
                $source .= $token;

                continue;
            }

            [$id, $text] = $token;

            if ($id === T_OPEN_TAG && ! $openTagRemoved) {
                $openTagRemoved = true;

                continue;
            }

            if ($fixLocation && $id === T_DIR) {
                $source .= var_export(dirname($file), true);
            } elseif ($fixLocation && $id === T_FILE) {
                $source .= var_export($file, true);
            } else {
                $source .= $text;
            }
        }

        // 클래스명만 변경 (namespace 유지 → use/extends 정상 작동)
        $renamed = preg_replace('/\bclass\s+'.preg_quote($fromClass, '/').'\b/', 'class '.$toClass, $source);

        return is_string($renamed) ? $renamed : null;
    }
}
