import React, { useCallback, useMemo } from 'react';
import { Div } from '../basic/Div';
import { Span } from '../basic/Span';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';
import { Checkbox } from '../basic/Checkbox';

/**
 * 카테고리 노드 인터페이스 (CategoryResource API 응답 기반)
 */
export interface CategoryNode {
  id: number;
  name: string;
  localized_name?: string;
  path?: string;
  products_count?: number;
  children_count?: number;
  children?: CategoryNode[];
}

export interface CategoryTreeProps {
  /** 카테고리 트리 데이터 (CategoryResource API 응답) */
  data?: CategoryNode[];
  /** 펼쳐진 노드 ID 배열 */
  expandedIds?: number[];
  /** 선택된 노드 ID 배열 */
  selectedIds?: number[];
  /** 검색 키워드 (하이라이트용) */
  searchKeyword?: string;
  /** 상품 수 표시 여부 */
  showProductCount?: boolean;
  /** 체크박스 선택 기능 활성화 */
  selectable?: boolean;
  /** 추가 클래스명 */
  className?: string;
  /** 펼치기/접기 콜백: (expandedIds: number[]) => void */
  onToggle?: (expandedIds: number[]) => void;
  /** 선택 변경 콜백: (selectedIds: number[]) => void */
  onSelectionChange?: (selectedIds: number[]) => void;
}

/**
 * 텍스트에서 키워드를 하이라이트하여 렌더링합니다.
 */
function HighlightText({ text, keyword }: { text: string; keyword: string }) {
  if (!keyword || !text) {
    return <>{text}</>;
  }

  const escaped = keyword.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const parts = text.split(new RegExp(`(${escaped})`, 'gi'));

  return (
    <>
      {parts.map((part, i) =>
        part.toLowerCase() === keyword.toLowerCase() ? (
          <Span key={i} className="bg-yellow-200 dark:bg-yellow-700 rounded px-0.5">
            {part}
          </Span>
        ) : (
          part
        )
      )}
    </>
  );
}

/**
 * CategoryTree 집합 컴포넌트
 *
 * 계층형 카테고리 트리를 렌더링하고 체크박스로 카테고리를 선택할 수 있습니다.
 * expandedIds와 selectedIds를 외부에서 제어하는 Controlled 컴포넌트입니다.
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "CategoryTree",
 *   "props": {
 *     "data": "{{categories?.data?.data ?? []}}",
 *     "expandedIds": "{{_local.categoryExpandedIds ?? []}}",
 *     "selectedIds": "{{_local.categorySearchSelected ?? []}}",
 *     "searchKeyword": "{{_local.categorySearchKeyword ?? ''}}",
 *     "showProductCount": true,
 *     "selectable": true
 *   },
 *   "actions": [
 *     {
 *       "event": "onToggle",
 *       "handler": "setState",
 *       "params": { "target": "_local", "categoryExpandedIds": "{{$args[0]}}" }
 *     },
 *     {
 *       "event": "onSelectionChange",
 *       "handler": "setState",
 *       "params": { "target": "_local", "categorySearchSelected": "{{$args[0]}}" }
 *     }
 *   ]
 * }
 */
