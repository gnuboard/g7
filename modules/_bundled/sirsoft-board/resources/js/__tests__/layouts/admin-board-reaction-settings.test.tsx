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
import formTab from '../../../layouts/admin/partials/admin_board_form/_tab_basic.json';

const settingsJson = JSON.stringify(settingsTab);
const formJson = JSON.stringify(formTab);

describe('환경설정 기본값 탭 — 반응 사용 토글 + 유형 체크박스', () => {
    // @scenario case=settings_toggle_and_checkbox_persist
    // @effects settings_toggle_persists_use_reaction
    it('use_reaction 토글이 basic_defaults.use_reaction 에 바인딩된다', () => {
        expect(/"name":\s*"basic_defaults\.use_reaction"/.test(settingsJson)).toBe(true);
    });

    it('유형 체크박스 서브패널은 use_reaction on 일 때만 노출된다', () => {
        expect(/_local\.form\?\.basic_defaults\?\.use_reaction === true/.test(settingsJson)).toBe(true);
    });

    it('reactionTypes 데이터소스를 iteration 해 유형을 동적 렌더한다', () => {
        expect(/reactionTypes\?\.data\?\.reaction_types/.test(settingsJson)).toBe(true);
        expect(/"item_var":\s*"reactionType"/.test(settingsJson)).toBe(true);
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
    it('use_reaction 토글이 use_reaction 에 바인딩된다', () => {
        expect(/"name":\s*"use_reaction"/.test(formJson)).toBe(true);
    });

    it('유형 체크박스는 use_reaction on 일 때만 노출되고 active_reaction_types 를 토글한다', () => {
        expect(/_local\.form\?\.use_reaction === true/.test(formJson)).toBe(true);
        expect(/reactionTypes\?\.data\?\.reaction_types/.test(formJson)).toBe(true);
        expect(
            /\(_local\.form\?\.active_reaction_types \?\? \[\]\)\.includes\(reactionType\.code\)/.test(formJson),
        ).toBe(true);
        expect(/"form\.active_reaction_types"/.test(formJson)).toBe(true);
    });
});
