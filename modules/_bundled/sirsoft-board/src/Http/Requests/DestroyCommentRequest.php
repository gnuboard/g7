<?php

namespace Modules\Sirsoft\Board\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 댓글 삭제 요청을 검증합니다.
 *
 * 비회원 댓글 삭제 시 본문 password 를 받는다 — 종전에는 컨트롤러가 전역
 * `request()->input('password')` 로 읽어(저장소 유일 사례) 배열 주입이 그대로
 * 비교 로직에 유입될 수 있었다. 소유권 판정 자체는 CommentService::canDelete 가
 * 담당하므로 여기서는 형식만 닫는다 (형제 store/update/verifyPassword 와 대칭).
 */
class DestroyCommentRequest extends FormRequest
{
    /**
     * 요청 권한 — 소유권/권한 판정은 Service(canDelete)가 담당.
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
     * @return array<string, array<int, string>> 검증 규칙
     */
    public function rules(): array
    {
        return [
            'password' => ['nullable', 'string', 'min:4', 'max:20'],
            'verification_token' => ['nullable', 'string', 'max:255'],
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
            'password' => __('sirsoft-board::validation.attributes.comment.password'),
        ];
    }
}
