/**
 * 게시판 신고 관리 — 좁은 화면 일괄 처리 메뉴 잠금 회귀 테스트 (#703)
 *
 * @description
 * 데스크톱 일괄 처리 버튼 4개는 관리 권한이 없거나 선택이 0건이면 비활성인데, 좁은 화면(portable)의
 * 일괄 처리 드롭다운 항목 4개에는 조건이 없어 선택 없이 눌리고 서버 거절(ids 필수 422)을 무안내로 삼켰다.
 * 회원 목록 좁은 화면 메뉴와 같은 부류 — 드롭다운 항목이 데스크톱 버튼과 같은 조건으로 잠겨야 한다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';

import boardReports from '../../../layouts/admin/admin_board_reports_index.json';

/** JSON 전체에서 특정 id를 가진 컴포넌트를 찾습니다. */
function findComponentById(obj: any, id: string): any | null {
    if (!obj || typeof obj !== 'object') return null;
    if (obj.id === id) return obj;
    for (const key of Object.keys(obj)) {
        const found = findComponentById(obj[key], id);
        if (found) return found;
    }
    return null;
}

/** `{{ … }}` 바인딩 식을 주어진 문맥으로 평가합니다. */
function evaluateBinding(expression: string, context: Record<string, unknown>): unknown {
    const body = expression.replace(/^\{\{/, '').replace(/\}\}$/, '');
    // eslint-disable-next-line no-new-func
    return new Function(...Object.keys(context), `return (${body});`)(...Object.values(context));
}

const DESKTOP_BUTTON_IDS = ['bulk_review', 'bulk_reject', 'bulk_suspend', 'bulk_delete'];

describe('게시판 신고 관리 — 좁은 화면 일괄 처리 메뉴 잠금', () => {
    const dropdown = findComponentById(boardReports, 'mobile_bulk_action_dropdown');
    const items: any[] = dropdown?.props?.items ?? [];

    /**
     * @scenario can_manage=granted, selection=none
     *
     * @effects mobile_bulk_menu_follows_desktop_gate
     */
    it('좁은 화면 드롭다운이 일괄 처리 항목 4개를 갖는다', () => {
        expect(dropdown).not.toBeNull();
        expect(items.map((item) => item.value)).toEqual(['review', 'rejected', 'suspended', 'deleted']);
    });

    /**
     * @scenario can_manage=granted, selection=some
     *
     * @effects mobile_bulk_menu_follows_desktop_gate
     */
    it('항목마다 데스크톱 일괄 버튼과 같은 잠금 조건을 쓴다', () => {
        const desktopConditions = DESKTOP_BUTTON_IDS.map((id) => findComponentById(boardReports, id)?.props?.disabled);
        expect(new Set(desktopConditions).size).toBe(1);

        items.forEach((item) => {
            expect(item.disabled, `항목 ${item.value}`).toBe(desktopConditions[0]);
        });
    });

    /**
     * @scenario can_manage=denied, selection=some
     *
     * @effects mobile_bulk_menu_follows_desktop_gate
     */
    it.each([
        { label: '권한 있음·선택 0건', canManage: true, selectedIds: [], locked: true },
        { label: '권한 있음·선택 없음(undefined)', canManage: true, selectedIds: undefined, locked: true },
        { label: '권한 없음·선택 1건', canManage: false, selectedIds: [7], locked: true },
        { label: '권한 있음·선택 1건', canManage: true, selectedIds: [7], locked: false },
    ])('$label 이면 잠금=$locked', ({ canManage, selectedIds, locked }) => {
        const context = {
            reports: { data: { abilities: { can_manage: canManage } } },
            _global: { selectedIds },
        };

        items.forEach((item) => {
            expect(Boolean(evaluateBinding(item.disabled, context)), `항목 ${item.value}`).toBe(locked);
        });
    });
});
