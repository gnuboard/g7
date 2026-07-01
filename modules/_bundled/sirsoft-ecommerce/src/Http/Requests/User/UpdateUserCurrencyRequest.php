<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;

/**
 * 유저 결제 통화 변경 요청 검증 (A3)
 *
 * 관리자가 등록한 통화(language_currency.currencies) 중 환율이 설정된(또는 기본) 통화만 허용한다.
 * 환율 미설정 통화는 셀렉터에서도 숨겨지므로 저장도 거부(미등록 422).
 */
class UpdateUserCurrencyRequest extends FormRequest
{
    /**
     * 권한 체크는 라우트의 auth:sanctum 미들웨어에서 수행됩니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'currency' => ['required', 'string', Rule::in($this->allowedCurrencyCodes())],
        ];
    }

    /**
     * 허용 통화 코드 목록 (is_default || exchange_rate>0).
     *
     * @return array<int, string>
     */
    private function allowedCurrencyCodes(): array
    {
        $currencies = app(EcommerceSettingsService::class)
            ->getSetting('language_currency.currencies', []);

        return collect($currencies)
            ->filter(fn ($c) => ($c['is_default'] ?? false) || (float) ($c['exchange_rate'] ?? 0) > 0)
            ->pluck('code')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * 검증 메시지
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'currency.required' => __('sirsoft-ecommerce::validation.custom.user_currency.required'),
            'currency.in' => __('sirsoft-ecommerce::validation.custom.user_currency.invalid'),
        ];
    }
}
