import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { DynamicFieldList } from '../DynamicFieldList';
import type { DynamicFieldColumn, RowAction } from '../DynamicFieldList/types';

// G7Core 목업
const mockG7Core = {
  locale: {
    current: () => 'ko',
    all: () => ['ko', 'en'],
  },
};

// window.G7Core 목업 설정
beforeEach(() => {
  (window as any).G7Core = mockG7Core;
});

afterEach(() => {
  delete (window as any).G7Core;
  vi.clearAllMocks();
});

// 테스트용 컬럼 정의
const sampleColumns: DynamicFieldColumn[] = [
  {
    key: 'name',
    label: '항목명',
    type: 'multilingual',
    placeholder: '항목명을 입력하세요',
  },
  {
    key: 'content',
    label: '내용',
    type: 'multilingual',
    placeholder: '내용을 입력하세요',
  },
];

// 테스트용 단순 컬럼 정의
const simpleColumns: DynamicFieldColumn[] = [
  {
    key: 'title',
    label: '제목',
    type: 'input',
    placeholder: '제목을 입력하세요',
  },
  {
    key: 'count',
    label: '수량',
    type: 'number',
    placeholder: '수량',
    min: 0,
    max: 100,
  },
];

// 테스트용 아이템 데이터
const sampleItems = [
  {
    _id: 'item_1',
    name: { ko: '제조사', en: 'Manufacturer' },
    content: { ko: '(주)테스트', en: 'Test Inc.' },
  },
  {
    _id: 'item_2',
    name: { ko: '원산지', en: 'Country of Origin' },
    content: { ko: '대한민국', en: 'Korea' },
  },
];

const simpleItems = [
  { _id: 'item_1', title: '첫 번째 항목', count: 10 },
  { _id: 'item_2', title: '두 번째 항목', count: 20 },
];

