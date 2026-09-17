<?php

namespace Modules\Sirsoft\Board\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Rules\AvailableReactionType;

/**
 * 게시글 반응 요청 폼 검증
 *
 * `reaction_type_id` 가 존재하는 활성 유형이면서 대상 게시판이 켠 유형인지
 * 검증합니다. 게시판의 활성 유형 code 목록(`active_reaction_types`)을 조회해
 * `AvailableReactionType` Rule 에 전달합니다.
 */
class ReactPostRequest extends FormRequest
{
    /**
     * 권한 체크는 라우트의 auth:sanctum 미들웨어에서 수행됩니다.
     *
     * @return bool 항상 true
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
        $slug = (string) ($this->route('slug') ?? '');
        $board = app(BoardRepositoryInterface::class)->findBySlug($slug);
        $activeCodes = $board?->active_reaction_types ?? [];

        return [
            'reaction_type_id' => ['required', 'integer', new AvailableReactionType($activeCodes)],
        ];
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reaction_type_id.required' => __('sirsoft-board::validation.reaction.reaction_type_id.required'),
            'reaction_type_id.integer' => __('sirsoft-board::validation.reaction.reaction_type_id.integer'),
        ];
    }

    /**
     * 검증할 필드의 이름을 커스터마이징
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reaction_type_id' => __('sirsoft-board::validation.attributes.reaction.reaction_type_id'),
        ];
    }
}
