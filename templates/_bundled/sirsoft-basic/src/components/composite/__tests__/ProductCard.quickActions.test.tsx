/**
 * ProductCard 장바구니·찜 단추 테스트
 *
 * @description 상품 카드의 곧바로 담기·찜 단추가 카드 링크 밖 형제로 서고(대화형 요소 중첩 금지),
 *   모르는 상태에서는 보이지 않으며, 누르면 카드 이동 없이 제 동작만 하는지 잠근다.
 *
 * @scenario-manifest tests/scenarios/product-card-quick-actions.yaml
 */

import React from 'react';
import { act, fireEvent, render, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ProductCard from '../ProductCard';
import { clearWishlistOverrides, setWishlistOverride } from '../../../support/wishlistOverrides';

const CART = '/api/modules/sirsoft-ecommerce/cart';

const product = (overrides: Record<string, unknown> = {}) => ({
  id: 7,
  product_code: 'P-7',
  name_localized: '면 손수건',
  thumbnail_url: '/img/handkerchief.jpg',
  selling_price: 3000,
  selling_price_formatted: '3,000원',
  sales_status: 'on_sale',
  options_count: 1,
  has_options: false,
  has_additional_options: false,
  is_wishlisted: false,
  ...overrides,
});

describe('ProductCard — 장바구니·찜 단추', () => {
  let originalG7Core: any;
  let globalState: Record<string, any>;
  let dispatch: ReturnType<typeof vi.fn>;
  let modalOpen: ReturnType<typeof vi.fn>;
  let toast: Record<string, ReturnType<typeof vi.fn>>;

  beforeEach(() => {
    clearWishlistOverrides();
    originalG7Core = (window as any).G7Core;
    globalState = { cartKey: 'ck_test', currentUser: { uuid: 'u-1' }, preferredCurrency: 'KRW' };
    modalOpen = vi.fn();
    toast = { success: vi.fn(), warning: vi.fn(), error: vi.fn() };
    dispatch = vi.fn(async (action: any) => {
      if (action.target === CART) return { success: true, data: { data: { cart_count: 1 } } };
      if (String(action.target).includes('/wishlist/toggle')) return { success: true, data: { data: { added: true } } };
      if (String(action.target).includes('/products/')) return { success: true, data: { data: { id: 7, options: [{ id: 1 }, { id: 2 }], option_groups: [{}] } } };
      return { success: true };
    });
    (window as any).G7Core = {
      ...originalG7Core,
      t: (key: string, params?: Record<string, unknown>) => (params?.name ? `${key}|${params.name}` : key),
      dispatch,
      toast,
      modal: { open: modalOpen },
      state: {
        get: () => globalState,
        set: (updates: Record<string, any>) => Object.assign(globalState, updates),
        subscribe: () => () => undefined,
      },
    };
  });

  afterEach(() => {
    (window as any).G7Core = originalG7Core;
  });

  const cartButton = (c: HTMLElement) => c.querySelector('[data-card-action="cart"]') as HTMLButtonElement | null;
  const wishButton = (c: HTMLElement) => c.querySelector('[data-card-action="wishlist"]') as HTMLButtonElement | null;
  const navigations = () => dispatch.mock.calls.filter((c) => c[0].handler === 'navigate');

  // @scenario case=c01
  // @effects card_actions_sit_outside_link
  it('단추가 카드 링크 밖 형제이고 이름·눌림 상태·다크 짝을 갖는다', () => {
    const { container } = render(<ProductCard product={product()} id="card-1" />);

    const cart = cartButton(container)!;
    const wish = wishButton(container)!;
    expect(cart.closest('a')).toBeNull();
    expect(wish.closest('a')).toBeNull();
    expect(cart.getAttribute('type')).toBe('button');
    expect(cart.getAttribute('aria-label')).toBe('shop.product_card.add_to_cart|면 손수건');
    expect(wish.getAttribute('aria-label')).toBe('shop.product_card.wishlist|면 손수건');
    expect(wish.getAttribute('aria-pressed')).toBe('false');
    expect(cart.className).toMatch(/bg-white\/\d+ dark:bg-gray-\d+/);

    // 겉 칸이 id 를 받고 카드 링크는 그 안에 그대로 있다
    const root = container.firstElementChild as HTMLElement;
    expect(root.id).toBe('card-1');
    expect(root.className).toContain('relative');
    expect(root.querySelector('a[href="/shop/products/P-7"]')).toBeTruthy();
  });

  // @scenario case=c02
  // @effects card_actions_hidden_when_turned_off
  it('showActions 를 끄면 단추가 없다', () => {
    const { container } = render(<ProductCard product={product()} showActions={false} />);

    expect(cartButton(container)).toBeNull();
    expect(wishButton(container)).toBeNull();
  });

  // @scenario case=c03
  // @effects wishlist_button_needs_known_state
  it('찜 여부를 모르는 응답이면 찜 단추를 그리지 않는다', () => {
    const { container } = render(<ProductCard product={product({ is_wishlisted: undefined })} />);

    expect(wishButton(container)).toBeNull();
    expect(cartButton(container)).toBeTruthy();
  });

  // @scenario case=c04
  // @effects cart_button_needs_purchasable_product
  it.each([
    ['구매할 수 있는 옵션이 없음', { options_count: 0 }],
    ['품절', { sales_status: 'sold_out' }],
  ])('%s → 담기 단추가 없다', (_label, overrides) => {
    const { container } = render(<ProductCard product={product(overrides)} />);

    expect(cartButton(container)).toBeNull();
    expect(wishButton(container)).toBeTruthy();
  });

  // @scenario case=c05
  // @effects cart_button_adds_without_navigating
  it('담기를 누르면 장바구니 요청만 가고 상세로 이동하지 않는다', async () => {
    const { container } = render(<ProductCard product={product()} />);

    await act(async () => {
      fireEvent.click(cartButton(container)!);
    });

    await waitFor(() => expect(dispatch.mock.calls.some((c) => c[0].handler === 'apiCall' && c[0].target === CART)).toBe(true));
    expect(navigations()).toHaveLength(0);
    expect(toast.success).toHaveBeenCalled();
    await waitFor(() => expect(cartButton(container)!.disabled).toBe(false));
  });

  // @scenario case=c06
  // @effects cart_button_opens_option_modal
  it('옵션이 둘 이상이면 담기 대신 옵션 선택 창을 연다', async () => {
    const { container } = render(<ProductCard product={product({ options_count: 3, has_options: true })} />);

    await act(async () => {
      fireEvent.click(cartButton(container)!);
    });

    await waitFor(() => expect(modalOpen).toHaveBeenCalledWith('quick_option_modal'));
    expect(dispatch.mock.calls.some((c) => c[0].target === CART)).toBe(false);
    expect(navigations()).toHaveLength(0);
  });

  // @scenario case=c07
  // @effects wishlist_button_needs_known_state
  it('비회원이 찜을 누르면 요청 없이 로그인 안내만 뜬다', async () => {
    globalState.currentUser = null;
    const { container } = render(<ProductCard product={product()} />);

    await act(async () => {
      fireEvent.click(wishButton(container)!);
    });

    expect(dispatch).not.toHaveBeenCalled();
    expect(toast.warning).toHaveBeenCalledWith('shop.wishlist_login_required');
  });

  // @scenario case=c08
  // @effects wishlist_button_reflects_shared_state
  it('찜을 누르면 눌림 상태가 바뀌고, 같은 상품의 다른 카드도 함께 바뀐다', async () => {
    const { container } = render(
      <div>
        <ProductCard product={product()} />
        <ProductCard product={product()} />
        <ProductCard product={product({ id: 8, product_code: 'P-8' })} />
      </div>,
    );
    const buttons = Array.from(container.querySelectorAll('[data-card-action="wishlist"]')) as HTMLButtonElement[];

    await act(async () => {
      fireEvent.click(buttons[0]);
    });

    await waitFor(() => expect(buttons[0].getAttribute('aria-pressed')).toBe('true'));
    expect(buttons[1].getAttribute('aria-pressed')).toBe('true');
    expect(buttons[2].getAttribute('aria-pressed')).toBe('false');

    // 다른 곳(상세 등)에서 바뀐 값도 카드가 따라간다
    act(() => setWishlistOverride(8, true));
    expect(buttons[2].getAttribute('aria-pressed')).toBe('true');
  });

  // @scenario case=c09
  // @effects card_actions_inert_in_editor
  it('편집기·미리보기에서는 단추를 그리되 동작하지 않는다', async () => {
    const { container, unmount } = render(<ProductCard product={product()} editorAttrs={{ 'data-editor-id': 'n1' } as any} />);
    await act(async () => {
      fireEvent.click(cartButton(container)!);
      fireEvent.click(wishButton(container)!);
    });
    expect(dispatch).not.toHaveBeenCalled();
    unmount();

    globalState.__isPreview = true;
    const preview = render(<ProductCard product={product()} />);
    await act(async () => {
      fireEvent.click(cartButton(preview.container)!);
    });
    expect(dispatch).not.toHaveBeenCalled();
  });
});
