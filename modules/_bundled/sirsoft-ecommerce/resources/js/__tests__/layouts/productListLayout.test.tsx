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
    // 검색 키워드 Input 식별: change 액션이 filter.searchKeyword 를 쓰는 Input
    // (주문 목록과 동일 패턴 — name auto-binding 대신 value 명시 바인딩 + change 핸들러)
    const getSearchInput = () =>
        findNodes(
            filterSection,
            (n: any) =>
                n.name === 'Input' &&
                Array.isArray(n.actions) &&
                n.actions.some(
                    (a: any) =>
                        a.type === 'change' && a.params?.['filter.searchKeyword'],
                ),
        )[0];

    it('검색 키워드 Input의 Enter 는 sequence[setState 선행 → searchProducts] 여야 함', () => {
        const searchInput = getSearchInput();
        expect(searchInput).toBeDefined();
        expect(searchInput.actions.length).toBeGreaterThanOrEqual(1);

        // keypress Enter 액션
        const enterAction = searchInput.actions.find(
            (a: any) => a.type === 'keypress' && a.key === 'Enter'
        );
        expect(enterAction).toBeDefined();

        // race 회피: setState(검색어 명시 선행) → navigate(searchProducts) 순서의 sequence
        // (handleSequence 가 currentState/_computed 동기화 → navigate 가 최신값 읽음)
        expect(enterAction.handler).toBe('sequence');
        expect(Array.isArray(enterAction.actions)).toBe(true);

        const firstSetState = enterAction.actions[0];
        expect(firstSetState.handler).toBe('setState');
        expect(firstSetState.params?.['filter.searchKeyword']).toContain('$event.target.value');
        // setState 선행은 즉시 반영되어야 하므로 debounce 없음
        expect(firstSetState.debounce).toBeUndefined();

        const navStep = enterAction.actions.find((a: any) => a.actionRef === 'searchProducts');
        expect(navStep).toBeDefined();
    });

    it('검색 키워드 Input 의 change 액션에 debounce 가 설정되어야 함', () => {
        const searchInput = getSearchInput();
        expect(searchInput).toBeDefined();

        const changeAction = searchInput.actions.find(
            (a: any) =>
                a.type === 'change' &&
                a.handler === 'setState' &&
                a.params?.['filter.searchKeyword'],
        );
        expect(changeAction).toBeDefined();
        // 타이핑마다 무거운 리렌더가 발생하지 않도록 디바운스 필수
        expect(changeAction.debounce).toBeGreaterThan(0);
    });

    it('검색 키워드 Input 은 value 를 _local.filter.searchKeyword 에 바인딩해야 함 (URL 직접 진입 유지)', () => {
        const searchInput = getSearchInput();
        expect(searchInput).toBeDefined();
        // name auto-binding 제거 후에도 표시값/URL 유지를 위해 value 명시 바인딩 필수
        expect(searchInput.props?.value).toContain('_local.filter.searchKeyword');
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
