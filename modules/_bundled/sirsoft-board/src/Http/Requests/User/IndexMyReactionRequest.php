<?php

namespace Modules\Sirsoft\Board\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Board\Http\Requests\Concerns\FallsBackUnknownSearchField;
use Modules\Sirsoft\Board\Http\Requests\Concerns\ProvidesFallbackRuleMessages;

/**
 * 마이페이지 「내 반응」 목록 조회 요청
 *
 * 검색 구분은 게시글 제목·내용을 나눠 고를 수 있게 하되(내 글·내 댓글 화면과 같은 규약),
 * 댓글에는 제목이 없으므로 제목 전용 검색에서는 댓글 분기를 켜지 않는다.
 */
class IndexMyReactionRequest extends FormRequest
{
    use FallsBackUnknownSearchField;
    use ProvidesFallbackRuleMessages;

    /**
     * 검색 대상 목록.
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
     * 허용 목록 밖의 검색 대상은 422 대신 기본 검색 대상(제목+내용)으로 바꿉니다.
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        $this->fallBackUnknownSearchField(self::SEARCH_FIELDS, 'title_content');
    }

    /**
     * 유효성 검사 규칙
     *
     * @return array<string, array<int, mixed>> 검증 규칙
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'search_field' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', self::SEARCH_FIELDS)],
            'reaction_type_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'target_type' => ['sometimes', 'nullable', 'string', 'in:post,comment,all'],
            'board_slug' => ['sometimes', 'nullable', 'string', 'max:50'],
            'sort' => ['sometimes', 'nullable', 'string', 'in:latest,oldest'],
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
            'search_field.in' => __('sirsoft-board::validation.reaction.search_field.in'),
            'target_type.in' => __('sirsoft-board::validation.reaction.target_type.in'),
        ];
    }

    /**
     * 검증 속성명
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'search_field' => __('sirsoft-board::validation.attributes.reaction.search_field'),
            'target_type' => __('sirsoft-board::validation.attributes.reaction.target_type'),
        ];
    }
}
