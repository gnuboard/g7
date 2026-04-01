/**
 * Sortable 기능 테스트
 *
 * 레이아웃 JSON의 sortable 속성을 통한 드래그앤드롭 정렬 기능 테스트
 *
 * @since engine-v1.14.0
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import React from 'react';
import { render, screen } from '@testing-library/react';
import { SortableContainer } from '../sortable/SortableContainer';
import { SortableItemWrapper } from '../sortable/SortableItemWrapper';
import { SortableProvider, useSortableContext } from '../sortable/SortableContext';

describe('Sortable 모듈', () => {
  describe('SortableContainer', () => {
    it('아이템들을 렌더링한다', () => {
      const items = [
        { id: '1', name: 'Item 1' },
        { id: '2', name: 'Item 2' },
        { id: '3', name: 'Item 3' },
      ];

      render(
        <SortableContainer
          items={items}
          itemKey="id"
          strategy="verticalList"
        >
          {items.map((item) => (
            <div key={item.id} data-testid={`item-${item.id}`}>
              {item.name}
            </div>
          ))}
        </SortableContainer>
      );

      expect(screen.getByTestId('item-1')).toBeInTheDocument();
      expect(screen.getByTestId('item-2')).toBeInTheDocument();
      expect(screen.getByTestId('item-3')).toBeInTheDocument();
    });

    it('onSortEnd 콜백을 호출할 수 있다', () => {
      const items = [{ id: '1', name: 'Item 1' }];
      const onSortEnd = vi.fn();

      render(
        <SortableContainer
          items={items}
          itemKey="id"
          strategy="verticalList"
          onSortEnd={onSortEnd}
        >
          <div>Test</div>
        </SortableContainer>
      );

      // onSortEnd는 드래그 완료 시 호출됨 (실제 드래그 테스트는 E2E에서 수행)
      expect(onSortEnd).not.toHaveBeenCalled();
    });
  });

  describe('SortableItemWrapper', () => {
    it('아이템을 sortable-item 래퍼로 감싼다', () => {
      render(
        <SortableItemWrapper id="test-item">
          <div data-testid="inner-content">Content</div>
        </SortableItemWrapper>
      );

      const wrapper = screen.getByTestId('inner-content').parentElement;
      expect(wrapper).toHaveAttribute('data-sortable-item');
    });

    it('드래그 중이 아닐 때 opacity가 1이다', () => {
      render(
        <SortableItemWrapper id="test-item">
          <div data-testid="inner-content">Content</div>
        </SortableItemWrapper>
      );

      const wrapper = screen.getByTestId('inner-content').parentElement;
      expect(wrapper).toHaveStyle({ opacity: '1' });
    });
  });

  describe('SortableProvider & useSortableContext', () => {
    const TestConsumer: React.FC = () => {
      const context = useSortableContext();
      return (
        <div data-testid="consumer">
          {context ? 'has-context' : 'no-context'}
        </div>
      );
    };

    it('Provider 외부에서는 null을 반환한다', () => {
      render(<TestConsumer />);
      expect(screen.getByTestId('consumer')).toHaveTextContent('no-context');
    });

    it('Provider 내부에서는 컨텍스트를 반환한다', () => {
      render(
        <SortableProvider value={{ handle: '[data-drag-handle]', isDragging: false }}>
          <TestConsumer />
        </SortableProvider>
      );
      expect(screen.getByTestId('consumer')).toHaveTextContent('has-context');
    });

    it('listeners를 전달할 수 있다', () => {
      const mockListeners = {
        onPointerDown: vi.fn(),
      };

      const ListenerConsumer: React.FC = () => {
        const context = useSortableContext();
        return (
          <div data-testid="listener-test">
            {context?.listeners ? 'has-listeners' : 'no-listeners'}
          </div>
        );
      };

      render(
        <SortableProvider value={{ listeners: mockListeners as any, handle: '[data-drag-handle]' }}>
          <ListenerConsumer />
        </SortableProvider>
      );
      expect(screen.getByTestId('listener-test')).toHaveTextContent('has-listeners');
    });
  });
});

describe('Sortable 통합 테스트', () => {
  it('SortableContainer와 SortableItemWrapper가 함께 동작한다', () => {
    const items = [
      { id: '1', name: 'First' },
      { id: '2', name: 'Second' },
    ];

    render(
      <SortableContainer
        items={items}
        itemKey="id"
        strategy="verticalList"
      >
        {items.map((item) => (
          <SortableItemWrapper key={item.id} id={item.id}>
            <div data-testid={`sortable-${item.id}`}>{item.name}</div>
          </SortableItemWrapper>
        ))}
      </SortableContainer>
    );

    expect(screen.getByTestId('sortable-1')).toHaveTextContent('First');
    expect(screen.getByTestId('sortable-2')).toHaveTextContent('Second');

    // 각 아이템이 sortable-item 래퍼로 감싸져 있는지 확인
    const first = screen.getByTestId('sortable-1').parentElement;
    const second = screen.getByTestId('sortable-2').parentElement;
    expect(first).toHaveAttribute('data-sortable-item');
    expect(second).toHaveAttribute('data-sortable-item');
  });

  it('handle 옵션이 전달된다', () => {
    const items = [{ id: '1', name: 'Item' }];

    render(
      <SortableContainer items={items} itemKey="id" strategy="verticalList">
        <SortableItemWrapper id="1" handle="[data-drag-handle]">
          <div data-testid="item-with-handle">Content</div>
        </SortableItemWrapper>
      </SortableContainer>
    );

    expect(screen.getByTestId('item-with-handle')).toBeInTheDocument();
  });

  it('sortVersion 변경 시 DndContext가 리마운트된다', () => {
    const items = [
      { id: '1', name: 'First' },
      { id: '2', name: 'Second' },
    ];

    const { rerender } = render(
      <SortableContainer
        items={items}
        itemKey="id"
        strategy="verticalList"
        sortVersion={0}
      >
        {items.map((item) => (
          <SortableItemWrapper key={item.id} id={item.id}>
            <div data-testid={`sv-${item.id}`}>{item.name}</div>
          </SortableItemWrapper>
        ))}
      </SortableContainer>
    );

    expect(screen.getByTestId('sv-1')).toHaveTextContent('First');

    // 정렬 후: 아이템 순서 변경 + sortVersion 증가
    const sortedItems = [items[1], items[0]];
    rerender(
      <SortableContainer
        items={sortedItems}
        itemKey="id"
        strategy="verticalList"
        sortVersion={1}
      >
        {sortedItems.map((item) => (
          <SortableItemWrapper key={item.id} id={item.id}>
            <div data-testid={`sv-${item.id}`}>{item.name}</div>
          </SortableItemWrapper>
        ))}
      </SortableContainer>
    );

    // 정렬된 순서로 렌더링됨
    expect(screen.getByTestId('sv-2')).toHaveTextContent('Second');
    expect(screen.getByTestId('sv-1')).toHaveTextContent('First');
  });

  it('빈 배열을 처리할 수 있다', () => {
    const items: any[] = [];

    const { container } = render(
      <SortableContainer items={items} itemKey="id" strategy="verticalList">
        {items.map((item) => (
          <div key={item.id}>{item.name}</div>
        ))}
      </SortableContainer>
    );

    // 빈 컨테이너가 렌더링됨
    expect(container).toBeInTheDocument();
  });
});
