<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 로그인 왕복 시작 요청.
 *
 * 복귀 주소는 여기서 형식만 보고, 같은 출처 상대 경로 제한은 `StateStore::safeRedirect()` 가 맡는다 — 틀린 주소는 거절하지
 * 않고 `/` 로 바꾼다(로그인 버튼이 오류 화면으로 끝나지 않게).
 *
 * @since 1.0.0
 */
class OAuthStartRequest extends FormRequest
{
    /** @var bool 검증 실패 여부 */
    private bool $invalid = false;

    /**
     * 누구나 시작할 수 있습니다(게스트 진입점).
     *
     * @return bool 허용 여부
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, string>> 규칙
     */
    public function rules(): array
    {
        return [
            'redirect' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * 검증 항목 라벨을 반환합니다.
     *
     * @return array<string, string> 필드 => 라벨
     */
    public function attributes(): array
    {
        return [
            'redirect' => __('sirsoft-social_login::validation.attributes.redirect'),
        ];
    }

    /**
     * 검증 실패를 예외 대신 표시로 남깁니다 — 복귀 주소가 틀리면 `/` 로 돌아가면 된다.
     *
     * @param  Validator  $validator  검증기
     * @return void 반환값 없음
     */
    protected function failedValidation(Validator $validator): void
    {
        $this->invalid = true;
    }

    /**
     * 복귀 주소를 반환합니다 (형식이 틀리면 null).
     *
     * @return string|null 복귀 주소
     */
    public function redirectPath(): ?string
    {
        $redirect = $this->query('redirect');

        return ! $this->invalid && is_string($redirect) ? $redirect : null;
    }
}
