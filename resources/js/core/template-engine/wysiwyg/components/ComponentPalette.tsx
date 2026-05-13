/**
 * ComponentPalette.tsx
 *
 * G7 위지윅 레이아웃 편집기 - 컴포넌트 팔레트
 *
 * 역할:
 * - 사용 가능한 컴포넌트 목록 표시
 * - 카테고리별 분류 (Basic, Composite, Layout, Extension)
 * - 검색 기능
 * - 드래그앤드롭 시작점
 *
 * Phase 2: 핵심 편집 기능
 */

import React, { useState, useMemo, useCallback } from 'react';
import type {
  ComponentPaletteProps,
  ComponentCategories,
  ExtensionComponent,
} from '../types/editor';
import type { ComponentMetadata } from '../../ComponentRegistry';

// ============================================================================
// 타입 정의
// ============================================================================

/** 팔레트 카테고리 */
type PaletteCategory = 'all' | 'basic' | 'composite' | 'layout' | 'extension';

/** 팔레트 아이템 */
interface PaletteItem {
  /** 컴포넌트 이름 */
  name: string;
  /** 컴포넌트 타입 */
  type: 'basic' | 'composite' | 'layout';
  /** 설명 */
  description?: string;
  /** 출처 (확장 컴포넌트인 경우) */
  source?: string;
  /** 원본 메타데이터 또는 확장 컴포넌트 */
  original: ComponentMetadata | ExtensionComponent;
  /** 확장 컴포넌트 여부 */
  isExtension: boolean;
}

// ============================================================================
// 카테고리 아이콘
// ============================================================================

const CATEGORY_ICONS: Record<PaletteCategory, string> = {
  all: '📦',
  basic: '🔲',
  composite: '🧩',
  layout: '📐',
  extension: '🔌',
};

const CATEGORY_LABELS: Record<PaletteCategory, string> = {
  all: '전체',
  basic: '기본',
  composite: '집합',
  layout: '레이아웃',
  extension: '확장',
};

// ============================================================================
// 컴포넌트 타입 아이콘
// ============================================================================

const getComponentIcon = (type: string, isExtension: boolean): string => {
  if (isExtension) return '🔌';
  switch (type) {
    case 'basic':
      return '🔲';
    case 'composite':
      return '🧩';
    case 'layout':
      return '📐';
    default:
      return '📦';
  }
};

// ============================================================================
// 팔레트 아이템 컴포넌트
// ============================================================================

interface PaletteItemComponentProps {
  item: PaletteItem;
  onDragStart: (component: ComponentMetadata | ExtensionComponent) => void;
}

const PaletteItemComponent: React.FC<PaletteItemComponentProps> = React.memo(({
  item,
  onDragStart,
}) => {
  const handleDragStart = useCallback(
    (e: React.DragEvent) => {
      e.dataTransfer.setData('application/json', JSON.stringify({
        type: 'component-palette',
        componentName: item.name,
        componentType: item.type,
        isExtension: item.isExtension,
        source: item.source,
      }));
      e.dataTransfer.effectAllowed = 'copy';
      onDragStart(item.original);
    },
    [item, onDragStart]
  );

  return (
    <div
      className="flex items-center gap-2 p-2 rounded-md cursor-grab bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 border border-gray-200 dark:border-gray-600 transition-colors"
      draggable
      onDragStart={handleDragStart}
      title={item.description || item.name}
      data-testid={`palette-item-${item.name}`}
    >
      {/* 아이콘 */}
      <span className="text-sm flex-shrink-0">
        {getComponentIcon(item.type, item.isExtension)}
      </span>

      {/* 컴포넌트 이름 */}
      <div className="flex-1 min-w-0">
        <div className="text-sm font-medium text-gray-900 dark:text-white truncate">
          {item.name}
        </div>
        {item.source && (
          <div className="text-xs text-gray-500 dark:text-gray-400 truncate">
            {item.source}
          </div>
        )}
      </div>

      {/* 타입 배지 */}
      <span className="text-xs px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-600 text-gray-600 dark:text-gray-300 flex-shrink-0">
        {item.type}
      </span>
    </div>
  );
});

// ============================================================================
// 카테고리 헤더 컴포넌트 (React.memo로 최적화)
// ============================================================================

interface CategoryHeaderProps {
  category: PaletteCategory;
  count: number;
  isExpanded: boolean;
  onToggle: () => void;
}

