<?php

namespace Plugins\Sirsoft\Sso\Http\Requests\Scim;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Plugins\Sirsoft\Sso\Exceptions\ScimException;

/**
 * SCIM 요청 검증의 공통 바탕 — 검증 실패를 G7 오류 형식이 아니라 SCIM 오류 스키마(400 `invalidValue`)로 돌려준다.
 *
 * 인증은 라우트 미들웨어(`ScimBearerAuth`)가 이미 했다.
 *
 * @since 1.0.0
 */
abstract class ScimRequest extends FormRequest
{
    /**
     * 요청 권한 — 미들웨어가 IdP 토큰을 확인했다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 오류 문구의 칸 라벨 — SCIM 속성 이름(`emails.*.value` 는 `emails_value` 키)으로 찾는다.
     *
     * @param  string  $field  검증 칸 이름
     * @return string 라벨
     */
    protected function label(string $field): string
    {
        return (string) __('sirsoft-sso::scim.attributes.'.str_replace(['.*.', '.'], '_', $field));
    }

    /**
     * 검증 실패를 SCIM 오류로 바꿉니다.
     *
     * @param  Validator  $validator  검증기
     * @return void
     *
     * @throws ScimException 400 invalidValue
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new ScimException(400, $this->scimTypeOnFailure(), __('sirsoft-sso::scim.error.invalid_value'));
    }

    /**
     * 검증 실패 때의 SCIM 오류 종류.
     *
     * @return string scimType
     */
    protected function scimTypeOnFailure(): string
    {
        return 'invalidValue';
    }
}
