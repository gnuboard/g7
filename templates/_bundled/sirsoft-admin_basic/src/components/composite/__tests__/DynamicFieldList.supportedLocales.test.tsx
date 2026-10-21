/**
 * DynamicFieldList — 새 행의 다국어 칸은 켜진 언어 전부 (#148 F4)
 *
 * 종전에는 없는 API(`G7Core.locale.all`)를 불러 늘 ko·en 두 칸만 만들었다. 실제 API 는 `locale.supported()` 다.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { DynamicFieldList } from '../DynamicFieldList';
import type { DynamicFieldColumn } from '../DynamicFieldList/types';

const columns: DynamicFieldColumn[] = [
  { key: 'name', label: '항목명', type: 'multilingual', placeholder: '항목명을 입력하세요' },
  { key: 'content', label: '내용', type: 'multilingual', placeholder: '내용을 입력하세요' },
];

beforeEach(() => {
  (window as any).G7Core = {
    locale: {
      current: () => 'ko',
      supported: () => ['ko', 'en', 'fil'],
    },
  };
});

afterEach(() => {
  delete (window as any).G7Core;
  vi.clearAllMocks();
});

describe('DynamicFieldList 새 행 다국어 칸 (#148)', () => {
  // @scenario shape=three_letter
  // @effects dynamic_field_row_has_every_supported_locale
  it('다국어 칸이 있는 행을 추가하면 켜진 언어마다 칸이 생긴다 (종전 ko·en 만)', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(<DynamicFieldList items={[]} columns={columns} onChange={onChange} addLabel="항목 추가" />);

    await user.click(screen.getByText('항목 추가'));

    const newItem = onChange.mock.calls[0][0][0];
    expect(Object.keys(newItem.name)).toEqual(['ko', 'en', 'fil']);
    expect(Object.keys(newItem.content)).toEqual(['ko', 'en', 'fil']);
  });
});
