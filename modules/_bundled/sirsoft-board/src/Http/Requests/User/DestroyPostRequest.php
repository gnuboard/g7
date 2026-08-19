<?php

namespace Modules\Sirsoft\Board\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 게시글 삭제 요청을 검증합니다.
 *
 * 비회원 게시글 삭제 시 본문 password 또는 verification_token 을 받는다 —
 * 종전에는 무검증으로 읽혀 배열 주입이 비교 로직에 유입될 수 있었다.
 * 소유권 판정 자체는 컨트롤러(canModifyPost)가 담당하므로 여기서는 형식만 닫는다.
 */
class DestroyPostRequest extends FormRequest
{
    /**
     * 요청 권한 — 소유권/권한 판정은 컨트롤러(canModifyPost)가 담당.
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
     * @return array<string, array<int, mixed>> 검증 규칙
     */
    public function rules(): array
    {
        return [
            'password' => ['nullable', 'string', 'min:4'],
            'verification_token' => ['nullable', 'string', 'max:255'],
            'force_delete' => ['nullable', 'boolean'],
        ];
    }

    /**
     * 검증 오류 메시지에 쓰일 필드 표시명을 반환합니다.
     *
     * @return array<string, string> 필드별 표시명
     */
    public function attributes(): array
    {
        return [
            'password' => __('sirsoft-board::validation.attributes.post.password'),
        ];
    }
}
