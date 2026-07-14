<?php

namespace App\Rules;

use App\Support\OutboundUrlValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 서버가 대신 호출하게 될 URL 이 내부망을 가리키지 않는지 검증하는 Custom Rule.
 *
 * 외부 API 엔드포인트·스케줄 URL 처럼 저장된 값이 나중에 서버의 outbound 요청 목적지가
 * 되는 입력에 적용한다. 사설/루프백/링크로컬 IP 와 내부 도메인을 입력 시점에 차단해,
 * 서버가 내부망 정찰 도구로 쓰이는 것을 막는다(SSRF).
 *
 * 사내 서버를 호출하는 것이 정당한 용도(외부 API 연동, 스케줄 URL 호출)에 한해, 관리자
 * 환경설정의 `security.allow_internal_outbound_urls` 로 내부 주소를 허용할 수 있다.
 * 원격 코드(언어팩 ZIP 등)를 내려받는 지점은 `allowInternalOptIn: false` 로 두어 이
 * 설정과 무관하게 항상 차단한다.
 */
class PublicOutboundUrl implements ValidationRule
{
    /**
     * @param  array<int, string>  $schemes  허용 scheme (기본 http/https — 사내 API 는 http 인 경우가 있다)
     * @param  bool  $allowInternalOptIn  관리자 설정으로 내부 주소를 허용할 수 있는 지점인지 (기본 true)
     */
    public function __construct(
        private readonly array $schemes = ['http', 'https'],
        private readonly bool $allowInternalOptIn = true,
    ) {}

    /**
     * 값이 공개 인터넷 URL 인지 검증합니다.
     *
     * @param  string  $attribute  검증 대상 필드명
     * @param  mixed  $value  검증 대상 값
     * @param  Closure  $fail  실패 콜백
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail(__('validation.outbound_url.invalid'));

            return;
        }

        $options = ['schemes' => $this->schemes];

        // 내부 주소 허용이 켜져 있으면 구조 검증(scheme/userinfo)만 하고 내부망 차단은 건너뛴다
        if ($this->allowInternalOptIn && (bool) g7_core_settings('security.allow_internal_outbound_urls', false)) {
            if (! OutboundUrlValidator::isStructurallySafeUrl($value, $options)) {
                $fail(__('validation.outbound_url.invalid'));
            }

            return;
        }

        if (! OutboundUrlValidator::isPublicHttpUrl($value, $options)) {
            $fail(__('validation.outbound_url.internal_not_allowed'));
        }
    }
}
