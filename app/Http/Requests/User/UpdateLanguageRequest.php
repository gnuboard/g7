<?php

namespace App\Http\Requests\User;

use App\Extension\HookManager;
use App\Models\User;
use App\Services\UserLockedFieldsService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLanguageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $supportedLocales = implode(',', config('app.supported_locales', ['ko', 'en']));

        $rules = [
            'language' => 'required|string|in:'.$supportedLocales,
        ];

        // 외부(회사 계정)가 관리하는 언어는 바꾸지 못한다(7.2.0) — 관리자 본인 화면 경로
        $user = $this->user();
        $rules = app(UserLockedFieldsService::class)->applyRules($rules, $user instanceof User ? $user : null, UserLockedFieldsService::SURFACE_ADMIN);

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.user.update_language_validation_rules', $rules, $this);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'language.required' => __('user.validation.language_required'),
            'language.string' => __('user.validation.language_string'),
            'language.in' => __('user.validation.language_invalid'),
        ];
    }
}
