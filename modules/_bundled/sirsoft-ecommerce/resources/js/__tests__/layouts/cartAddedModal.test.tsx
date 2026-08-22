/**
 * 장바구니 담기 완료 모달 레이아웃 구조 검증 테스트
 *
 * @description
 * - _modal_cart_added.json 파셜이 올바른 Modal 구조인지 확인
 * - 배경 클릭 비활성화 확인
 * - 쇼핑 계속하기 버튼이 setState + closeModal 시퀀스를 실행하는지 확인
 * - 장바구니 이동 버튼이 로딩 스피너 + closeModal + navigate 시퀀스를 실행하는지 확인
 * - show.json에 modals 섹션이 올바르게 설정되었는지 확인
 * - _purchase_card.json의 onSuccess에서 옵션 초기화 + openModal을 호출하는지 확인
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

import modalCartAdded from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/shop/detail/_modal_cart_added.json';
import showLayout from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/shop/show.json';
import purchaseCard from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/shop/detail/_purchase_card.json';

describe('장바구니 담기 완료 모달 구조 검증', () => {
    it('모달이 올바른 id와 Modal 컴포넌트를 사용해야 함', () => {
        expect(modalCartAdded.id).toBe('cart_added_modal');
        expect(modalCartAdded.type).toBe('composite');
        expect(modalCartAdded.name).toBe('Modal');
    });

    it('모달 제목이 다국어 키를 사용해야 함', () => {
        expect(modalCartAdded.props.title).toContain('$t:');
        expect(modalCartAdded.props.title).toContain('cart_added_modal_title');
    });

    it('배경 클릭이 비활성화되어야 함', () => {
        expect(modalCartAdded.props.closeOnBackdropClick).toBe(false);
    });

    it('체크 아이콘이 존재해야 함', () => {
        const content = modalCartAdded.children[0];
        const iconContainer = content.children[0];
        const iconWrapper = iconContainer.children[0];
        const icon = iconWrapper.children[0];

        expect(icon.name).toBe('Icon');
        expect(icon.props.name).toBe('check');
    });

    it('메시지 텍스트가 다국어 키를 사용해야 함', () => {
        const content = modalCartAdded.children[0];
        const message = content.children[1];

        expect(message.text).toContain('$t:');
        expect(message.text).toContain('cart_added_modal_message');
    });

    it('쇼핑 계속하기 버튼이 setState(옵션 초기화) + closeModal 시퀀스를 실행해야 함', () => {
        const footer = modalCartAdded.children[1];
        const continueBtn = footer.children[0];

        expect(continueBtn.text).toContain('continue_shopping');

        const clickAction = continueBtn.actions[0];
        expect(clickAction.type).toBe('click');
        expect(clickAction.handler).toBe('sequence');
        expect(clickAction.actions).toHaveLength(2);

        // setState로 옵션 초기화 (additionalOptions 포함)
        const setStateAction = clickAction.actions[0];
        expect(setStateAction.handler).toBe('setState');
        expect(setStateAction.params.selectedOptionItems).toEqual([]);
        expect(setStateAction.params.currentSelection).toEqual({});
        expect(setStateAction.params.noOptionQuantity).toBe(1);
        expect(setStateAction.params.additionalOptions).toEqual({});

        // closeModal
        const closeAction = clickAction.actions[1];
        expect(closeAction.handler).toBe('closeModal');
    });

    it('쇼핑 계속하기 버튼이 로딩 중 비활성화되어야 함', () => {
        const footer = modalCartAdded.children[1];
        const continueBtn = footer.children[0];

        expect(continueBtn.props.disabled).toContain('isNavigatingToCart');
    });

    it('장바구니 이동 버튼이 setState(로딩) + closeModal + navigate 시퀀스를 실행해야 함', () => {
        const footer = modalCartAdded.children[1];
        const goToCartBtn = footer.children[1];

        const clickAction = goToCartBtn.actions[0];
        expect(clickAction.type).toBe('click');
        expect(clickAction.handler).toBe('sequence');
        expect(clickAction.actions).toHaveLength(3);

        // setState로 로딩 상태 설정
        expect(clickAction.actions[0].handler).toBe('setState');
        expect(clickAction.actions[0].params.target).toBe('global');
        expect(clickAction.actions[0].params.isNavigatingToCart).toBe(true);
        // closeModal
        expect(clickAction.actions[1].handler).toBe('closeModal');
        // navigate
        expect(clickAction.actions[2].handler).toBe('navigate');
        // shopBase prefix 가 도입되어 path 가 동적으로 조립됨
        expect(clickAction.actions[2].params.path).toBe('{{_global.shopBase}}/cart');
    });

    it('장바구니 이동 버튼이 로딩 중 비활성화되어야 함', () => {
        const footer = modalCartAdded.children[1];
        const goToCartBtn = footer.children[1];

        expect(goToCartBtn.props.disabled).toContain('isNavigatingToCart');
    });

    it('장바구니 이동 버튼에 로딩 스피너가 있어야 함', () => {
        const footer = modalCartAdded.children[1];
        const goToCartBtn = footer.children[1];

        // children으로 Icon(spinner) + Span(텍스트) 구조
        const spinnerIcon = goToCartBtn.children[0];
        expect(spinnerIcon.name).toBe('Icon');
        expect(spinnerIcon.props.name).toBe('spinner');
        expect(spinnerIcon.props.className).toContain('animate-spin');
        expect(spinnerIcon.if).toContain('isNavigatingToCart');
    });

    it('다크 모드 클래스가 모든 색상 요소에 적용되어야 함', () => {
        const json = JSON.stringify(modalCartAdded);

        expect(json).toContain('dark:bg-green-900');
        expect(json).toContain('dark:text-green-400');
        expect(json).toContain('dark:text-gray-300');
        expect(json).toContain('dark:border-gray-700');
    });
});

describe('show.json modals 섹션 검증', () => {
    it('modals 섹션에 cart_added_modal 파셜이 포함되어야 함', () => {
        const modals = (showLayout as any).modals;
        expect(modals).toBeDefined();
        expect(Array.isArray(modals)).toBe(true);

        const cartModal = modals.find((m: any) =>
            m.partial?.includes('_modal_cart_added.json')
        );
        expect(cartModal).toBeDefined();
    });
});

describe('show.json init_actions에서 isNavigatingToCart 초기화 검증', () => {
    it('init_actions에서 _global.isNavigatingToCart를 false로 초기화해야 함', () => {
        const initActions = showLayout.init_actions;
        const resetAction = initActions.find(
            (a: any) => a.handler === 'setState' && a.params?.target === 'global' && 'isNavigatingToCart' in (a.params ?? {}),
        );
        expect(resetAction).toBeDefined();
        expect(resetAction!.params.isNavigatingToCart).toBe(false);
    });
});

describe('_purchase_card.json 장바구니 버튼 onSuccess 검증', () => {
    it('장바구니 버튼 onSuccess에서 openModal을 호출해야 함', () => {
        const json = JSON.stringify(purchaseCard);

        expect(json).toContain('openModal');
        expect(json).toContain('cart_added_modal');
    });

    it('장바구니 버튼 onSuccess에서 cartCount를 업데이트해야 함', () => {
        const json = JSON.stringify(purchaseCard);

        expect(json).toContain('cartCount');
        expect(json).toContain('response.data.cart_count');
    });

    it('장바구니 버튼 onSuccess에서 옵션 상태를 초기화해야 함', () => {
        const json = JSON.stringify(purchaseCard);

        expect(json).toContain('"selectedOptionItems":[]');
        expect(json).toContain('"currentSelection":{}');
        expect(json).toContain('"noOptionQuantity":1');
        expect(json).toContain('"additionalOptions":{}');
    });

    it('장바구니 버튼 onSuccess에서 toast가 아닌 openModal을 사용해야 함', () => {
        const json = JSON.stringify(purchaseCard);

        expect(json).not.toContain('"cart_added"');
    });
});
