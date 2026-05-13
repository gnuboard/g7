/**
 * PropertyPanel/index.tsx
 *
 * 그누보드7 위지윅 레이아웃 편집기의 속성 패널 컴포넌트
 *
 * 역할:
 * - 선택된 컴포넌트의 속성 편집
 * - 탭 기반 속성 분류 (Props, Bindings, Actions, Conditions, Responsive, Advanced)
 * - 동적 폼 생성
 */

import React, { useState, useCallback, useMemo } from 'react';
import { useEditorState } from '../../hooks/useEditorState';
import { useComponentMetadata } from '../../hooks/useComponentMetadata';
import { PropsEditor } from './PropsEditor';
import { BindingsEditor } from './BindingsEditor';
import { ActionsEditor } from './ActionsEditor';
import { ConditionsEditor } from './ConditionsEditor';
import { ResponsiveEditor } from './ResponsiveEditor';
import { AdvancedEditor } from './AdvancedEditor';
import type { ComponentDefinition } from '../../types/editor';

// ============================================================================
// 타입 정의
// ============================================================================

export interface PropertyPanelProps {
  /** 클래스명 */
  className?: string;
}

type TabId = 'props' | 'bindings' | 'actions' | 'conditions' | 'responsive' | 'advanced';

interface Tab {
  id: TabId;
  label: string;
  icon: string;
  description: string;
}

// ============================================================================
// 상수
// ============================================================================

const TABS: Tab[] = [
  {
    id: 'props',
    label: 'Props',
    icon: '⚙️',
    description: '기본 속성 편집',
  },
  {
    id: 'bindings',
    label: 'Bindings',
    icon: '🔗',
    description: '데이터 바인딩 설정',
  },
  {
    id: 'actions',
    label: 'Actions',
    icon: '⚡',
    description: '이벤트 핸들러 설정',
  },
  {
    id: 'conditions',
    label: 'Conditions',
    icon: '🔀',
    description: '조건부/반복 렌더링',
  },
  {
    id: 'responsive',
    label: 'Responsive',
    icon: '📱',
    description: '반응형 오버라이드',
  },
  {
    id: 'advanced',
    label: 'Advanced',
    icon: '🔧',
    description: '고급 설정',
  },
];

// ============================================================================
// 탭 버튼 컴포넌트
// ============================================================================

interface TabButtonProps {
  tab: Tab;
  isActive: boolean;
  onClick: () => void;
}

function TabButton({ tab, isActive, onClick }: TabButtonProps) {
  return (
    <button
      className={`flex items-center gap-1 px-3 py-2 text-sm font-medium border-b-2 transition-colors ${
        isActive
          ? 'border-blue-500 text-blue-600 dark:text-blue-400'
          : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 hover:border-gray-300 dark:hover:border-gray-600'
      }`}
      onClick={onClick}
      title={tab.description}
    >
      <span>{tab.icon}</span>
      <span className="hidden lg:inline">{tab.label}</span>
    </button>
  );
}

// ============================================================================
// 빈 상태 컴포넌트
// ============================================================================

function EmptyState() {
  return (
    <div className="flex flex-col items-center justify-center h-full p-8 text-gray-500 dark:text-gray-400">
      <div className="text-4xl mb-4">👆</div>
      <h3 className="text-lg font-medium mb-2">컴포넌트를 선택하세요</h3>
      <p className="text-sm text-center">
        편집할 컴포넌트를 미리보기 캔버스 또는 레이아웃 트리에서 클릭하세요.
      </p>
    </div>
  );
}

// ============================================================================
// 컴포넌트 정보 헤더
// ============================================================================

interface ComponentHeaderProps {
  component: ComponentDefinition;
}

function ComponentHeader({ component }: ComponentHeaderProps) {
  const { getMetadata } = useComponentMetadata();
  const metadata = getMetadata(component.name);

  return (
    <div className="px-4 py-3 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800">
      <div className="flex items-center gap-2">
        {/* 타입 아이콘 */}
        <span className="text-lg">
          {component.type === 'basic'
            ? '📦'
            : component.type === 'composite'
            ? '🧩'
            : component.type === 'layout'
            ? '📐'
            : '🔌'}
        </span>

        {/* 컴포넌트 이름 */}
        <div className="flex-1 min-w-0">
          <h3 className="text-sm font-semibold text-gray-900 dark:text-white truncate">
            {component.name}
          </h3>
          <p className="text-xs text-gray-500 dark:text-gray-400 truncate">
            ID: {component.id}
          </p>
        </div>

        {/* 타입 배지 */}
        <span
          className={`px-2 py-0.5 text-xs rounded ${
            component.type === 'basic'
              ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300'
              : component.type === 'composite'
              ? 'bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300'
              : component.type === 'layout'
              ? 'bg-orange-100 dark:bg-orange-900 text-orange-700 dark:text-orange-300'
              : 'bg-purple-100 dark:bg-purple-900 text-purple-700 dark:text-purple-300'
          }`}
        >
          {component.type}
        </span>
      </div>

      {/* 컴포넌트 설명 */}
      {metadata?.description && (
        <p className="mt-2 text-xs text-gray-600 dark:text-gray-400">
          {metadata.description}
        </p>
      )}
    </div>
  );
}

