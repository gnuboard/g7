/**
 * ComponentPalette.test.tsx
 *
 * G7 위지윅 레이아웃 편집기 - 컴포넌트 팔레트 테스트
 *
 * 테스트 항목:
 * 1. 기본 렌더링
 * 2. 카테고리 필터링
 * 3. 검색 기능
 * 4. 드래그 이벤트
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ComponentPalette } from '../components/ComponentPalette';
import type { ComponentCategories, ExtensionComponent } from '../types/editor';
import type { ComponentMetadata } from '../../ComponentRegistry';

// ============================================================================
// 테스트 데이터
// ============================================================================

const createMockComponentMetadata = (name: string, type: 'basic' | 'composite' | 'layout'): ComponentMetadata => ({
  name,
  type,
  description: `${name} 컴포넌트`,
  props: {},
});

const createMockExtensionComponent = (name: string, source: string): ExtensionComponent => ({
  name,
  source,
  sourceType: 'module',
  type: 'composite',
  description: `${name} 확장 컴포넌트`,
});

const createMockCategories = (): ComponentCategories => ({
  basic: [
    createMockComponentMetadata('Button', 'basic'),
    createMockComponentMetadata('Input', 'basic'),
    createMockComponentMetadata('Div', 'basic'),
  ],
  composite: [
    createMockComponentMetadata('Card', 'composite'),
    createMockComponentMetadata('Modal', 'composite'),
  ],
  layout: [
    createMockComponentMetadata('Grid', 'layout'),
    createMockComponentMetadata('Flex', 'layout'),
  ],
  extension: [
    createMockExtensionComponent('ProductCard', 'sirsoft-ecommerce'),
    createMockExtensionComponent('CartWidget', 'sirsoft-ecommerce'),
  ],
});

// ============================================================================
// 테스트
// ============================================================================

describe('ComponentPalette', () => {
  const mockCategories = createMockCategories();
  const mockOnSearchChange = vi.fn();
  const mockOnDragStart = vi.fn();

  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe('렌더링', () => {
    it('팔레트가 올바르게 렌더링되어야 함', () => {
      render(
        <ComponentPalette
          categories={mockCategories}
          searchQuery=""
          onSearchChange={mockOnSearchChange}
          onDragStart={mockOnDragStart}
        />
      );

      expect(screen.getByTestId('component-palette')).toBeInTheDocument();
      expect(screen.getByText('컴포넌트')).toBeInTheDocument();
    });

    it('검색 입력 필드가 렌더링되어야 함', () => {
      render(
        <ComponentPalette
          categories={mockCategories}
          searchQuery=""
          onSearchChange={mockOnSearchChange}
          onDragStart={mockOnDragStart}
        />
      );

      expect(screen.getByTestId('palette-search')).toBeInTheDocument();
      expect(screen.getByPlaceholderText('컴포넌트 검색...')).toBeInTheDocument();
    });

    it('카테고리 탭들이 렌더링되어야 함', () => {
      render(
        <ComponentPalette
          categories={mockCategories}
          searchQuery=""
          onSearchChange={mockOnSearchChange}
          onDragStart={mockOnDragStart}
        />
      );

      expect(screen.getByTestId('category-tab-all')).toBeInTheDocument();
      expect(screen.getByTestId('category-tab-basic')).toBeInTheDocument();
      expect(screen.getByTestId('category-tab-composite')).toBeInTheDocument();
      expect(screen.getByTestId('category-tab-layout')).toBeInTheDocument();
      expect(screen.getByTestId('category-tab-extension')).toBeInTheDocument();
    });

    it('컴포넌트 목록이 표시되어야 함', () => {
      render(
        <ComponentPalette
          categories={mockCategories}
          searchQuery=""
          onSearchChange={mockOnSearchChange}
          onDragStart={mockOnDragStart}
        />
      );

      // 기본 카테고리 헤더가 표시되어야 함
      expect(screen.getByTestId('category-header-basic')).toBeInTheDocument();
      expect(screen.getByTestId('category-header-composite')).toBeInTheDocument();
      expect(screen.getByTestId('category-header-layout')).toBeInTheDocument();
      expect(screen.getByTestId('category-header-extension')).toBeInTheDocument();
    });

    it('컴포넌트 아이템들이 표시되어야 함', () => {
      render(
        <ComponentPalette
          categories={mockCategories}
          searchQuery=""
          onSearchChange={mockOnSearchChange}
          onDragStart={mockOnDragStart}
        />
      );

      // Basic 컴포넌트
      expect(screen.getByTestId('palette-item-Button')).toBeInTheDocument();
      expect(screen.getByTestId('palette-item-Input')).toBeInTheDocument();
      expect(screen.getByTestId('palette-item-Div')).toBeInTheDocument();

      // Composite 컴포넌트
      expect(screen.getByTestId('palette-item-Card')).toBeInTheDocument();
      expect(screen.getByTestId('palette-item-Modal')).toBeInTheDocument();

      // Layout 컴포넌트
      expect(screen.getByTestId('palette-item-Grid')).toBeInTheDocument();
      expect(screen.getByTestId('palette-item-Flex')).toBeInTheDocument();

      // Extension 컴포넌트
      expect(screen.getByTestId('palette-item-ProductCard')).toBeInTheDocument();
      expect(screen.getByTestId('palette-item-CartWidget')).toBeInTheDocument();
    });

    it('빈 카테고리일 때 빈 상태 메시지가 표시되어야 함', () => {
      const emptyCategories: ComponentCategories = {
        basic: [],
        composite: [],
        layout: [],
        extension: [],
      };

      render(
        <ComponentPalette
          categories={emptyCategories}
          searchQuery=""
          onSearchChange={mockOnSearchChange}
          onDragStart={mockOnDragStart}
        />
      );

      expect(screen.getByText('컴포넌트가 없습니다')).toBeInTheDocument();
    });
  });

  describe('카테고리 필터링', () => {
    it('카테고리 탭 클릭 시 해당 카테고리만 표시되어야 함', () => {
      render(
        <ComponentPalette
          categories={mockCategories}
          searchQuery=""
          onSearchChange={mockOnSearchChange}
          onDragStart={mockOnDragStart}
        />
      );

      // Basic 탭 클릭
      fireEvent.click(screen.getByTestId('category-tab-basic'));

      // Basic 컴포넌트만 표시
      expect(screen.getByTestId('palette-item-Button')).toBeInTheDocument();
      expect(screen.getByTestId('palette-item-Input')).toBeInTheDocument();
      expect(screen.getByTestId('palette-item-Div')).toBeInTheDocument();

      // 다른 카테고리 컴포넌트는 숨겨짐
      expect(screen.queryByTestId('palette-item-Card')).not.toBeInTheDocument();
      expect(screen.queryByTestId('palette-item-Grid')).not.toBeInTheDocument();
    });

    it('전체 탭 클릭 시 모든 카테고리가 표시되어야 함', () => {
      render(
        <ComponentPalette
          categories={mockCategories}
          searchQuery=""
          onSearchChange={mockOnSearchChange}
          onDragStart={mockOnDragStart}
        />
      );

      // Basic 탭 클릭 후
      fireEvent.click(screen.getByTestId('category-tab-basic'));

      // 전체 탭 클릭
      fireEvent.click(screen.getByTestId('category-tab-all'));

      // 모든 컴포넌트가 다시 표시됨
      expect(screen.getByTestId('palette-item-Button')).toBeInTheDocument();
      expect(screen.getByTestId('palette-item-Card')).toBeInTheDocument();
      expect(screen.getByTestId('palette-item-Grid')).toBeInTheDocument();
    });

    it('카테고리 헤더 클릭 시 접기/펼치기가 되어야 함', () => {
      render(
        <ComponentPalette
          categories={mockCategories}
          searchQuery=""
          onSearchChange={mockOnSearchChange}
          onDragStart={mockOnDragStart}
        />
      );

      // 초기 상태: Basic 카테고리가 펼쳐져 있음
      expect(screen.getByTestId('palette-item-Button')).toBeInTheDocument();

      // Basic 카테고리 헤더 클릭 (접기)
      fireEvent.click(screen.getByTestId('category-header-basic'));

      // Basic 컴포넌트가 숨겨짐
      expect(screen.queryByTestId('palette-item-Button')).not.toBeInTheDocument();

      // 다시 클릭 (펼치기)
      fireEvent.click(screen.getByTestId('category-header-basic'));

      // Basic 컴포넌트가 다시 표시됨
      expect(screen.getByTestId('palette-item-Button')).toBeInTheDocument();
    });
  });

  describe('검색 기능', () => {
    it('검색어 입력 시 onSearchChange가 호출되어야 함', () => {
      render(
        <ComponentPalette
          categories={mockCategories}
          searchQuery=""
          onSearchChange={mockOnSearchChange}
          onDragStart={mockOnDragStart}
        />
      );

      const searchInput = screen.getByTestId('palette-search');
      fireEvent.change(searchInput, { target: { value: 'Button' } });

      expect(mockOnSearchChange).toHaveBeenCalledWith('Button');
    });

    it('검색 결과가 필터링되어야 함', () => {
      render(
        <ComponentPalette
          categories={mockCategories}
          searchQuery="Button"
          onSearchChange={mockOnSearchChange}
          onDragStart={mockOnDragStart}
        />
      );

      // Button만 표시
      expect(screen.getByTestId('palette-item-Button')).toBeInTheDocument();

      // 다른 컴포넌트는 숨겨짐
      expect(screen.queryByTestId('palette-item-Input')).not.toBeInTheDocument();
      expect(screen.queryByTestId('palette-item-Card')).not.toBeInTheDocument();
    });

    it('검색 결과가 없을 때 메시지가 표시되어야 함', () => {
      render(
        <ComponentPalette
          categories={mockCategories}
          searchQuery="NonExistentComponent"
          onSearchChange={mockOnSearchChange}
          onDragStart={mockOnDragStart}
        />
      );

      expect(screen.getByText('검색 결과가 없습니다')).toBeInTheDocument();
    });

    it('확장 컴포넌트 출처로 검색 가능해야 함', () => {
      render(
        <ComponentPalette
          categories={mockCategories}
          searchQuery="ecommerce"
          onSearchChange={mockOnSearchChange}
          onDragStart={mockOnDragStart}
        />
      );

      // ecommerce 모듈의 확장 컴포넌트만 표시
      expect(screen.getByTestId('palette-item-ProductCard')).toBeInTheDocument();
      expect(screen.getByTestId('palette-item-CartWidget')).toBeInTheDocument();

      // 다른 컴포넌트는 숨겨짐
      expect(screen.queryByTestId('palette-item-Button')).not.toBeInTheDocument();
    });
  });

  describe('드래그 이벤트', () => {
    it('컴포넌트 아이템이 draggable이어야 함', () => {
      render(
        <ComponentPalette
          categories={mockCategories}
          searchQuery=""
          onSearchChange={mockOnSearchChange}
          onDragStart={mockOnDragStart}
        />
      );

      const buttonItem = screen.getByTestId('palette-item-Button');
      expect(buttonItem).toHaveAttribute('draggable', 'true');
    });

    it('드래그 시작 시 onDragStart가 호출되어야 함', () => {
      render(
        <ComponentPalette
          categories={mockCategories}
          searchQuery=""
          onSearchChange={mockOnSearchChange}
          onDragStart={mockOnDragStart}
        />
      );

      const buttonItem = screen.getByTestId('palette-item-Button');

      // 드래그 시작 이벤트 생성
      const dataTransfer = {
        setData: vi.fn(),
        effectAllowed: '',
      };

      fireEvent.dragStart(buttonItem, { dataTransfer });

      expect(mockOnDragStart).toHaveBeenCalled();
      expect(dataTransfer.setData).toHaveBeenCalledWith(
        'application/json',
        expect.any(String)
      );
    });

    it('드래그 데이터에 컴포넌트 정보가 포함되어야 함', () => {
      render(
        <ComponentPalette
          categories={mockCategories}
          searchQuery=""
          onSearchChange={mockOnSearchChange}
          onDragStart={mockOnDragStart}
        />
      );

      const buttonItem = screen.getByTestId('palette-item-Button');

      let capturedData = '';
      const dataTransfer = {
        setData: vi.fn((type, data) => {
          capturedData = data;
        }),
        effectAllowed: '',
      };

      fireEvent.dragStart(buttonItem, { dataTransfer });

      const parsedData = JSON.parse(capturedData);
      expect(parsedData).toEqual({
        type: 'component-palette',
        componentName: 'Button',
        componentType: 'basic',
        isExtension: false,
        source: undefined,
      });
    });

    it('확장 컴포넌트 드래그 시 출처 정보가 포함되어야 함', () => {
      render(
        <ComponentPalette
          categories={mockCategories}
          searchQuery=""
          onSearchChange={mockOnSearchChange}
          onDragStart={mockOnDragStart}
        />
      );

      const productCardItem = screen.getByTestId('palette-item-ProductCard');

      let capturedData = '';
      const dataTransfer = {
        setData: vi.fn((type, data) => {
          capturedData = data;
        }),
        effectAllowed: '',
      };

      fireEvent.dragStart(productCardItem, { dataTransfer });

      const parsedData = JSON.parse(capturedData);
      expect(parsedData).toEqual({
        type: 'component-palette',
        componentName: 'ProductCard',
        componentType: 'composite',
        isExtension: true,
        source: 'sirsoft-ecommerce',
      });
    });
  });

  describe('확장 컴포넌트', () => {
    it('확장 컴포넌트에 출처가 표시되어야 함', () => {
      render(
        <ComponentPalette
          categories={mockCategories}
          searchQuery=""
          onSearchChange={mockOnSearchChange}
          onDragStart={mockOnDragStart}
        />
      );

      const productCardItem = screen.getByTestId('palette-item-ProductCard');
      expect(productCardItem).toHaveTextContent('sirsoft-ecommerce');
    });

    it('확장 카테고리 탭 클릭 시 확장 컴포넌트만 표시되어야 함', () => {
      render(
        <ComponentPalette
          categories={mockCategories}
          searchQuery=""
          onSearchChange={mockOnSearchChange}
          onDragStart={mockOnDragStart}
        />
      );

      fireEvent.click(screen.getByTestId('category-tab-extension'));

      // 확장 컴포넌트만 표시
      expect(screen.getByTestId('palette-item-ProductCard')).toBeInTheDocument();
      expect(screen.getByTestId('palette-item-CartWidget')).toBeInTheDocument();

      // 일반 컴포넌트는 숨겨짐
      expect(screen.queryByTestId('palette-item-Button')).not.toBeInTheDocument();
      expect(screen.queryByTestId('palette-item-Card')).not.toBeInTheDocument();
    });
  });

  describe('카운트 표시', () => {
    it('각 카테고리 탭에 컴포넌트 수가 표시되어야 함', () => {
      render(
        <ComponentPalette
          categories={mockCategories}
          searchQuery=""
          onSearchChange={mockOnSearchChange}
          onDragStart={mockOnDragStart}
        />
      );

      // 전체: 9개 (3 basic + 2 composite + 2 layout + 2 extension)
      expect(screen.getByTestId('category-tab-all')).toHaveTextContent('(9)');
      expect(screen.getByTestId('category-tab-basic')).toHaveTextContent('(3)');
      expect(screen.getByTestId('category-tab-composite')).toHaveTextContent('(2)');
      expect(screen.getByTestId('category-tab-layout')).toHaveTextContent('(2)');
      expect(screen.getByTestId('category-tab-extension')).toHaveTextContent('(2)');
    });
  });
});
