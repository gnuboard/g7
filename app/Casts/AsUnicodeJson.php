<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Unicode 이스케이프 없이 JSON을 저장하는 커스텀 캐스트
 *
 * Laravel 기본 'array' 캐스트는 json_encode()로 한글을 \uXXXX 이스케이프합니다.
 * 이 캐스트는 JSON_UNESCAPED_UNICODE 플래그를 사용하여 실제 UTF-8 문자로 저장합니다.
 *
 * FULLTEXT 인덱스(ngram)가 한글 토큰을 올바르게 생성하려면
 * 실제 UTF-8 문자가 저장되어야 합니다.
 */
class AsUnicodeJson implements CastsAttributes
{
    /**
     * 데이터베이스에서 값을 읽을 때 변환합니다.
     *
     * @param Model $model 모델 인스턴스
     * @param string $key 속성명
     * @param mixed $value 원본 값
     * @param array<string, mixed> $attributes 전체 속성
     * @return array|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        // 이미 배열인 경우 (다시 read 시점에 배열이 들어오는 시나리오 방어)
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        // 스칼라/문자열로 디코드된 경우는 반환 타입(?array) 위반 → null 처리
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * 데이터베이스에 값을 저장할 때 변환합니다.
     *
     * JSON_UNESCAPED_UNICODE 플래그를 사용하여 한글 등 멀티바이트 문자를
     * \uXXXX 이스케이프 없이 실제 UTF-8로 저장합니다.
     *
     * @param Model $model 모델 인스턴스
     * @param string $key 속성명
     * @param mixed $value 저장할 값
     * @param array<string, mixed> $attributes 전체 속성
     * @return string|null
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
