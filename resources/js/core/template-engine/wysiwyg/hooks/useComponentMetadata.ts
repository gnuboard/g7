/**
 * useComponentMetadata.ts
 *
 * G7 위지윅 레이아웃 편집기의 컴포넌트 메타데이터 훅
 *
 * 역할:
 * - 컴포넌트 메타데이터 조회
 * - 컴포넌트 카테고리 분류
 * - 컴포넌트 검색 및 필터링
 * - Props 스키마 조회
 */

import { useMemo, useCallback } from 'react';
import { useEditorState } from './useEditorState';
import type {
  ComponentMetadata,
  ComponentCategories,
  ExtensionComponent,
  PropSchema,
  HandlerInfo,
} from '../types/editor';

// ============================================================================
// 훅 타입 정의
// ============================================================================

export interface UseComponentMetadataReturn {
  /** 컴포넌트 카테고리 */
  categories: ComponentCategories | null;

  /** 모든 컴포넌트 메타데이터 */
  allComponents: ComponentMetadata[];

  /** 확장 컴포넌트 목록 */
  extensionComponents: ExtensionComponent[];

  /** 핸들러 목록 */
  handlers: HandlerInfo[];

  /** 컴포넌트 이름으로 메타데이터 조회 */
  getMetadata: (name: string) => ComponentMetadata | null;

  /** 컴포넌트 Props 스키마 조회 */
  getPropsSchema: (name: string) => PropSchema[];

  /** 컴포넌트 검색 */
  searchComponents: (query: string) => (ComponentMetadata | ExtensionComponent)[];

  /** 카테고리별 컴포넌트 필터링 */
  filterByCategory: (category: 'basic' | 'composite' | 'layout' | 'extension') => (ComponentMetadata | ExtensionComponent)[];

  /** 컴포넌트가 children을 허용하는지 확인 */
  allowsChildren: (name: string) => boolean;

  /** 핸들러 정보 조회 */
  getHandler: (name: string) => HandlerInfo | null;

  /** 카테고리별 핸들러 필터링 */
  filterHandlersByCategory: (category: 'built-in' | 'custom' | 'module') => HandlerInfo[];
}

// ============================================================================
// 기본 Props 스키마 (컴포넌트별)
// ============================================================================

/**
 * 기본 컴포넌트 Props 스키마
 */