describe('DynamicFieldList', () => {
  describe('기본 렌더링', () => {
    it('컴포넌트가 렌더링됨', () => {
      render(<DynamicFieldList items={sampleItems} columns={sampleColumns} />);

      // 헤더가 렌더링되는지 확인
      expect(screen.getByText('항목명')).toBeInTheDocument();
      expect(screen.getByText('내용')).toBeInTheDocument();
    });

    it('빈 목록일 때 빈 상태 메시지가 표시됨', () => {
      render(
        <DynamicFieldList
          items={[]}
          columns={sampleColumns}
          emptyMessage="데이터가 없습니다."
        />
      );

      expect(screen.getByText('데이터가 없습니다.')).toBeInTheDocument();
    });

    it('항목 추가 버튼이 표시됨', () => {
      render(
        <DynamicFieldList
          items={[]}
          columns={sampleColumns}
          addLabel="+ 항목 추가"
        />
      );

      expect(screen.getByText('+ 항목 추가')).toBeInTheDocument();
    });

    it('className prop이 적용됨', () => {
      const { container } = render(
        <DynamicFieldList
          items={sampleItems}
          columns={sampleColumns}
          className="custom-list"
        />
      );

      expect(container.firstChild).toHaveClass('custom-list');
    });

    it('순번이 표시됨', () => {
      render(
        <DynamicFieldList
          items={sampleItems}
          columns={sampleColumns}
          showIndex={true}
        />
      );

      expect(screen.getByText('#')).toBeInTheDocument();
      expect(screen.getByText('1')).toBeInTheDocument();
      expect(screen.getByText('2')).toBeInTheDocument();
    });

    it('showIndex=false일 때 순번이 표시되지 않음', () => {
      render(
        <DynamicFieldList
          items={sampleItems}
          columns={sampleColumns}
          showIndex={false}
        />
      );

      expect(screen.queryByText('#')).not.toBeInTheDocument();
    });
  });

  describe('항목 추가', () => {
    it('항목 추가 버튼 클릭 시 onChange가 호출됨', async () => {
      const user = userEvent.setup();
      const onChange = vi.fn();

      render(
        <DynamicFieldList
          items={[]}
          columns={simpleColumns}
          onChange={onChange}
          addLabel="항목 추가"
        />
      );

      const addButton = screen.getByText('항목 추가');
      await user.click(addButton);

      expect(onChange).toHaveBeenCalledTimes(1);
      const newItems = onChange.mock.calls[0][0];
      expect(newItems.length).toBe(1);
      expect(newItems[0]).toHaveProperty('_id');
      expect(newItems[0].title).toBe('');
    });

    it('onAddItem이 정의되면 외부 핸들러가 호출됨', async () => {
      const user = userEvent.setup();
      const onAddItem = vi.fn();
      const onChange = vi.fn();

      render(
        <DynamicFieldList
          items={[]}
          columns={simpleColumns}
          onAddItem={onAddItem}
          onChange={onChange}
          addLabel="항목 추가"
        />
      );

      const addButton = screen.getByText('항목 추가');
      await user.click(addButton);

      expect(onAddItem).toHaveBeenCalledTimes(1);
      expect(onChange).not.toHaveBeenCalled();
    });

    it('maxItems에 도달하면 추가 버튼이 비활성화됨', () => {
      render(
        <DynamicFieldList
          items={simpleItems}
          columns={simpleColumns}
          maxItems={2}
          addLabel="항목 추가"
        />
      );

      expect(screen.queryByText('항목 추가')).not.toBeInTheDocument();
    });

    it('createDefaultItem이 정의되면 해당 팩토리 사용', async () => {
      const user = userEvent.setup();
      const onChange = vi.fn();
      const createDefaultItem = vi.fn(() => ({
        _id: 'custom_id',
        title: '기본값',
        count: 5,
      }));

      render(
        <DynamicFieldList
          items={[]}
          columns={simpleColumns}
          onChange={onChange}
          createDefaultItem={createDefaultItem}
          addLabel="항목 추가"
        />
      );

      const addButton = screen.getByText('항목 추가');
      await user.click(addButton);

      expect(createDefaultItem).toHaveBeenCalledTimes(1);
      const newItems = onChange.mock.calls[0][0];
      expect(newItems[0].title).toBe('기본값');
      expect(newItems[0].count).toBe(5);
    });
  });

  describe('항목 삭제', () => {
    it('삭제 버튼 클릭 시 onChange가 호출됨', async () => {
      const user = userEvent.setup();
      const onChange = vi.fn();

      render(
        <DynamicFieldList
          items={simpleItems}
          columns={simpleColumns}
          onChange={onChange}
        />
      );

      const removeButtons = screen.getAllByTitle('삭제');
      await user.click(removeButtons[0]);

      expect(onChange).toHaveBeenCalledTimes(1);
      const newItems = onChange.mock.calls[0][0];
      expect(newItems.length).toBe(1);
      expect(newItems[0]._id).toBe('item_2');
    });

    it('minItems 이하로 삭제할 수 없음', async () => {
      const user = userEvent.setup();
      const onChange = vi.fn();

      render(
        <DynamicFieldList
          items={[simpleItems[0]]}
          columns={simpleColumns}
          onChange={onChange}
          minItems={1}
        />
      );

      const removeButtons = screen.queryAllByTitle('삭제');
      expect(removeButtons.length).toBe(0);
    });

    it('onRemoveItem이 정의되면 외부 핸들러가 호출됨', async () => {
      const user = userEvent.setup();
      const onRemoveItem = vi.fn();
      const onChange = vi.fn();

      render(
        <DynamicFieldList
          items={simpleItems}
          columns={simpleColumns}
          onRemoveItem={onRemoveItem}
          onChange={onChange}
        />
      );

      const removeButtons = screen.getAllByTitle('삭제');
      await user.click(removeButtons[0]);

      expect(onRemoveItem).toHaveBeenCalledTimes(1);
      expect(onRemoveItem).toHaveBeenCalledWith(0, simpleItems[0]);
      expect(onChange).not.toHaveBeenCalled();
    });
  });

  describe('값 변경', () => {
    it('입력 필드 변경 시 onChange가 호출됨', async () => {
      const user = userEvent.setup();
      const onChange = vi.fn();

      render(
        <DynamicFieldList
          items={simpleItems}
          columns={simpleColumns}
          onChange={onChange}
        />
      );

      const titleInputs = screen.getAllByPlaceholderText('제목을 입력하세요');
      await user.clear(titleInputs[0]);
      await user.type(titleInputs[0], '새 제목');

      expect(onChange).toHaveBeenCalled();
    });

    it('숫자 필드 변경 시 onChange가 호출됨', async () => {
      const user = userEvent.setup();
      const onChange = vi.fn();

      render(
        <DynamicFieldList
          items={simpleItems}
          columns={simpleColumns}
          onChange={onChange}
        />
      );

      const numberInputs = screen.getAllByPlaceholderText('수량');
      await user.clear(numberInputs[0]);
      await user.type(numberInputs[0], '50');

      expect(onChange).toHaveBeenCalled();
    });
  });

  describe('읽기 전용 모드', () => {
    it('readOnly=true일 때 입력 필드가 비활성화됨', () => {
      render(
        <DynamicFieldList
          items={simpleItems}
          columns={simpleColumns}
          readOnly={true}
        />
      );

      const inputs = screen.getAllByRole('textbox');
      inputs.forEach((input) => {
        expect(input).toBeDisabled();
      });
    });

    it('readOnly=true일 때 삭제 버튼이 표시되지 않음', () => {
      render(
        <DynamicFieldList
          items={simpleItems}
          columns={simpleColumns}
          readOnly={true}
        />
      );

      expect(screen.queryByTitle('삭제')).not.toBeInTheDocument();
    });

    it('readOnly=true일 때 추가 버튼이 표시되지 않음', () => {
      render(
        <DynamicFieldList
          items={simpleItems}
          columns={simpleColumns}
          readOnly={true}
          addLabel="항목 추가"
        />
      );

      expect(screen.queryByText('항목 추가')).not.toBeInTheDocument();
    });
  });

  describe('드래그 앤 드롭', () => {
    it('enableDrag=true일 때 드래그 핸들이 표시됨', () => {
      const { container } = render(
        <DynamicFieldList
          items={simpleItems}
          columns={simpleColumns}
          enableDrag={true}
        />
      );

      const dragHandles = container.querySelectorAll('.cursor-grab');
      expect(dragHandles.length).toBeGreaterThan(0);
    });

    it('enableDrag=false일 때 드래그 핸들이 표시되지 않음', () => {
      const { container } = render(
        <DynamicFieldList
          items={simpleItems}
          columns={simpleColumns}
          enableDrag={false}
        />
      );

      const dragHandles = container.querySelectorAll('.cursor-grab');
      expect(dragHandles.length).toBe(0);
    });
  });

  describe('커스텀 행 액션', () => {
    const customRowActions: RowAction[] = [
      {
        key: 'edit',
        label: '편집',
        icon: 'edit',
        onClick: vi.fn(),
      },
      {
        key: 'delete',
        label: '삭제',
        icon: 'trash',
        danger: true,
        onClick: vi.fn(),
      },
    ];

    it('rowActions이 정의되면 커스텀 액션 버튼이 표시됨', () => {
      render(
        <DynamicFieldList
          items={simpleItems}
          columns={simpleColumns}
          rowActions={customRowActions}
        />
      );

      expect(screen.getAllByTitle('편집').length).toBe(2);
      expect(screen.getAllByTitle('삭제').length).toBe(2);
    });

    it('커스텀 액션 클릭 시 onClick이 호출됨', async () => {
      const user = userEvent.setup();
      const onClick = vi.fn();
      const rowActions: RowAction[] = [
        {
          key: 'edit',
          label: '편집',
          icon: 'edit',
          onClick,
        },
      ];

      render(
        <DynamicFieldList
          items={simpleItems}
          columns={simpleColumns}
          rowActions={rowActions}
        />
      );

      const editButtons = screen.getAllByTitle('편집');
      await user.click(editButtons[0]);

      expect(onClick).toHaveBeenCalledWith(simpleItems[0], 0);
    });

    it('disabled 조건이 적용됨', () => {
      const rowActions: RowAction[] = [
        {
          key: 'edit',
          label: '편집',
          icon: 'edit',
          disabled: (item, index) => index === 0,
        },
      ];

      render(
        <DynamicFieldList
          items={simpleItems}
          columns={simpleColumns}
          rowActions={rowActions}
        />
      );

      const editButtons = screen.getAllByTitle('편집');
      expect(editButtons[0]).toBeDisabled();
      expect(editButtons[1]).not.toBeDisabled();
    });
  });

  describe('다국어 지원', () => {
    it('multilingual 타입 컬럼이 현재 로케일 값을 표시', () => {
      render(
        <DynamicFieldList
          items={sampleItems}
          columns={sampleColumns}
        />
      );

      // G7Core 목업에서 current()가 'ko'를 반환하므로 한국어 값이 표시됨
      expect(screen.getByDisplayValue('제조사')).toBeInTheDocument();
      expect(screen.getByDisplayValue('(주)테스트')).toBeInTheDocument();
    });

    it('multilingual 필드 변경 시 해당 로케일 값만 업데이트', async () => {
      const user = userEvent.setup();
      const onChange = vi.fn();

      render(
        <DynamicFieldList
          items={sampleItems}
          columns={sampleColumns}
          onChange={onChange}
        />
      );

      const nameInput = screen.getByDisplayValue('제조사');
      await user.clear(nameInput);
      await user.type(nameInput, '새 제조사');

      expect(onChange).toHaveBeenCalled();
      const lastCall = onChange.mock.calls[onChange.mock.calls.length - 1][0];
      // 한국어 값만 업데이트되고 영어 값은 유지됨
      expect(lastCall[0].name.ko).toContain('새');
      expect(lastCall[0].name.en).toBe('Manufacturer');
    });
  });

  describe('Select 타입 컬럼', () => {
    const columnsWithSelect: DynamicFieldColumn[] = [
      {
        key: 'status',
        label: '상태',
        type: 'select',
        options: [
          { value: 'active', label: '활성' },
          { value: 'inactive', label: '비활성' },
        ],
      },
    ];

    const itemsWithSelect = [
      { _id: 'item_1', status: 'active' },
      { _id: 'item_2', status: 'inactive' },
    ];

    it('select 타입 컬럼이 렌더링됨', () => {
      render(
        <DynamicFieldList
          items={itemsWithSelect}
          columns={columnsWithSelect}
        />
      );

      const selects = screen.getAllByRole('combobox');
      expect(selects.length).toBe(2);
    });

    it('select 값 변경 시 onChange가 호출됨', async () => {
      const user = userEvent.setup();
      const onChange = vi.fn();

      render(
        <DynamicFieldList
          items={itemsWithSelect}
          columns={columnsWithSelect}
          onChange={onChange}
        />
      );

      const selects = screen.getAllByRole('combobox');
      await user.selectOptions(selects[0], 'inactive');

      expect(onChange).toHaveBeenCalled();
      const newItems = onChange.mock.calls[0][0];
      expect(newItems[0].status).toBe('inactive');
    });
  });
});

