<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * IP 이진 저장 캐스트
 *
 * IP 를 문자열이 아니라 이진값(IPv4 4바이트 · IPv6 16바이트)으로 저장합니다.
 * 문자열로 두면 같은 주소를 여러 표기로 쓸 수 있어 비교가 어긋나고, IPv6 는
 * 39자까지 늘어나 인덱스가 커집니다. 이진값은 표기가 하나뿐이라 대역 비교와
 * 정렬이 그대로 맞습니다.
 *
 * 저장값이 깨졌거나 알 수 없는 길이면 예외를 던지지 않고 null 을 돌려줍니다 —
 * 통계 한 줄 때문에 화면이 죽는 것보다 그 칸이 비는 편이 낫습니다.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class IpBinary implements CastsAttributes
{
    /**
     * 저장된 이진값을 IP 문자열로 바꿉니다.
     *
     * @param  Model  $model  대상 모델
     * @param  string  $key  속성명
     * @param  mixed  $value  저장된 이진값
     * @param  array<string, mixed>  $attributes  전체 속성
     * @return string|null IP 문자열 (복원 불가 시 null)
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // 드라이버에 따라 스트림으로 올라오는 경우가 있다.
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        if (! is_string($value)) {
            return null;
        }

        $length = strlen($value);

        if ($length !== 4 && $length !== 16) {
            return null;
        }

        $address = @inet_ntop($value);

        return $address === false ? null : $address;
    }

    /**
     * IP 문자열을 저장용 이진값으로 바꿉니다.
     *
     * @param  Model  $model  대상 모델
     * @param  string  $key  속성명
     * @param  mixed  $value  IP 문자열
     * @param  array<string, mixed>  $attributes  전체 속성
     * @return array<string, mixed> 저장할 속성
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '') {
            return [$key => null];
        }

        // 이미 이진값이면 그대로 둔다 (소급 익명화처럼 이진값을 직접 넣는 경로).
        if (is_string($value) && (strlen($value) === 4 || strlen($value) === 16) && ! filter_var($value, FILTER_VALIDATE_IP)) {
            return [$key => $value];
        }

        if (! is_string($value)) {
            return [$key => null];
        }

        $packed = @inet_pton($value);

        return [$key => $packed === false ? null : $packed];
    }
}
