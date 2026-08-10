/**
 * @file ProductCard.list-context.test.tsx
 * @description 상품 카드 클릭이 목록 컨텍스트를 상세 URL 로 나르는지 회귀 (#75)
 *
 * 결함: 상품 목록(`/shop/products?page=2&sort=price_asc`) 에서 카드를 클릭하면
 *   `ProductCard` 가 `G7Core.dispatch({ handler: 'navigate', params: { path } })` 만 호출해
 *   `mergeQuery` 없이 이동했다. 그 결과 상세 URL 에서 목록 상태가 통째로 사라지고,
 *   상세의 '뒤로가기'(`mergeQuery: true`)는 복원할 상태가 없어 1페이지로 되돌아갔다.
 *   레이아웃 JSON 이 아니라 컴포넌트 코드에 있던 leg 이라 audit 룰의 스캔 표면 밖이었다.
 *
 * 정정: 목록 클러스터 안에서 쓰는 자리만 `preserveListQuery` 로 옵트인한다.
 *   찜 목록·검색 결과에서 상품 상세로 나가는 이동은 다른 도메인으로의 이동이므로
 *   기본값(false)을 유지해 목록 상태를 승계하지 않는다.
 *
 * @scenario surface=product_grid|wishlist|search
 * @effects navigation_preserves_list_query
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, screen, cleanup } from '@testing-library/react';
import React from 'react';

import ProductCard from '../../components/composite/ProductCard';

const product = {
    id: 23,
    product_code: 'O58FN04YPDRSA20C',
    name: '기본 양말 5족',
    thumbnail_url: '/img/socks.png',
};

let dispatched: Array<Record<string, unknown>>;

beforeEach(() => {
    dispatched = [];
    (window as unknown as Record<string, unknown>).G7Core = {
        dispatch: (action: Record<string, unknown>) => {
            dispatched.push(action);
        },
    };
});

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

/**
 * 카드를 클릭하고 발행된 navigate 액션을 돌려줍니다.
 *
 * @returns navigate 액션 (없으면 undefined)
 */
function clickCardAndGetNav(): Record<string, unknown> | undefined {
    const card = screen.getAllByRole('button')[0];
    card.click();
    return dispatched.find((a) => a.handler === 'navigate');
}

describe('#75 상품 카드 클릭이 목록 컨텍스트를 상세로 나른다', () => {
    it('preserveListQuery 면 mergeQuery: true 와 빈 query 로 이동한다', () => {
        render(<ProductCard product={product as never} shopBase="/shop" preserveListQuery />);

        const nav = clickCardAndGetNav();

        expect(nav, 'navigate 액션이 발행되지 않음').toBeTruthy();
        expect((nav?.params as Record<string, unknown>).path).toBe('/shop/products/O58FN04YPDRSA20C');
        expect((nav?.params as Record<string, unknown>).mergeQuery).toBe(true);
        expect((nav?.params as Record<string, unknown>).query).toEqual({});
    });

    it('기본값(찜 목록·검색 결과)은 목록 상태를 승계하지 않는다', () => {
        render(<ProductCard product={product as never} shopBase="/shop" />);

        const nav = clickCardAndGetNav();

        expect(nav).toBeTruthy();
        expect((nav?.params as Record<string, unknown>).path).toBe('/shop/products/O58FN04YPDRSA20C');
        expect((nav?.params as Record<string, unknown>).mergeQuery).toBeUndefined();
    });

    it('onClick 콜백이 주어지면 navigate 를 발행하지 않는다 (기존 동작 유지)', () => {
        const onClick = vi.fn();
        render(<ProductCard product={product as never} shopBase="/shop" preserveListQuery onClick={onClick} />);

        screen.getAllByRole('button')[0].click();

        expect(onClick).toHaveBeenCalledWith(23);
        expect(dispatched.find((a) => a.handler === 'navigate')).toBeUndefined();
    });
});
