/**
 * @file IconButton.a11y.test.tsx
 * @description 글자 없는 아이콘 버튼의 이름 (7.1.0 영역 J D-J4)
 *
 * 목록 머리말의 새로고침 단추(IconButton, 글자 없음)가 아이콘이 꾸밈으로 숨겨진 뒤 이름 없는 단추로 읽혔다.
 * 직접 준 ariaLabel → 아이콘별 기본 이름(새로고침·추가·닫기 등, 번역 키) 순으로 이름과 툴팁을 단다.
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { IconButton } from '../IconButton';

describe('IconButton 이름', () => {
  beforeEach(() => {
    (window as any).G7Core = { t: (key: string) => ({ 'common.refresh': '새로고침', 'common.add': '추가' } as Record<string, string>)[key] ?? key };
  });
  afterEach(() => {
    delete (window as any).G7Core;
  });

  /**
   * @scenario control=icon_only_button
   * @effects icon_button_named_by_locale
   */
  it('글자 없는 새로고침 단추는 「새로고침」 이름과 툴팁을 갖는다', () => {
    render(<IconButton iconName={'refresh' as any} variant="outline" />);
    const b = screen.getByRole('button', { name: '새로고침' });
    expect(b.getAttribute('title')).toBe('새로고침');
  });

  /**
   * @scenario control=icon_only_button
   * @effects icon_button_named_by_locale
   */
  it('직접 준 ariaLabel 이 우선하고, 글자가 있으면 따로 이름을 달지 않는다', () => {
    render(
      <>
        <IconButton iconName={'refresh' as any} ariaLabel="목록 다시 불러오기" />
        <IconButton iconName={'plus' as any} label="스케줄 등록" />
      </>
    );
    expect(screen.getByRole('button', { name: '목록 다시 불러오기' })).toBeTruthy();
    const withText = screen.getByRole('button', { name: '스케줄 등록' });
    expect(withText.hasAttribute('aria-label')).toBe(false);
  });
});
