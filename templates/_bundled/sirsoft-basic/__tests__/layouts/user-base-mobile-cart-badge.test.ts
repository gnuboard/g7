/**
 * @file user-base-mobile-cart-badge.test.ts
 * @description 모바일 머리글 장바구니 수 배지 「99+」 넘침 — PC 머리글(Header.tsx) 수정의 모바일 짝 (7.1.0 D-N2 검증 후속)
 *
 * 결함: PC 머리글의 장바구니·알림 배지는 `min-w-5 px-1` 로 고쳤지만, 모바일 머리글은 `_user_base` 레이아웃이
 *   직접 그리는 배지라 고정 폭 `w-5`(20px) 원이 그대로 남아 「99+」(약 23px)가 원 밖으로 넘쳤다.
 *
 * 테스트 대상: layouts/_user_base.json
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import base from '../../layouts/_user_base.json';

type Node = Record<string, any>;

/**
 * 레이아웃 JSON 에서 조건에 맞는 첫 객체를 찾습니다.
 *
 * @param node 시작 노드
 * @param pred 판정 함수
 * @returns 찾은 노드 또는 null
 */
function find(node: unknown, pred: (n: Node) => boolean): Node | null {
    if (Array.isArray(node)) {
        for (const child of node) {
            const hit = find(child, pred);
            if (hit) return hit;
        }
    } else if (node && typeof node === 'object') {
        if (pred(node as Node)) return node as Node;
        for (const child of Object.values(node as Node)) {
            const hit = find(child, pred);
            if (hit) return hit;
        }
    }

    return null;
}

describe('사용자 공통 레이아웃 — 모바일 장바구니 수 배지', () => {
    /**
     * @scenario surface=mobile_cart_badge
     * @effects count_badge_grows_with_text
     */
    it('배지가 고정 폭 없이 최소 폭 + 좌우 여백으로 늘어난다', () => {
        const cartButton = find(base, (n) => n.id === 'mobile_cart_btn');
        expect(cartButton, 'mobile_cart_btn').not.toBeNull();

        const badge = find(cartButton, (n) => typeof n.if === 'string' && n.if.includes('_global.cartCount > 0'));
        expect(badge, '장바구니 수 배지').not.toBeNull();

        const classes = String(badge!.props?.className ?? '').split(/\s+/);
        expect(classes).toContain('min-w-5');
        expect(classes).toContain('px-1');
        expect(classes).not.toContain('w-5');
    });
});
