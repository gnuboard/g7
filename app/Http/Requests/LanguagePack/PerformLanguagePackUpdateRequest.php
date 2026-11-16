<?php

namespace App\Http\Requests\LanguagePack;

use App\Extension\HookManager;
use App\Http\Requests\Concerns\HasPackageIntegrityRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 언어팩 업데이트 실행 요청 (7.2.0).
 *
 * GitHub 에서 받은 새 판이 서명되지 않았을 때 409 경고 창의 「미서명으로 계속」이 같은 요청을 `allow_unsigned=1` 로
 * 다시 보낸다.
 *
 * @since 7.2.0
 */
class PerformLanguagePackUpdateRequest extends FormRequest
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
        $rules = $this->packageIntegrityRules();

        // 모듈/플러그인이 validation rules 를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.language_packs.perform_update_validation_rules', $rules, $this);
    }

    /**
     * 검증 메시지를 정의합니다.
     *
     * @return array<string, string> 검증 메시지
     */
    public function messages(): array
    {
        return $this->packageIntegrityMessages();
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
