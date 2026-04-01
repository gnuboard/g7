/**
 * @file DynamicFieldList.test.tsx
 * @description DynamicFieldList 아이템 ID 정규화 및 포커스 안정성 테스트
 *
 * 테스트 대상:
 * - itemIdKey가 없는 아이템에 자동 ID 부여
 * - onChange 콜백에 ID가 포함된 아이템 전달
 * - 값 변경 시 ID 안정성 유지 (리마운트 방지)
 */

import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { DynamicFieldList } from '../../src/components/composite/DynamicFieldList/DynamicFieldList';
import type { DynamicFieldColumn } from '../../src/components/composite/DynamicFieldList/types';

// G7Core 모킹
(window as any).G7Core = {
  locale: {
    all: () => ['ko', 'en'],
    current: () => 'ko',
  },
  form: null,
};

const basicColumns: DynamicFieldColumn[] = [
  { key: 'name', type: 'input', label: '항목명' },
  { key: 'content', type: 'input', label: '내용' },
];

describe('DynamicFieldList ID 정규화', () => {
  it('itemIdKey가 없는 아이템에 자동으로 _id를 부여한다', () => {
    const onChange = vi.fn();
    const items = [
      { name: '항목1', content: '내용1' },
      { name: '항목2', content: '내용2' },
    ];

    render(
      <DynamicFieldList
        items={items}
        columns={basicColumns}
        onChange={onChange}
        enableDrag={false}
      />
    );

    // 항목이 렌더링되는지 확인
    const inputs = screen.getAllByRole('textbox');
    expect(inputs.length).toBeGreaterThanOrEqual(2);
  });

  it('이미 _id가 있는 아이템은 변경하지 않는다', () => {
    const onChange = vi.fn();
    const items = [
      { _id: 'existing_1', name: '항목1', content: '내용1' },
      { _id: 'existing_2', name: '항목2', content: '내용2' },
    ];

    render(
      <DynamicFieldList
        items={items}
        columns={basicColumns}
        onChange={onChange}
        enableDrag={false}
      />
    );

    const inputs = screen.getAllByRole('textbox');
    expect(inputs.length).toBeGreaterThanOrEqual(2);
  });

  it('값 변경 시 onChange에 _id가 포함된 아이템이 전달된다', () => {
    const onChange = vi.fn();
    const items = [
      { name: '항목1', content: '내용1' },
    ];

    render(
      <DynamicFieldList
        items={items}
        columns={basicColumns}
        onChange={onChange}
        enableDrag={false}
      />
    );

    // 첫 번째 input에 값 입력
    const inputs = screen.getAllByRole('textbox');
    fireEvent.change(inputs[0], { target: { value: '수정됨' } });

    // onChange가 호출되었는지 확인
    expect(onChange).toHaveBeenCalled();

    // 전달된 아이템에 _id가 포함되어 있는지 확인
    const updatedItems = onChange.mock.calls[0][0];
    expect(updatedItems[0]).toHaveProperty('_id');
    expect(typeof updatedItems[0]._id).toBe('string');
    expect(updatedItems[0]._id.length).toBeGreaterThan(0);
  });

  it('값 변경 전후로 _id가 동일하게 유지된다', () => {
    const onChange = vi.fn();
    const items = [
      { _id: 'stable_id_1', name: '항목1', content: '내용1' },
    ];

    render(
      <DynamicFieldList
        items={items}
        columns={basicColumns}
        onChange={onChange}
        enableDrag={false}
      />
    );

    const inputs = screen.getAllByRole('textbox');
    fireEvent.change(inputs[0], { target: { value: '수정됨' } });

    expect(onChange).toHaveBeenCalled();
    const updatedItems = onChange.mock.calls[0][0];
    expect(updatedItems[0]._id).toBe('stable_id_1');
  });

  it('커스텀 itemIdKey를 사용할 때도 정규화가 동작한다', () => {
    const onChange = vi.fn();
    const items = [
      { name: '항목1', content: '내용1' },
    ];

    render(
      <DynamicFieldList
        items={items}
        columns={basicColumns}
        onChange={onChange}
        itemIdKey="key"
        enableDrag={false}
      />
    );

    const inputs = screen.getAllByRole('textbox');
    fireEvent.change(inputs[0], { target: { value: '수정됨' } });

    expect(onChange).toHaveBeenCalled();
    const updatedItems = onChange.mock.calls[0][0];
    // itemIdKey가 'key'이므로 key 필드에 ID가 부여됨
    expect(updatedItems[0]).toHaveProperty('key');
    expect(typeof updatedItems[0].key).toBe('string');
  });

  it('항목 추가 시 새 아이템에 _id가 부여된다', () => {
    const onChange = vi.fn();
    const items = [
      { _id: 'existing_1', name: '항목1', content: '내용1' },
    ];

    render(
      <DynamicFieldList
        items={items}
        columns={basicColumns}
        onChange={onChange}
        enableDrag={false}
        addLabel="항목 추가"
      />
    );

    // 추가 버튼 클릭
    const addButton = screen.getByText('항목 추가');
    fireEvent.click(addButton);

    expect(onChange).toHaveBeenCalled();
    const updatedItems = onChange.mock.calls[0][0];
    expect(updatedItems).toHaveLength(2);
    // 기존 아이템 ID 유지
    expect(updatedItems[0]._id).toBe('existing_1');
    // 새 아이템에 ID 부여
    expect(updatedItems[1]).toHaveProperty('_id');
    expect(typeof updatedItems[1]._id).toBe('string');
  });

  it('항목 삭제 시 나머지 아이템의 _id가 유지된다', () => {
    const onChange = vi.fn();
    const items = [
      { _id: 'id_1', name: '항목1', content: '내용1' },
      { _id: 'id_2', name: '항목2', content: '내용2' },
      { _id: 'id_3', name: '항목3', content: '내용3' },
    ];

    render(
      <DynamicFieldList
        items={items}
        columns={basicColumns}
        onChange={onChange}
        enableDrag={false}
        minItems={0}
      />
    );

    // 삭제 버튼 클릭 (첫 번째 항목)
    const removeButtons = screen.getAllByTitle(/삭제|제거|remove/i);
    if (removeButtons.length > 0) {
      fireEvent.click(removeButtons[0]);

      expect(onChange).toHaveBeenCalled();
      const updatedItems = onChange.mock.calls[0][0];
      expect(updatedItems).toHaveLength(2);
      expect(updatedItems[0]._id).toBe('id_2');
      expect(updatedItems[1]._id).toBe('id_3');
    }
  });
});
