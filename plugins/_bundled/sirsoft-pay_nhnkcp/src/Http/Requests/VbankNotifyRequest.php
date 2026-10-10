<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Http\Requests;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * KCP 가상계좌 입금 통보 요청 검증
 *
 * POST /plugins/sirsoft-pay_nhnkcp/payment/vbank-notify
 *
 * KCP 서버가 직접 호출하는 입금 확인 웹훅입니다.
 */
class VbankNotifyRequest extends FormRequest
{
    /**
     * FormRequest 인가 — 외부 PG 웹훅이라 항상 true
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * KCP 공통통보 페이로드 검증 규칙 (KCP 공식 webhook 명세)
     *
     * 키 체계는 KCP 결제창 콜백(authCallback) 과 다르므로 주의 — 결제창은 ordr_idxx/good_mny/res_cd
     * 를 쓰지만, 통보 webhook 은 order_no/ipgm_mnyx/op_cd 를 쓴다.
     *
     * tx_cd 분기 (그누보드5 settle_kcp_common.php 참고):
     *   TX00 = 가상계좌 입금 통보 (본 endpoint 가 처리)
     *   TX01 = 가상계좌 환불 통보
     *   TX02 = 구매확인/구매취소 통보
     *   TX03 = 배송시작 통보
     *
     * 본 endpoint 는 TX00 만 처리하지만 KCP 가 단일 URL 로 모든 tx_cd 를 보낼 수 있으므로
     * tx_cd 자체는 nullable 로 받고 컨트롤러에서 분기.
     *
     * @return array<string, mixed> Laravel 검증 규칙
     */
    public function rules(): array
    {
        return [
            'site_cd' => ['nullable', 'string'],
            'tno' => ['required', 'string'],
            'order_no' => ['required', 'string'],
            'tx_cd' => ['nullable', 'string'],
            'tx_tm' => ['nullable', 'string'],
            'ipgm_name' => ['nullable', 'string'],
            'remitter' => ['nullable', 'string'],
            'ipgm_mnyx' => ['nullable', 'numeric'],
            'bank_code' => ['nullable', 'string'],
            'account' => ['nullable', 'string'],
            'op_cd' => ['nullable', 'string'],
            'noti_id' => ['nullable', 'string'],
        ];
    }

    /**
     * 검증 실패를 422 JSON 으로 끝냅니다.
     *
     * 보안: 이 주소는 브라우저 화면이 아니라 PG 서버·결제 스크립트가 부른다. Laravel 기본 검증 실패
     * 처리는 JSON 을 요구하지 않은 요청을 `previous()`(Referer → 세션 → 요청 Host)로 302 이동시켜,
     * 우리 주소가 외부 이동 경유지가 된다. 302 도 PG 에는 실패라 재시도 동작은 같다.
     *
     * @param  Validator  $validator  실패한 검증기
     * @return void 반환값 없음
     *
     * @throws HttpResponseException 422 JSON 응답
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ResponseHelper::validationError($validator->errors()));
    }
}
