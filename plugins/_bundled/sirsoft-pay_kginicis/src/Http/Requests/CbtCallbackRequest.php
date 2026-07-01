<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * KG 이니시스 CBT 인증 콜백 요청 검증.
 *
 * PG 실패/취소 콜백도 정상 라우팅되어야 하므로 필드는 대부분 nullable 로 두고,
 * 컨트롤러가 결과 코드와 주문 상태에 따라 결제 성공/실패 흐름을 결정한다.
 */
class CbtCallbackRequest extends FormRequest
{
    /**
     * authorize
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * rules
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'sid' => ['nullable', 'string', 'max:255'],
            'resultCode' => ['nullable', 'string', 'max:100'],
            'resultMsg' => ['nullable', 'string', 'max:500'],
            'orderID' => ['nullable', 'string', 'max:100'],
            'orderId' => ['nullable', 'string', 'max:100'],
            'oid' => ['nullable', 'string', 'max:100'],
            'mid' => ['nullable', 'string', 'max:30'],
            'paymethod' => ['nullable', 'string', 'max:50'],
            'selectedPaymentMethod' => ['nullable', 'string', 'max:50'],
        ];
    }
}
