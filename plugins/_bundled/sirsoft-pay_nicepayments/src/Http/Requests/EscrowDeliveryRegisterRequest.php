<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 에스크로 배송 등록 요청 검증
 *
 * 종전 컨트롤러 인라인 validate 를 승격했다 (에스크로 배송등록 3형제 계약 대칭).
 * tid 상한 보강: 나이스페이먼츠 TID 는 30자다.
 */
class EscrowDeliveryRegisterRequest extends FormRequest
{
    /**
     * 요청 권한 — 라우트 permission 미들웨어 체인이 담당.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 에스크로 배송 등록 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, string>> 필드별 검증 규칙
     */
    public function rules(): array
    {
        return [
            'tid' => ['required', 'string', 'max:30'],
            'delivery_name' => ['required', 'string', 'max:100'],
            'tracking_number' => ['required', 'string', 'max:100'],
            'buyer_address' => ['required', 'string', 'max:200'],
            'register_name' => ['required', 'string', 'max:50'],
        ];
    }
}
