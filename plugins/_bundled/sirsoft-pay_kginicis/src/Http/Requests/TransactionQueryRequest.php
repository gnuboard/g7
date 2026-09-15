<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * TID 단건 거래 조회 요청 검증
 *
 * 상한 근거: 저장 컬럼 `ecommerce_order_payments.transaction_id` varchar(100) 이 SSoT.
 * 국내 TID 는 30자지만 해외결제(CBT) TID 는 40자(관측)로 형식이 다르다 — 이 엔드포인트는
 * 두 형식을 모두 받으므로 특정 형식 길이(30)로 조이면 CBT 조회가 422 로 깨진다.
 */
class TransactionQueryRequest extends FormRequest
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
     * 거래 조회 요청 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, string>> 필드별 검증 규칙
     */
    public function rules(): array
    {
        return [
            'tid' => ['required', 'string', 'max:100'],
        ];
    }
}