export const CategoryTree: React.FC<CategoryTreeProps> = ({
  data = [],
  expandedIds = [],
  selectedIds = [],
  searchKeyword = '',
  showProductCount = false,
  selectable = false,
  className = '',
  onToggle,
  onSelectionChange,
}) => {
  /**
   * 노드 펼치기/접기 토글
   */
  const handleToggle = useCallback(
    (nodeId: number) => {
      if (!onToggle) return;
      const newExpandedIds = expandedIds.includes(nodeId)
        ? expandedIds.filter((id) => id !== nodeId)
        : [...expandedIds, nodeId];
      onToggle(newExpandedIds);
    },
    [expandedIds, onToggle]
  );

  /**
   * 노드 선택/해제 토글
   */
  const handleSelect = useCallback(
    (nodeId: number, checked: boolean) => {
      if (!onSelectionChange) return;
      const newSelectedIds = checked
        ? [...selectedIds, nodeId]
        : selectedIds.filter((id) => id !== nodeId);
      onSelectionChange(newSelectedIds);
    },
    [selectedIds, onSelectionChange]
  );

  /**
   * 검색 키워드와 매칭되는 노드 ID 집합 (자동 필터링용)
   */
  const matchedNodeIds = useMemo(() => {
    if (!searchKeyword) return null;
    const ids = new Set<number>();

    const findMatches = (nodes: CategoryNode[]) => {
      for (const node of nodes) {
        const displayName = node.localized_name || node.name;
        if (displayName.toLowerCase().includes(searchKeyword.toLowerCase())) {
          ids.add(node.id);
        }
        if (node.children) {
          findMatches(node.children);
        }
      }
    };

    findMatches(data);
    return ids;
  }, [data, searchKeyword]);

  /**
   * 노드 또는 하위 노드 중 검색어에 매칭되는 것이 있는지 확인
   */
  const hasMatchInSubtree = useCallback(
    (node: CategoryNode): boolean => {
      if (!matchedNodeIds) return true;
      if (matchedNodeIds.has(node.id)) return true;
      return (node.children ?? []).some((child) => hasMatchInSubtree(child));
    },
    [matchedNodeIds]
  );

  /**
   * 카테고리 노드 렌더링 (재귀)
   */
  const renderNode = (node: CategoryNode, depth: number = 0) => {
    const hasChildren = (node.children ?? []).length > 0;
    const isExpanded = expandedIds.includes(node.id);
    const isSelected = selectedIds.includes(node.id);
    const displayName = node.localized_name || node.name;

    // 검색 키워드가 있는 경우 매칭되지 않는 서브트리 숨김
    if (searchKeyword && !hasMatchInSubtree(node)) {
      return null;
    }

    return (
      <Div key={node.id} data-testid={`category-node-${node.id}`}>
        {/* 노드 행 */}
        <Div
          className={`flex items-center gap-2 py-1.5 px-2 rounded cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors ${
            depth > 0 ? 'ml-' + Math.min(depth * 5, 20) : ''
          }`}
          style={depth > 0 ? { marginLeft: `${depth * 20}px` } : undefined}
        >
          {/* 펼치기/접기 토글 */}
          {hasChildren ? (
            <Div
              className="flex-shrink-0 w-5 h-5 flex items-center justify-center text-gray-400 dark:text-gray-500"
              onClick={() => handleToggle(node.id)}
              data-testid={`toggle-${node.id}`}
            >
              <Icon
                name={isExpanded ? IconName.CaretDown : IconName.CaretRight}
                className="text-xs"
              />
            </Div>
          ) : (
            <Div className="flex-shrink-0 w-5 h-5" />
          )}

          {/* 체크박스 */}
          {selectable && (
            <Checkbox
              checked={isSelected}
              onChange={(e: React.ChangeEvent<HTMLInputElement>) => {
                e.stopPropagation();
                handleSelect(node.id, e.target.checked);
              }}
              className="flex-shrink-0"
              data-testid={`checkbox-${node.id}`}
            />
          )}

          {/* 폴더 아이콘 */}
          <Icon
            name={IconName.Folder}
            className={`flex-shrink-0 text-sm ${
              hasChildren
                ? 'text-yellow-500 dark:text-yellow-400'
                : 'text-gray-400 dark:text-gray-500'
            }`}
          />

          {/* 카테고리 이름 */}
          <Span
            className="text-sm text-gray-700 dark:text-gray-300 select-none"
            onClick={() => hasChildren && handleToggle(node.id)}
          >
            <HighlightText text={displayName} keyword={searchKeyword} />
          </Span>

          {/* 상품 수 Badge */}
          {showProductCount && node.products_count !== undefined && (
            <Span className="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400 ml-1">
              {node.products_count}
            </Span>
          )}
        </Div>

        {/* 하위 노드 */}
        {hasChildren && isExpanded && (
          <Div data-testid={`children-${node.id}`}>
            {(node.children ?? []).map((child) => renderNode(child, depth + 1))}
          </Div>
        )}
      </Div>
    );
  };

  if (data.length === 0) {
    return (
      <Div className={`text-sm text-gray-400 dark:text-gray-500 py-4 text-center ${className}`}>
        데이터가 없습니다.
      </Div>
    );
  }

  return (
    <Div className={`border border-gray-200 dark:border-gray-700 rounded-lg p-2 max-h-80 overflow-y-auto ${className}`}>
      {data.map((node) => renderNode(node, 0))}
    </Div>
  );
};
