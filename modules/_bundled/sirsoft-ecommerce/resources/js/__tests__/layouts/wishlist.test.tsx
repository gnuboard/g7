/**
 * 찜(위시리스트) 레이아웃 구조 검증 테스트
 *
 * @description
 * - _header.json 찜 버튼 구조 및 중복 클릭 방지 검증
 * - wishlist.json 데이터소스 및 API 엔드포인트 검증
 * - _list.json 찜 목록 그리드, 삭제 버튼, 빈 목록 표시 검증
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

import headerPartial from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/shop/detail/_header.json';
import wishlistLayout from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/mypage/wishlist.json';
import wishlistListPartial from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/mypage/wishlist/_list.json';

/**
 * 트리에서 조건을 만족하는 첫 노드 찾기 (재귀)
 */
function findFirstNode(node: any, predicate: (n: any) => boolean): any | null {
    if (!node) return null;
    if (predicate(node)) return node;
    if (Array.isArray(node.children)) {
        for (const child of node.children) {
            const found = findFirstNode(child, predicate);
            if (found) return found;
        }
    }
    return null;
}

/**
 * 액션 트리(중첩 sequence/conditions 포함)에서 특정 handler 의 액션 찾기
 */
function findHandlerInActions(actions: any[] | undefined, handler: string): any | null {
    if (!Array.isArray(actions)) return null;
    for (const action of actions) {
        if (action?.handler === handler) return action;
        const nestedSources = [
            action?.actions,
            action?.params?.actions,
        ];
        for (const conditionItem of action?.conditions ?? []) {
            // conditions 핸들러: { if, then } 형태
            if (conditionItem?.then) nestedSources.push([conditionItem.then]);
            if (conditionItem?.else) nestedSources.push([conditionItem.else]);
        }
        for (const nested of nestedSources) {
            const found = findHandlerInActions(nested, handler);
            if (found) return found;
        }
    }
    return null;
}

describe('상품 상세 찜 버튼 구조 검증 (_header.json)', () => {
    // 찜 버튼은 disabled 가 _local.wishlistLoading 인 Button 으로 식별 (위치 의존 제거)
    const wishlistButton = findFirstNode(
        headerPartial,
        (n: any) =>
            n?.name === 'Button' &&
            typeof n?.props?.disabled === 'string' &&
            n.props.disabled.includes('wishlistLoading'),
    );

    it('찜 버튼(Button + wishlistLoading disabled)이 존재해야 함', () => {
        expect(wishlistButton).not.toBeNull();
        expect(wishlistButton.type).toBe('basic');
    });

    it('찜 버튼 disabled 가 wishlistLoading 으로 묶여 중복 클릭이 방지되어야 함', () => {
        expect(wishlistButton.props.disabled).toContain('_local.wishlistLoading');
    });

    it('찜 아이콘이 isWishlisted 상태에 따라 iconStyle 이 토글되어야 함', () => {
        const icon = wishlistButton.children?.find((c: any) => c.name === 'Icon');
        expect(icon).toBeDefined();
        // 아이콘 자체는 'heart' 고정, iconStyle 표현식이 isWishlisted 를 참조
        expect(icon.props.name).toBe('heart');
        expect(icon.props.iconStyle).toContain('isWishlisted');
    });

    it('찜 버튼 클릭 액션이 conditions 핸들러로 비회원/회원 분기되어야 함', () => {
        const click = wishlistButton.actions?.find((a: any) => a.type === 'click');
        expect(click).toBeDefined();
        expect(click.handler).toBe('conditions');
        expect(Array.isArray(click.conditions)).toBe(true);
        expect(click.conditions.length).toBeGreaterThanOrEqual(2);
    });

    it('회원 분기 sequence 첫 setState 가 wishlistLoading=true 로 설정해야 함', () => {
        const click = wishlistButton.actions.find((a: any) => a.type === 'click');
        const setState = findHandlerInActions([click], 'setState');
        expect(setState).toBeDefined();
        expect(setState.params.wishlistLoading).toBe(true);
    });

    it('회원 분기에서 apiCall 로 wishlist/toggle API 가 호출되어야 함', () => {
        const click = wishlistButton.actions.find((a: any) => a.type === 'click');
        const apiCall = findHandlerInActions([click], 'apiCall');
        expect(apiCall).toBeDefined();
        expect(apiCall.target).toContain('/wishlist/toggle');
        expect(apiCall.params.method).toBe('POST');
    });

    it('apiCall onSuccess 에서 wishlistLoading 을 false 로 복원해야 함', () => {
        const click = wishlistButton.actions.find((a: any) => a.type === 'click');
        const apiCall = findHandlerInActions([click], 'apiCall');
        const setStateOnSuccess = apiCall.onSuccess.find((a: any) => a.handler === 'setState');
        expect(setStateOnSuccess).toBeDefined();
        expect(setStateOnSuccess.params.wishlistLoading).toBe(false);
    });

    it('apiCall onError 에서 wishlistLoading 을 false 로 복원해야 함', () => {
        const click = wishlistButton.actions.find((a: any) => a.type === 'click');
        const apiCall = findHandlerInActions([click], 'apiCall');
        const setStateOnError = apiCall.onError.find((a: any) => a.handler === 'setState');
        expect(setStateOnError).toBeDefined();
        expect(setStateOnError.params.wishlistLoading).toBe(false);
    });

    it('apiCall 에 auth_mode: required 가 설정되어 있어야 함', () => {
        const click = wishlistButton.actions.find((a: any) => a.type === 'click');
        const apiCall = findHandlerInActions([click], 'apiCall');
        // auth_required boolean → auth_mode: 'required' 표준 표기로 통일됨
        expect(apiCall.auth_mode).toBe('required');
    });
});

