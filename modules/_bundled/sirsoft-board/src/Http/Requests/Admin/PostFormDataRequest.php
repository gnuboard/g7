<?php

namespace Modules\Sirsoft\Board\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 관리자 게시글 폼 데이터 조회 요청 폼 검증
 *
 * 생성/수정/답글 모드를 구분하기 위해 post_id 또는 parent_id 를 선택적으로 받습니다.
 * 권한은 라우트 permission 미들웨어가 담당합니다.
 */
class PostFormDataRequest extends FormRequest
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
            'post_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
