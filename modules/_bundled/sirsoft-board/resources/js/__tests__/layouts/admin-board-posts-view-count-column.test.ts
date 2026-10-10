/**
 * 관리자 게시글 목록 「조회수」 열 — 게시판의 「조회수 표시」 설정을 따르는 계약.
 *
 * DataGrid 의 열 정의는 컴포넌트가 아니라 prop 배열이라 엔진의 `if` 게이트가 걸리지 않는다
 * (엔진은 컴포넌트 정의의 `if` 만 평가한다). 열을 감추는 값은 DataGrid 가 읽는 `hidden` 이다.
 * 종전에는 `if="{{posts?.data?.board?.show_view_count}}"` 로 적어 두어 조건이 무시됐고, 경로도
 * 목록 응답에 없는 자리(board 바로 아래)를 가리켰다(K3 실측 2026-10-07: 응답 board 키는
 * slug·name·type·categories·show_category·settings 뿐). 응답은 settings.show_view_count 로 싣는다.
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
const viewCountColumn = columns.find((c: any) => c.field === 'view_count');

describe('관리자 게시글 목록 — 조회수 열', () => {
    // @scenario show_view_count=false, board_type=admin_list
    // @effects admin_list_view_count_column_follows_show_view_count
    it('조회수 열은 DataGrid 가 읽는 hidden 으로 응답의 settings.show_view_count 를 따른다', () => {
        expect(viewCountColumn).toBeTruthy();
        expect(viewCountColumn.hidden).toBe('{{posts?.data?.board?.settings?.show_view_count === false}}');
    });

    // @scenario show_view_count=unset, board_type=admin_list
    // @effects admin_list_view_count_column_follows_show_view_count
    it('열 정의에 엔진이 평가하지 않는 if 를 쓰지 않는다', () => {
        expect(viewCountColumn).not.toHaveProperty('if');
    });
});
