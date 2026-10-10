/**
 * VisitorCounter 컴포넌트 테스트
 *
 * @description 홈 방문자 카운터가 네 숫자를 천 단위로 그리고, 라벨을 레이아웃에서 받아 쓰며,
 *   숫자가 아닌 값에 NaN 을 띄우지 않는지 잠근다. 데이터를 스스로 받지 않는 순수 표시 컴포넌트다.
 *
 * @effects counter_renders_four_numbers, counter_uses_layout_labels, counter_never_shows_nan
 */

import React from 'react';
import { render } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import { VisitorCounter } from '../VisitorCounter';

describe('VisitorCounter', () => {
  // @scenario screen=component, state=normal, viewport=1440, locale=ko
  // @effects counter_renders_four_numbers
  it('오늘·어제·최대·전체 네 숫자를 천 단위 구분으로 그린다', () => {
    const { container } = render(<VisitorCounter today={1284} yesterday={1146} max={3910} total={214551} />);

    const values = Array.from(container.querySelectorAll('[data-visitor-counter-value]')).map((el) => el.textContent);
    expect(values).toEqual(['1,284', '1,146', '3,910', '214,551']);
    expect(container.querySelectorAll('[data-visitor-counter-item]')).toHaveLength(4);
  });

  // @scenario screen=component, state=normal, viewport=1440, locale=en
  // @effects counter_uses_layout_labels
  it('라벨은 레이아웃이 넘긴 값을 쓰고 없으면 영문 기본값이다', () => {
    const { container, rerender } = render(
      <VisitorCounter today={1} labels={{ today: '오늘', yesterday: '어제', max: '최대', total: '전체' }} />,
    );
    expect(container.textContent).toContain('오늘');
    expect(container.textContent).toContain('전체');

    rerender(<VisitorCounter today={1} />);
    expect(container.textContent).toContain('Today');
    expect(container.textContent).toContain('Total');
  });

  // @scenario screen=component, state=empty, viewport=1440, locale=ko
  // @effects counter_never_shows_nan
  it('문자열 숫자는 숫자로, 숫자가 아닌 값은 0 으로 그린다 (NaN 금지)', () => {
    const { container } = render(<VisitorCounter today="42" yesterday={'abc' as unknown as number} max={undefined} total={null as unknown as number} />);

    const values = Array.from(container.querySelectorAll('[data-visitor-counter-value]')).map((el) => el.textContent);
    expect(values).toEqual(['42', '0', '0', '0']);
    expect(container.textContent).not.toContain('NaN');
  });

  // @scenario screen=component, state=normal, viewport=390, locale=ko
  // @effects counter_layout_switches_grid
  it('column 배치는 한 열, row 배치는 모바일 2열·데스크톱 4열 그리드다', () => {
    const { container, rerender } = render(<VisitorCounter layout="column" />);
    expect(container.querySelector('.grid')?.className).toContain('grid-cols-1');

    rerender(<VisitorCounter layout="row" />);
    expect(container.querySelector('.grid')?.className).toContain('grid-cols-2');
    expect(container.querySelector('.grid')?.className).toContain('md:grid-cols-4');
  });

  // @scenario screen=component, state=normal, viewport=1440, locale=ko
  // @effects counter_applies_id_class_and_dark
  it('id·className·다크 변형이 루트에 적용된다', () => {
    const { container } = render(<VisitorCounter id="home_visitor_counter" className="mt-4" />);
    const root = container.querySelector('.visitor-counter');
    expect(root).toHaveAttribute('id', 'home_visitor_counter');
    expect(root?.className).toContain('mt-4');
    expect(root?.className).toContain('dark:bg-gray-800');
  });
  // @scenario screen=component, state=normal, viewport=1440, locale=ko
  // @effects counter_labels_are_announced_with_values
  it('숫자마다 라벨이 붙어 읽힌다 — 묶음은 이름 있는 group 이고 라벨·값은 dt/dd 다', () => {
    // span 두 개로는 스크린리더가 「오늘」과 「1,284」를 잇지 못하고 숫자 넷을 맥락 없이 읽는다
    // (#682 S3 라운드 3 — §6.5 접근성 의무).
    const { container } = render(
      <VisitorCounter today={1284} yesterday={1146} max={3910} total={214551} labels={{ title: '방문자 수', today: '오늘' }} />,
    );

    const root = container.querySelector('.visitor-counter');
    expect(root).toHaveAttribute('role', 'group');
    expect(root).toHaveAttribute('aria-label', '방문자 수');

    const terms = Array.from(container.querySelectorAll('dt')).map((el) => el.textContent);
    const values = Array.from(container.querySelectorAll('dd')).map((el) => el.textContent);
    expect(terms.length).toBe(4);
    expect(values.length).toBe(4);
    expect(terms[0]).toBe('오늘');
    expect(values[0]).toBe('1,284');
  });
});
