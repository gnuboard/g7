/**
 * 도서산간 추가배송비 템플릿 창 — 빈 목록 표시 레이아웃 계약 테스트
 *
 * @description
 * 템플릿이 없거나 검색 결과가 0건일 때 아이콘과 「등록된 템플릿이 없습니다」가 위아래로 쌓여야 한다.
 * 아이콘 클래스의 mx-auto·mb 는 Font Awesome 의 display(inline-block)에 무시되어 두 요소가 한 줄에
 * 겹쳐 붙었다(2026-09-26 실측) — 배치는 감싸는 줄이 맡는다.
 */

import { describe, expect, it } from 'vitest';

import extraFeeTemplateModal from '../../../layouts/admin/partials/admin_ecommerce_shipping_policy_form/_modal_extra_fee_template.json';

/**
 * 레이아웃 트리에서 id 로 노드를 찾는다.
 *
 * @param node 탐색 시작 노드
 * @param id 찾을 id
 * @returns 노드 또는 null
 */
function findById(node: any, id: string): any {
    if (!node || typeof node !== 'object') return null;
    if (!Array.isArray(node) && node.id === id) return node;
    for (const child of Object.values(node)) {
        const found = findById(child, id);
        if (found) return found;
    }

    return null;
}

describe('도서산간 템플릿 창 — 빈 목록 표시', () => {
    /**
     * @scenario empty_reason=search_no_match
     *
     * @effects empty_state_stacks_icon_above_message
     */
    it('빈 목록은 감싸는 줄이 아이콘과 문구를 세로로 쌓고, 아이콘에는 배치 클래스가 없다', () => {
        const emptyState = findById(extraFeeTemplateModal, 'empty_state');

        expect(emptyState).toBeTruthy();
        expect(String(emptyState.if ?? '')).toContain('length === 0');
        expect(String(emptyState.props?.className ?? '')).toMatch(/\bflex\b/);
        expect(String(emptyState.props?.className ?? '')).toMatch(/\bflex-col\b/);
        expect(String(emptyState.props?.className ?? '')).toMatch(/\bitems-center\b/);

        const icon = (emptyState.children ?? []).find((c: any) => c.name === 'Icon');
        expect(icon).toBeTruthy();
        expect(String(icon.props?.className ?? '')).not.toMatch(/\b(mx-auto|mb-\d+|block)\b/);
    });
});
