<?php

namespace App\Support;

use stdClass;

/**
 * 이미 `[]` 로 굳은 레이아웃 빈 객체를 원본 기준으로 되돌리는 판정 (#758)
 *
 * 편집기 저장·버전 복원 같은 쓰기 경로가 레이아웃 원문을 연관 배열로 읽어 다시 저장하면 `{}` 가 `[]` 로
 * 굳는다. 동기화기는 `{}`·`[]` 를 같은 문서로 보므로(편집기 왕복의 표기 차이를 운영자 수정으로 세지 않으려는
 * 의도) 업데이트·재동기화로는 저절로 고쳐지지 않는다. 이 판정은 그렇게 굳은 자리만 골라 되돌린다.
 *
 * - 기준(원본)이 빈 객체이고 대상(저장본)의 같은 자리가 빈 배열일 때만 빈 객체로 바꾼다.
 * - 그 밖의 차이는 운영자가 고친 내용이므로 일절 건드리지 않는다.
 * - 원소가 모두 `id` 를 가진 객체 목록이면 `id` 로 짝을 맞춘다 — 운영자가 노드를 끼워 넣어 순서가 밀렸을 때
 *   엉뚱한 노드를 고치지 않기 위해서다. 그 밖의 목록은 인덱스로 맞춘다.
 * - 두 쪽의 타입이 다르면 그 아래로 내려가지 않는다.
 *
 * 입력은 두 원문을 **객체 디코드**(`json_decode($raw)`)한 값이다 — 연관 배열 디코드는 이미 둘을 구분하지 못한다.
 */
final class LayoutEmptyObjectRepair
{
    /**
     * 대상의 굳은 빈 객체 자리를 기준에 맞춰 되돌립니다.
     *
     * @param  mixed  $target  고칠 값 (저장본을 객체 디코드한 값)
     * @param  mixed  $reference  기준 값 (원본을 객체 디코드한 값)
     * @return array{0: mixed, 1: list<string>} [고친 값, 고친 자리의 경로 목록]
     */
    public static function repair(mixed $target, mixed $reference): array
    {
        $paths = [];
        $fixed = self::walk($target, $reference, '', $paths);

        return [$fixed, $paths];
    }

    /**
     * 두 값을 같은 경로로 함께 내려가며 굳은 자리를 고칩니다.
     *
     * @param  mixed  $target  대상 노드
     * @param  mixed  $reference  기준 노드
     * @param  string  $path  현재 경로
     * @param  list<string>  $paths  고친 경로 누적 (참조)
     * @return mixed 고친 대상 노드
     */
    private static function walk(mixed $target, mixed $reference, string $path, array &$paths): mixed
    {
        if ($reference instanceof stdClass && get_object_vars($reference) === [] && $target === []) {
            $paths[] = $path;

            return new stdClass;
        }

        if ($target instanceof stdClass && $reference instanceof stdClass) {
            foreach (get_object_vars($target) as $key => $value) {
                if (property_exists($reference, (string) $key)) {
                    $target->{$key} = self::walk($value, $reference->{$key}, $path === '' ? (string) $key : $path.'.'.$key, $paths);
                }
            }

            return $target;
        }

        if (is_array($target) && is_array($reference)) {
            $referenceById = self::indexById($reference);
            $targetById = self::indexById($target);

            if ($referenceById !== null && $targetById !== null) {
                foreach ($target as $index => $item) {
                    $id = (string) $item->id;

                    if (array_key_exists($id, $referenceById)) {
                        $target[$index] = self::walk($item, $referenceById[$id], $path.'['.$id.']', $paths);
                    }
                }

                return $target;
            }

            foreach ($target as $index => $item) {
                if (array_key_exists($index, $reference)) {
                    $target[$index] = self::walk($item, $reference[$index], $path.'['.$index.']', $paths);
                }
            }
        }

        return $target;
    }

    /**
     * 원소가 모두 스칼라 `id` 를 가진 객체인 목록을 `id` 로 색인합니다.
     *
     * @param  array<int, mixed>  $list  목록
     * @return array<string, stdClass>|null `id` → 원소 (조건을 만족하지 않거나 비었거나 `id` 가 겹치면 null)
     */
    private static function indexById(array $list): ?array
    {
        if ($list === []) {
            return null;
        }

        $byId = [];

        foreach ($list as $item) {
            if (! $item instanceof stdClass || ! isset($item->id) || ! is_scalar($item->id)) {
                return null;
            }

            $id = (string) $item->id;

            if (array_key_exists($id, $byId)) {
                return null;
            }

            $byId[$id] = $item;
        }

        return $byId;
    }
}
