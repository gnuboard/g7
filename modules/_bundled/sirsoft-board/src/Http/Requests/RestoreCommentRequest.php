<?php

namespace Modules\Sirsoft\Board\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 댓글 복원 요청 폼 검증
 *
 * 블라인드 처리된 댓글을 복원할 때 사용됩니다. 블라인드(BlindCommentRequest) 와 대칭으로
 * 복원 사유를 검증합니다.
 */
class RestoreCommentRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
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
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 오류 메시지 배열
     */
    public function messages(): array
    {
        return [
            'reason.string' => __('sirsoft-board::validation.restore.reason.string'),
            'reason.max' => __('sirsoft-board::validation.restore.reason.max'),
        ];
    }

    /**
     * 검증할 필드의 이름을 커스터마이징
     *
     * @return array<string, string> 필드명 → 표시명 매핑
     */
    public function attributes(): array
    {
        return [
            'reason' => __('sirsoft-board::validation.attributes.restore.reason'),
        ];
    }
}
