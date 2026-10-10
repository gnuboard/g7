<?php

namespace Modules\Sirsoft\Board\Http\Requests\Concerns;

/**
 * 게시판 설정 필드의 검증 표시명(:attribute)을 환경설정 라벨에서 가져옵니다.
 *
 * 정수·설정 필드가 기본 메시지로 떨어질 때 `:attribute` 가 영문 snake_case(「new display hours」)로
 * 보이지 않도록, 환경설정 기본값 라벨(`validation.attributes.settings` 의 `basic_defaults.*`)을
 * 그대로 쓴다. 게시판 생성 요청과 수정 요청이 같은 표시명을 쓰게 하려고 한곳에 둔다 — 종전에는
 * 수정 요청에만 있어 생성 화면의 오류 문구에 필드 키가 그대로 나왔다.
 */
trait MapsBoardSettingAttributes
{
    /**
     * 표시명을 매핑할 게시판 설정 필드 키 목록
     *
     * @var array<int, string>
     */
    private array $boardSettingAttributeFields = [
        'per_page', 'per_page_mobile', 'order_by', 'order_direction',
        'secret_mode', 'use_comment', 'use_reply', 'use_report',
        'use_scrap',
        'use_reaction', 'use_comment_reaction', 'active_reaction_types',
        'comment_order', 'show_view_count',
        'max_reply_depth', 'max_comment_depth',
        'min_title_length', 'max_title_length',
        'min_content_length', 'max_content_length',
        'min_comment_length', 'max_comment_length',
        'use_file_upload', 'max_file_size', 'max_file_count',
        'allowed_extensions', 'notify_admin_on_post', 'notify_author',
        'new_display_hours',
    ];

    /**
     * 게시판 설정 필드 → 표시명 매핑을 만듭니다.
     *
     * 라벨 배열의 키는 'basic_defaults.per_page' 형태(점 포함 단일 키)이므로
     * settings 배열을 통째로 받아 인덱싱한다(__() 의 점 경로 분해로는 도달 불가).
     *
     * @return array<string, string> 필드명 → 표시명
     */
    protected function boardSettingAttributes(): array
    {
        $settingLabels = __('sirsoft-board::validation.attributes.settings');

        if (! is_array($settingLabels)) {
            return [];
        }

        $attributes = [];

        foreach ($this->boardSettingAttributeFields as $field) {
            $labelKey = "basic_defaults.{$field}";

            if (isset($settingLabels[$labelKey])) {
                $attributes[$field] = $settingLabels[$labelKey];
            }
        }

        return $attributes;
    }
}
