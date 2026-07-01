<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Concerns;

trait SanitizesPgResponse
{
    /**
     * PG 원문 응답을 그대로 보관하지 않고 운영에 필요한 필드만 남긴다.
     *
     * 카드번호, 구매자 연락처처럼 PG 응답에 섞일 수 있는 민감 필드는 whitelist 에
     * 넣지 않는 방식으로 차단한다. 기존 관리자 조회 fallback 호환을 위해 결과는
     * payment_meta.pg_raw_response 키에 저장하되 내용은 raw 가 아닌 sanitized subset.
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
