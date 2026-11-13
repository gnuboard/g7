<?php

namespace Modules\Sirsoft\Board\Http\Requests\Concerns;

use App\DisplayName\DisplayNameArea;
use App\DisplayName\DisplayNameKindRegistry;
use Illuminate\Validation\Rule;

/**
 * 게시판 설정 폼의 작성자 표시 이름 칸과 비회원 작성자 항목 표 검증 규칙.
 *
 * 게시판 생성과 수정 요청이 같은 규칙을 쓴다.
 *
 * | 요청 키 | 모양 |
 * | --- | --- |
 * | `display_name_setting` | `{mode: inherit|fixed|member, kind}`. `fixed` 면 `kind` 필수이고 지금 쓸 수 있는 종류여야 한다 |
 * | `guest_author_fields` | `{항목 키: {post: {enabled, required}, comment: {enabled, required}}}` |
 *
 * 저장할 모양으로 정리하는 일(따름은 null, 잠긴 속성은 선언 값)은 서비스가 한다.
 */
trait ValidatesMemberFieldSettings
{
    /**
     * 작성자 표시 이름 칸과 비회원 작성자 항목 표의 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, mixed>> 요청 속성 경로 => 규칙
     */
    protected function memberFieldSettingRules(): array
    {
        return [
            'display_name_setting' => ['nullable', 'array'],
            'display_name_setting.mode' => ['nullable', 'string', Rule::in(DisplayNameArea::MODES)],
            'display_name_setting.kind' => [
                'nullable',
                'string',
                'max:100',
                'required_if:display_name_setting.mode,'.DisplayNameArea::MODE_FIXED,
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($this->input('display_name_setting.mode') !== DisplayNameArea::MODE_FIXED) {
                        return;
                    }
                    if (! is_string($value) || ! app(DisplayNameKindRegistry::class)->has($value)) {
                        $fail(__('sirsoft-board::validation.board.display_name_setting.kind_unavailable'));
                    }
                },
            ],
            'guest_author_fields' => ['nullable', 'array'],
            'guest_author_fields.*' => ['array'],
            'guest_author_fields.*.post' => ['nullable', 'array'],
            'guest_author_fields.*.post.enabled' => ['nullable', 'boolean'],
            'guest_author_fields.*.post.required' => ['nullable', 'boolean'],
            'guest_author_fields.*.comment' => ['nullable', 'array'],
            'guest_author_fields.*.comment.enabled' => ['nullable', 'boolean'],
            'guest_author_fields.*.comment.required' => ['nullable', 'boolean'],
        ];
    }

    /**
     * 두 칸의 검증 속성 이름을 반환합니다.
     *
     * @return array<string, string> 요청 속성 경로 => 이름
     */
    protected function memberFieldSettingAttributes(): array
    {
        return [
            'display_name_setting' => __('sirsoft-board::validation.attributes.board.display_name_setting'),
            'display_name_setting.mode' => __('sirsoft-board::validation.attributes.board.display_name_mode'),
            'display_name_setting.kind' => __('sirsoft-board::validation.attributes.board.display_name_kind'),
            'guest_author_fields' => __('sirsoft-board::validation.attributes.board.guest_author_fields'),
            'guest_author_fields.*' => __('sirsoft-board::validation.attributes.board.guest_author_field'),
            'guest_author_fields.*.post' => __('sirsoft-board::validation.attributes.board.guest_author_field_post'),
            'guest_author_fields.*.post.enabled' => __('sirsoft-board::validation.attributes.board.guest_author_field_post_enabled'),
            'guest_author_fields.*.post.required' => __('sirsoft-board::validation.attributes.board.guest_author_field_post_required'),
            'guest_author_fields.*.comment' => __('sirsoft-board::validation.attributes.board.guest_author_field_comment'),
            'guest_author_fields.*.comment.enabled' => __('sirsoft-board::validation.attributes.board.guest_author_field_comment_enabled'),
            'guest_author_fields.*.comment.required' => __('sirsoft-board::validation.attributes.board.guest_author_field_comment_required'),
        ];
    }
}
