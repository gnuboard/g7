/**
 * @file HeaderControls.a11y.test.tsx
 * @description 사용자 머리말 단추 이름 (7.1.0 영역 J D-J5, admin_basic 대칭)
 *
 * 테마 단추가 언어와 무관하게 「Toggle theme」 였고, 모바일 장바구니 단추는 아이콘(꾸밈)과 개수 배지뿐이라
 * 이름이 없었다(아이콘 이름 「shopping cart」 로 읽히던 것이 아이콘을 숨긴 뒤 빈 이름이 됨).
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ThemeToggle } from '../ThemeToggle';

describe('사용자 머리말 단추 이름', () => {
  beforeEach(() => {
    (window as any).G7Core = { t: (key: string) => (key === 'common.theme_select' ? '화면 테마' : key) };
  });
  afterEach(() => {
    delete (window as any).G7Core;
  });

  /**
   * @scenario control=header_controls
   * @effects header_controls_named_by_locale
   */
  it('테마 단추는 번역된 이름과 펼침 상태를 갖는다', () => {
    render(<ThemeToggle autoText="시스템" lightText="라이트" darkText="다크" />);
    const toggle = screen.getByRole('button', { name: '화면 테마' });
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    expect(screen.queryByRole('button', { name: 'Toggle theme' })).toBeNull();
  });

  /**
   * @scenario control=header_controls
   * @effects header_controls_named_by_locale
   */
  it('모바일 장바구니 단추(레이아웃)는 담긴 상품 수를 싣는 「장바구니」 이름을 갖는다', () => {
    // PC 머리말(Header.tsx)과 같은 nav.cart_with_count — 배지 숫자만으로는 무엇의 수인지 읽히지 않는다
    const layout = require('../../../../layouts/_user_base.json');
    const json = JSON.stringify(layout);
    const idx = json.indexOf('"mobile_cart_btn"');
    expect(idx).toBeGreaterThan(-1);
    expect(json.slice(Math.max(0, idx - 400), idx + 400)).toContain('$t:nav.cart_with_count|count=');
  });
});
