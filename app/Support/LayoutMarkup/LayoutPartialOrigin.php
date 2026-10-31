<?php

namespace App\Support\LayoutMarkup;

/**
 * 조각 출처 표식(`__partial_origin`) — "이 서브트리는 어느 조각 파일에서 왔다" 를 남기는 키.
 *
 * 설치·갱신 때 조각(`<include src>`·`<modal src>`)은 부모에 **인라인**되므로 DB 본문에는 조각 경계가
 * 남지 않는다. 확장 업데이트의 3자 병합은 조각 파일 하나를 병합 단위로 삼기 때문에 경계가 필요하다 —
 * 그래서 표식은 병합 기준으로만 쓰는 원본 본문(`original_content`)에만 넣고, 서비스되는 `content`
 * 에는 넣지 않는다. 런타임·편집기·마크업 역변환은 표식을 볼 일이 없다.
 *
 * 값은 layouts 루트 기준 상대 경로에서 확장자를 뺀 것이다(예: `partials/admin_module_list/_modal_update`).
 * 설치 경로(활성 디렉토리·`_bundled`·업데이트 백업)가 달라도 같은 조각이면 같은 값이 나온다.
 */
final class LayoutPartialOrigin
{
    /**
     * 표식 키.
     */
    public const KEY = '__partial_origin';

    /**
     * 값 안의 표식을 재귀로 모두 지웁니다.
     *
     * @param  mixed  $value  레이아웃 값
     * @return mixed 표식이 없는 값
     */
    public static function strip(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        unset($value[self::KEY]);

        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = self::strip($child);
            }
        }

        return $value;
    }

    /**
     * 값 어딘가에 표식이 있는지 판정합니다.
     *
     * @param  mixed  $value  레이아웃 값
     * @return bool 표식이 하나라도 있으면 true
     */
    public static function has(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        if (array_key_exists(self::KEY, $value)) {
            return true;
        }

        foreach ($value as $child) {
            if (is_array($child) && self::has($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 조각 파일 경로를 표식 값으로 바꿉니다 — layouts 루트 기준 상대 경로, 구분자 `/`, 확장자 제거.
     *
     * @param  string  $absolutePath  조각 파일 절대 경로
     * @param  string  $layoutsRoot  layouts 루트 절대 경로
     * @return string 표식 값
     */
    public static function keyFor(string $absolutePath, string $layoutsRoot): string
    {
        $path = str_replace('\\', '/', $absolutePath);
        $root = rtrim(str_replace('\\', '/', $layoutsRoot), '/').'/';

        if (str_starts_with($path, $root)) {
            $path = substr($path, strlen($root));
        }

        return (string) preg_replace('/\.(g7\.html|json)$/i', '', $path);
    }
}
