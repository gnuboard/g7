/**
 * 게시판 환경설정 — 숫자 필드 범위 힌트 동적 바인딩 회귀 테스트 (이슈 #413)
 *
 * 배경: 환경설정 "게시판 설정" 탭의 숫자 입력 필드는 description 에 허용 범위를
 *   `({{min}}~{{max}})` 형태로 표시한다. 범위 값은 `config('sirsoft-board.limits')` 를
 *   SSoT 로 하여 BoardSettingsController.index() 가 `data._meta.limits` 로 노출하고,
 *   데이터소스 `settings`(initLocal "form") 를 통해 `_local.form._meta.limits` 로 바인딩된다.
 *
 * 회귀 차단 포인트:
 *   1. description text 가 `$t:...|min={{_local?.form?._meta?.limits?.{f}_min ?? N}}|max={{...}}`
 *      파라미터를 포함해야 한다. (파라미터 누락 시 lang 의 `{{min}}`/`{{max}}` 가 raw 노출)
 *   2. 경로는 코어 전역 `_global.settings` 와 ID 충돌하는 `settings.data._meta` 가 아닌
 *      `_local.form._meta` 여야 한다. (충돌 시 fallback 으로만 표시되거나 raw 노출)
 *   3. Input 의 min/max 도 동일 동적 표현식이어야 한다. (하드코딩 시 config 변경과 어긋남)
 *
 * 실제 사용 파일(부모 admin_board_settings.json 이 partial 로 include):
 *   _tab_board_settings_list / _post / _reply / _comment / _attachment
 *   (구 _tab_basic_defaults.json 은 더 이상 사용하지 않음 — 삭제됨)
 */

import { describe, it, expect } from 'vitest';

import tabList from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_list.json';
import tabPost from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_post.json';
import tabReply from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_reply.json';
import tabComment from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_comment.json';
import tabAttachment from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_attachment.json';

/**
 * [필드명, partial, limits 키] 매핑.
 * limits 키는 description/Input 이 참조하는 `_meta.limits.{키}_min/max` 의 키 prefix.
 * per_page_mobile 은 per_page 와 동일한 limits 범위(per_page_min/max)를 공유한다.
 */
const fields: Array<[string, unknown, string]> = [
    ['per_page', tabList, 'per_page'],
    ['per_page_mobile', tabList, 'per_page'],
    ['min_title_length', tabPost, 'min_title_length'],
    ['max_title_length', tabPost, 'max_title_length'],
    ['min_content_length', tabPost, 'min_content_length'],
    ['max_content_length', tabPost, 'max_content_length'],
    ['max_reply_depth', tabReply, 'max_reply_depth'],
    ['min_comment_length', tabComment, 'min_comment_length'],
    ['max_comment_length', tabComment, 'max_comment_length'],
    ['max_comment_depth', tabComment, 'max_comment_depth'],
    ['max_file_size', tabAttachment, 'max_file_size'],
    ['max_file_count', tabAttachment, 'max_file_count'],
];

describe('게시판 환경설정 — 범위 힌트 동적 바인딩 가드 (#413)', () => {
    it.each(fields)(
        '%s — description text 가 _local.form._meta.limits 파라미터를 포함함',
        (field, layout, limitsKey) => {
            const str = JSON.stringify(layout);
            // description 키에 |min=.../|max=... 파라미터가 _local.form._meta.limits 경로로 존재
            expect(str).toMatch(
                new RegExp(
                    `descriptions\\.${field}\\|min=\\{\\{_local\\?\\.form\\?\\._meta\\?\\.limits\\?\\.${limitsKey}_min`,
                ),
            );
            expect(str).toMatch(
                new RegExp(`max=\\{\\{_local\\?\\.form\\?\\._meta\\?\\.limits\\?\\.${limitsKey}_max`),
            );
        },
    );

    it.each(fields)(
        '%s — Input min/max 가 하드코딩 정수가 아닌 _local.form._meta.limits 동적 표현식임',
        (_field, layout, limitsKey) => {
            const str = JSON.stringify(layout);
            // Input 의 min/max 가 동적 표현식 (하드코딩 정수 금지)
            expect(str).toMatch(
                new RegExp(`"min":\\s*"\\{\\{_local\\?\\.form\\?\\._meta\\?\\.limits\\?\\.${limitsKey}_min`),
            );
            expect(str).toMatch(
                new RegExp(`"max":\\s*"\\{\\{_local\\?\\.form\\?\\._meta\\?\\.limits\\?\\.${limitsKey}_max`),
            );
        },
    );

    it.each(fields)(
        '%s — 코어 전역과 충돌하는 settings.data._meta 경로를 사용하지 않음',
        (_field, layout, limitsKey) => {
            const str = JSON.stringify(layout);
            // settings?.data?._meta 경로는 _global.settings(코어 사이트 설정)와 ID 충돌 → 금지
            expect(str).not.toMatch(
                new RegExp(`settings\\?\\.data\\?\\._meta\\?\\.limits\\?\\.${limitsKey}`),
            );
        },
    );
});
