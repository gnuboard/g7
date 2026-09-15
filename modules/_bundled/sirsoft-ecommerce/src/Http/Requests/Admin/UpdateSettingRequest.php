<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;

/**
 * 특정 설정값 업데이트 요청 검증
 */
class UpdateSettingRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     *
     * @return bool 처리 결과
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:255'],
            'value' => ['present'],
        ];
    }

    /** 단건 통화 저장도 벌크 요청의 환율 관리 계약으로 검증합니다.
     * @param  \Illuminate\Validation\Validator  $validator  validator 입력값
     * @return void 반환값 없음
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            $key = $this->input('key');
            if (! is_string($key) || ($key !== 'language_currency' && ! str_starts_with($key, 'language_currency.'))) {
                return;
            }
            $payload = ['language_currency' => app(EcommerceSettingsService::class)->getSettings('language_currency')];
            Arr::set($payload, $key, $this->input('value'));
            $request = new StoreEcommerceSettingsRequest($payload);
            $request->setContainer(app());
            $nested = Validator::make($payload, $request->rules(), $request->messages());
            $request->withValidator($nested);
            if ($nested->fails()) {
                foreach ($nested->errors()->all() as $message) {
                    $validator->errors()->add('value', $message);
                }
            }
        });
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'key.required' => __('sirsoft-ecommerce::validation.settings.key_required'),
            'value.present' => __('sirsoft-ecommerce::validation.settings.value_present'),
        ];
    }
}
