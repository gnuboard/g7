/**
 * @file adminUserShippingCountryTab.test.ts
 * @description 관리자 회원 수정 화면 「배송국가」 책갈피 탭과 본문 카드의 표시 조건 일치 회귀
 *
 * 배송국가 칸은 해외배송을 켠 쇼핑몰에서만 카드를 낸다(`international_shipping_enabled`). 그런데
 * 책갈피 탭 항목은 `route?.id` 만 보고 늘 붙어, 해외배송을 끈 쇼핑몰에서는 「배송국가」 탭을 눌러도 갈 곳이
 * 없는 빈 탭이 됐다(2026-10-07 실측: 탭 활성 표시만 바뀌고 `#ext_user_shipping_country` 없음).
 * 탭과 카드는 같은 id 하나로 이어지므로 표시 조건도 같아야 한다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const EXTENSION = path.resolve(__dirname, '../../../extensions/admin-user-shipping-country-field.json');
const TAB_ID = 'ext_user_shipping_country';

/**
 * 트리를 순회하며 조건에 맞는 노드를 모읍니다.
 *
 * @param node 시작 노드
 * @param predicate 수집 조건
 * @return 일치 노드 목록
 */
function collectNodes(node: any, predicate: (n: any) => boolean): any[] {
    const found: any[] = [];
    const walk = (current: any): void => {
        if (Array.isArray(current)) {
            current.forEach(walk);

            return;
        }
        if (current && typeof current === 'object') {
            if (predicate(current)) {
                found.push(current);
            }
            Object.values(current).forEach(walk);
        }
    };
    walk(node);

    return found;
}

describe('회원 수정 「배송국가」 탭 표시 조건', () => {
    const ext = JSON.parse(fs.readFileSync(EXTENSION, 'utf8'));
    const tabItems = collectNodes(ext, (n) => n?.id === TAB_ID && typeof n?.label === 'string');
    // 본문 카드는 컴포넌트 노드의 props.id 로 탭과 이어지고, 표시 조건(`if`)은 그 노드에 붙는다
    const cards = collectNodes(ext, (n) => n?.props?.id === TAB_ID && typeof n?.if === 'string');

    /**
     * @scenario case=c01
     * @effects user_shipping_country_tab_renders
     */
    it('탭 항목과 본문 카드가 하나씩 있다', () => {
        expect(tabItems.length).toBe(1);
        expect(cards.length).toBe(1);
    });

    /**
     * @scenario case=c02
     * @effects user_shipping_country_tab_renders
     */
    it('해외배송을 끈 쇼핑몰에서는 탭도 붙지 않는다(카드와 같은 조건)', () => {
        const tabIf = String(tabItems[0].if ?? '');
        expect(tabIf).toContain('international_shipping_enabled === true');
        expect(tabIf).toContain('ecommerce_preferred_shipping_country !== undefined');
        expect(cards[0].if).toContain('international_shipping_enabled === true');
    });
});
