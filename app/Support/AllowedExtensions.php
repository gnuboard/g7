<?php

namespace App\Support;

/**
 * 허용 확장자 목록 정규화 (#493 C2)
 *
 * 허용 확장자는 관리자 화면에서 콤마로 구분한 한 줄 텍스트로 들어오고, API 를 직접 쓰는
 * 경로에서는 배열로 들어온다. 저장 계층과 적용 계층이 각자 형태를 판단하면 한쪽이
 * 받아들이는 값을 다른 쪽이 조용히 버리게 되므로(관리자가 확장자를 바꿔도 업로드 제한은
 * 그대로), 형태 판정과 정규화를 이 한 곳에서만 수행한다.
 */
final class AllowedExtensions
{
    /**
     * 문자열/배열 입력을 소문자 확장자 배열로 정규화합니다.
     *
     * @param  mixed  $value  콤마 구분 문자열 또는 배열
     * @return array<int, string> 정규화된 확장자 목록 (빈 배열 = 제한 없음)
     */
    public static function normalize(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $extension) {
            if (! is_string($extension) && ! is_numeric($extension)) {
                continue;
            }

            $extension = strtolower(ltrim(trim((string) $extension), '.'));

            if ($extension === '' || in_array($extension, $normalized, true)) {
                continue;
            }

            $normalized[] = $extension;
        }

        return $normalized;
    }
}
