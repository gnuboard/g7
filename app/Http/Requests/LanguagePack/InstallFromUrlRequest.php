<?php

namespace App\Http\Requests\LanguagePack;

use App\Extension\HookManager;
use App\Http\Requests\Concerns\HasPackageIntegrityRules;
use App\Rules\LanguagePack\RequiresActivationPermission;
use App\Rules\PublicOutboundUrl;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 임의 URL 을 통한 언어팩 설치 요청.
 */
class InstallFromUrlRequest extends FormRequest
{
    use HasPackageIntegrityRules;

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
            // 원격 코드를 내려받는 지점이므로 https 고정 + 내부 주소 허용 설정과 무관하게 항상 차단
            'url' => ['required', 'url', 'max:500', new PublicOutboundUrl(schemes: ['https'], allowInternalOptIn: false)],
            'checksum' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/i'],
            // 설치 권한만으로 활성화까지 수행하지 못하게 한다 — 활성 팩만 require 경로에 배선된다.
            'auto_activate' => ['nullable', 'boolean', new RequiresActivationPermission],
        ];

        // 패키지 무결성 입력 — 미서명 계속(allow_unsigned)·서명 파일 주소(signature_url) (7.2.0)
        $rules = array_merge($rules, $this->packageIntegrityRules(signatureUrl: true));

        // 모듈/플러그인이 validation rules 를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.language_packs.install_from_url_validation_rules', $rules, $this);
    }

    /**
     * 검증 메시지를 정의합니다.
     *
     * @return array<string, string> 검증 메시지
     */
    public function messages(): array
    {
        return $this->packageIntegrityMessages() + [
            'url.required' => __('language_packs.validation.url_required'),
            'url.url' => __('language_packs.validation.url_invalid'),
            'checksum.regex' => __('language_packs.validation.checksum_invalid'),
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
