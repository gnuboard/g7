<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Plugins\Sirsoft\PayKginicis\Support\PaymentLimits;

class SignatureRequest extends FormRequest
{
    /**
     * authorize
     *
     * @return bool 항상 true (권한은 미들웨어에서 검증)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * rules
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        return [
            'oid' => ['required', 'string', 'max:40'],
            // PC·모바일 동일 하한 (PaymentLimits SSoT) — 모바일에서 1원 결제가 성립 중이므로
            // 100 으로 통일하면 현재 성공 중인 모바일 결제를 깨뜨린다
            'price' => ['required', 'integer', 'min:'.PaymentLimits::MIN_PRICE],
            'timestamp' => ['required', 'string', 'max:20'],
            'buyer_email' => ['nullable', 'string', 'max:255'],
            'buyer_phone' => ['nullable', 'string', 'max:30'],
        ];
    }
}