const DEFAULT_PROPS_SCHEMAS: Record<string, PropSchema[]> = {
  // Basic Components
  Div: [
    { name: 'className', type: 'className', label: 'CSS 클래스', allowBinding: true },
  ],
  Span: [
    { name: 'className', type: 'className', label: 'CSS 클래스', allowBinding: true },
  ],
  Button: [
    { name: 'className', type: 'className', label: 'CSS 클래스', allowBinding: true },
    { name: 'type', type: 'select', label: '버튼 타입', options: [
      { label: 'Button', value: 'button' },
      { label: 'Submit', value: 'submit' },
      { label: 'Reset', value: 'reset' },
    ]},
    { name: 'disabled', type: 'boolean', label: '비활성화', allowBinding: true },
  ],
  Input: [
    { name: 'className', type: 'className', label: 'CSS 클래스', allowBinding: true },
    { name: 'type', type: 'select', label: '입력 타입', options: [
      { label: 'Text', value: 'text' },
      { label: 'Password', value: 'password' },
      { label: 'Email', value: 'email' },
      { label: 'Number', value: 'number' },
      { label: 'Tel', value: 'tel' },
      { label: 'Date', value: 'date' },
      { label: 'Hidden', value: 'hidden' },
    ]},
    { name: 'name', type: 'text', label: 'Name', required: true },
    { name: 'placeholder', type: 'text', label: 'Placeholder', allowBinding: true },
    { name: 'disabled', type: 'boolean', label: '비활성화', allowBinding: true },
    { name: 'required', type: 'boolean', label: '필수' },
  ],
  Label: [
    { name: 'className', type: 'className', label: 'CSS 클래스', allowBinding: true },
    { name: 'htmlFor', type: 'text', label: 'For (ID)' },
  ],
  Image: [
    { name: 'className', type: 'className', label: 'CSS 클래스', allowBinding: true },
    { name: 'src', type: 'text', label: '이미지 URL', required: true, allowBinding: true },
    { name: 'alt', type: 'text', label: '대체 텍스트', allowBinding: true },
  ],
  Link: [
    { name: 'className', type: 'className', label: 'CSS 클래스', allowBinding: true },
    { name: 'href', type: 'text', label: 'URL', allowBinding: true },
    { name: 'target', type: 'select', label: '열기 방식', options: [
      { label: '현재 창', value: '_self' },
      { label: '새 창', value: '_blank' },
    ]},
  ],
  Icon: [
    { name: 'className', type: 'className', label: 'CSS 클래스', allowBinding: true },
    { name: 'name', type: 'icon', label: '아이콘 이름', required: true },
    { name: 'size', type: 'select', label: '크기', options: [
      { label: 'xs', value: 'xs' },
      { label: 'sm', value: 'sm' },
      { label: 'md', value: 'md' },
      { label: 'lg', value: 'lg' },
      { label: 'xl', value: 'xl' },
    ]},
  ],
  H1: [{ name: 'className', type: 'className', label: 'CSS 클래스', allowBinding: true }],
  H2: [{ name: 'className', type: 'className', label: 'CSS 클래스', allowBinding: true }],
  H3: [{ name: 'className', type: 'className', label: 'CSS 클래스', allowBinding: true }],
  H4: [{ name: 'className', type: 'className', label: 'CSS 클래스', allowBinding: true }],
  H5: [{ name: 'className', type: 'className', label: 'CSS 클래스', allowBinding: true }],
  H6: [{ name: 'className', type: 'className', label: 'CSS 클래스', allowBinding: true }],
  P: [{ name: 'className', type: 'className', label: 'CSS 클래스', allowBinding: true }],
  Form: [
    { name: 'className', type: 'className', label: 'CSS 클래스', allowBinding: true },
    { name: 'dataKey', type: 'text', label: '데이터 키', required: true },
  ],
  Select: [
    { name: 'className', type: 'className', label: 'CSS 클래스', allowBinding: true },
    { name: 'name', type: 'text', label: 'Name', required: true },
    { name: 'placeholder', type: 'text', label: 'Placeholder' },
    { name: 'disabled', type: 'boolean', label: '비활성화', allowBinding: true },
  ],
  Textarea: [
    { name: 'className', type: 'className', label: 'CSS 클래스', allowBinding: true },
    { name: 'name', type: 'text', label: 'Name', required: true },
    { name: 'placeholder', type: 'text', label: 'Placeholder', allowBinding: true },
    { name: 'rows', type: 'number', label: '행 수', defaultValue: 3 },
    { name: 'disabled', type: 'boolean', label: '비활성화', allowBinding: true },
  ],

  // Composite Components
  Card: [
    { name: 'className', type: 'className', label: 'CSS 클래스', allowBinding: true },
    { name: 'title', type: 'text', label: '제목', allowBinding: true },
  ],
  Modal: [
    { name: 'id', type: 'text', label: '모달 ID', required: true },
    { name: 'title', type: 'text', label: '제목', allowBinding: true },
    { name: 'size', type: 'select', label: '크기', options: [
      { label: 'Small', value: 'sm' },
      { label: 'Medium', value: 'md' },
      { label: 'Large', value: 'lg' },
      { label: 'XLarge', value: 'xl' },
      { label: 'Full', value: 'full' },
    ]},
  ],
  DataGrid: [
    { name: 'className', type: 'className', label: 'CSS 클래스', allowBinding: true },
    { name: 'columns', type: 'array', label: '컬럼 정의', required: true },
    { name: 'data', type: 'expression', label: '데이터', required: true, allowBinding: true },
    { name: 'rowKey', type: 'text', label: '행 키', defaultValue: 'id' },
  ],
  PageHeader: [
    { name: 'className', type: 'className', label: 'CSS 클래스', allowBinding: true },
    { name: 'title', type: 'text', label: '제목', allowBinding: true },
    { name: 'description', type: 'text', label: '설명', allowBinding: true },
  ],
  Pagination: [
    { name: 'className', type: 'className', label: 'CSS 클래스', allowBinding: true },
    { name: 'current', type: 'expression', label: '현재 페이지', allowBinding: true },
    { name: 'total', type: 'expression', label: '전체 항목 수', allowBinding: true },
    { name: 'perPage', type: 'number', label: '페이지당 항목 수', defaultValue: 10 },
  ],
  Tabs: [
    { name: 'className', type: 'className', label: 'CSS 클래스', allowBinding: true },
    { name: 'defaultTab', type: 'text', label: '기본 탭' },
  ],
  Tab: [
    { name: 'id', type: 'text', label: '탭 ID', required: true },
    { name: 'label', type: 'text', label: '탭 레이블', required: true, allowBinding: true },
  ],
  Alert: [
    { name: 'className', type: 'className', label: 'CSS 클래스', allowBinding: true },
    { name: 'type', type: 'select', label: '타입', options: [
      { label: 'Info', value: 'info' },
      { label: 'Success', value: 'success' },
      { label: 'Warning', value: 'warning' },
      { label: 'Error', value: 'error' },
    ]},
    { name: 'dismissible', type: 'boolean', label: '닫기 가능' },
  ],

  // Layout Components
  Container: [
    { name: 'className', type: 'className', label: 'CSS 클래스', allowBinding: true },
    { name: 'maxWidth', type: 'select', label: '최대 너비', options: [
      { label: 'sm', value: 'sm' },
      { label: 'md', value: 'md' },
      { label: 'lg', value: 'lg' },
      { label: 'xl', value: 'xl' },
      { label: '2xl', value: '2xl' },
      { label: 'Full', value: 'full' },
    ]},
  ],
  Grid: [
    { name: 'className', type: 'className', label: 'CSS 클래스', allowBinding: true },
    { name: 'cols', type: 'number', label: '컬럼 수', defaultValue: 1 },
    { name: 'gap', type: 'number', label: '간격', defaultValue: 4 },
  ],
  Flex: [
    { name: 'className', type: 'className', label: 'CSS 클래스', allowBinding: true },
    { name: 'direction', type: 'select', label: '방향', options: [
      { label: 'Row', value: 'row' },
      { label: 'Column', value: 'col' },
      { label: 'Row Reverse', value: 'row-reverse' },
      { label: 'Column Reverse', value: 'col-reverse' },
    ]},
    { name: 'justify', type: 'select', label: '주축 정렬', options: [
      { label: 'Start', value: 'start' },
      { label: 'Center', value: 'center' },
      { label: 'End', value: 'end' },
      { label: 'Between', value: 'between' },
      { label: 'Around', value: 'around' },
      { label: 'Evenly', value: 'evenly' },
    ]},
    { name: 'align', type: 'select', label: '교차축 정렬', options: [
      { label: 'Start', value: 'start' },
      { label: 'Center', value: 'center' },
      { label: 'End', value: 'end' },
      { label: 'Stretch', value: 'stretch' },
      { label: 'Baseline', value: 'baseline' },
    ]},
    { name: 'gap', type: 'number', label: '간격', defaultValue: 0 },
  ],
};

