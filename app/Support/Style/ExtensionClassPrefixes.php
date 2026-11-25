<?php

namespace App\Support\Style;

use App\Support\CssLayerFlattener;

/**
 * 확장 CSS 접두 빌드 앞글자(letters) 계산.
 *
 * 확장은 자기 CSS 를 `{letters}:utility` 클래스로 공급한다. letters 는 manifest `assets.css.namespace` 에서
 * 영문 소문자만 남긴 값이다. 브라우저용 목록(`G7Config.extensionClassPrefixes`, 뷰 컴포저)과 SEO 렌더러의
 * 클래스 병합(`ClassMerger`)이 같은 계산을 쓴다. 확장 목록을 받는 순수 변환이다 — 조회는 호출자가 한다.
 *
 * @since 7.2.1
 */
final class ExtensionClassPrefixes
{
    /**
     * 확장 목록들에서 앞글자 목록을 모은다.
     *
     * @param  iterable<int, iterable<int|string, object>>  $extensionLists  `getAssets(): array` 를 가진 확장 객체 목록들
     * @return list<string> 앞글자 목록(중복 없음, 처음 나온 순서)
     */
    public static function collect(iterable $extensionLists): array
    {
        $prefixes = [];
        foreach ($extensionLists as $extensions) {
            foreach ($extensions as $extension) {
                if (! is_object($extension) || ! method_exists($extension, 'getAssets')) {
                    continue;
                }
                $assets = $extension->getAssets();
                $namespace = CssLayerFlattener::declaredNamespace(is_array($assets) ? $assets : []);
                $letters = $namespace === null ? '' : (string) preg_replace('/[^a-z]/', '', $namespace);
                if ($letters !== '') {
                    $prefixes[$letters] = true;
                }
            }
        }

        return array_keys($prefixes);
    }
}