describe('DynamicFieldList - 빠른 연속 편집 (stale closure 방지)', () => {
  it('빠른 연속 편집 시 모든 수정분이 누적 반영됨', async () => {
    const onChange = vi.fn();
    const items = [
      { _id: 'item_1', title: 'A', count: 1 },
      { _id: 'item_2', title: 'B', count: 2 },
      { _id: 'item_3', title: 'C', count: 3 },
    ];

    const { rerender } = render(
      <DynamicFieldList
        items={items}
        columns={simpleColumns}
        onChange={onChange}
      />
    );

    // 첫 번째 항목 title 입력 변경을 시뮬레이션
    const titleInputs = screen.getAllByPlaceholderText('제목을 입력하세요');
    fireEvent.change(titleInputs[0], { target: { value: 'A1' } });

    // 리렌더 전에 두 번째 항목도 변경
    fireEvent.change(titleInputs[1], { target: { value: 'B1' } });

    // 리렌더 전에 세 번째 항목도 변경
    fireEvent.change(titleInputs[2], { target: { value: 'C1' } });

    // onChange가 3번 호출됨
    expect(onChange).toHaveBeenCalledTimes(3);

    // 마지막 호출에서 모든 수정분이 반영되어야 함
    const lastCallItems = onChange.mock.calls[2][0];
    expect(lastCallItems[0].title).toBe('A1');
    expect(lastCallItems[1].title).toBe('B1');
    expect(lastCallItems[2].title).toBe('C1');
  });

  it('연속 삭제 시 올바른 아이템이 삭제됨', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    const items = [
      { _id: 'item_1', title: 'A', count: 1 },
      { _id: 'item_2', title: 'B', count: 2 },
      { _id: 'item_3', title: 'C', count: 3 },
    ];

    render(
      <DynamicFieldList
        items={items}
        columns={simpleColumns}
        onChange={onChange}
      />
    );

    // 첫 번째 삭제 버튼 클릭
    const removeButtons = screen.getAllByTitle('삭제');
    await user.click(removeButtons[0]);

    expect(onChange).toHaveBeenCalledTimes(1);
    const afterFirstDelete = onChange.mock.calls[0][0];
    expect(afterFirstDelete.length).toBe(2);
    expect(afterFirstDelete[0]._id).toBe('item_2');
    expect(afterFirstDelete[1]._id).toBe('item_3');
  });

  it('편집 후 추가 시 기존 편집분이 유지됨', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    const items = [
      { _id: 'item_1', title: '기존 항목', count: 10 },
    ];

    render(
      <DynamicFieldList
        items={items}
        columns={simpleColumns}
        onChange={onChange}
      />
    );

    // 기존 항목 수정
    const titleInput = screen.getByPlaceholderText('제목을 입력하세요');
    fireEvent.change(titleInput, { target: { value: '수정된 항목' } });

    // 리렌더 전에 항목 추가
    const addButton = screen.getByText('항목 추가');
    await user.click(addButton);

    expect(onChange).toHaveBeenCalledTimes(2);

    // 추가 호출에서 기존 수정분이 유지되어야 함
    const afterAdd = onChange.mock.calls[1][0];
    expect(afterAdd[0].title).toBe('수정된 항목');
    expect(afterAdd.length).toBe(2);
  });
});

describe('DynamicFieldList - 엣지 케이스', () => {
  it('items가 undefined일 때 에러 없이 렌더링됨', () => {
    render(
      <DynamicFieldList
        items={undefined as any}
        columns={simpleColumns}
      />
    );

    expect(screen.getByText('항목이 없습니다.')).toBeInTheDocument();
  });

  it('columns가 빈 배열일 때 에러 없이 렌더링됨', () => {
    const { container } = render(
      <DynamicFieldList
        items={simpleItems}
        columns={[]}
      />
    );

    expect(container).toBeInTheDocument();
  });

  it('itemIdKey로 커스텀 ID 필드 사용 가능', () => {
    const customItems = [
      { customId: 'a', title: '항목 A' },
      { customId: 'b', title: '항목 B' },
    ];

    const { container } = render(
      <DynamicFieldList
        items={customItems}
        columns={[{ key: 'title', label: '제목', type: 'input' }]}
        itemIdKey="customId"
      />
    );

    expect(container).toBeInTheDocument();
  });
});