const CategoryHeader: React.FC<CategoryHeaderProps> = React.memo(({
  category,
  count,
  isExpanded,
  onToggle,
}) => (
  <button
    className="flex items-center justify-between w-full px-3 py-2 text-left bg-gray-50 dark:bg-gray-800 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-md transition-colors"
    onClick={onToggle}
    data-testid={`category-header-${category}`}
  >
    <div className="flex items-center gap-2">
      <span>{CATEGORY_ICONS[category]}</span>
      <span className="font-medium text-gray-900 dark:text-white">
        {CATEGORY_LABELS[category]}
      </span>
      <span className="text-xs text-gray-500 dark:text-gray-400">
        ({count})
      </span>
    </div>
    <span className="text-gray-400 dark:text-gray-500">
      {isExpanded ? '▼' : '▶'}
    </span>
  </button>
));

// ============================================================================
// 메인 컴포넌트: ComponentPalette
// ============================================================================

export const ComponentPalette: React.FC<ComponentPaletteProps> = ({
  categories,
  searchQuery,
  onSearchChange,
  onDragStart,
}) => {
  // 확장된 카테고리 상태
  const [expandedCategories, setExpandedCategories] = useState<Set<PaletteCategory>>(
    new Set(['basic', 'composite', 'layout', 'extension'])
  );

  // 활성 카테고리 필터
  const [activeCategory, setActiveCategory] = useState<PaletteCategory>('all');

  // 카테고리 토글
  const toggleCategory = useCallback((category: PaletteCategory) => {
    setExpandedCategories(prev => {
      const next = new Set(prev);
      if (next.has(category)) {
        next.delete(category);
      } else {
        next.add(category);
      }
      return next;
    });
  }, []);

  // 팔레트 아이템 변환
  const allItems = useMemo((): PaletteItem[] => {
    const items: PaletteItem[] = [];

    // Basic 컴포넌트
    if (categories.basic) {
      categories.basic.forEach(comp => {
        items.push({
          name: comp.name,
          type: 'basic',
          description: comp.description,
          original: comp,
          isExtension: false,
        });
      });
    }

    // Composite 컴포넌트
    if (categories.composite) {
      categories.composite.forEach(comp => {
        items.push({
          name: comp.name,
          type: 'composite',
          description: comp.description,
          original: comp,
          isExtension: false,
        });
      });
    }

    // Layout 컴포넌트
    if (categories.layout) {
      categories.layout.forEach(comp => {
        items.push({
          name: comp.name,
          type: 'layout',
          description: comp.description,
          original: comp,
          isExtension: false,
        });
      });
    }

    // Extension 컴포넌트
    if (categories.extension) {
      categories.extension.forEach(comp => {
        items.push({
          name: comp.name,
          type: comp.type as 'basic' | 'composite' | 'layout',
          description: comp.description,
          source: comp.source,
          original: comp,
          isExtension: true,
        });
      });
    }

    return items;
  }, [categories]);

  // 검색 필터링
  const filteredItems = useMemo(() => {
    let items = allItems;

    // 검색어 필터
    if (searchQuery.trim()) {
      const query = searchQuery.toLowerCase();
      items = items.filter(
        item =>
          item.name.toLowerCase().includes(query) ||
          item.description?.toLowerCase().includes(query) ||
          item.source?.toLowerCase().includes(query)
      );
    }

    // 카테고리 필터
    if (activeCategory !== 'all') {
      if (activeCategory === 'extension') {
        items = items.filter(item => item.isExtension);
      } else {
        items = items.filter(item => !item.isExtension && item.type === activeCategory);
      }
    }

    return items;
  }, [allItems, searchQuery, activeCategory]);

  // 카테고리별 그룹화
  const groupedItems = useMemo(() => {
    const groups: Record<PaletteCategory, PaletteItem[]> = {
      all: [],
      basic: [],
      composite: [],
      layout: [],
      extension: [],
    };

    filteredItems.forEach(item => {
      if (item.isExtension) {
        groups.extension.push(item);
      } else {
        groups[item.type as 'basic' | 'composite' | 'layout'].push(item);
      }
    });

    return groups;
  }, [filteredItems]);

  // 카테고리 카운트
  const categoryCounts = useMemo(() => ({
    all: filteredItems.length,
    basic: groupedItems.basic.length,
    composite: groupedItems.composite.length,
    layout: groupedItems.layout.length,
    extension: groupedItems.extension.length,
  }), [filteredItems, groupedItems]);

  return (
    <div className="flex flex-col h-full bg-white dark:bg-gray-800" data-testid="component-palette">
      {/* 헤더 */}
      <div className="flex-shrink-0 p-3 border-b border-gray-200 dark:border-gray-700">
        <h3 className="text-sm font-semibold text-gray-900 dark:text-white mb-2">
          컴포넌트
        </h3>

        {/* 검색 입력 */}
        <input
          type="text"
          value={searchQuery}
          onChange={(e) => onSearchChange(e.target.value)}
          placeholder="컴포넌트 검색..."
          className="w-full px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
          data-testid="palette-search"
        />
      </div>

      {/* 카테고리 탭 */}
      <div className="flex-shrink-0 flex gap-1 p-2 border-b border-gray-200 dark:border-gray-700 overflow-x-auto">
        {(['all', 'basic', 'composite', 'layout', 'extension'] as PaletteCategory[]).map(cat => (
          <button
            key={cat}
            onClick={() => setActiveCategory(cat)}
            className={`
              flex items-center gap-1 px-2 py-1 text-xs rounded-md whitespace-nowrap transition-colors
              ${activeCategory === cat
                ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300'
                : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-600'
              }
            `}
            title={CATEGORY_LABELS[cat]}
            data-testid={`category-tab-${cat}`}
          >
            <span>{CATEGORY_ICONS[cat]}</span>
            <span className="hidden sm:inline">{CATEGORY_LABELS[cat]}</span>
            <span className="text-[10px] opacity-75">({categoryCounts[cat]})</span>
          </button>
        ))}
      </div>

      {/* 컴포넌트 목록 */}
      <div className="flex-1 overflow-y-auto p-2 space-y-2">
        {filteredItems.length === 0 ? (
          <div className="text-center py-8 text-gray-500 dark:text-gray-400">
            {searchQuery ? '검색 결과가 없습니다' : '컴포넌트가 없습니다'}
          </div>
        ) : activeCategory === 'all' ? (
          // 전체 보기: 카테고리별 그룹화
          <>
            {/* Basic */}
            {groupedItems.basic.length > 0 && (
              <div className="space-y-1">
                <CategoryHeader
                  category="basic"
                  count={groupedItems.basic.length}
                  isExpanded={expandedCategories.has('basic')}
                  onToggle={() => toggleCategory('basic')}
                />
                {expandedCategories.has('basic') && (
                  <div className="pl-2 space-y-1">
                    {groupedItems.basic.map(item => (
                      <PaletteItemComponent
                        key={item.name}
                        item={item}
                        onDragStart={onDragStart}
                      />
                    ))}
                  </div>
                )}
              </div>
            )}

            {/* Composite */}
            {groupedItems.composite.length > 0 && (
              <div className="space-y-1">
                <CategoryHeader
                  category="composite"
                  count={groupedItems.composite.length}
                  isExpanded={expandedCategories.has('composite')}
                  onToggle={() => toggleCategory('composite')}
                />
                {expandedCategories.has('composite') && (
                  <div className="pl-2 space-y-1">
                    {groupedItems.composite.map(item => (
                      <PaletteItemComponent
                        key={item.name}
                        item={item}
                        onDragStart={onDragStart}
                      />
                    ))}
                  </div>
                )}
              </div>
            )}

            {/* Layout */}
            {groupedItems.layout.length > 0 && (
              <div className="space-y-1">
                <CategoryHeader
                  category="layout"
                  count={groupedItems.layout.length}
                  isExpanded={expandedCategories.has('layout')}
                  onToggle={() => toggleCategory('layout')}
                />
                {expandedCategories.has('layout') && (
                  <div className="pl-2 space-y-1">
                    {groupedItems.layout.map(item => (
                      <PaletteItemComponent
                        key={item.name}
                        item={item}
                        onDragStart={onDragStart}
                      />
                    ))}
                  </div>
                )}
              </div>
            )}

            {/* Extension */}
            {groupedItems.extension.length > 0 && (
              <div className="space-y-1">
                <CategoryHeader
                  category="extension"
                  count={groupedItems.extension.length}
                  isExpanded={expandedCategories.has('extension')}
                  onToggle={() => toggleCategory('extension')}
                />
                {expandedCategories.has('extension') && (
                  <div className="pl-2 space-y-1">
                    {groupedItems.extension.map(item => (
                      <PaletteItemComponent
                        key={`${item.source}-${item.name}`}
                        item={item}
                        onDragStart={onDragStart}
                      />
                    ))}
                  </div>
                )}
              </div>
            )}
          </>
        ) : (
          // 특정 카테고리 보기: 플랫 리스트
          <div className="space-y-1">
            {filteredItems.map(item => (
              <PaletteItemComponent
                key={item.isExtension ? `${item.source}-${item.name}` : item.name}
                item={item}
                onDragStart={onDragStart}
              />
            ))}
          </div>
        )}
      </div>

      {/* 푸터: 드래그 힌트 */}
      <div className="flex-shrink-0 p-2 border-t border-gray-200 dark:border-gray-700">
        <p className="text-xs text-gray-500 dark:text-gray-400 text-center">
          컴포넌트를 드래그하여 캔버스에 배치하세요
        </p>
      </div>
    </div>
  );
};

export default ComponentPalette;
