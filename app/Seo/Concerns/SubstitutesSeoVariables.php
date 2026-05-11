<?php

namespace App\Seo\Concerns;

/**
 * SEO 템플릿 변수 치환 — 옵셔널 그룹 + separator cleanup.
 *
 * (a) 옵셔널 그룹 `[ ... ]` — 그룹 내 변수 1개라도 비면 그룹 통째 drop.
 *     예: "[{commerce_name} - ]{product_name}" + commerce_name 빈 값 → "{product_name}"
 *
 * (b) 자동 separator cleanup — 빈 변수 인접 separator(- – — · |) 자동 제거.
 *     예: "{commerce_name} - {product_name}" + commerce_name 빈 값 → "{product_name}"
 *
 * 중첩 그룹 미지원 (1단계만).
 */
trait SubstitutesSeoVariables
{
    /**
     * 템플릿의 {var} 자리표시자를 $vars 로 치환.
     *
     * @param  string  $template  템플릿 문자열
     * @param  array  $vars  변수 맵 (키 → 값)
     */
    protected function substituteVars(string $template, array $vars): string
    {
        if ($template === '') {
            return '';
        }

        // (a) 옵셔널 그룹: [내용] — 그룹 내 변수 1개라도 비면 그룹 drop
        $template = (string) preg_replace_callback('/\[([^\[\]]+)\]/', function ($matches) use ($vars) {
            $group = $matches[1];
            $allFilled = true;
            preg_match_all('/\{(\w+)\}/', $group, $varMatches);
            foreach ($varMatches[1] as $varName) {
                $value = $vars[$varName] ?? '';
                if ($value === '' || $value === null) {
                    $allFilled = false;
                    break;
                }
            }

            return $allFilled ? $group : '';
        }, $template);

        // (b) 변수 치환 — 빈 변수는 sentinel 로 표시
        $sentinel = "\x00EMPTYVAR\x00";
        $result = (string) preg_replace_callback('/\{(\w+)\}/', function ($matches) use ($vars, $sentinel) {
            if (! array_key_exists($matches[1], $vars)) {
                return $matches[0];
            }
            $value = (string) $vars[$matches[1]];

            return $value === '' ? $sentinel : $value;
        }, $template);

        // (c) sentinel 인접 separator + 공백 cleanup
        $sep = '[\-\x{2013}\x{2014}\x{00B7}\|]'; // - – — · |
        $sentinelEsc = preg_quote($sentinel, '/');
        $result = preg_replace('/\s*'.$sep.'\s*'.$sentinelEsc.'/u', '', $result);
        $result = preg_replace('/'.$sentinelEsc.'\s*'.$sep.'\s*/u', '', $result);
        $result = str_replace($sentinel, '', $result);
        $result = preg_replace('/\s+/u', ' ', $result);

        return trim($result);
    }
}
