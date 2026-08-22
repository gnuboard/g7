<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 토스페이먼츠 결제상태 변경(PAYMENT_STATUS_CHANGED) 웹훅 요청 검증.
 *
 * 본문 예시:
 *   { "eventType": "PAYMENT_STATUS_CHANGED", "createdAt": "...",
 *     "data": { "orderId": "...", "status": "...", "paymentKey": "..." } }
 *
 * 상태 동기화(로깅 + 불일치 경고)만 수행하므로 data 하위 필드는 nullable 로 둔다.
 */
class PaymentStatusWebhookRequest extends FormRequest
{
    /**
     * 인가는 미들웨어 체인에 위임한다 (웹훅은 CSRF 면제 공개 엔드포인트).
     *
     * @return bool 항상 true (상태 동기화 로깅 전용 엔드포인트)
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
            'eventType' => ['nullable', 'string', 'max:64'],
            'createdAt' => ['nullable', 'string', 'max:64'],
            'data' => ['required', 'array'],
            'data.orderId' => ['required', 'string', 'max:100'],
            'data.status' => ['required', 'string', 'max:40'],
            'data.paymentKey' => ['nullable', 'string', 'max:255'],
        ];
    }
}
