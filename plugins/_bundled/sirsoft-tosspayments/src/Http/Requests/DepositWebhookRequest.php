<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 토스페이먼츠 가상계좌 입금통보(DEPOSIT_CALLBACK) 웹훅 요청 검증.
 *
 * 본문 예시:
 *   { "createdAt": "...", "secret": "...", "status": "DONE",
 *     "transactionKey": "...", "orderId": "..." }
 *
 * status 는 입금완료(DONE) / 입금취소(CANCELED) 두 값이 유효하다. secret 대조는
 * 저장된 payment_meta.toss_secret 과 컨트롤러에서 수행하므로 여기서는 형식만 검증한다.
 */
class DepositWebhookRequest extends FormRequest
{
    /**
     * 인가는 미들웨어 체인에 위임한다 (웹훅은 CSRF 면제 공개 엔드포인트).
     *
     * @return bool 항상 true (인가는 secret 대조로 컨트롤러에서 수행)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙 반환.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'orderId' => ['required', 'string', 'max:100'],
            'status' => ['required', 'string', 'in:DONE,CANCELED'],
            'secret' => ['nullable', 'string', 'max:255'],
            'transactionKey' => ['nullable', 'string', 'max:255'],
            'createdAt' => ['nullable', 'string', 'max:64'],
        ];
    }
}
