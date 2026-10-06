/**
 * @file NotificationCenter.badgeDigits.test.tsx
 * @description 알림 종 배지 — 세 자리(99+)일 때 글꼴을 줄인다
 *
 * 배지는 20px 고정 원(`w-5 h-5`)에 12px 굵은 글씨라 `99+` 의 실제 폭(23.28px)이 원을 넘쳤다
 * (7.1.0 출시 전 점검 실측: scrollWidth 22 > clientWidth 20). 100 이상일 때만 9px 로 줄인다.
 */
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { NotificationCenter } from '../NotificationCenter';

describe('NotificationCenter 배지 자릿수', () => {
  /**
   * @scenario defect=count_badge_triple_digit_overflow
   * @effects triple_digit_badge_font_shrinks
   */
  it('세 자리(99+) 표시일 때 배지 글꼴을 9px 로 줄인다', () => {
    render(<NotificationCenter unreadCount={150} />);
    const badge = screen.getByText('99+');
    expect(badge.className).toContain('text-[9px]');
    expect(badge.className).not.toContain('text-xs');
    expect(badge.className).toContain('bg-red-500');
  });

  /**
   * @scenario defect=count_badge_triple_digit_overflow
   * @effects triple_digit_badge_font_shrinks
   */
  it('두 자리까지는 배지 글꼴이 12px(text-xs) 그대로다', () => {
    render(<NotificationCenter unreadCount={99} />);
    const badge = screen.getByText('99');
    expect(badge.className).toContain('text-xs');
    expect(badge.className).not.toContain('text-[9px]');
  });
});
