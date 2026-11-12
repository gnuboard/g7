/**
 * ProductRail 컴포넌트 테스트
 *
 * @description 가로 상품 줄 컨테이너가 자식 카드를 그대로 감싸고(상품 링크가 봇 HTML 에 남는다),
 *   이전·다음 단추로 카드 한 칸씩 넘기며, 줄 끝에서 단추를 잠그는지 잠근다.
 *
 * @scenario-manifest tests/scenarios/shop-product-rail.yaml
 */

import React from 'react';
import { act, fireEvent, render } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ProductRail } from '../ProductRail';

/**
 * 트랙의 스크롤 치수를 흉내 낸다 (jsdom 은 레이아웃을 계산하지 않는다).
 *
 * @param track 트랙 요소
 * @param dims 스크롤 위치·보이는 폭·전체 폭
 */
function mockTrack(track: HTMLElement, dims: { scrollLeft: number; clientWidth: number; scrollWidth: number }): void {
  Object.defineProperty(track, 'scrollLeft', { configurable: true, get: () => dims.scrollLeft, set: (v) => { dims.scrollLeft = v; } });
  Object.defineProperty(track, 'clientWidth', { configurable: true, get: () => dims.clientWidth });
  Object.defineProperty(track, 'scrollWidth', { configurable: true, get: () => dims.scrollWidth });
}

function renderRail(extra: Record<string, unknown> = {}) {
  return render(
    <ProductRail title="인기상품" icon="flame" prevLabel="이전" nextLabel="다음" roleDescription="상품 줄" {...extra}>
      <div data-card="1"><a href="/shop/products/A">A</a></div>
      <div data-card="2"><a href="/shop/products/B">B</a></div>
      <div data-card="3"><a href="/shop/products/C">C</a></div>
    </ProductRail>,
  );
}

