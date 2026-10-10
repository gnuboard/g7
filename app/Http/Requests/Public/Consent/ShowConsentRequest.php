<?php

namespace App\Http\Requests\Public\Consent;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 동의 스냅샷 조회 요청
 *
 * 입력 값이 없는 공개 조회입니다. 회원 식별은 `optional.sanctum` 이, 게스트 식별은 선택된 동의
 * 제공자(`ConsentManager::subjectKey()`)가 맡으므로 검증할 필드가 없습니다.
 */
class ShowConsentRequest extends FormRequest
{
    /**
     * 요청 권한을 확인합니다.
     *
     * 공개 조회 경로라 인증을 요구하지 않습니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙 (입력 없음)
     */
    public function rules(): array
    {
        return [];
    }
}
