/**
 * @file Toggle.ariaLabel.test.tsx
 * @description 보이는 라벨 없는 스위치의 접근 가능한 이름 — `ariaLabel` prop
 *
 * 표의 「사용」 열이나 카드 옆 문구처럼 라벨이 스위치 밖에 있으면 스위치에는 이름이 없었다(`label`·`name` 모두
 * 비어 화면 읽기 프로그램이 이름 없는 스위치로 읽음 — 7.1.0 실측 O-B6). 보이는 라벨을 더하지 않고 이름만 붙인다.
 */

import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Toggle } from '../Toggle';

describe('Toggle — 접근 가능한 이름', () => {
  /**
   * @scenario source=aria_label
   * @effects switch_has_accessible_name
   */
  it('ariaLabel 을 주면 보이는 라벨 없이 스위치 이름이 된다', () => {
    render(<Toggle ariaLabel="규칙 사용" />);

    expect(screen.getByRole('switch', { name: '규칙 사용' })).toBeInTheDocument();
    expect(screen.queryByText('규칙 사용')).toBeNull();
  });

  /**
   * @scenario source=label
   * @effects switch_has_accessible_name
   */
  it('ariaLabel 이 없으면 종전대로 label 을 이름으로 쓴다', () => {
    render(<Toggle label="알림 활성화" />);

    expect(screen.getByRole('switch', { name: '알림 활성화' })).toBeInTheDocument();
    expect(screen.getByText('알림 활성화')).toBeInTheDocument();
  });

  /**
   * @scenario source=aria_label
   * @effects switch_has_accessible_name
   */
  it('ariaLabel 과 label 이 함께 있으면 ariaLabel 이 이름이다', () => {
    render(<Toggle label="사용" ariaLabel="규칙 admin/* 사용" />);

    expect(screen.getByRole('switch', { name: '규칙 admin/* 사용' })).toBeInTheDocument();
  });

  /**
   * @scenario source=adjacent_label_late
   * @effects switch_has_accessible_name
   */
  it('첫 그리기에 곁 제목이 없어 name 으로 읽히다가 제목이 그려지면 그 제목을 이름으로 잇는다', () => {
    // 곁 제목을 못 찾으면 마지막 수단인 name(필드 키)을 이름으로 단다. 그 이름이 「이미 이름 있음」으로 보여
    // 늦게 그려진 제목을 다시 찾지 않으면, 화면 읽기가 계속 필드 키를 읽는다.
    const Row = ({ showTitle }: { showTitle: boolean }) => (
      <div className="flex-between">
        <div>{showTitle ? <span>HTTPS 강제</span> : null}</div>
        <Toggle name="security.force_https" />
      </div>
    );
    const { rerender } = render(<Row showTitle={false} />);
    expect(screen.getByRole('switch', { name: 'security.force_https' })).toBeInTheDocument();

    rerender(<Row showTitle />);

    const sw = screen.getByRole('switch', { name: 'HTTPS 강제' });
    expect(sw.getAttribute('aria-label')).toBeNull();
  });
});