describe('ProductRail', () => {
  beforeEach(() => {
    (window as any).matchMedia = vi.fn().mockReturnValue({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() });
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  // @scenario case=c01
  // @effects rail_wraps_children_in_named_section
  it('제목으로 이름 붙은 section 안에 자식 카드를 그대로 그린다', () => {
    const { container } = renderRail({ id: 'rail-1', className: 'mb-12' });

    const section = container.querySelector('section')!;
    const heading = section.querySelector('h2')!;
    expect(section.id).toBe('rail-1');
    expect(section.className).toContain('mb-12');
    expect(section.getAttribute('aria-roledescription')).toBe('상품 줄');
    expect(section.getAttribute('aria-labelledby')).toBe(heading.id);
    expect(heading.textContent).toContain('인기상품');

    const track = section.querySelector('[data-product-rail-track]')!;
    expect(track.querySelectorAll('[data-card]')).toHaveLength(3);
    expect(track.querySelectorAll('a')).toHaveLength(3);
    expect(track.className).toContain('overflow-x-auto');
    expect(track.className).toContain('snap-x');
  });

  // @scenario case=c02
  // @effects rail_buttons_scroll_by_one_card, rail_buttons_lock_at_edges
  it('다음 단추는 첫 카드 폭 + 간격만큼 넘기고, 처음에는 이전 단추가 잠겨 있다', () => {
    const { container, getByLabelText } = renderRail();
    const track = container.querySelector('[data-product-rail-track]') as HTMLElement;
    const dims = { scrollLeft: 0, clientWidth: 400, scrollWidth: 1200 };
    mockTrack(track, dims);
    (track.firstElementChild as HTMLElement).getBoundingClientRect = () => ({ width: 184 } as DOMRect);
    vi.spyOn(window, 'getComputedStyle').mockReturnValue({ columnGap: '16px' } as CSSStyleDeclaration);
    const scrollBy = vi.fn();
    (track as any).scrollBy = scrollBy;

    act(() => { fireEvent.scroll(track); });

    const prev = getByLabelText('이전') as HTMLButtonElement;
    const next = getByLabelText('다음') as HTMLButtonElement;
    expect(prev.disabled).toBe(true);
    expect(next.disabled).toBe(false);
    expect(next.getAttribute('aria-controls')).toBe(track.id);

    fireEvent.click(next);
    expect(scrollBy).toHaveBeenCalledWith({ left: 200, behavior: 'smooth' });

    dims.scrollLeft = 200;
    act(() => { fireEvent.scroll(track); });
    expect(prev.disabled).toBe(false);
    fireEvent.click(prev);
    expect(scrollBy).toHaveBeenLastCalledWith({ left: -200, behavior: 'smooth' });
  });

  // @scenario case=c03
  // @effects rail_buttons_lock_at_edges
  it('줄 끝에 닿으면 다음 단추가 잠기고, 넘칠 것이 없으면 두 단추 모두 잠긴다', () => {
    const { container, getByLabelText } = renderRail();
    const track = container.querySelector('[data-product-rail-track]') as HTMLElement;
    const dims = { scrollLeft: 800, clientWidth: 400, scrollWidth: 1200 };
    mockTrack(track, dims);

    act(() => { fireEvent.scroll(track); });
    expect((getByLabelText('다음') as HTMLButtonElement).disabled).toBe(true);
    expect((getByLabelText('이전') as HTMLButtonElement).disabled).toBe(false);

    dims.scrollLeft = 0;
    dims.scrollWidth = 400;
    act(() => { fireEvent.scroll(track); });
    expect((getByLabelText('다음') as HTMLButtonElement).disabled).toBe(true);
    expect((getByLabelText('이전') as HTMLButtonElement).disabled).toBe(true);
  });

  // @scenario case=c04
  // @effects rail_respects_reduced_motion
  it('움직임 줄이기 설정이면 부드러운 스크롤을 쓰지 않는다', () => {
    (window as any).matchMedia = vi.fn().mockReturnValue({ matches: true, addEventListener: vi.fn(), removeEventListener: vi.fn() });
    const { container, getByLabelText } = renderRail();
    const track = container.querySelector('[data-product-rail-track]') as HTMLElement;
    mockTrack(track, { scrollLeft: 0, clientWidth: 400, scrollWidth: 1200 });
    (track.firstElementChild as HTMLElement).getBoundingClientRect = () => ({ width: 184 } as DOMRect);
    vi.spyOn(window, 'getComputedStyle').mockReturnValue({ columnGap: '16px' } as CSSStyleDeclaration);
    const scrollBy = vi.fn();
    (track as any).scrollBy = scrollBy;
    act(() => { fireEvent.scroll(track); });

    fireEvent.click(getByLabelText('다음'));

    expect(scrollBy).toHaveBeenCalledWith({ left: 200, behavior: 'auto' });
  });

  // @scenario case=c05
  // @effects rail_has_dark_pairs
  it('머리 줄·단추에 다크 모드 짝이 있고 라벨이 없으면 영문 기본값을 쓴다', () => {
    const { container } = render(
      <ProductRail title="Popular">
        <div>card</div>
      </ProductRail>,
    );

    expect(container.querySelector('h2')!.className).toContain('dark:text-white');
    const buttons = container.querySelectorAll('button');
    expect(buttons).toHaveLength(2);
    expect(buttons[0].getAttribute('aria-label')).toBe('Previous');
    expect(buttons[1].getAttribute('aria-label')).toBe('Next');
    expect(buttons[1].className).toContain('dark:border-gray-');
    expect(buttons[1].className).toContain('dark:text-gray-');
    expect(buttons[0].getAttribute('type')).toBe('button');
  });

  // @scenario case=c16
  // @effects rail_shows_two_three_four_cards_per_screen
  it('한 화면 카드 수는 좁은 화면 2·중간 3·넓은 화면 4장이다', () => {
    const { container } = renderRail();
    const track = container.querySelector('[data-product-rail-track]') as HTMLElement;
    const cls = track.className.split(/\s+/);
    // 간격 gap-4(16px) 를 장수로 나눠 뺀 열 폭 — 2장: 50% - 8px, 3장: 33.333% - 11px, 4장: 25% - 12px
    expect(cls).toContain('grid-flow-col');
    expect(cls).toContain('gap-4');
    expect(cls).toContain('auto-cols-[calc(50%_-_8px)]');
    expect(cls).toContain('sm:auto-cols-[calc(33.333%_-_11px)]');
    expect(cls).toContain('lg:auto-cols-[calc(25%_-_12px)]');
  });
});
