<?php

namespace Modules\Sirsoft\Board\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 사용자 게시글 목록 조회 요청
 *
 * 이 요청이 닫는 것은 **검색 대상(search_field)** 하나다. 나머지 목록 파라미터
 * (page·per_page·정렬·카테고리)는 종전처럼 PostService::buildListParams 가 상·하한과
 * 폴백을 적용하므로 규칙을 중복해 두지 않는다 — 두 곳에서 같은 값을 검증하면
 * 한쪽만 바뀌었을 때 어느 쪽이 진짜 게이트인지 알 수 없게 된다.
 *
 * search_field 만 게이트에서 닫는 이유: 저장소의 검색 분기가 닫힌 집합이라
 * 모르는 값이 들어오면 조용히 'all' 로 떨어진다. 그러면 「내용」 으로 검색했는데
 * 제목 매칭이 섞여 나오는 식으로, 오류 없이 결과만 틀린다.
 */
class PostIndexRequest extends FormRequest
{
    /**
     * 허용하는 검색 대상 값
     *
     * @var array<int, string>
     */
    public const SEARCH_FIELDS = ['all', 'title_content', 'title', 'content', 'author'];

    /**
     * 권한 확인 (게시판 접근 권한은 컨트롤러·미들웨어가 담당).
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
            'search_field.in' => __('sirsoft-board::validation.post.search_field.in'),
        ];
    }
}
