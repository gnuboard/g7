<?php

namespace App\Support;

use stdClass;

/**
 * 「이름표 → 키-값 맵」 모양의 배열에서 비어 있는 자식 맵을 JSON 빈 객체(`{}`)로 바꿉니다.
 *
 * PHP 빈 배열은 `json_encode` 가 `[]` 로 낸다. 최상위만 `(object)` 로 감싸면 그 아래 자식 맵
 * (예: `window.G7Config.settings` 의 카테고리 값)은 비었을 때 여전히 `[]` 로 나가, 화면이 이름표로
 * 읽는 맵의 모양이 비었을 때만 달라진다(#758, 7.1.1 출시 전).
 *
 * 한 단계(자식)만 바꾼다 — 자식 맵 안의 값은 목록일 수도 맵일 수도 있어 여기서 판정하지 않는다.
 */
final class EmptyMapChildren
{
    /**
     * 비어 있는 자식 배열만 빈 객체로 바꾼 사본을 돌려줍니다.
     *
     * @param  array<string, mixed>  $map  이름표 → 자식 맵
     * @return array<string, mixed> 빈 자식이 `stdClass` 로 바뀐 사본 (값이 있는 자식·배열 아닌 값은 그대로)
     */
    public static function asObjects(array $map): array
    {
        foreach ($map as $key => $child) {
            if ($child === []) {
                $map[$key] = new stdClass;
            }
        }

        return $map;
    }
}
