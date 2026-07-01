<?php

namespace Modules\Sirsoft\Ecommerce\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * 배송정책 계산 API 연동 설정(api_config) 캐스트
 *
 * api_config 는 JSON 배열로 저장되며, 그 안의 `auth_token` 값만 DB 평문 저장을
 * 막기 위해 암호화/복호화합니다. 나머지 키(http_method/field_map 등)는 평문 유지하여
 * 검증·디버깅 가시성을 보존합니다.
 */
class ShippingApiConfigCast implements CastsAttributes
{
    /**
     * DB 값을 모델 속성(배열)으로 변환하며 auth_token 을 복호화합니다.
     *
     * @param  Model  $model  모델 인스턴스
     * @param  string  $key  속성명
     * @param  mixed  $value  DB 원본 값(JSON 문자열)
     * @param  array<string, mixed>  $attributes  전체 속성 배열
     * @return array<string, mixed>|null 복호화된 설정 배열
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $config = json_decode($value, true);

        if (! is_array($config)) {
            return null;
        }

        if (! empty($config['auth_token'])) {
            try {
                $config['auth_token'] = Crypt::decryptString($config['auth_token']);
            } catch (\Throwable $e) {
                // 복호화 실패(키 로테이션 등) 시 토큰을 비워 인증 없이 진행 — 평문 노출 방지
                Log::warning('배송 API auth_token 복호화 실패', ['error' => $e->getMessage()]);
                $config['auth_token'] = null;
            }
        }

        return $config;
    }

    /**
     * 모델 속성(배열)을 DB 저장 값으로 변환하며 auth_token 을 암호화합니다.
     *
     * @param  Model  $model  모델 인스턴스
     * @param  string  $key  속성명
     * @param  mixed  $value  설정 배열
     * @param  array<string, mixed>  $attributes  전체 속성 배열
     * @return string|null JSON 문자열(auth_token 암호화 적용)
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            return null;
        }

        if (! empty($value['auth_token'])) {
            $value['auth_token'] = Crypt::encryptString($value['auth_token']);
        }

        return json_encode($value);
    }
}
