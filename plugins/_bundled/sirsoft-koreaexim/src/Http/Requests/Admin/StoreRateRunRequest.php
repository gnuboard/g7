<?php

namespace Plugins\Sirsoft\Koreaexim\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreRateRunRequest extends FormRequest
{
    /**
     * 권한 검사를 라우트 미들웨어에 위임합니다.
     *
     * @return bool 처리 결과
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 입력 검증 규칙을 반환합니다.
     *
     * @return array 처리 결과
     */
    public function rules(): array
    {
        return ['dry_run' => ['sometimes', 'boolean']];
    }

    /**
     * 저장 전에 연관 설정과의 정합성을 검증합니다.
     *
     * @param  Validator  $validator  validator 입력값
     * @return void 반환값 없음
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->boolean('dry_run') && ! plugin_setting('sirsoft-koreaexim', 'auto_manage', false)) {
                $validator->errors()->add('dry_run', __('sirsoft-koreaexim::validation.auto_manage_required'));
            }
        });
    }
}
