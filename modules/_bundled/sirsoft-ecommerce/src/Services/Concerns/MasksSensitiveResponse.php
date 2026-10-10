<?php

namespace Modules\Sirsoft\Ecommerce\Services\Concerns;

/**
 * 외부 결제·발급 응답의 민감 키 마스킹 trait (서비스 공용)
 *
 * PG·발급사 원응답에는 주민등록번호·휴대전화·사업자번호·이메일·시크릿 키 같은 값이 섞여 온다.
 * 그 원문을 `raw_response` 컬럼에 그대로 적재하면 관리자 화면·백업·로그 반출에 그대로 실린다.
 * 저장 전에 이 trait 을 경유해 민감 키를 마스킹한다 — 대사에 필요한 나머지 값은 보존한다.
 *
 * 판정은 키 이름 부분 일치(대소문자·구분자 무시)다. 벤더마다 키 표기가 달라
 * (`customerIdentityNumber` · `customer_identity_number` · `CUSTOMER-IDENTITY-NUMBER`)
 * 정확 일치로는 같은 값을 놓친다.
 */
trait MasksSensitiveResponse
{
    /**
     * 원응답에서 마스킹할 민감 키 목록 (대소문자 무시, 부분 일치)
     *
     * @var array<int, string>
     */
    private const SENSITIVE_RESPONSE_KEYS = [
        'customeridentitynumber',
        'identitynumber',
        'identifier',
        'customermobilephone',
        'mobilephone',
        'phone',
        'businessnumber',
        'customername',
        'customeremail',
        'email',
        'secret',
        'secretkey',
        'password',
    ];

    /**
     * 원응답을 마스킹해 저장 가능한 배열로 만듭니다.
     *
     * @param  mixed  $response  원응답 (배열이 아니면 저장하지 않는다)
     * @return array<mixed, mixed>|null 마스킹된 배열
     */
    protected function sanitizeResponse(mixed $response): ?array
    {
        if (! is_array($response)) {
            return null;
        }

        return $this->maskSensitiveKeys($response);
    }

    /**
     * 배열을 재귀 순회하며 민감 키의 값을 마스킹합니다.
     *
     * @param  array<mixed, mixed>  $data  원본 배열
     * @return array<mixed, mixed> 마스킹된 배열
     */
    private function maskSensitiveKeys(array $data): array
    {
        $masked = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $masked[$key] = $this->maskSensitiveKeys($value);

                continue;
            }

            $masked[$key] = $this->isSensitiveKey((string) $key) ? '***' : $value;
        }

        return $masked;
    }

    /**
     * 민감 키인지 확인합니다.
     *
     * @param  string  $key  키 이름
     * @return bool 민감 키 여부
     */
    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['_', '-'], '', $key));

        foreach (self::SENSITIVE_RESPONSE_KEYS as $sensitive) {
            if (str_contains($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
