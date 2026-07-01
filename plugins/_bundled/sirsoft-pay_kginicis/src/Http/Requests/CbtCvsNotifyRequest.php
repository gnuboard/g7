<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * KG 이니시스 CBT 편의점 입금 NOTI 요청 검증.
 *
 * POST /plugins/sirsoft-pay_kginicis/payment/cbt/cvs-notify
 *
 * CBT CVS 입금 통보는 JSON으로 수신되며, PG 재시도 정책 때문에 필수값 누락도
 * Laravel 422가 아니라 컨트롤러에서 "FAIL" text/plain으로 응답한다.
 */
class CbtCvsNotifyRequest extends FormRequest
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

    protected function prepareForValidation(): void
    {
        if ($this->all() !== []) {
            return;
        }

        $content = $this->getContent();
        if ($content === '') {
            return;
        }

        if (! mb_check_encoding($content, 'UTF-8')) {
            $converted = @mb_convert_encoding($content, 'UTF-8', 'CP949');
            if ($converted !== false && $converted !== '') {
                $content = $converted;
            }
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            $this->replace($decoded);
        }
    }

    /**
     * rules
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'tid' => ['nullable', 'string', 'max:80'],
            'mid' => ['nullable', 'string', 'max:20'],
            'applDt' => ['nullable', 'string', 'max:8'],
            'applTm' => ['nullable', 'string', 'max:6'],
            'status' => ['nullable', 'string', 'max:10'],
            'payNm' => ['nullable', 'string', 'max:50'],
            'orderId' => ['nullable', 'string', 'max:100'],
            'applNo' => ['nullable', 'string', 'max:80'],
            'sid' => ['nullable', 'string', 'max:100'],
            'convenience' => ['nullable', 'string', 'max:30'],
            'confNo' => ['nullable', 'string', 'max:80'],
            'receiptNo' => ['nullable', 'string', 'max:80'],
            'paymentTerm' => ['nullable', 'string', 'max:20'],
            'amount' => ['nullable'],
            'currencyCd' => ['nullable', 'string', 'max:10'],
        ];
    }
}
