/**
 * 관리자 게시글 목록 「반응」 열 — 반응을 끈 게시판에서 열이 사라지는 계약.
 *
 * DataGrid 의 열 정의는 컴포넌트가 아니라 prop 배열이라 엔진의 `if` 게이트가 걸리지 않는다
 * (엔진은 컴포넌트 정의의 `if` 만 평가한다). 열을 감추는 값은 DataGrid 가 읽는 `hidden` 이다.
 * `if` 로 적어 두면 무시되어 반응을 끈 게시판에도 「반응」 열이 0 으로 남는다(K1 실측 2026-10-06).
 *
 * 저작본(.g7.html)의 기계 산출물인 `.json` 을 직접 읽는다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import { asArray, findById } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

const layout = require('../../../layouts/admin/admin_board_posts_index.json');

const grid = findById(layout, 'posts_data_grid');
const columns = asArray(grid?.props?.columns);
const reactionColumn = columns.find((c: any) => c.field === 'reaction_total');

describe('관리자 게시글 목록 — 반응 열', () => {
    // @scenario case=use_reaction_off_payload_omits_reaction
    // @effects admin_list_reaction_column_hidden_when_use_reaction_off
    it('반응 열은 DataGrid 가 읽는 hidden 으로 게시판 반응 설정을 따른다', () => {
        expect(reactionColumn).toBeTruthy();
        expect(reactionColumn.hidden).toBe('{{!posts?.data?.board?.settings?.use_reaction}}');
    });

    // @scenario case=use_reaction_off_payload_omits_reaction
    // @effects admin_list_reaction_column_hidden_when_use_reaction_off
    it('열 정의에 엔진이 평가하지 않는 if 를 쓰지 않는다', () => {
        expect(reactionColumn).not.toHaveProperty('if');
    });
});
