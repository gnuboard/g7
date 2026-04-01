<?php

namespace Modules\Sirsoft\Board\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 비회원 게시글 비밀번호 검증 요청
 *
 * 비회원 게시글(비밀글)의 비밀번호를 검증하는 요청입니다.
 * 비밀번호 검증 성공 시 게시글 내용 열람이 가능합니다.
 */
class VerifyGuestPasswordRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed>
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
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.required' => __('sirsoft-board::messages.posts.password_required'),
        ];
    }

    /**
     * 검증할 필드의 이름을 커스터마이징
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'password' => __('sirsoft-board::validation.attributes.post.password'),
        ];
    }
}