describe('마이페이지 찜 목록 레이아웃 검증 (wishlist.json)', () => {
    it('데이터소스가 올바른 API 엔드포인트를 사용해야 함', () => {
        const ds = wishlistLayout.data_sources[0];
        expect(ds.id).toBe('wishlist');
        expect(ds.endpoint).toContain('/api/modules/sirsoft-ecommerce/wishlist');
        expect(ds.method).toBe('GET');
        expect(ds.auto_fetch).toBe(true);
    });

    it('_user_base 레이아웃을 상속해야 함', () => {
        expect(wishlistLayout.extends).toBe('_user_base');
    });
});

describe('찜 목록 partial 구조 검증 (_list.json)', () => {
    it('blur_until_loaded 가 wishlist 데이터소스에 설정되어야 함', () => {
        expect(wishlistListPartial.blur_until_loaded).toBeDefined();
        expect(wishlistListPartial.blur_until_loaded.data_sources).toBe('wishlist');
    });

    it('찜 목록 그리드에 iteration 이 wishlist.data.data 를 source 로 사용해야 함', () => {
        // 트리 어디에 있든 iteration.source 가 wishlist.data.data 인 노드 검색
        const gridNode = findFirstNode(
            wishlistListPartial,
            (n: any) => typeof n?.iteration?.source === 'string'
                && n.iteration.source.includes('wishlist.data.data'),
        );
        expect(gridNode).not.toBeNull();
    });

    it('삭제 버튼이 wishlist DELETE API 를 호출해야 함', () => {
        // partial 내부 어디든 apiCall + DELETE + wishlist 경로를 호출하는 액션을 찾는다
        const json = JSON.stringify(wishlistListPartial);
        expect(json).toContain('"handler":"apiCall"');
        expect(json).toContain('/api/modules/sirsoft-ecommerce/wishlist/');
        expect(json).toContain('"method":"DELETE"');
    });

    it('빈 목록 분기 if 가 wishlist.data.data 를 참조해야 함', () => {
        const emptyNode = findFirstNode(
            wishlistListPartial,
            (n: any) => typeof n?.if === 'string'
                && n.if.includes('wishlist.data.data')
                && n.if.includes('length === 0'),
        );
        expect(emptyNode).not.toBeNull();
    });

    it('빈 목록에서 shopBase/products 로 navigate 하는 Button 이 존재해야 함', () => {
        // 경로가 _global.shopBase 동적 표현식 + /products 로 변경됨
        const navButton = findFirstNode(
            wishlistListPartial,
            (n: any) => n?.name === 'Button'
                && Array.isArray(n.actions)
                && n.actions.some(
                    (a: any) => a.handler === 'navigate'
                        && typeof a.params?.path === 'string'
                        && a.params.path.includes('shopBase')
                        && a.params.path.includes('/products'),
                ),
        );
        expect(navButton).not.toBeNull();
    });

    it('Pagination 컴포넌트가 존재하고 wishlist pagination 메타에 바인딩되어야 함', () => {
        const pagination = findFirstNode(
            wishlistListPartial,
            (n: any) => n?.name === 'Pagination',
        );
        expect(pagination).not.toBeNull();
        const propsJson = JSON.stringify(pagination.props ?? {});
        expect(propsJson).toContain('wishlist.data.pagination');
    });
});
