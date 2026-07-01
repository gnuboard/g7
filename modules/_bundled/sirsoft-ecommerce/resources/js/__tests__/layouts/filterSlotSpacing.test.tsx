/**
 * 필터 슬롯 아웃렛 간격 회귀 테스트 (#408 퍼블리셔 시맨틱화 회귀)
 *
 * @description
 * #408 시맨틱화가 `.filter-row` 의 `mb-4`(행 자체 하단 마진)를 제거하고
 * 행 간 간격 책임을 부모 컨테이너 `.filter-grid`(gap-4)로 이전했다.
 * 그러나 이 필터들은 슬롯 시스템을 사용한다 — 행은 작성 위치(숨김 등록 영역
 * `hidden filter-grid`)가 아니라 `basic_filters`/`detail_filters` 슬롯
 * 아웃렛으로 투영되어 렌더된다. 아웃렛이 plain `flex flex-col`(gap 없음)이면
 * 행들이 0 간격으로 붙어 간격이 좁아진다.
 *
 * 따라서 슬롯 기반 필터의 모든 `basic_filters`/`detail_filters` 아웃렛은
 * 간격을 부여하는 컨테이너 자산(`filter-grid` = flex flex-col gap-4)을
 * className 으로 보유해야 한다.
 *
 * 회귀 상태(아웃렛이 `flex flex-col` 또는 className 없음)에서는 fail,
 * 수정 상태(`filter-grid` 부여)에서 green.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';

import productFilter from '../../../layouts/admin/partials/admin_ecommerce_product_list/_partial_filter_section.json';
import orderFilter from '../../../layouts/admin/partials/admin_ecommerce_order_list/_partial_filter_section.json';
import couponFilter from '../../../layouts/admin/partials/admin_ecommerce_promotion_coupon_list/_partial_filter_section.json';

/** JSON 트리 전체를 순회하며 조건을 만족하는 노드를 모두 수집 */
function collectNodes(obj: any, predicate: (n: any) => boolean, results: any[] = []): any[] {
    if (!obj || typeof obj !== 'object') return results;
    if (predicate(obj)) results.push(obj);
    for (const key of Object.keys(obj)) {
        const value = obj[key];
        if (Array.isArray(value)) {
            for (const item of value) collectNodes(item, predicate, results);
        } else if (value && typeof value === 'object') {
            collectNodes(value, predicate, results);
        }
    }
    return results;
}

/** slotId 가 필터 슬롯(basic_filters/detail_filters)인 SlotContainer 아웃렛 수집 */
function findFilterSlotOutlets(layout: any): any[] {
    return collectNodes(
        layout,
        (n) =>
            n?.name === 'SlotContainer' &&
            (n?.props?.slotId === 'basic_filters' || n?.props?.slotId === 'detail_filters')
    );
}

const FILTERS: Array<[string, any]> = [
    ['상품 관리(product)', productFilter],
    ['주문 관리(order)', orderFilter],
    ['쿠폰(coupon)', couponFilter],
];

describe('필터 슬롯 아웃렛 간격 자산 검증 (#408 회귀 방지)', () => {
    it.each(FILTERS)(
        '%s — 모든 필터 슬롯 아웃렛이 간격 자산(filter-grid)을 보유해야 함',
        (_label, layout) => {
            const outlets = findFilterSlotOutlets(layout);

            // basic_filters + detail_filters 두 아웃렛이 존재해야 함
            expect(outlets.length).toBeGreaterThanOrEqual(2);

            for (const outlet of outlets) {
                const className: string = outlet.props?.className ?? '';
                // 회귀: className 없음 또는 gap 없는 `flex flex-col` → 간격 0
                expect(className).toContain('filter-grid');
                // 회귀 패턴 직접 차단: gap 자산 없이 flex flex-col 만 있으면 안 됨
                expect(className.trim()).not.toBe('flex flex-col');
            }
        }
    );

    it.each(FILTERS)(
        '%s — 회귀 패턴(아웃렛이 plain flex flex-col)이 존재하지 않아야 함',
        (_label, layout) => {
            const outlets = findFilterSlotOutlets(layout);
            const regressed = outlets.filter((o) => {
                const cls: string = o.props?.className ?? '';
                return cls.trim() === '' || cls.trim() === 'flex flex-col';
            });
            expect(regressed).toHaveLength(0);
        }
    );
});
