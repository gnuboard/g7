/**
 * @file ColumnSelector.a11y.test.tsx
 * @description 컬럼 선택 목록의 체크박스 이름·키보드 조작 (7.1.0 영역 J D-J13)
 *
 * 컬럼 선택 목록의 체크박스가 읽기 전용(readOnly)·pointer-events 없음이라 접근성 트리에 이름 없는 체크박스로만
 * 보였고, 키보드(Tab → Space)로는 켜고 끌 수 없었다(행 div 클릭만 동작). 체크박스에 열 이름을 달고 직접 조작한다.
 */

import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ColumnSelector } from '../ColumnSelector';

const columns = [
  { field: 'name', header: '이름', required: true },
  { field: 'nickname', header: '닉네임' },
  { field: 'country', header: '국가' },
];

describe('ColumnSelector 접근성', () => {
  /**
   * @scenario control=column_checkbox
   * @effects column_selector_checkboxes_named_and_operable
   */
  it('열 체크박스는 열 이름으로 읽히고, 체크박스 자체 조작이 한 번만 토글한다', () => {
    const onChange = vi.fn();
    render(<ColumnSelector columns={columns as any} visibleColumns={['name']} onColumnVisibilityChange={onChange} />);
    fireEvent.click(screen.getByRole('button'));

    const nick = screen.getByRole('checkbox', { name: '닉네임' });
    expect(screen.getByRole('checkbox', { name: '이름' })).toBeDisabled();
    fireEvent.click(nick);

    // 처음 그릴 때의 'init' 알림은 빼고, 사용자 조작('user')만 센다 — 한 번 눌러 한 번만 토글돼야 한다
    const userCalls = onChange.mock.calls.filter((c) => c[1]?.source === 'user');
    expect(userCalls).toHaveLength(1);
    expect(userCalls[0][0]).toEqual(expect.arrayContaining(['name', 'nickname']));
  });
});
