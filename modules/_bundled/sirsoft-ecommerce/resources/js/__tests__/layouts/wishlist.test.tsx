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

import headerPartial from '../../../../../../templates/sirsoft-basic/layouts/partials/shop/detail/_header.json';
import wishlistLayout from '../../../../../../templates/sirsoft-basic/layouts/mypage/wishlist.json';
import wishlistListPartial from '../../../../../../templates/sirsoft-basic/layouts/partials/mypage/wishlist/_list.json';

describe('상품 상세 찜 버튼 구조 검증 (_header.json)', () => {
    const headerChildren = headerPartial.children[0].children;
    const wishlistButton = headerChildren[1]; // 두 번째 자식이 찜 버튼

    it('찜 버튼이 Button 컴포넌트여야 함', () => {
        expect(wishlistButton.name).toBe('Button');
        expect(wishlistButton.type).toBe('basic');
    });

    it('찜 버튼에 disabled 속성이 있어야 함 (중복 클릭 방지)', () => {
        expect(wishlistButton.props.disabled).toBe('{{_local.wishlistLoading}}');
    });

    it('찜 아이콘이 is_wishlisted 상태에 따라 변경되어야 함', () => {
        const icon = wishlistButton.children[0];
        expect(icon.name).toBe('Icon');
        expect(icon.props.name).toContain('is_wishlisted');
    });

    it('찜 버튼 클릭 시 setState로 wishlistLoading을 true로 설정해야 함', () => {
        const actions = wishlistButton.actions;
        const setStateAction = actions.find(
            (a: any) => a.handler === 'setState'
        );
        expect(setStateAction).toBeDefined();
        expect(setStateAction.params.values.wishlistLoading).toBe(true);
    });

    it('찜 버튼 클릭 시 apiCall로 toggle API를 호출해야 함', () => {
        const actions = wishlistButton.actions;
        const apiCallAction = actions.find(
            (a: any) => a.handler === 'apiCall'
        );
        expect(apiCallAction).toBeDefined();
        expect(apiCallAction.target).toContain('/wishlist/toggle');
        expect(apiCallAction.params.method).toBe('POST');
    });

    it('apiCall onSuccess에서 wishlistLoading을 false로 복원해야 함', () => {
        const apiCallAction = wishlistButton.actions.find(
            (a: any) => a.handler === 'apiCall'
        );
        const setStateOnSuccess = apiCallAction.onSuccess.find(
            (a: any) => a.handler === 'setState'
        );
        expect(setStateOnSuccess).toBeDefined();
        expect(setStateOnSuccess.params.values.wishlistLoading).toBe(false);
    });

    it('apiCall onError에서 wishlistLoading을 false로 복원해야 함', () => {
        const apiCallAction = wishlistButton.actions.find(
            (a: any) => a.handler === 'apiCall'
        );
        const setStateOnError = apiCallAction.onError.find(
            (a: any) => a.handler === 'setState'
        );
        expect(setStateOnError).toBeDefined();
        expect(setStateOnError.params.values.wishlistLoading).toBe(false);
    });

    it('apiCall에 auth_required가 설정되어 있어야 함', () => {
        const apiCallAction = wishlistButton.actions.find(
            (a: any) => a.handler === 'apiCall'
        );
        expect(apiCallAction.auth_required).toBe(true);
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
    const listContainer = wishlistListPartial.children[0];

    it('blur_until_loaded가 wishlist 데이터소스에 설정되어야 함', () => {
        expect(wishlistListPartial.blur_until_loaded).toBeDefined();
        expect(wishlistListPartial.blur_until_loaded.data_sources).toBe('wishlist');
    });

    it('찜 목록 그리드에 iteration이 설정되어 있어야 함', () => {
        const grid = listContainer.children.find(
            (c: any) => c.comment === '찜 목록 그리드'
        );
        expect(grid).toBeDefined();
        expect(grid.iteration).toBeDefined();
        expect(grid.iteration.source).toContain('wishlist.data.data');
    });

    it('삭제 버튼이 올바른 API 엔드포인트를 호출해야 함', () => {
        const grid = listContainer.children.find(
            (c: any) => c.comment === '찜 목록 그리드'
        );
        const itemWrapper = grid.children[0];
        const deleteButton = itemWrapper.children[1]; // ProductCard 다음
        const deleteAction = deleteButton.actions[0];

        expect(deleteAction.handler).toBe('apiCall');
        expect(deleteAction.target).toContain('/api/modules/sirsoft-ecommerce/wishlist/');
        expect(deleteAction.params.method).toBe('DELETE');
    });

    it('빈 목록 메시지가 표시되어야 함', () => {
        const emptyState = listContainer.children.find(
            (c: any) => c.comment === '찜 목록 없음'
        );
        expect(emptyState).toBeDefined();
        expect(emptyState.if).toContain('wishlist.data.data');
    });

    it('빈 목록에 쇼핑하기 버튼이 /shop으로 이동해야 함', () => {
        const emptyState = listContainer.children.find(
            (c: any) => c.comment === '찜 목록 없음'
        );
        const goShoppingBtn = emptyState.children.find(
            (c: any) => c.name === 'Button'
        );
        expect(goShoppingBtn).toBeDefined();
        const navAction = goShoppingBtn.actions[0];
        expect(navAction.handler).toBe('navigate');
        expect(navAction.params.path).toBe('/shop');
    });

    it('페이지네이션 컴포넌트가 존재해야 함', () => {
        const pagination = listContainer.children.find(
            (c: any) => c.comment === '페이지네이션'
        );
        expect(pagination).toBeDefined();
        expect(pagination.name).toBe('Pagination');
    });
});
