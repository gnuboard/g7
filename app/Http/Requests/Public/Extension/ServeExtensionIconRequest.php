<?php

namespace App\Http\Requests\Public\Extension;

use App\Rules\ValidExtensionIdentifier;
use App\Services\Extension\ExtensionIconResolver;
use App\Services\LanguagePack\LanguagePackManifestValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 확장 아이콘 서빙 요청 검증
 *
 * 라우트 파라미터 둘(type·identifier) 만 받는 공개 요청이다. 식별자는 파일시스템
 * 경로로 쓰이므로 여기가 유일한 방어선 — 경로 구분자·상위 탈출·대문자를 막는다.
 */
class ServeExtensionIconRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인합니다.
     *
     * 아이콘은 미설치 번들 확장까지 대상이라 공개 lane 이다.
     *
     * @return bool 항상 true (공개 라우트)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙 배열
     */
    // audit:allow core-formrequest-hook-filter reason: 아이콘 서빙의 식별자 검증은
    // 파일시스템 경로 조립 직전의 유일한 방어선이다. 확장이 필터로 규칙을 대체할 수
    // 있으면 경로 탈출이 열린다.
    public function rules(): array
    {
        $type = (string) $this->route('type');

        $identifierRules = $type === 'language-pack'
            ? ['required', 'string', 'max:255', 'regex:'.LanguagePackManifestValidator::PACK_IDENTIFIER_PATTERN]
            : ['required', 'string', 'max:255', new ValidExtensionIdentifier];

        return [
            'type' => ['required', 'string', Rule::in(ExtensionIconResolver::TYPES)],
            'identifier' => $identifierRules,
        ];
    }

    /**
     * 검증을 위해 라우트 파라미터를 요청 데이터에 병합합니다.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'type' => $this->route('type'),
            'identifier' => $this->route('identifier'),
        ]);
    }
}
