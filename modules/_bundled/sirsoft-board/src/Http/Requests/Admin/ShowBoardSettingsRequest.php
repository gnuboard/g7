<?php

namespace Modules\Sirsoft\Board\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 게시판 환경설정 카테고리별 조회 요청
 *
 * 카테고리는 라우트 파라미터(`settings/{category}`)로 전달되며, 별도 입력 검증은
 * 없습니다. 권한 체크는 라우트의 permission 미들웨어
 * (`permission:admin,sirsoft-board.settings.read`)에서 수행됩니다.
 */
class ShowBoardSettingsRequest extends FormRequest
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
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        return [];
    }
}
