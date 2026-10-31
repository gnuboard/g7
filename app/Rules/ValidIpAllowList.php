<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * IP 허용 목록 검증 (웹 크론 호출 제한 — A-5).
 *
 * 줄바꿈 또는 쉼표로 구분한 IPv4/IPv6 주소와 CIDR 표기를 받는다. 비우면 "제한 없음"
 * 이라는 명시적 의사이므로 통과시킨다.
 *
 * 저장 단계에서 막는 이유는 이 값이 **접근 게이트**이기 때문이다. 오타가 그대로
 * 저장되면 판정기가 그 항목을 조용히 매칭 실패로 처리해, 운영자는 "허용 목록에 넣었는데
 * 왜 막히는가" 를 알 수 없다.
 *
 * @since 7.1.0
 */
class ValidIpAllowList implements ValidationRule
{
    /**
     * 검증을 수행합니다.
     *
     * @param  string  $attribute  검증 대상 필드명
     * @param  mixed  $value  입력값
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || ! is_string($value) || trim($value) === '') {
            return;
        }

        // `\R` 은 UTF-8 모드가 아니면 바이트 단위로 판정해 한글 바이트(0x85)를 개행으로
        // 오인한다 — 실제 줄 종결자만 열거한다.
        $entries = preg_split('~\r\n|\r|\n|,~', $value) ?: [];

        foreach ($entries as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            if (! $this->isValidEntry($entry)) {
                $fail(__('validation.ip_allow_list.invalid', ['value' => $entry]));

                return;
            }
        }
    }

    /**
     * 항목 하나가 IP 또는 CIDR 표기인지 판정합니다.
     *
     * @param  string  $entry  항목
     * @return bool 유효 여부
     */
    private function isValidEntry(string $entry): bool
    {
        if (! str_contains($entry, '/')) {
            return filter_var($entry, FILTER_VALIDATE_IP) !== false;
        }

        [$address, $prefix] = explode('/', $entry, 2);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if (! ctype_digit($prefix)) {
            return false;
        }

        $max = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 128 : 32;

        return (int) $prefix >= 0 && (int) $prefix <= $max;
    }
}
