<?php

namespace Plugins\Sirsoft\Sso\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 로그인 왕복 시작 요청 (`GET /plugins/sirsoft-sso/login/{idp}`).
 *
 * 브라우저 이동이라 검증 실패를 오류 응답으로 내지 않는다 — 형식이 틀린 값은 버리고(기본값으로) 시작 서비스가 판정한다.
 *
 * @since 1.0.0
 */
class LoginStartRequest extends FormRequest
{
    /**
     * 누구나 시작할 수 있다 — link, reauth, test 는 시작 티켓이 권한이다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙.
     *
     * @return array<string, array<int, string>> 규칙
     */
    public function rules(): array
    {
        return [
            'intent' => ['nullable', 'string', 'max:10'],
            'guard' => ['nullable', 'string', 'in:user,admin'],
            'redirect' => ['nullable', 'string', 'max:2000'],
            'ticket' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * 칸 이름.
     *
     * @return array<string, string> 칸 => 이름
     */
    public function attributes(): array
    {
        return [
            'intent' => __('sirsoft-sso::validation.attributes.intent'),
            'guard' => __('sirsoft-sso::validation.attributes.guard'),
            'redirect' => __('sirsoft-sso::validation.attributes.redirect'),
            'ticket' => __('sirsoft-sso::validation.attributes.ticket'),
        ];
    }

    /**
     * 검증 실패는 버린다 — 틀린 칸만 비우고 계속한다(시작 서비스가 기본값과 오류 화면을 고른다).
     *
     * @param  Validator  $validator  검증기
     * @return void
     */
    protected function failedValidation(Validator $validator): void
    {
        foreach (array_keys($validator->errors()->toArray()) as $field) {
            $this->query->remove($field);
        }
    }
}
