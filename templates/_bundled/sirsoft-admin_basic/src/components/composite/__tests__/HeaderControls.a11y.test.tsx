/**
 * @file HeaderControls.a11y.test.tsx
 * @description 관리자 머리말 단추 접근 가능한 이름 (7.1.0 영역 J D-J5 — 사이드바 토글 이름 없음·테마 단추 영어)
 *
 * - 테마 단추 이름이 언어와 무관하게 「Toggle theme」 였고, 고른 테마는 체크 아이콘(색)으로만 표시됐다.
 * - 아바타만 덮는 사용자 메뉴 단추(dropdownOnly)는 이름이 없었다.
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ThemeToggle } from '../ThemeToggle';
import { UserProfile } from '../UserProfile';

const DICT: Record<string, string> = {
  'common.theme_select': '화면 테마',
  'common.user_menu': '사용자 메뉴',
};

describe('관리자 머리말 단추 이름', () => {
  beforeEach(() => {
    (window as any).G7Core = { t: (key: string) => DICT[key] ?? key };
  });
  afterEach(() => {
    delete (window as any).G7Core;
  });

  /**
   * @scenario control=header_controls
   * @effects header_controls_named_by_locale
   */
  it('테마 단추는 번역된 이름을 갖고, 열린 목록은 고른 테마를 aria-pressed 로 알린다', () => {
    render(<ThemeToggle autoText="시스템 설정" lightText="라이트 모드" darkText="다크 모드" />);
    const toggle = screen.getByRole('button', { name: '화면 테마' });
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    fireEvent.click(toggle);
    expect(toggle.getAttribute('aria-expanded')).toBe('true');
    const pressed = screen.getAllByRole('button').filter((b) => b.getAttribute('aria-pressed') === 'true');
    expect(pressed).toHaveLength(1);
    expect(screen.queryByRole('button', { name: 'Toggle theme' })).toBeNull();
  });

  /**
   * @scenario control=header_controls
   * @effects header_controls_named_by_locale
   */
  it('아바타만 덮는 사용자 메뉴 단추(dropdownOnly)에 이름이 있다', () => {
    render(<UserProfile user={{ name: '관리자', email: 'a@example.com' } as any} dropdownOnly />);
    const button = screen.getByRole('button', { name: '사용자 메뉴' });
    expect(button.getAttribute('aria-expanded')).toBe('false');
  });
});
