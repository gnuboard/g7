/**
 * @file Icon.a11y.test.tsx
 * @description admin_basic Icon — 꾸밈 아이콘 숨김 (7.1.0 영역 J D-J4)
 *
 * 이름 없는 아이콘에 글리프 이름(영어)을 이름표로 달아 버튼 이름이 「refresh 업데이트 확인」·「trash can」·「ellipsis」 로
 * 읽혔다. ariaLabel 을 받은 아이콘만 이미지로 읽히고 나머지는 접근성 트리에서 숨긴다.
 */

import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import { Icon } from '../Icon';

describe('Icon 접근성', () => {
  // 7.1.0 영역 J D-J4: 이름 없는 아이콘은 꾸밈으로 숨긴다. 종전에는 글리프 이름을 영어 이름표로 달아
  // 버튼 이름이 「refresh 업데이트 확인」·「trash can」·「ellipsis」 처럼 읽혔다(번역도 안 됨).
    /**
     * @scenario control=icon
     * @effects decorative_icon_hidden_from_a11y_tree
     */
    it('ariaLabel 이 없으면 꾸밈 아이콘으로 접근성 트리에서 숨긴다', () => {
      const { container } = render(<Icon name="shopping-cart" />);
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
      const { container } = render(<Icon name="user" ariaLabel="사용자 프로필" />);
      const icon = container.querySelector('i');
      expect(icon?.getAttribute('role')).toBe('img');
      expect(icon?.getAttribute('aria-label')).toBe('사용자 프로필');
      expect(icon?.hasAttribute('aria-hidden')).toBe(false);
    });

    /**
     * @scenario control=icon
     * @effects decorative_icon_hidden_from_a11y_tree
     */
    it('아이콘과 글자가 함께 있는 버튼의 이름에 글리프 이름이 섞이지 않는다', () => {
      const { getByRole } = render(<button type="button"><Icon name="refresh" /> 업데이트 확인</button>);
      expect(getByRole('button').textContent?.trim()).toBe('업데이트 확인');
      expect(getByRole('button', { name: '업데이트 확인' })).toBeTruthy();
    });

    /**
     * @scenario control=icon
     * @effects labelled_icon_exposes_given_name
     */
    it('레이아웃이 직접 준 aria-* 속성이 우선한다', () => {
      const { container } = render(<Icon name="check" aria-label="성공" role="img" aria-hidden={undefined} />);
      const icon = container.querySelector('i');
      expect(icon?.getAttribute('aria-label')).toBe('성공');
    });
  });
