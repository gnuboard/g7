<?php

namespace Modules\Sirsoft\Board\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 관리자 게시글 폼 메타데이터 조회 요청 폼 검증
 *
 * 별도 입력 파라미터를 받지 않습니다. 권한은 라우트 permission 미들웨어가 담당하며,
 * 컨트롤러가 base Illuminate\Http\Request 를 주입받지 않도록 전용 FormRequest 를 둡니다.
 */
class PostFormMetaRequest extends FormRequest
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
     * @return array<string, array<int, mixed>> 검증 규칙 (요청 파라미터 없음)
     */
    public function rules(): array
    {
        return [];
    }
}
