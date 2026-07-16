<?php

namespace Modules\Sirsoft\Board\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 게시글 복원 요청 검증 클래스
 *
 * 블라인드 또는 삭제된 게시글을 복원할 때 사용됩니다.
 */
class RestorePostRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인합니다.
     *
     * @return bool 권한 여부
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙을 정의합니다.
     *
     * @return array<string, mixed> 검증 규칙 배열
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * 검증 오류 메시지를 정의합니다.
     *
     * @return array<string, string> 오류 메시지 배열
     */
    public function messages(): array
    {
        return [
            'reason.string' => __('sirsoft-board::validation.restore_reason_string'),
            'reason.max' => __('sirsoft-board::validation.restore_reason_max'),
        ];
    }
}
