<?php

namespace App\Http\Requests\LanguagePack;

use App\Extension\HookManager;
use App\Http\Requests\LanguagePack\Concerns\ValidatesLanguagePackArchive;
use App\Rules\LanguagePack\RequiresActivationPermission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ZIP 파일 업로드를 통한 언어팩 설치 요청.
 */
class InstallFromFileRequest extends FormRequest
{
    use ValidatesLanguagePackArchive;

    /**
     * 권한 체크는 라우트 미들웨어가 담당.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 정의합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            // 규칙은 미리보기와 **같은 하나**를 읽는다(trait) — 두 벌이면 한쪽만 고쳐졌을 때
            // "설치는 되는데 미리보기는 안 되는 파일" 이 다시 생긴다.
            'file' => $this->languagePackArchiveRules(),
            // 설치 권한만으로 활성화까지 수행하지 못하게 한다 — 활성 팩만 require 경로에 배선된다.
            'auto_activate' => ['nullable', 'boolean', new RequiresActivationPermission],
        ];

        // 모듈/플러그인이 validation rules 를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.language_packs.install_from_file_validation_rules', $rules, $this);
    }

    /**
     * 검증 메시지를 정의합니다.
     *
     * @return array<string, string> 검증 메시지
     */
    public function messages(): array
    {
        return $this->languagePackArchiveMessages();
    }
}
