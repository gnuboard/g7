<?php

namespace App\Http\Requests\Module;

use App\Extension\HookManager;
use App\Http\Requests\Concerns\HasPackageIntegrityRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GitHub에서 모듈 설치 요청
 */
class InstallModuleFromGithubRequest extends FormRequest
{
    use HasPackageIntegrityRules;

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool 항상 true (권한은 라우트의 permission 미들웨어가 검증)
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
        $rules = [
            'github_url' => [
                'required',
                'url',
                'regex:/^https?:\/\/(www\.)?github\.com\/[a-zA-Z0-9\-_]+\/[a-zA-Z0-9\-_]+\/?$/',
            ],
        ];

        // 패키지 무결성 입력 — 미서명 계속(allow_unsigned) (7.2.0)
        $rules = array_merge($rules, $this->packageIntegrityRules());

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.module.install_from_github_validation_rules', $rules, $this);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->packageIntegrityMessages() + [
            'github_url.required' => __('modules.validation.github_url_required'),
            'github_url.url' => __('modules.validation.github_url_invalid'),
            'github_url.regex' => __('modules.validation.github_url_format'),
        ];
    }

    /**
     * 검증 오류 문구에 쓸 칸 이름 라벨을 돌려줍니다.
     *
     * @return array<string, string> 라벨
     */
    public function attributes(): array
    {
        return $this->packageIntegrityAttributes();
    }
}
