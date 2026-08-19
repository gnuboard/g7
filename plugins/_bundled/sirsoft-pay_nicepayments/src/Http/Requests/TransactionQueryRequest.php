<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * TID 단건 거래 조회 요청 검증
 *
 * 상한 근거: 나이스페이먼츠 TID 는 30자.
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
            'tid' => ['required', 'string', 'max:30'],
        ];
    }
}
