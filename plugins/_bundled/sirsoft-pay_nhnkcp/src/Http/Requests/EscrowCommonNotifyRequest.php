<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Http\Requests;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * KCP 에스크로 공통 통보 요청 검증
 *
 * KCP가 구매확인(TX02) 또는 배송시작(TX03) 이벤트 발생 시
 * 등록된 공통통보 URL로 POST 요청을 보냅니다.
 */
class EscrowCommonNotifyRequest extends FormRequest
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
     * KCP 에스크로 통보 페이로드 검증 규칙 (KCP 공식 webhook 명세)
     *
     * KCP 결제창 콜백과 달리 통보 webhook 은 order_no 키를 사용한다
     * (그누보드5 settle_kcp_common.php 와 동일). ordr_idxx 사용 시 422 차단.
     *
     * @return array<string, mixed> Laravel 검증 규칙
     */
    public function rules(): array
    {
        return [
            'site_cd' => ['nullable', 'string'],
            'tno' => ['nullable', 'string'],
            'order_no' => ['required', 'string'],
            'tx_cd' => ['required', 'string'],
            'tx_tm' => ['nullable', 'string'],
            'cl_status' => ['nullable', 'string'],
            'st_cd' => ['nullable', 'string'],
            'can_msg' => ['nullable', 'string'],
            'waybill_no' => ['nullable', 'string'],
            'waybill_corp' => ['nullable', 'string'],
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
