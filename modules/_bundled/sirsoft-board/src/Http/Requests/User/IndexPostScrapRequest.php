<?php

namespace Modules\Sirsoft\Board\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 스크랩 목록 조회 요청
 *
 * 마이페이지 스크랩 탭의 페이지네이션 파라미터 상·하한을 게이트에서 닫는다
 * (찜의 WishlistListRequest 와 동일 패턴).
 */
class IndexPostScrapRequest extends FormRequest
{
    /**
     * 권한 확인 (인증은 라우트 미들웨어가 담당).
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 유효성 검사 규칙
     *
     * @return array<string, array<int, string>> 검증 규칙
     */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