// ============================================================================
// 훅 구현
// ============================================================================

/**
 * 컴포넌트 메타데이터 관리 훅
 */
export function useComponentMetadata(): UseComponentMetadataReturn {
  // Zustand 상태
  const componentCategories = useEditorState((state) => state.componentCategories);
  const handlers = useEditorState((state) => state.handlers);

  // 모든 컴포넌트 메타데이터 병합
  const allComponents = useMemo<ComponentMetadata[]>(() => {
    if (!componentCategories) return [];
    return [
      ...componentCategories.basic,
      ...componentCategories.composite,
      ...componentCategories.layout,
    ];
  }, [componentCategories]);

  // 확장 컴포넌트
  const extensionComponents = useMemo<ExtensionComponent[]>(() => {
    return componentCategories?.extension || [];
  }, [componentCategories]);

  // 컴포넌트 이름으로 메타데이터 조회
  const getMetadata = useCallback(
    (name: string): ComponentMetadata | null => {
      return allComponents.find((c) => c.name === name) || null;
    },
    [allComponents]
  );

  // Props 스키마 조회
  const getPropsSchema = useCallback(
    (name: string): PropSchema[] => {
      // 기본 스키마에서 먼저 찾기
      if (DEFAULT_PROPS_SCHEMAS[name]) {
        return DEFAULT_PROPS_SCHEMAS[name];
      }

      // 메타데이터에서 props 목록 가져오기
      const metadata = getMetadata(name);
      if (metadata?.props) {
        return metadata.props.map((prop) => ({
          name: prop,
          type: 'text',
          label: prop,
          allowBinding: true,
        }));
      }

      // 기본: className만 제공
      return [
        { name: 'className', type: 'className', label: 'CSS 클래스', allowBinding: true },
      ];
    },
    [getMetadata]
  );

  // 컴포넌트 검색
  const searchComponents = useCallback(
    (query: string): (ComponentMetadata | ExtensionComponent)[] => {
      const lowerQuery = query.toLowerCase();

      const matchedComponents = allComponents.filter(
        (c) =>
          c.name.toLowerCase().includes(lowerQuery) ||
          c.description?.toLowerCase().includes(lowerQuery)
      );

      const matchedExtensions = extensionComponents.filter(
        (c) =>
          c.name.toLowerCase().includes(lowerQuery) ||
          c.description?.toLowerCase().includes(lowerQuery) ||
          c.source.toLowerCase().includes(lowerQuery)
      );

      return [...matchedComponents, ...matchedExtensions];
    },
    [allComponents, extensionComponents]
  );

  // 카테고리별 필터링
  const filterByCategory = useCallback(
    (category: 'basic' | 'composite' | 'layout' | 'extension'): (ComponentMetadata | ExtensionComponent)[] => {
      if (!componentCategories) return [];

      if (category === 'extension') {
        return componentCategories.extension;
      }

      return componentCategories[category];
    },
    [componentCategories]
  );

  // children 허용 여부 확인
  const allowsChildren = useCallback(
    (name: string): boolean => {
      const metadata = getMetadata(name);

      // 메타데이터에 명시된 경우
      if (metadata?.allowsChildren !== undefined) {
        return metadata.allowsChildren;
      }

      // 기본값: layout과 대부분의 composite는 허용
      if (metadata?.type === 'layout') return true;
      if (metadata?.type === 'composite') return true;

      // basic 중 특정 컴포넌트는 허용
      const basicWithChildren = ['Div', 'Span', 'Button', 'Form', 'Label', 'Link'];
      return basicWithChildren.includes(name);
    },
    [getMetadata]
  );

  // 핸들러 정보 조회
  const getHandler = useCallback(
    (name: string): HandlerInfo | null => {
      return handlers.find((h) => h.name === name) || null;
    },
    [handlers]
  );

  // 카테고리별 핸들러 필터링
  const filterHandlersByCategory = useCallback(
    (category: 'built-in' | 'custom' | 'module'): HandlerInfo[] => {
      return handlers.filter((h) => h.category === category);
    },
    [handlers]
  );

  return {
    categories: componentCategories,
    allComponents,
    extensionComponents,
    handlers,
    getMetadata,
    getPropsSchema,
    searchComponents,
    filterByCategory,
    allowsChildren,
    getHandler,
    filterHandlersByCategory,
  };
}

// ============================================================================
// Export
// ============================================================================

export default useComponentMetadata;
