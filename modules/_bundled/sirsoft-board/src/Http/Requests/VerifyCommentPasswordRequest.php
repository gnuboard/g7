<?php

namespace Modules\Sirsoft\Board\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 비회원 댓글 비밀번호 확인 요청 폼 검증
 */
class VerifyCommentPasswordRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * @return bool 권한 검증 결과 (권한 체크는 미들웨어 위임, 항상 true)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed> 필드별 검증 규칙
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string'],
        ];
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 규칙별 다국어 메시지
     */
    public function messages(): array
    {
        return [
            'password.required' => __('sirsoft-board::validation.comment.password.required'),
        ];
    }

    /**
     * 검증할 필드의 이름을 커스터마이징
     *
     * @return array<string, string> 필드별 표시명
     */
    public function attributes(): array
    {
        return [
            'password' => __('sirsoft-board::validation.attributes.comment.password'),
        ];
    }
}
