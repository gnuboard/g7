<?php

namespace Modules\Sirsoft\Board\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Modules\Sirsoft\Board\Http\Requests\Concerns\ResolvesAllowedExtensions;

/**
 * 허용 확장자 컬럼 캐스팅
 *
 * "확장자 미지정" 을 표현하는 값이 빈 배열([])과 NULL 두 가지로 공존하면
 * 소비 지점마다 `?? 기본값` 이 [] 를 통과시켜 `mimes:` 빈 규칙을 만드는 결함이 반복됩니다.
 * 저장 시점에 센티널을 NULL 하나로 붕괴시켜 이 버그 클래스를 구조적으로 제거합니다.
 *
 * 조회 시맨틱은 기존 'array' 캐스트와 동일합니다 (NULL 은 NULL 그대로 반환).
 *
 * @implements CastsAttributes<array<int, string>|null, array<int, string>|null>
 */
class AsAllowedExtensions implements CastsAttributes
{
    use ResolvesAllowedExtensions;

    /**
     * DB 값을 배열로 변환합니다.
     *
     * @param  Model  $model  모델 인스턴스
     * @param  string  $key  속성명
     * @param  mixed  $value  DB 원본 값
     * @param  array<string, mixed>  $attributes  전체 속성
     * @return array<int, string>|null 확장자 목록 (미지정 시 null)
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * 저장 값을 정규화하여 JSON 문자열로 변환합니다.
     *
     * 빈 값은 NULL 로 저장하여 "미지정" 표현을 단일화합니다.
     *
     * @param  Model  $model  모델 인스턴스
     * @param  string  $key  속성명
     * @param  mixed  $value  설정 값
     * @param  array<string, mixed>  $attributes  전체 속성
     * @return string|null JSON 문자열 (미지정 시 null)
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : explode(',', $value);
        }

        $normalized = self::normalizeAllowedExtensions($value);

        return $normalized === [] ? null : json_encode($normalized, JSON_UNESCAPED_UNICODE);
    }
}
