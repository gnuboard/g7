/**
 * MobileBuyBar 컴포넌트 테스트
 *
 * @description 좁은 화면 아래에 고정되는 구매바가 넓은 화면에서 숨고, 본문이 가려지지 않게 body 에 표시를 남기며,
 *   단추마다 레이아웃이 건 동작을 부르는지 잠근다. 좁은지 넓은지는 창 폭(CSS 미디어)이 아니라 엔진 폭
 *   (`G7Core.useResponsive().width`, 편집기 미리보기에서는 기기 폭)으로 가른다 — 경계는 Tailwind md(768px)와 같다.
 *
 * @scenario-manifest tests/scenarios/shop-mobile-buy-bar.yaml
 */

import { fireEvent, render } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { MobileBuyBar } from '../MobileBuyBar';

describe('MobileBuyBar', () => {
  let originalG7Core: any;
  /** 엔진 폭(G7Core.useResponsive().width) — 기본은 좁은 화면, 넓은 화면 시험만 바꾼다 */
  let viewportWidth = 375;

  beforeEach(() => {
    originalG7Core = (window as any).G7Core;
    viewportWidth = 375;
    (window as any).G7Core = { ...originalG7Core, t: (k: string) => k, useResponsive: () => ({ width: viewportWidth }) };
  });

  afterEach(() => {
    (window as any).G7Core = originalG7Core;
    document.body.className = '';
  });

  const bar = (c: HTMLElement) => c.querySelector('[data-mobile-buy-bar]') as HTMLElement;
  const button = (c: HTMLElement, action: string) => c.querySelector(`[data-buy-bar-action="${action}"]`) as HTMLButtonElement | null;

  // @scenario case=c01
  // @effects buy_bar_hidden_on_wide_screens
  it('아래에 고정되고 넓은 화면에서는 숨는다(다크 짝·안전 영역 여백 포함)', () => {
    const { container, rerender } = render(<MobileBuyBar variant="product" priceText="12,000원" />);
    const root = bar(container);
    const tokens = () => bar(container).className.split(/\s+/);

    // 767px — 좁은 화면: 아래에 고정
    viewportWidth = 767;
    rerender(<MobileBuyBar variant="product" priceText="12,000원" />);
    expect(tokens()).toEqual(expect.arrayContaining(['fixed', 'bottom-0', 'pb-[env(safe-area-inset-bottom)]']));
    expect(tokens()).not.toContain('hidden');
    expect(root.className).toMatch(/bg-white dark:bg-gray-800/);
    expect(root.className).toMatch(/border-gray-200 dark:border-gray-700/);
    expect(root.textContent).toContain('12,000원');

    // 768px — 넓은 화면: 숨는다(창 폭 미디어 접두 없이)
    viewportWidth = 768;
    rerender(<MobileBuyBar variant="product" priceText="12,000원" />);
    expect(tokens()).toContain('hidden');
    expect(tokens()).not.toContain('fixed');
    expect(bar(container).className).not.toMatch(/(^|\s)(sm|md|lg|xl|2xl):/);
  });

  // @scenario case=c02
  // @effects buy_bar_marks_body_while_mounted
  it('있는 동안 body 에 표시를 남기고 사라지면 지운다(본문·푸터가 가려지지 않게)', () => {
    const first = render(<MobileBuyBar variant="product" priceText="1" />);
    const second = render(<MobileBuyBar variant="cart" priceText="2" primaryLabel="주문하기" />);
    expect(document.body.classList.contains('g7-has-mobile-buybar')).toBe(true);

    first.unmount();
    // 다른 바가 남아 있으면 표시를 지우지 않는다
    expect(document.body.classList.contains('g7-has-mobile-buybar')).toBe(true);

    second.unmount();
    expect(document.body.classList.contains('g7-has-mobile-buybar')).toBe(false);
  });

  // @scenario case=c02
  // @effects buy_bar_marks_body_while_mounted
  it('넓은 화면에서는 바가 숨으므로 body 표시를 남기지 않고, 폭이 바뀌면 표시가 따라간다', () => {
    viewportWidth = 1024;
    const view = render(<MobileBuyBar variant="product" priceText="1" />);
    expect(document.body.classList.contains('g7-has-mobile-buybar')).toBe(false);

    viewportWidth = 375;
    view.rerender(<MobileBuyBar variant="product" priceText="1" />);
    expect(document.body.classList.contains('g7-has-mobile-buybar')).toBe(true);

    viewportWidth = 1024;
    view.rerender(<MobileBuyBar variant="product" priceText="1" />);
    expect(document.body.classList.contains('g7-has-mobile-buybar')).toBe(false);

    view.unmount();
    expect(document.body.classList.contains('g7-has-mobile-buybar')).toBe(false);
  });

  // @scenario case=c03
  // @effects buy_bar_product_buttons_call_layout_actions
  it('상품 바 — 찜·장바구니·바로구매가 각자의 동작을 부르고 찜은 눌림 상태를 알린다', () => {
    const onWish = vi.fn();
    const onCart = vi.fn();
    const onBuy = vi.fn();
    const { container, rerender } = render(
      <MobileBuyBar variant="product" priceText="12,000원" captionText="2개" wished={false} onWish={onWish} onCart={onCart} onBuy={onBuy} />,
    );

    const wish = button(container, 'wish')!;
    expect(wish.getAttribute('aria-pressed')).toBe('false');
    expect(wish.getAttribute('aria-label')).toBe('shop.product.add_to_wishlist');
    fireEvent.click(wish);
    fireEvent.click(button(container, 'cart')!);
    fireEvent.click(button(container, 'buy')!);
    expect(onWish).toHaveBeenCalledTimes(1);
    expect(onCart).toHaveBeenCalledTimes(1);
    expect(onBuy).toHaveBeenCalledTimes(1);
    expect(container.textContent).toContain('2개');

    rerender(<MobileBuyBar variant="product" priceText="12,000원" wished onWish={onWish} />);
    expect(button(container, 'wish')!.getAttribute('aria-pressed')).toBe('true');
    expect(button(container, 'wish')!.getAttribute('aria-label')).toBe('shop.product.remove_from_wishlist');

    rerender(<MobileBuyBar variant="product" priceText="12,000원" showWish={false} />);
    expect(button(container, 'wish')).toBeNull();
  });

  // @scenario case=c04
  // @effects buy_bar_respects_disabled_and_busy
  it('잠긴 단추는 동작하지 않고, 진행 중이면 모두 잠긴다', () => {
    const onCart = vi.fn();
    const onBuy = vi.fn();
    const { container, rerender } = render(<MobileBuyBar variant="product" priceText="1" cartDisabled buyDisabled={false} onCart={onCart} onBuy={onBuy} />);

    expect(button(container, 'cart')!.disabled).toBe(true);
    expect(button(container, 'buy')!.disabled).toBe(false);
    fireEvent.click(button(container, 'cart')!);
    expect(onCart).not.toHaveBeenCalled();

    rerender(<MobileBuyBar variant="product" priceText="1" busy onCart={onCart} onBuy={onBuy} />);
    expect(button(container, 'cart')!.disabled).toBe(true);
    expect(button(container, 'buy')!.disabled).toBe(true);
  });

  // @scenario case=c05
  // @effects buy_bar_shows_status_when_not_on_sale
  it('판매 중이 아니면 가격·찜과 판매 상태만 보인다', () => {
    const { container } = render(<MobileBuyBar variant="product" priceText="12,000원" soldOutLabel="품절" wished={false} />);

    expect(button(container, 'cart')).toBeNull();
    expect(button(container, 'buy')).toBeNull();
    expect(button(container, 'wish')).toBeTruthy();
    expect(container.querySelector('[data-buy-bar-status]')?.textContent).toBe('품절');
  });

  // @scenario case=c06
  // @effects buy_bar_cart_variant_has_one_primary
  it('장바구니 바 — 금액과 주 단추 하나만 있다', () => {
    const onPrimary = vi.fn();
    const { container, rerender } = render(<MobileBuyBar variant="cart" priceText="30,000원" primaryLabel="주문하기 (2)" onPrimary={onPrimary} />);

    expect(button(container, 'wish')).toBeNull();
    expect(button(container, 'cart')).toBeNull();
    const primary = button(container, 'primary')!;
    expect(primary.textContent).toContain('주문하기 (2)');
    fireEvent.click(primary);
    expect(onPrimary).toHaveBeenCalledTimes(1);

    rerender(<MobileBuyBar variant="cart" priceText="30,000원" primaryLabel="주문하기 (0)" primaryDisabled onPrimary={onPrimary} />);
    expect(button(container, 'primary')!.disabled).toBe(true);
  });
});
