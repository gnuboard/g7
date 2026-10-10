/**
 * @file Icon.a11y.test.tsx
 * @description sirsoft-basic Icon — 꾸밈 아이콘 숨김 회귀 테스트 (7.1.0 영역 J D-J4, admin_basic 대칭)
 *
 * 이름 없는 아이콘에 글리프 이름(영어)을 이름표로 달아, 마이페이지 알림 삭제 단추가 「trash can」 으로,
 * 글자 단추는 「heart 찜하기」 처럼 읽혔다. 이름(ariaLabel)을 받은 아이콘만 이미지로 읽히고 나머지는 숨긴다.
 */

import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import { Icon } from '../Icon';

describe('sirsoft-basic Icon 접근성', () => {
  /**
   * @scenario control=icon
   * @effects decorative_icon_hidden_from_a11y_tree
   */
  it('ariaLabel 이 없으면 꾸밈 아이콘으로 숨긴다', () => {
    const { container } = render(<Icon name="trash-can" />);
    const icon = container.querySelector('i');
    expect(icon?.getAttribute('aria-hidden')).toBe('true');
    expect(icon?.hasAttribute('aria-label')).toBe(false);
    expect(icon?.hasAttribute('role')).toBe(false);
  });

  /**
   * @scenario control=icon
   * @effects labelled_icon_exposes_given_name
   */
  it('ariaLabel 을 주면 그 이름의 이미지로 읽힌다', () => {
    const { container } = render(<Icon name="user" ariaLabel="내 정보" />);
    const icon = container.querySelector('i');
    expect(icon?.getAttribute('role')).toBe('img');
    expect(icon?.getAttribute('aria-label')).toBe('내 정보');
  });

  /**
   * @scenario control=icon
   * @effects decorative_icon_hidden_from_a11y_tree
   */
  it('아이콘과 글자가 함께 있는 버튼의 이름에 글리프 이름이 섞이지 않는다', () => {
    const { getByRole } = render(<button type="button"><Icon name="pen" /> 글쓰기</button>);
    expect(getByRole('button', { name: '글쓰기' })).toBeTruthy();
  });
});
