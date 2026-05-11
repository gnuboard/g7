/**
 * 상품 그리드 레이아웃 구조 검증 테스트
 *
 * @description
 * - _product_grid.json partial이 ProductCard 컴포넌트를 올바르게 참조하는지 확인
 * - 반복 렌더링(iteration)이 products 데이터소스를 참조하는지 확인
 * - 빈 상태 및 페이지네이션 조건부 렌더링 확인
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';

// 레이아웃 JSON 임포트
import productGrid from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/shop/list/_product_grid.json';

describe('상품 그리드 레이아웃 구조 검증', () => {
    it('최상위 컴포넌트가 Div여야 함', () => {
        expect(productGrid.type).toBe('basic');
        expect(productGrid.name).toBe('Div');
    });

    it('상품 반복 렌더링이 products 데이터를 참조해야 함', () => {
        const gridContainer = productGrid.children[0];
        expect(gridContainer.children).toBeDefined();

        const iterationNode = gridContainer.children[0];
        expect(iterationNode.iteration).toBeDefined();
        expect(iterationNode.iteration.source).toContain('products');
        expect(iterationNode.iteration.item_var).toBe('product');
    });

    it('ProductCard 컴포넌트를 사용해야 함', () => {
        const gridContainer = productGrid.children[0];
        const iterationNode = gridContainer.children[0];
        const productCard = iterationNode.children[0];

        expect(productCard.type).toBe('composite');
        expect(productCard.name).toBe('ProductCard');
    });

    it('ProductCard에 product 전체 객체가 전달되어야 함', () => {
        const gridContainer = productGrid.children[0];
        const iterationNode = gridContainer.children[0];
        const productCard = iterationNode.children[0];

        expect(productCard.props.product).toBe('{{product}}');
    });

    it('빈 상태 영역이 조건부로 표시되어야 함', () => {
        const emptyState = productGrid.children[1];
        expect(emptyState.if).toBeDefined();
        expect(emptyState.if).toContain('length === 0');
    });

    it('페이지네이션이 조건부로 표시되어야 함', () => {
        const pagination = productGrid.children[2];
        expect(pagination.if).toBeDefined();
        expect(pagination.if).toContain('last_page');
    });
});