<?php

namespace Modules\Sirsoft\Board\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 스크랩 목록 조회 요청
 *
 * 마이페이지 스크랩 탭의 페이지네이션 파라미터 상·하한을 게이트에서 닫는다
 * (찜의 WishlistListRequest 와 동일 패턴).
 * search_field 는 검색 대상(제목+내용 / 제목 / 내용)을 가르는 닫힌 집합이다.
 */
class IndexPostScrapRequest extends FormRequest
{
    /**
     * 허용하는 검색 대상 값
     *
     * @var array<int, string>
     */
    public const SEARCH_FIELDS = ['title_content', 'title', 'content'];

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
     * @return array<string, array<int, mixed>> 검증 규칙
     */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:255'],
            'search_field' => ['nullable', 'string', Rule::in(self::SEARCH_FIELDS)],
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
            'search_field.in' => __('sirsoft-board::validation.scrap.search_field.in'),
        ];
    }
}
