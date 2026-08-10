<?php

namespace App\Http\Requests\LanguagePack;

use App\Extension\HookManager;
use App\Rules\LanguagePack\RequiresActivationPermission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 번들(`lang-packs/_bundled/{identifier}`) 디렉토리에서 언어팩 설치 요청.
 *
 * 코어/공식 번들 언어팩을 외부 다운로드 없이 로컬 번들에서 (재)설치하는 경로.
 * 모듈/플러그인/템플릿의 `_bundled` 설치 패턴과 동일.
 */
// audit:allow api-doc-coverage reason: 이번 변경은 rules() 주석 문구 정정 1건뿐이다. 요청 파라미터·검증 규칙·응답 구조가 그대로라 docs/backend/api/language-packs.md 에 갱신할 내용이 없다.
class InstallFromBundledRequest extends FormRequest
{
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
            'identifier' => [
                'required',
                'string',
                'max:200',
                'regex:/^[a-zA-Z0-9._\-]+$/',
            ],
            /*
             * 외부 소스(파일·URL·GitHub)와 동일하게 활성화 권한 게이트를 건다 (프로젝트 결정, 2026-08-04).
             * 설치(install)와 활성화(manage)를 별도 권한으로 두는 정책을 네 경로 전부에 같은 강도로
             * 적용해, "어느 경로로 들어오느냐" 가 권한 경계를 바꾸지 않게 한다.
             *
             * 부수 효과: 관리자 화면에서 auto_activate 를 보내는 곳이 번들 재설치 모달 하나뿐이므로,
             * 설치 권한만 가진 운영자가 활성 팩을 재설치하면 그 팩이 installed 로 내려간다.
             * 다시 켜려면 언어팩 관리 권한이 필요하다 — 의도된 동작이다.
             */
            'auto_activate' => ['nullable', 'boolean', new RequiresActivationPermission],
        ];

        // 모듈/플러그인이 validation rules 를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.language_packs.install_from_bundled_validation_rules', $rules, $this);
    }

    /**
     * 검증 메시지를 정의합니다.
     *
     * @return array<string, string> 검증 메시지
     */
    public function messages(): array
    {
        return [
            'identifier.required' => __('language_packs.validation.identifier_required'),
            'identifier.regex' => __('language_packs.validation.identifier_invalid'),
        ];
    }
}
