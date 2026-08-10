/**
 * 개별 게시판 수정 화면 — 숫자 입력 경계값 계약 테스트 (#493 T8)
 *
 * 배경: 같은 게시판 설정 항목이 두 화면에 있다.
 *   - 일괄(모듈 기본값): `admin_board_settings` → 데이터소스 `settings`(initLocal "form")
 *   - 개별(게시판별)  : `admin_board_form`     → 데이터소스 `boards`(initLocal "form")
 *
 * 두 화면 모두 서버가 `data._meta.limits` 로 같은 경계값을 내려준다
 * (`BoardSettingsController::index()` / `BoardController::getFormData()`).
 * 그런데 일괄 화면만 그 값을 min/max 로 바인딩하고 개별 화면은 아무 경계도 렌더하지 않아,
 * 같은 항목인데 한 화면에서만 범위를 알 수 있는 비대칭이 생겼다.
 *
 * 저장은 `StoreBoardRequest`/`UpdateBoardRequest` 가 `ReadsBoardLimits` 로 동일하게 막으므로
 * 잘못된 값이 저장되지는 않는다. 문제는 사용자가 **제출 전에** 알 수 없다는 것이다.
 *
 * 회귀 차단 포인트:
 *   1. 개별 화면의 숫자 입력은 min/max 를 가진다 (미지정 금지).
 *   2. 그 값은 리터럴이 아니라 `_local.form._meta.limits.*` 표현식이다
 *      (리터럴이면 config 를 바꿔도 화면이 따라오지 않아 원래 결함으로 되돌아간다).
 *   3. 참조하는 limits 키는 일괄 화면이 쓰는 키와 동일하다 (두 화면이 갈라지지 않도록).
 */

import { describe, it, expect } from 'vitest';

import formTabList from '../../../layouts/admin/partials/admin_board_form/_tab_list.json';
import formTabPost from '../../../layouts/admin/partials/admin_board_form/_tab_post.json';

/**
 * [필드명, partial, limits 키 prefix]
 *
 * per_page_mobile 은 per_page 와 같은 범위를 공유한다(일괄 화면과 동일 규약).
 */
const fields: Array<[string, unknown, string]> = [
    ['per_page', formTabList, 'per_page'],
    ['per_page_mobile', formTabList, 'per_page'],
    ['min_title_length', formTabPost, 'min_title_length'],
    ['max_title_length', formTabPost, 'max_title_length'],
    ['min_content_length', formTabPost, 'min_content_length'],
    ['max_content_length', formTabPost, 'max_content_length'],
    ['new_display_hours', formTabPost, 'new_display_hours'],
    ['max_reply_depth', formTabPost, 'max_reply_depth'],
    ['min_comment_length', formTabPost, 'min_comment_length'],
    ['max_comment_length', formTabPost, 'max_comment_length'],
    ['max_comment_depth', formTabPost, 'max_comment_depth'],
    ['max_file_size', formTabPost, 'max_file_size'],
    ['max_file_count', formTabPost, 'max_file_count'],
];

/**
 * 레이아웃 트리에서 지정한 name 의 number 입력 props 를 찾습니다.
 *
 * @param node 탐색 대상 노드
 * @param name 찾을 입력 name
 * @returns 찾은 props (없으면 null)
 */
function findNumberInput(node: unknown, name: string): Record<string, unknown> | null {
    if (Array.isArray(node)) {
        for (const child of node) {
            const found = findNumberInput(child, name);
            if (found) return found;
        }

        return null;
    }

    if (node && typeof node === 'object') {
        const props = (node as Record<string, unknown>).props as Record<string, unknown> | undefined;

        if (props && props.type === 'number' && props.name === name) {
            return props;
        }

        for (const value of Object.values(node as Record<string, unknown>)) {
            const found = findNumberInput(value, name);
            if (found) return found;
        }
    }

    return null;
}

describe('개별 게시판 수정 화면 — 숫자 입력 경계값 계약 (#493 T8)', () => {
    it.each(fields)('%s — min/max 가 지정되어 있다', (field, layout) => {
        const props = findNumberInput(layout, field);

        expect(props, `${field} 입력을 찾지 못했습니다`).not.toBeNull();
        expect(props?.min, `${field}: min 미지정 — 사용자가 하한을 알 수 없습니다`).toBeDefined();
        expect(props?.max, `${field}: max 미지정 — 사용자가 상한을 알 수 없습니다`).toBeDefined();
    });

    it.each(fields)('%s — 경계값이 _meta.limits 표현식이다 (리터럴 금지)', (field, layout, limitsKey) => {
        const props = findNumberInput(layout, field);

        expect(String(props?.min)).toBe(
            `{{_local?.form?._meta?.limits?.${limitsKey}_min ?? ${fallbacks[`${limitsKey}_min`]}}}`
        );
        expect(String(props?.max)).toBe(
            `{{_local?.form?._meta?.limits?.${limitsKey}_max ?? ${fallbacks[`${limitsKey}_max`]}}}`
        );
    });
});

/**
 * 표현식 폴백 값 — `config/board.php` 의 limits 및 `ReadsBoardLimits` 기본치와 같은 값.
 *
 * 폴백까지 고정하는 이유: 폴백이 서버 기본치와 다르면 설정 응답이 늦거나 실패한 순간
 * 화면이 서버와 다른 경계를 보여 준다.
 */
const fallbacks: Record<string, number> = {
    per_page_min: 5,
    per_page_max: 100,
    min_title_length_min: 0,
    min_title_length_max: 200,
    max_title_length_min: 1,
    max_title_length_max: 1000,
    min_content_length_min: 0,
    min_content_length_max: 10000,
    max_content_length_min: 1,
    max_content_length_max: 100000,
    new_display_hours_min: 0,
    new_display_hours_max: 720,
    max_reply_depth_min: 1,
    max_reply_depth_max: 10,
    min_comment_length_min: 0,
    min_comment_length_max: 1000,
    max_comment_length_min: 1,
    max_comment_length_max: 10000,
    max_comment_depth_min: 0,
    max_comment_depth_max: 10,
    max_file_size_min: 1,
    max_file_size_max: 200,
    max_file_count_min: 1,
    max_file_count_max: 20,
};
