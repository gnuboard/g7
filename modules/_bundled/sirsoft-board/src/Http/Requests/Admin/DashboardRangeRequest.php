<?php

namespace Modules\Sirsoft\Board\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 대시보드 조회 파라미터 검증
 *
 * 최신글/신고 카드의 limit 을 검증합니다.
 * 그래프 표시 일수는 모듈 설정(graph_days)이 SSoT 이므로 요청 파라미터로 받지 않습니다.
 */
class DashboardRangeRequest extends FormRequest
{
    /**
     * 요청 권한 확인 (권한은 미들웨어에서 처리).
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
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
