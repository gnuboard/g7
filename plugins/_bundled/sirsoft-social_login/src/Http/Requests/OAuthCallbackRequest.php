<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 제공자 콜백 요청.
 *
 * 제공자가 보내는 값의 길이만 제한한다. 검증에 걸려도 422 JSON 이 아니라 로그인 화면 오류로 끝나야 하므로(브라우저가 직접
 * 오는 주소) 실패를 예외로 던지지 않고 빈 값으로 취급한다 — 그러면 state 확인에서 실패로 기록된다.
 *
 * @since 1.0.0
 */
class OAuthCallbackRequest extends FormRequest
{
    /** @var bool 검증 실패 여부 */
    private bool $invalid = false;

    /**
     * 누구나 부를 수 있습니다(제공자 → 브라우저 이동).
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
            'state' => ['nullable', 'string', 'max:128'],
            'code' => ['nullable', 'string', 'max:4096'],
            'error' => ['nullable', 'string', 'max:100'],
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
            'state' => __('sirsoft-social_login::validation.attributes.state'),
            'code' => __('sirsoft-social_login::validation.attributes.code'),
            'error' => __('sirsoft-social_login::validation.attributes.error'),
        ];
    }

    /**
     * 검증 실패를 예외 대신 표시로 남깁니다.
     *
     * @param  Validator  $validator  검증기
     * @return void 반환값 없음
     */
    protected function failedValidation(Validator $validator): void
    {
        $this->invalid = true;
    }

    /**
     * 검증을 통과했는지 반환합니다.
     *
     * @return bool 통과하면 true
     */
    public function isWellFormed(): bool
    {
        return ! $this->invalid;
    }
}
