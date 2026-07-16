<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Concerns;

trait SanitizesPgResponse
{
    /**
     * PG 응답 원문 대신 운영에 필요한 필드만 남긴다.
     *
     * 구매자명/연락처/카드번호/가상계좌번호처럼 PG 응답에 섞일 수 있는 민감 필드는
     * whitelist 에 넣지 않는 방식으로 차단한다. 기존 관리자 조회 호환을 위해
     * payment_meta.pg_raw_response 키는 유지하되 내용은 sanitized subset 이다.
     */
    protected function sanitizePgResponse(array $response, array $allowedKeys): array
    {
        $allowed = array_flip($allowedKeys);
        $sanitized = [];

        foreach ($response as $key => $value) {
            if (! isset($allowed[$key])) {
                continue;
            }

            $sanitized[$key] = is_scalar($value) || $value === null
                ? $value
                : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $sanitized;
    }
}
