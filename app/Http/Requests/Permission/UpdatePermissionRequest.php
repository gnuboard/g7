<?php

namespace App\Http\Requests\Permission;

use App\Extension\HookManager;
use App\Rules\LocaleRequiredTranslatable;
use App\Rules\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 권한 업데이트 요청 검증
 *
 * 권한의 name, description을 업데이트할 수 있습니다.
 * name과 description은 다국어 필드로 처리됩니다.
 */
class UpdatePermissionRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 전 데이터 전처리
     *
     * 역호환성을 위해 문자열로 들어온 name, description을
     * 다국어 배열 형식으로 자동 변환합니다.
     */
    protected function prepareForValidation(): void
    {
        $locales = config('app.translatable_locales', ['ko', 'en']);

        // name이 문자열로 들어온 경우 배열로 변환 (역호환성)
        if ($this->has('name') && is_string($this->name)) {
            $nameArray = [];
            foreach ($locales as $locale) {
                $nameArray[$locale] = $this->name;
            }
            $this->merge(['name' => $nameArray]);
        }

        // description이 문자열로 들어온 경우 배열로 변환
        if ($this->has('description') && is_string($this->description)) {
            $descriptionArray = [];
            foreach ($locales as $locale) {
                $descriptionArray[$locale] = $this->description;
            }
            $this->merge(['description' => $descriptionArray]);
        }
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['nullable', new LocaleRequiredTranslatable(maxLength: 255)],
            'description' => ['nullable', new TranslatableField(maxLength: 1000)],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.permission.update_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     */
    public function messages(): array
    {
        return [
            'name.required' => __('validation..name.required'),
            'description.max' => __('validation.permission.description.max'),
        ];
    }
}