// AdvancedEditor는 별도 파일에서 import됨 (./AdvancedEditor.tsx)

// ============================================================================
// PropertyPanel 메인 컴포넌트
// ============================================================================

export function PropertyPanel({ className = '' }: PropertyPanelProps) {
  const [activeTab, setActiveTab] = useState<TabId>('props');

  // Zustand 상태
  const layoutData = useEditorState((state) => state.layoutData);
  const selectedComponentId = useEditorState((state) => state.selectedComponentId);
  const updateComponentProps = useEditorState((state) => state.updateComponentProps);
  const updateComponent = useEditorState((state) => state.updateComponent);

  // 선택된 컴포넌트 찾기
  // Note: selectComponent에서 auto_path: ID가 영구 ID로 정규화되므로
  // 여기서는 단순히 layoutData에서 ID로 찾으면 됨
  const selectedComponent = useMemo(() => {
    if (!layoutData || !selectedComponentId) return null;

    const findComponent = (
      components: (ComponentDefinition | string)[]
    ): ComponentDefinition | null => {
      for (const comp of components) {
        if (typeof comp === 'string') continue;
        if (comp.id === selectedComponentId) return comp;
        if (comp.children) {
          const found = findComponent(comp.children);
          if (found) return found;
        }
      }
      return null;
    };

    return findComponent(layoutData.components as (ComponentDefinition | string)[]);
  }, [layoutData, selectedComponentId]);

  // 컴포넌트 업데이트 핸들러
  // Note: selectComponent에서 auto_path: ID가 영구 ID로 정규화되므로
  // 여기서는 단순히 ID로 업데이트하면 됨
  const handleComponentChange = useCallback(
    (updates: Partial<ComponentDefinition>) => {
      if (!selectedComponentId || !selectedComponent) return;

      // props만 업데이트하는 경우 (최적화)
      if (Object.keys(updates).length === 1 && updates.props) {
        updateComponentProps(selectedComponentId, updates.props);
      } else {
        // 다른 필드 업데이트 (actions, if, iteration 등)
        updateComponent(selectedComponentId, updates);
      }
    },
    [selectedComponentId, selectedComponent, updateComponentProps, updateComponent]
  );

  // 선택된 컴포넌트가 없으면 빈 상태 표시
  if (!selectedComponent) {
    return (
      <div className={`h-full bg-white dark:bg-gray-900 border-l border-gray-200 dark:border-gray-700 ${className}`}>
        <EmptyState />
      </div>
    );
  }

  // 현재 탭 컨텐츠 렌더링
  const renderTabContent = () => {
    switch (activeTab) {
      case 'props':
        return (
          <PropsEditor
            component={selectedComponent}
            onChange={handleComponentChange}
          />
        );
      case 'bindings':
        return (
          <BindingsEditor
            component={selectedComponent}
            onChange={handleComponentChange}
          />
        );
      case 'actions':
        return (
          <ActionsEditor
            component={selectedComponent}
            onChange={handleComponentChange}
          />
        );
      case 'conditions':
        return (
          <ConditionsEditor
            component={selectedComponent}
            onChange={handleComponentChange}
          />
        );
      case 'responsive':
        return (
          <ResponsiveEditor
            component={selectedComponent}
            onChange={handleComponentChange}
          />
        );
      case 'advanced':
        return (
          <AdvancedEditor
            component={selectedComponent}
            onChange={handleComponentChange}
          />
        );
      default:
        return null;
    }
  };

  return (
    <div className={`flex flex-col h-full bg-white dark:bg-gray-900 border-l border-gray-200 dark:border-gray-700 ${className}`}>
      {/* 컴포넌트 헤더 */}
      <ComponentHeader component={selectedComponent} />

      {/* 탭 네비게이션 */}
      <div className="flex border-b border-gray-200 dark:border-gray-700 overflow-x-auto">
        {TABS.map((tab) => (
          <TabButton
            key={tab.id}
            tab={tab}
            isActive={activeTab === tab.id}
            onClick={() => setActiveTab(tab.id)}
          />
        ))}
      </div>

      {/* 탭 컨텐츠 */}
      <div className="flex-1 overflow-auto">{renderTabContent()}</div>
    </div>
  );
}

// ============================================================================
// Export
// ============================================================================

export default PropertyPanel;
