/**
 * SlotEditor.test.tsx
 *
 * SlotEditor 컴포넌트 테스트
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import {
  SlotEditor,
  SlotDefinition,
  LayoutInfo,
} from '../components/PropertyPanel/SlotEditor';
import type { ComponentDefinition } from '../types/editor';

// ============================================================================
// 테스트 헬퍼
// ============================================================================

function createTestParentSlots(): SlotDefinition[] {
  return [
    {
      id: 'header-slot',
      type: 'slot',
      name: 'header',
      default: [
        {
          id: 'default-header',
          type: 'composite',
          name: 'PageHeader',
        },
      ],
    },
    {
      id: 'content-slot',
      type: 'slot',
      name: 'content',
    },
    {
      id: 'footer-slot',
      type: 'slot',
      name: 'footer',
      default: [],
    },
  ];
}

function createTestLayouts(): LayoutInfo[] {
  return [
    { name: '_admin_base', title: 'Admin 기본 레이아웃' },
    { name: '_user_base', title: 'User 기본 레이아웃' },
    { name: 'dashboard', title: '대시보드' },
  ];
}

function createTestSlots(): Record<string, ComponentDefinition[]> {
  return {
    content: [
      {
        id: 'custom-content',
        type: 'basic',
        name: 'Div',
        text: '커스텀 컨텐츠',
      },
    ],
  };
}

// ============================================================================
// 테스트
// ============================================================================

describe('SlotEditor', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe('기본 렌더링', () => {
    it('헤더가 표시되어야 함', () => {
      const onExtendsChange = vi.fn();
      const onSlotsChange = vi.fn();

      render(
        <SlotEditor
          extendsLayout={null}
          slots={{}}
          availableLayouts={createTestLayouts()}
          parentSlots={[]}
          onExtendsChange={onExtendsChange}
          onSlotsChange={onSlotsChange}
        />
      );

      expect(screen.getByText('레이아웃 상속')).toBeInTheDocument();
    });

    it('부모 레이아웃 선택 드롭다운이 표시되어야 함', () => {
      const onExtendsChange = vi.fn();
      const onSlotsChange = vi.fn();

      render(
        <SlotEditor
          extendsLayout={null}
          slots={{}}
          availableLayouts={createTestLayouts()}
          parentSlots={[]}
          onExtendsChange={onExtendsChange}
          onSlotsChange={onSlotsChange}
        />
      );

      expect(screen.getByText('부모 레이아웃 (extends)')).toBeInTheDocument();
      expect(screen.getByText('상속하지 않음 (base 레이아웃)')).toBeInTheDocument();
    });

    it('상속하지 않을 때 안내 메시지가 표시되어야 함', () => {
      const onExtendsChange = vi.fn();
      const onSlotsChange = vi.fn();

      render(
        <SlotEditor
          extendsLayout={null}
          slots={{}}
          availableLayouts={createTestLayouts()}
          parentSlots={[]}
          onExtendsChange={onExtendsChange}
          onSlotsChange={onSlotsChange}
        />
      );

      expect(screen.getByText(/상속하지 않는 base 레이아웃입니다/)).toBeInTheDocument();
    });

    it('도움말이 표시되어야 함', () => {
      const onExtendsChange = vi.fn();
      const onSlotsChange = vi.fn();

      render(
        <SlotEditor
          extendsLayout={null}
          slots={{}}
          availableLayouts={createTestLayouts()}
          parentSlots={[]}
          onExtendsChange={onExtendsChange}
          onSlotsChange={onSlotsChange}
        />
      );

      expect(screen.getByText('레이아웃 상속 규칙:')).toBeInTheDocument();
      expect(screen.getByText(/최대 상속 깊이 10단계/)).toBeInTheDocument();
    });
  });

  describe('부모 레이아웃 선택', () => {
    it('레이아웃 선택 시 onExtendsChange가 호출되어야 함', () => {
      const onExtendsChange = vi.fn();
      const onSlotsChange = vi.fn();

      render(
        <SlotEditor
          extendsLayout={null}
          slots={{}}
          availableLayouts={createTestLayouts()}
          parentSlots={[]}
          onExtendsChange={onExtendsChange}
          onSlotsChange={onSlotsChange}
        />
      );

      const select = screen.getByRole('combobox');
      fireEvent.change(select, { target: { value: '_admin_base' } });

      expect(onExtendsChange).toHaveBeenCalledWith('_admin_base');
      expect(onSlotsChange).toHaveBeenCalledWith({});
    });

    it('상속 해제 시 onExtendsChange(null)이 호출되어야 함', () => {
      const onExtendsChange = vi.fn();
      const onSlotsChange = vi.fn();

      render(
        <SlotEditor
          extendsLayout="_admin_base"
          slots={createTestSlots()}
          availableLayouts={createTestLayouts()}
          parentSlots={createTestParentSlots()}
          onExtendsChange={onExtendsChange}
          onSlotsChange={onSlotsChange}
        />
      );

      const select = screen.getByRole('combobox');
      fireEvent.change(select, { target: { value: '' } });

      expect(onExtendsChange).toHaveBeenCalledWith(null);
      expect(onSlotsChange).toHaveBeenCalledWith({});
    });
  });

  describe('슬롯 목록 표시', () => {
    it('상속 시 슬롯 목록이 표시되어야 함', () => {
      const onExtendsChange = vi.fn();
      const onSlotsChange = vi.fn();

      render(
        <SlotEditor
          extendsLayout="_admin_base"
          slots={{}}
          availableLayouts={createTestLayouts()}
          parentSlots={createTestParentSlots()}
          onExtendsChange={onExtendsChange}
          onSlotsChange={onSlotsChange}
        />
      );

      expect(screen.getByText('사용 가능한 슬롯')).toBeInTheDocument();
      expect(screen.getByText('header')).toBeInTheDocument();
      expect(screen.getByText('content')).toBeInTheDocument();
      expect(screen.getByText('footer')).toBeInTheDocument();
    });

    it('슬롯이 없는 부모 레이아웃 선택 시 안내 메시지가 표시되어야 함', () => {
      const onExtendsChange = vi.fn();
      const onSlotsChange = vi.fn();

      render(
        <SlotEditor
          extendsLayout="_admin_base"
          slots={{}}
          availableLayouts={createTestLayouts()}
          parentSlots={[]}
          onExtendsChange={onExtendsChange}
          onSlotsChange={onSlotsChange}
        />
      );

      expect(screen.getByText(/부모 레이아웃에 정의된 슬롯이 없습니다/)).toBeInTheDocument();
    });

    it('슬롯 사용 통계가 표시되어야 함', () => {
      const onExtendsChange = vi.fn();
      const onSlotsChange = vi.fn();

      render(
        <SlotEditor
          extendsLayout="_admin_base"
          slots={createTestSlots()}
          availableLayouts={createTestLayouts()}
          parentSlots={createTestParentSlots()}
          onExtendsChange={onExtendsChange}
          onSlotsChange={onSlotsChange}
        />
      );

      expect(screen.getByText('1/3 슬롯 사용')).toBeInTheDocument();
    });
  });

  describe('슬롯 상태 표시', () => {
    it('컴포넌트가 할당된 슬롯에 체크 표시가 있어야 함', () => {
      const onExtendsChange = vi.fn();
      const onSlotsChange = vi.fn();

      render(
        <SlotEditor
          extendsLayout="_admin_base"
          slots={createTestSlots()}
          availableLayouts={createTestLayouts()}
          parentSlots={createTestParentSlots()}
          onExtendsChange={onExtendsChange}
          onSlotsChange={onSlotsChange}
        />
      );

      expect(screen.getByText(/✓ 1개 컴포넌트/)).toBeInTheDocument();
    });

    it('기본값이 있는 빈 슬롯에 안내가 표시되어야 함', () => {
      const onExtendsChange = vi.fn();
      const onSlotsChange = vi.fn();

      render(
        <SlotEditor
          extendsLayout="_admin_base"
          slots={{}}
          availableLayouts={createTestLayouts()}
          parentSlots={createTestParentSlots()}
          onExtendsChange={onExtendsChange}
          onSlotsChange={onSlotsChange}
        />
      );

      expect(screen.getByText('(기본값 사용)')).toBeInTheDocument();
    });

    it('기본값이 없는 빈 슬롯에 경고가 표시되어야 함', () => {
      const onExtendsChange = vi.fn();
      const onSlotsChange = vi.fn();

      render(
        <SlotEditor
          extendsLayout="_admin_base"
          slots={{}}
          availableLayouts={createTestLayouts()}
          parentSlots={createTestParentSlots()}
          onExtendsChange={onExtendsChange}
          onSlotsChange={onSlotsChange}
        />
      );

      // content와 footer 슬롯이 비어있음 (2개)
      const emptySlots = screen.getAllByText('(비어있음)');
      expect(emptySlots.length).toBeGreaterThanOrEqual(1);
    });
  });

  describe('슬롯 상세 보기', () => {
    it('슬롯 클릭 시 상세 정보가 펼쳐져야 함', () => {
      const onExtendsChange = vi.fn();
      const onSlotsChange = vi.fn();

      render(
        <SlotEditor
          extendsLayout="_admin_base"
          slots={createTestSlots()}
          availableLayouts={createTestLayouts()}
          parentSlots={createTestParentSlots()}
          onExtendsChange={onExtendsChange}
          onSlotsChange={onSlotsChange}
        />
      );

      // content 슬롯 클릭
      fireEvent.click(screen.getByText('content'));

      // 상세 정보 표시
      expect(screen.getByText('슬롯 ID:')).toBeInTheDocument();
      expect(screen.getByText('할당된 컴포넌트')).toBeInTheDocument();
    });

    it('할당된 컴포넌트 목록이 표시되어야 함', () => {
      const onExtendsChange = vi.fn();
      const onSlotsChange = vi.fn();

      render(
        <SlotEditor
          extendsLayout="_admin_base"
          slots={createTestSlots()}
          availableLayouts={createTestLayouts()}
          parentSlots={createTestParentSlots()}
          onExtendsChange={onExtendsChange}
          onSlotsChange={onSlotsChange}
        />
      );

      // content 슬롯 클릭
      fireEvent.click(screen.getByText('content'));

      // 컴포넌트 정보 표시 - li 요소 내에서 확인
      const listItems = screen.getAllByRole('listitem');
      const divItem = listItems.find((li) => li.textContent?.includes('Div'));
      expect(divItem).toBeDefined();
      expect(divItem?.textContent).toContain('custom-content');
    });
  });

  describe('슬롯 초기화', () => {
    it('초기화 버튼 클릭 시 슬롯이 비워져야 함', () => {
      const onExtendsChange = vi.fn();
      const onSlotsChange = vi.fn();

      render(
        <SlotEditor
          extendsLayout="_admin_base"
          slots={createTestSlots()}
          availableLayouts={createTestLayouts()}
          parentSlots={createTestParentSlots()}
          onExtendsChange={onExtendsChange}
          onSlotsChange={onSlotsChange}
        />
      );

      // 초기화 버튼 클릭
      const clearButton = screen.getByText('초기화');
      fireEvent.click(clearButton);

      expect(onSlotsChange).toHaveBeenCalledWith({});
    });
  });

  describe('상속 체인 시각화', () => {
    it('상속 시 체인 시각화가 표시되어야 함', () => {
      const onExtendsChange = vi.fn();
      const onSlotsChange = vi.fn();

      render(
        <SlotEditor
          extendsLayout="_admin_base"
          slots={{}}
          availableLayouts={createTestLayouts()}
          parentSlots={createTestParentSlots()}
          onExtendsChange={onExtendsChange}
          onSlotsChange={onSlotsChange}
        />
      );

      expect(screen.getByText('상속 체인')).toBeInTheDocument();
      expect(screen.getByText('현재 레이아웃')).toBeInTheDocument();
      expect(screen.getByText('_admin_base')).toBeInTheDocument();
    });
  });

  describe('JSON 미리보기', () => {
    it('슬롯이 채워져 있으면 JSON 미리보기가 표시되어야 함', () => {
      const onExtendsChange = vi.fn();
      const onSlotsChange = vi.fn();

      render(
        <SlotEditor
          extendsLayout="_admin_base"
          slots={createTestSlots()}
          availableLayouts={createTestLayouts()}
          parentSlots={createTestParentSlots()}
          onExtendsChange={onExtendsChange}
          onSlotsChange={onSlotsChange}
        />
      );

      expect(screen.getByText('Slots JSON 미리보기')).toBeInTheDocument();
      expect(screen.getByText(/\"extends\": \"_admin_base\"/)).toBeInTheDocument();
    });

    it('슬롯이 비어있으면 JSON 미리보기가 표시되지 않아야 함', () => {
      const onExtendsChange = vi.fn();
      const onSlotsChange = vi.fn();

      render(
        <SlotEditor
          extendsLayout="_admin_base"
          slots={{}}
          availableLayouts={createTestLayouts()}
          parentSlots={createTestParentSlots()}
          onExtendsChange={onExtendsChange}
          onSlotsChange={onSlotsChange}
        />
      );

      expect(screen.queryByText('Slots JSON 미리보기')).not.toBeInTheDocument();
    });
  });

  describe('레이아웃 옵션', () => {
    it('사용 가능한 레이아웃이 옵션으로 표시되어야 함', () => {
      const onExtendsChange = vi.fn();
      const onSlotsChange = vi.fn();

      render(
        <SlotEditor
          extendsLayout={null}
          slots={{}}
          availableLayouts={createTestLayouts()}
          parentSlots={[]}
          onExtendsChange={onExtendsChange}
          onSlotsChange={onSlotsChange}
        />
      );

      expect(screen.getByText('Admin 기본 레이아웃')).toBeInTheDocument();
      expect(screen.getByText('User 기본 레이아웃')).toBeInTheDocument();
      expect(screen.getByText('대시보드')).toBeInTheDocument();
    });
  });
});
