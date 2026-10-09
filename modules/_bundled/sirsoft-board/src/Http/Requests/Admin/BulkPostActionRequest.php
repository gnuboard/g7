<?php

namespace Modules\Sirsoft\Board\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Board\Http\Requests\Concerns\ProvidesFallbackRuleMessages;

/**
 * 관리자 게시글 일괄 처리 요청 폼 검증 (삭제·블라인드·복구 공통)
 *
 * exists 규칙을 두지 않는다 — 없는 ID 는 서비스가 건너뛰고 응답의 skipped 에 사유와 함께
 * 실린다. 422 로 되돌리면 목록이 살짝 낡았다는 이유로 나머지 정상 항목까지 막힌다.
 */
class BulkPostActionRequest extends FormRequest
{
    use ProvidesFallbackRuleMessages;

    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인합니다.
     *
     * @return bool 항상 true (권한은 라우트 permission 미들웨어에서 검증)
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
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * 검증 실패 메시지
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => __('sirsoft-board::validation.post.bulk.ids.required'),
            'ids.array' => __('sirsoft-board::validation.post.bulk.ids.array'),
            'ids.min' => __('sirsoft-board::validation.post.bulk.ids.min'),
            'ids.max' => __('sirsoft-board::validation.post.bulk.ids.max', ['max' => 100]),
            'ids.*.integer' => __('sirsoft-board::validation.post.bulk.ids.integer'),
            'ids.*.distinct' => __('sirsoft-board::validation.post.bulk.ids.distinct'),
            'reason.max' => __('sirsoft-board::validation.post.bulk.reason.max', ['max' => 1000]),
        ];
    }
}
