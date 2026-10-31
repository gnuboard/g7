<?php

namespace App\Analytics;

/**
 * 체류시간·본 페이지 수 구간 라벨
 *
 * 구간 리포트의 라벨은 **경계를 그대로 담은 문자열**로 저장한다(체류 `0-10`·`10-30`·`1800+`,
 * 페이지 `1`·`6-7`·`21+`). 운영자가 경계를 바꾸면 그 뒤 집계부터 새 경계로 쌓이는데, 라벨이
 * 자기 경계를 담고 있으면 옛 라벨과 새 라벨이 한 기간에 섞여도 각 행을 제 뜻대로 읽을 수 있다.
 * 사람이 읽는 문장(「1분 ~ 3분」)은 조회 시점에 `ReportService` 가 만든다.
 *
 * 체류 구간은 (하한, 상한] — 첫 구간만 [0, 상한]. 페이지 구간은 하한·상한을 모두 포함한다.
 */
class BucketLabeler
{
    /**
     * 체류시간(초)이 든 구간의 라벨을 만듭니다.
     *
     * @param  int  $seconds  방문 체류시간 (초)
     * @param  array<int, int>  $bounds  순증가 경계 (초)
     * @return string 구간 라벨 (`0-10`, `10-30`, …, `1800+`)
     */
    public static function duration(int $seconds, array $bounds): string
    {
        $seconds = max(0, $seconds);
        $lower = 0;

        foreach ($bounds as $bound) {
            if ($seconds <= $bound) {
                return $lower.'-'.$bound;
            }

            $lower = $bound;
        }

        return $lower.'+';
    }

    /**
     * 본 페이지 수가 든 구간의 라벨을 만듭니다.
     *
     * 경계 목록의 각 값은 그 구간의 상한이다. 앞 경계 +1 이 하한이고, 하한과 상한이 같으면 한
     * 숫자 라벨(`3`), 다르면 범위 라벨(`6-7`), 마지막 경계를 넘으면 `21+` 이다.
     *
     * @param  int  $pages  본 페이지 수 (1 이상)
     * @param  array<int, int>  $bounds  순증가 경계
     * @return string 구간 라벨
     */
    public static function pages(int $pages, array $bounds): string
    {
        $pages = max(1, $pages);
        $lower = 1;

        foreach ($bounds as $bound) {
            if ($pages <= $bound) {
                return $lower === $bound ? (string) $bound : $lower.'-'.$bound;
            }

            $lower = $bound + 1;
        }

        return $lower.'+';
    }

    /**
     * 라벨에서 정렬용 하한을 꺼냅니다.
     *
     * @param  string  $label  구간 라벨
     * @return int 하한 (해석할 수 없으면 PHP_INT_MAX — 맨 뒤로)
     */
    public static function lowerBound(string $label): int
    {
        return preg_match('/^(\d+)/', $label, $m) === 1 ? (int) $m[1] : PHP_INT_MAX;
    }

    /**
     * 라벨을 하한과 상한으로 풉니다.
     *
     * @param  string  $label  구간 라벨
     * @return array{0: int, 1: int|null}|null [하한, 상한(열린 구간이면 null)] — 해석할 수 없으면 null
     */
    public static function parse(string $label): ?array
    {
        if (preg_match('/^(\d+)\+$/', $label, $m) === 1) {
            return [(int) $m[1], null];
        }

        if (preg_match('/^(\d+)-(\d+)$/', $label, $m) === 1) {
            return [(int) $m[1], (int) $m[2]];
        }

        if (preg_match('/^(\d+)$/', $label, $m) === 1) {
            return [(int) $m[1], (int) $m[1]];
        }

        return null;
    }
}
