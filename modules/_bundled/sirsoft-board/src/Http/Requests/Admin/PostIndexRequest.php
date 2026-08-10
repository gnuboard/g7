<?php

namespace Modules\Sirsoft\Board\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 관리자 게시글 목록 조회 요청 폼 검증
 *
 * 목록 필터·정렬·페이지네이션 파라미터를 받습니다. 값은 Service 의 buildListParams 에서
 * 정규화되므로 여기서는 형식 수준만 검증합니다. 권한은 라우트 permission 미들웨어가 담당합니다.
 */
class PostIndexRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인합니다.
     *
     * @return bool 항상 true (권한은 미들웨어 체인에서 처리)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, mixed>> 검증 규칙
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
