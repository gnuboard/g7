<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * PC 결제창 프레임 요청 (#158)
 *
 * `sdk` 는 KCP SDK 주소 두 개(테스트·운영) 중 하나와 **정확히 같아야** 한다 — 프레임 페이지가 그 주소를
 * `<script src>` 로 그대로 싣기 때문이다(다른 주소를 넣으면 임의 스크립트를 이 출처에서 실행하는 통로가 된다).
 */
class KcpFrameRequest extends FormRequest
{
    /** 허용 SDK 주소 (plugin.json `trusted_script_hosts` · requestPayment.ts `KNOWN_SDK_HOSTS` 와 함께 갱신) */
    public const ALLOWED_SDK_URLS = [
        'https://testpay.kcp.co.kr/plugin/payplus_web.jsp',
        'https://pay.kcp.co.kr/plugin/payplus_web.jsp',
    ];

    /**
     * 결제창 프레임 요청을 허용합니다 (비회원 결제도 같은 프레임을 연다).
     *
     * @return bool 요청 허용 여부
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 프레임 요청 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, mixed>> 필드별 검증 규칙
     */
    public function rules(): array
    {
        return [
            'sdk' => ['required', 'string', 'in:'.implode(',', self::ALLOWED_SDK_URLS)],
        ];
    }

    /**
     * 검증 항목명을 반환합니다.
     *
     * @return array<string, string> 필드별 항목명
     */
    public function attributes(): array
    {
        return [
            'sdk' => __('sirsoft-pay_nhnkcp::messages.kcp_frame.sdk'),
        ];
    }

    /**
     * 검증 실패는 빈 400 으로 끝낸다 — iframe 안이라 리다이렉트·JSON 오류가 의미 없고, 부모는 시간 초과로 결제를 실패 처리한다.
     *
     * @param  Validator  $validator  실패한 검증기
     * @return void 반환값 없음 (예외로 끝난다)
     *
     * @throws HttpResponseException 항상
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response('', 400, ['Content-Type' => 'text/plain; charset=UTF-8']));
    }
}
