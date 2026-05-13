/**
 * 상품 목록 레이아웃 구조 검증 테스트
 *
 * @description
 * - 검색 필드 옵션값이 백엔드 validation과 일치하는지 확인
 * - 총 개수 바인딩이 올바른 API 응답 경로를 참조하는지 확인
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';

// 레이아웃 JSON 임포트
import productList from '../../../layouts/admin/admin_ecommerce_product_list.json';
import filterSection from '../../../layouts/admin/partials/admin_ecommerce_product_list/_partial_filter_section.json';

/** 재귀적으로 JSON 트리에서 특정 조건의 노드를 찾는 유틸리티 */
function findNodes(node: any, predicate: (n: any) => boolean, results: any[] = []): any[] {
    if (!node) return results;
    if (predicate(node)) results.push(node);
    if (node.children) {
        for (const child of node.children) {
            findNodes(child, predicate, results);
        }
    }
    return results;
}

/** JSON 전체에서 특정 문자열을 포함하는 text 속성을 찾는 유틸리티 */
function findTextNodes(obj: any, pattern: string, results: any[] = []): any[] {
    if (!obj) return results;
    if (typeof obj === 'object') {
        if (obj.text && typeof obj.text === 'string' && obj.text.includes(pattern)) {
            results.push(obj);
        }
        for (const key of Object.keys(obj)) {
            findTextNodes(obj[key], pattern, results);
        }
    }
    return results;
}

describe('상품 목록 레이아웃 검색 필드 검증', () => {
    it('검색 필드 Select 옵션에 product_code가 있어야 함 (code가 아닌)', () => {
        // 필터 섹션에서 searchField Select 컴포넌트 찾기
        const selectNodes = findNodes(filterSection, (n: any) =>
            n.name === 'Select' && n.props?.name === 'searchField'
        );

        expect(selectNodes.length).toBeGreaterThanOrEqual(1);

        const searchFieldSelect = selectNodes[0];
        const options = searchFieldSelect.props.options;

        // product_code 옵션이 존재해야 함
        const productCodeOption = options.find((o: any) => o.value === 'product_code');
        expect(productCodeOption).toBeDefined();

        // code 옵션이 존재하면 안 됨 (잘못된 값)
        const codeOption = options.find((o: any) => o.value === 'code');
        expect(codeOption).toBeUndefined();
    });

    it('검색 필드 옵션값이 백엔드 허용 목록과 일치해야 함', () => {
        const selectNodes = findNodes(filterSection, (n: any) =>
            n.name === 'Select' && n.props?.name === 'searchField'
        );

        const searchFieldSelect = selectNodes[0];
        const optionValues = searchFieldSelect.props.options.map((o: any) => o.value);

        // 백엔드 허용 목록 (ProductRepository): all, name, description, product_code, sku, barcode
        const allowedValues = ['all', 'name', 'description', 'product_code', 'sku', 'barcode'];

        for (const value of optionValues) {
            expect(allowedValues).toContain(value);
        }
    });
});

describe('상품 목록 레이아웃 검색 키워드 Enter 키 검색 검증', () => {
    it('검색 키워드 Input에 keypress Enter 액션이 있어야 함', () => {
        const inputNodes = findNodes(filterSection, (n: any) =>
            n.name === 'Input' && n.props?.name === 'searchKeyword'
        );

        expect(inputNodes.length).toBeGreaterThanOrEqual(1);

        const searchInput = inputNodes[0];

        // actions 배열이 존재해야 함
        expect(searchInput.actions).toBeDefined();
        expect(searchInput.actions.length).toBeGreaterThanOrEqual(1);

        // keypress Enter 액션이 존재해야 함
        const enterAction = searchInput.actions.find(
            (a: any) => a.type === 'keypress' && a.key === 'Enter'
        );
        expect(enterAction).toBeDefined();
        // actionRef로 named_actions 참조
        expect(enterAction.actionRef).toBe('searchProducts');
    });
});

describe('상품 목록 레이아웃 named_actions 검증', () => {
    it('부모 레이아웃에 named_actions.searchProducts가 정의되어 있어야 함', () => {
        const namedActions = (productList as any).named_actions;
        expect(namedActions).toBeDefined();
        expect(namedActions.searchProducts).toBeDefined();
    });

    it('searchProducts named_action이 올바른 navigate 핸들러를 가져야 함', () => {
        const searchProducts = (productList as any).named_actions.searchProducts;
        expect(searchProducts.handler).toBe('navigate');
        expect(searchProducts.params.path).toBe('/admin/ecommerce/products');
        expect(searchProducts.params.replace).toBe(true);
        expect(searchProducts.params.mergeQuery).toBe(true);
        expect(searchProducts.params.query.search_keyword).toBeDefined();
        expect(searchProducts.params.query.page).toBe(1);
    });

    it('검색 버튼도 actionRef로 searchProducts를 참조해야 함', () => {
        // 검색 버튼 찾기 (btn-primary)
        const searchButtons = findNodes(filterSection, (n: any) =>
            n.name === 'Button' && n.props?.className?.includes('btn-primary') &&
            n.text?.includes('search')
        );

        expect(searchButtons.length).toBeGreaterThanOrEqual(1);

        const searchButton = searchButtons[0];
        const clickAction = searchButton.actions.find((a: any) => a.type === 'click');
        expect(clickAction).toBeDefined();
        expect(clickAction.actionRef).toBe('searchProducts');
    });
});

describe('상품 목록 레이아웃 총 개수 표시 검증', () => {
    it('총 개수 바인딩이 products?.data?.pagination?.total 경로를 사용해야 함', () => {
        // total_count 텍스트 노드 찾기
        const totalCountNodes = findTextNodes(productList, 'total_count');

        expect(totalCountNodes.length).toBeGreaterThanOrEqual(1);

        const totalCountNode = totalCountNodes[0];

        // products?.data?.pagination?.total 경로를 사용해야 함
        expect(totalCountNode.text).toContain('products?.data?.pagination?.total');

        // products?.meta?.total (잘못된 경로)를 사용하면 안 됨
        expect(totalCountNode.text).not.toContain('products?.meta?.total');
    });
});
