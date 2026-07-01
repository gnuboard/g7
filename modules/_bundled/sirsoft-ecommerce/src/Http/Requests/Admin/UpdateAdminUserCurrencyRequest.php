<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;

/**
 * 관리자 회원 결제 통화 변경 요청 검증 (A3)
 *
 * 권한은 라우트의 permission:admin,sirsoft-ecommerce.user-currency.manage 미들웨어가 담당한다.
 * 등록 통화(is_default || exchange_rate>0)만 허용한다.
 */
class UpdateAdminUserCurrencyRequest extends FormRequest
{
    /**
     * 권한 체크는 permission 미들웨어에서 수행됩니다.
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
