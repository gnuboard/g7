/**
 * @file HeaderCartBadge.test.tsx
 * @description PC 헤더 장바구니 배지 — 세 자리(99+)일 때 글꼴을 줄인다 (실제 Header 렌더)
 *
 * 배지는 20px 고정 원(`w-5 h-5`)에 12px 글씨라 `99+` 가 원을 넘쳤다(7.1.0 출시 전 점검 실측:
 * scrollWidth 21 > clientWidth 20). 100 이상일 때만 9px 로 줄인다.
 *
 * @vitest-environment jsdom
 */
import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import Header from '../Header';

beforeEach(() => {
  (window as any).G7Core = {
    t: (key: string) => key,
    // 데스크톱 폭 — 장바구니 배지가 있는 데스크톱 헤더 분기를 그린다
    useResponsive: () => ({ width: 1280, breakpoint: 'desktop' }),
    dispatch: vi.fn(),
    state: { getLocal: () => ({}), getGlobal: () => ({}) },
    getSlotContext: () => ({ getSlotComponents: () => [], subscribeToSlot: () => () => {}, isEnabled: true }),
  };
  (window as any).__slotContextValue = (window as any).G7Core.getSlotContext();
});

afterEach(() => {
  vi.clearAllMocks();
  delete (window as any).G7Core;
  delete (window as any).__slotContextValue;
});

describe('Header 장바구니 배지 자릿수', () => {
  /**
   * @scenario defect=count_badge_triple_digit_overflow
   * @effects triple_digit_badge_font_shrinks
   */
  it('세 자리(99+)이면 글꼴을 9px 로 줄인다', () => {
    render(<Header siteName="S" cartCount={150} availableLocales={['ko', 'en']} currentLocale="ko" />);
    const badge = screen.getByText('99+');
    expect(badge.className).toContain('text-[9px]');
    expect(badge.className).not.toContain('text-xs');
  });

  /**
   * @scenario defect=count_badge_triple_digit_overflow
   * @effects triple_digit_badge_font_shrinks
   */
  it('한두 자리이면 글꼴은 12px(text-xs) 그대로다', () => {
    render(<Header siteName="S" cartCount={5} availableLocales={['ko', 'en']} currentLocale="ko" />);
    const badge = screen.getByText('5');
    expect(badge.className).toContain('text-xs');
    expect(badge.className).not.toContain('text-[9px]');
  });
});
