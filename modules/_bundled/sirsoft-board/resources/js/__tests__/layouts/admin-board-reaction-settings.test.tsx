// e2e:allow 반응 사용 토글 + 유형 체크박스 저장은 본 레이아웃 회귀테스트(vitest) + MCP 실브라우저 검증(토글/체크/저장)으로 커버. 게시판 환경설정 화면 Playwright 인프라 부재
/**
 * 게시판 반응(추천/비추천) 설정 UI 회귀 가드 (이슈 #525 확정 02·11·13)
 *
 * @description
 * 고정 대상:
 *  1) 환경설정 기본값 탭(_tab_board_settings_basic)에 use_reaction 토글이 있고
 *     basic_defaults.use_reaction 에 바인딩된다 (확정 13, 새 게시판 기본값).
 *  2) 유형 체크박스 서브패널은 use_reaction on 일 때만 노출되며,
 *     reactionTypes 데이터소스를 iteration 해 basic_defaults.active_reaction_types(code 배열)를
 *     토글한다 (확정 02·11, DB 유형 목록 동적 렌더).
 *  3) 개별 게시판 편집 폼(admin_board_form/_tab_basic)에도 동일 구조가 use_reaction /
 *     active_reaction_types 에 바인딩되어 존재한다 (게시판별 override).
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

import settingsTab from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_basic.json';
import settingsLayout from '../../../layouts/admin/admin_board_settings.json';
import formTab from '../../../layouts/admin/partials/admin_board_form/_tab_basic.json';
import bulkApplyModal from '../../../layouts/admin/partials/admin_board_settings/_bulk_apply_modal.json';

const settingsJson = JSON.stringify(settingsTab);
const formJson = JSON.stringify(formTab);

describe('환경설정 기본값 탭 — 반응 사용 토글 + 유형 체크박스', () => {
    // @scenario case=settings_toggle_and_checkbox_persist
    // @effects settings_toggle_persists_use_reaction
    it('use_reaction 토글이 basic_defaults.use_reaction 에 바인딩된다', () => {
        expect(/"name":\s*"basic_defaults\.use_reaction"/.test(settingsJson)).toBe(true);
    });

    // @scenario case=settings_toggle_and_checkbox_persist
    // @effects settings_toggle_persists_use_reaction
    it('유형 체크박스 서브패널은 use_reaction on 일 때만 노출된다', () => {
        expect(/_local\.form\?\.basic_defaults\?\.use_reaction === true/.test(settingsJson)).toBe(true);
    });

    // @scenario case=settings_checkbox_reads_form_rows
    // @effects settings_editor_iterates_form_reaction_types_not_datasource
    it('체크박스가 데이터소스가 아니라 편집 중인 폼 배열을 순회한다', () => {
        // 아래 편집기에서 방금 추가한 유형을 저장 전에도 고를 수 있어야 하고, 지운 유형이
        // 선택지에 남으면 안 된다 — 그래서 별도 데이터소스가 아니라 같은 폼 배열을 본다.
        expect(/_local\.form\?\.basic_defaults\?\.reaction_types/.test(settingsJson)).toBe(true);
        expect(/"item_var":\s*"reactionType"/.test(settingsJson)).toBe(true);
    });

    // @scenario case=settings_checkbox_reads_form_rows
    // @effects settings_layout_drops_reaction_types_datasource
    it('환경설정 레이아웃에서 reactionTypes 데이터소스가 빠졌다', () => {
        // 남겨 두면 탭을 열 때마다 아무도 읽지 않는 요청이 한 번씩 더 나간다.
        const layoutJson = JSON.stringify(settingsLayout);

        expect(/"id":\s*"reactionTypes"/.test(layoutJson)).toBe(false);
        expect(/reaction-types/.test(layoutJson)).toBe(false);
    });

    // @scenario case=settings_checkbox_reads_form_rows
    // @scenario case=icon_image_priority_settings
    // @effects settings_checkbox_renders_image_icon_before_font_icon, settings_checkbox_renders_image_icon
    it('올려 둔 아이콘 이미지가 있으면 이미지로, 없으면 글꼴 아이콘으로 그린다', () => {
        // 두 노드가 배타 조건을 쓰지 않으면 같은 자리에 둘 다 뜬다.
        expect(/!!reactionType\.icon_url/.test(settingsJson)).toBe(true);
        expect(/!reactionType\.icon_url/.test(settingsJson)).toBe(true);
    });

    // @scenario case=settings_checkbox_reads_form_rows
    // @effects settings_checkbox_disabled_for_inactive_type
    it('비활성 유형은 고를 수 없고 그 사실을 배지로 말한다', () => {
        expect(/reactionType\.is_active === false/.test(settingsJson)).toBe(true);
        expect(/reaction_types\.badges\.inactive/.test(settingsJson)).toBe(true);
    });

    // @scenario case=settings_checkbox_reads_form_rows
    // @effects settings_layout_drops_reaction_types_datasource
    it('행이 하나도 없으면 먼저 유형을 만들라고 안내한다', () => {
        expect(/reaction_types\.none_hint/.test(settingsJson)).toBe(true);
    });

    // @scenario case=settings_toggle_and_checkbox_persist
    // @effects settings_checkbox_persists_active_reaction_types
    it('체크박스가 active_reaction_types(code 배열) 를 include/exclude 로 토글한다', () => {
        // 체크 상태: code 포함 여부
        expect(
            /\(_local\.form\?\.basic_defaults\?\.active_reaction_types \?\? \[\]\)\.includes\(reactionType\.code\)/.test(
                settingsJson,
            ),
        ).toBe(true);
        // 저장: 포함 시 filter 제거, 미포함 시 spread 추가 (upsert 토글)
        expect(/"form\.basic_defaults\.active_reaction_types"/.test(settingsJson)).toBe(true);
        expect(/filter\(c => c !== reactionType\.code\)/.test(settingsJson)).toBe(true);
    });
});

describe('개별 게시판 편집 폼 — 반응 사용 토글 + 유형 체크박스 (override)', () => {
    // @scenario case=new_board_seeds_reaction_defaults
    // @effects new_board_seeds_reaction_defaults_from_settings
    it('use_reaction 토글이 use_reaction 에 바인딩된다', () => {
        expect(/"name":\s*"use_reaction"/.test(formJson)).toBe(true);
    });

    // @scenario case=settings_toggle_and_checkbox_persist
    // @effects settings_checkbox_persists_active_reaction_types
    it('유형 체크박스는 use_reaction on 일 때만 노출되고 active_reaction_types 를 토글한다', () => {
        expect(/_local\.form\?\.use_reaction === true/.test(formJson)).toBe(true);
        // 게시판 폼은 환경설정과 달리 유형을 편집하지 않고 고르기만 하므로 데이터소스를 유지한다.
        expect(/reactionTypes\?\.data\?\.reaction_types/.test(formJson)).toBe(true);
        expect(
            /\(_local\.form\?\.active_reaction_types \?\? \[\]\)\.includes\(reactionType\.code\)/.test(formJson),
        ).toBe(true);
        expect(/"form\.active_reaction_types"/.test(formJson)).toBe(true);
    });

    // @scenario case=settings_checkbox_reads_form_rows
    // @scenario case=icon_image_priority_board_form
    // @effects board_form_checkbox_renders_image_icon
    it('게시판 폼 체크박스도 아이콘 이미지를 먼저 쓰고 없으면 글꼴 아이콘으로 떨어진다', () => {
        expect(/!!reactionType\.icon_url/.test(formJson)).toBe(true);
        expect(/!reactionType\.icon_url/.test(formJson)).toBe(true);
    });
});

describe('댓글 반응 토글 — 반응 사용의 하위 토글', () => {
    // @scenario case=comment_reaction_toggle_off_blocks
    // @effects comment_reaction_requires_parent_toggle
    it('환경설정 기본값 탭에 use_comment_reaction 토글이 있고 상위가 꺼지면 잠긴다', () => {
        expect(/"name":\s*"basic_defaults\.use_comment_reaction"/.test(settingsJson)).toBe(true);
        // 글 반응을 끄면 댓글 반응만 남을 수 없다 — 백엔드도 같은 판정을 한다.
        expect(/_local\.form\?\.basic_defaults\?\.use_reaction !== true/.test(settingsJson)).toBe(true);
    });

    // @scenario case=comment_reaction_toggle_off_blocks
    // @effects comment_reaction_requires_parent_toggle
    it('게시판 폼에도 같은 토글과 같은 잠금 규칙이 있다', () => {
        expect(/"name":\s*"use_comment_reaction"/.test(formJson)).toBe(true);
        expect(/_local\.form\?\.use_reaction !== true/.test(formJson)).toBe(true);
    });

    // @scenario case=settings_checkbox_reads_form_rows
    // @effects settings_layout_drops_reaction_types_datasource
    it('일괄 적용 모달이 두 토글을 기본 구획으로 분류한다', () => {
        const bulkJson = JSON.stringify(bulkApplyModal);

        expect(/use_reaction:'basic'/.test(bulkJson)).toBe(true);
        expect(/use_comment_reaction:'basic'/.test(bulkJson)).toBe(true);
    });
});
