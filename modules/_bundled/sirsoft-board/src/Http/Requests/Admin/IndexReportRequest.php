<?php

namespace Modules\Sirsoft\Board\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 관리자 신고 목록 조회 요청 폼 검증
 *
 * 검색/필터/정렬/페이지네이션 파라미터의 형식을 검증합니다.
 */
class IndexReportRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인합니다.
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행합니다.
     *
     * @return bool 항상 true (권한은 미들웨어에서 검증)
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
        // status/target_type/target_status 는 단일 문자열(?status=pending) 또는
        // 배열(?status[]=pending&status[]=review) 두 형태 모두 허용한다.
        return [
            'filters' => ['nullable', 'array'],
            'status' => ['nullable'],
            'target_type' => ['nullable'],
            'target_status' => ['nullable'],
            'board_id' => ['nullable', 'integer'],
            'reported_at_from' => ['nullable', 'string'],
            'reported_at_to' => ['nullable', 'string'],
            'sort_by' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'string', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer'],
        ];
    }
}
