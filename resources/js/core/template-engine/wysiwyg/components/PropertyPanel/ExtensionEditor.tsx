/**
 * ExtensionEditor.tsx
 *
 * G7 위지윅 레이아웃 편집기 - 확장 컴포넌트 편집기
 *
 * 역할:
 * - 모듈/플러그인에서 주입한 확장 컴포넌트 표시
 * - Extension Point 시각화
 * - Overlay로 주입된 컴포넌트 관리
 * - 확장 컴포넌트의 출처 표시
 *
 * Phase 3: 고급 기능
 */

import React, { useState, useCallback, useMemo } from 'react';
import type { ComponentDefinition } from '../../types/editor';

// ============================================================================
// 타입 정의
// ============================================================================

export interface ExtensionSource {
  type: 'module' | 'plugin' | 'template';
  identifier: string;
  name?: string;
  version?: string;
  isActive: boolean;
}

export interface InjectedComponent {
  component: ComponentDefinition;
  source: ExtensionSource;
  targetId: string;
  position: 'prepend' | 'append' | 'prepend_child' | 'append_child' | 'replace';
  priority: number;
  isEditable: boolean;
  hasOverride: boolean;
}

export interface ExtensionPointInfo {
  id: string;
  name: string;
  description?: string;
  registeredExtensions: {
    source: ExtensionSource;
    components: ComponentDefinition[];
    priority: number;
  }[];
  defaultComponents?: ComponentDefinition[];
}

export interface ExtensionEditorProps {
  /** 주입된 컴포넌트 목록 */
  injectedComponents: InjectedComponent[];
  /** Extension Point 목록 */
  extensionPoints: ExtensionPointInfo[];
  /** 활성화된 모듈 목록 */
  activeModules: ExtensionSource[];
  /** 활성화된 플러그인 목록 */
  activePlugins: ExtensionSource[];
  /** 오버라이드 저장 콜백 */
  onSaveOverride?: (extensionId: string, components: ComponentDefinition[]) => void;
  /** 오버라이드 삭제 콜백 */
  onRemoveOverride?: (extensionId: string) => void;
}

// ============================================================================
// 출처 배지 컴포넌트
// ============================================================================

interface SourceBadgeProps {
  source: ExtensionSource;
  size?: 'small' | 'medium';
}

function SourceBadge({ source, size = 'small' }: SourceBadgeProps) {
  const colorClasses = {
    module: 'bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-400',
    plugin: 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400',
    template: 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400',
  };

  const typeLabels = {
    module: '모듈',
    plugin: '플러그인',
    template: '템플릿',
  };

  const sizeClasses = size === 'small' ? 'px-1.5 py-0.5 text-xs' : 'px-2 py-1 text-sm';

  return (
    <span className={`${sizeClasses} rounded font-mono ${colorClasses[source.type]}`}>
      {typeLabels[source.type]}: {source.name || source.identifier}
    </span>
  );
}

// ============================================================================
// 주입된 컴포넌트 아이템
// ============================================================================

interface InjectedComponentItemProps {
  item: InjectedComponent;
  isOpen: boolean;
  onToggle: () => void;
  onRemoveOverride?: () => void;
}

function InjectedComponentItem({
  item,
  isOpen,
  onToggle,
  onRemoveOverride,
}: InjectedComponentItemProps) {
  const positionLabels: Record<string, string> = {
    prepend: '앞에 삽입',
    append: '뒤에 삽입',
    prepend_child: '첫 번째 자식',
    append_child: '마지막 자식',
    replace: '교체',
  };

  return (
    <div className="border border-gray-200 dark:border-gray-700 rounded overflow-hidden">
      {/* 헤더 */}
      <div
        className="flex items-center justify-between px-3 py-2 bg-gray-50 dark:bg-gray-800 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-750 transition-colors"
        onClick={onToggle}
      >
        <div className="flex items-center gap-2">
          <span className="text-gray-500">🔌</span>
          <span className="text-xs font-mono text-gray-700 dark:text-gray-300">
            {item.component.name}
          </span>
          <SourceBadge source={item.source} />
          {item.hasOverride && (
            <span className="px-1.5 py-0.5 text-xs bg-orange-100 dark:bg-orange-900/30 text-orange-700 dark:text-orange-400 rounded">
              오버라이드됨
            </span>
          )}
        </div>

        <div className="flex items-center gap-2">
          <span className="px-1.5 py-0.5 text-xs bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 rounded">
            {positionLabels[item.position]}
          </span>
          <span className={`text-gray-400 transition-transform ${isOpen ? 'rotate-180' : ''}`}>
            ▼
          </span>
        </div>
      </div>

      {/* 상세 정보 */}
      {isOpen && (
        <div className="p-3 space-y-3 border-t border-gray-200 dark:border-gray-700">
          {/* 출처 정보 */}
          <div className="flex items-center gap-2">
            <span className="text-xs text-gray-500 dark:text-gray-400">출처:</span>
            <SourceBadge source={item.source} size="medium" />
            {!item.source.isActive && (
              <span className="text-xs text-red-500">⚠️ 비활성화됨</span>
            )}
          </div>

          {/* 타겟 정보 */}
          <div className="flex items-center gap-2">
            <span className="text-xs text-gray-500 dark:text-gray-400">타겟:</span>
            <code className="text-xs bg-gray-100 dark:bg-gray-700 px-1 rounded">
              {item.targetId}
            </code>
          </div>

          {/* 우선순위 */}
          <div className="flex items-center gap-2">
            <span className="text-xs text-gray-500 dark:text-gray-400">우선순위:</span>
            <span className="text-xs text-gray-700 dark:text-gray-300">{item.priority}</span>
          </div>

          {/* 컴포넌트 ID */}
          {item.component.id && (
            <div className="flex items-center gap-2">
              <span className="text-xs text-gray-500 dark:text-gray-400">컴포넌트 ID:</span>
              <code className="text-xs bg-gray-100 dark:bg-gray-700 px-1 rounded">
                {item.component.id}
              </code>
            </div>
          )}

          {/* 오버라이드 복원 버튼 */}
          {item.hasOverride && onRemoveOverride && (
            <div className="pt-2 border-t border-gray-200 dark:border-gray-700">
              <button
                onClick={(e) => {
                  e.stopPropagation();
                  onRemoveOverride();
                }}
                className="px-3 py-1.5 text-xs bg-orange-500 text-white rounded hover:bg-orange-600 transition-colors"
              >
                원본으로 복원
              </button>
              <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                오버라이드를 삭제하고 원본 확장으로 복원합니다.
              </p>
            </div>
          )}

          {/* 편집 안내 */}
          <div className="p-2 bg-blue-50 dark:bg-blue-900/20 rounded text-xs text-blue-700 dark:text-blue-300">
            {item.isEditable ? (
              <p>✅ 이 컴포넌트는 편집 가능합니다. 변경 시 템플릿 오버라이드로 저장됩니다.</p>
            ) : (
              <p>⚠️ 이 컴포넌트는 읽기 전용입니다.</p>
            )}
          </div>
        </div>
      )}
    </div>
  );
}

// ============================================================================
// Extension Point 아이템
// ============================================================================

interface ExtensionPointItemProps {
  point: ExtensionPointInfo;
  isOpen: boolean;
  onToggle: () => void;
}

function ExtensionPointItem({ point, isOpen, onToggle }: ExtensionPointItemProps) {
  const totalExtensions = point.registeredExtensions.length;
  const totalComponents = point.registeredExtensions.reduce(
    (sum, ext) => sum + ext.components.length,
    0
  );

  return (
    <div className="border border-gray-200 dark:border-gray-700 rounded overflow-hidden">
      {/* 헤더 */}
      <div
        className="flex items-center justify-between px-3 py-2 bg-gray-50 dark:bg-gray-800 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-750 transition-colors"
        onClick={onToggle}
      >
        <div className="flex items-center gap-2">
          <span className="text-gray-500">📍</span>
          <span className="text-xs font-mono bg-indigo-100 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-400 px-1.5 py-0.5 rounded">
            {point.name}
          </span>
          {totalExtensions > 0 ? (
            <span className="text-xs text-green-600 dark:text-green-400">
              {totalExtensions}개 확장 등록됨
            </span>
          ) : (
            <span className="text-xs text-gray-500 dark:text-gray-400">
              (기본값 사용)
            </span>
          )}
        </div>

        <span className={`text-gray-400 transition-transform ${isOpen ? 'rotate-180' : ''}`}>
          ▼
        </span>
      </div>

      {/* 상세 정보 */}
      {isOpen && (
        <div className="p-3 space-y-3 border-t border-gray-200 dark:border-gray-700">
          {/* 설명 */}
          {point.description && (
            <p className="text-xs text-gray-600 dark:text-gray-400">{point.description}</p>
          )}

          {/* 등록된 확장 목록 */}
          {totalExtensions > 0 ? (
            <div className="space-y-2">
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300">
                등록된 확장 ({totalComponents}개 컴포넌트)
              </label>
              {point.registeredExtensions.map((ext, i) => (
                <div
                  key={i}
                  className="p-2 bg-gray-50 dark:bg-gray-800 rounded text-xs space-y-1"
                >
                  <div className="flex items-center justify-between">
                    <SourceBadge source={ext.source} />
                    <span className="text-gray-500">우선순위: {ext.priority}</span>
                  </div>
                  <ul className="list-disc list-inside text-gray-600 dark:text-gray-400">
                    {ext.components.map((comp, j) => (
                      <li key={j}>
                        {comp.name}
                        {comp.id && <span className="text-gray-400"> ({comp.id})</span>}
                      </li>
                    ))}
                  </ul>
                </div>
              ))}
            </div>
          ) : point.defaultComponents && point.defaultComponents.length > 0 ? (
            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                기본 컴포넌트
              </label>
              <ul className="list-disc list-inside text-xs text-gray-600 dark:text-gray-400">
                {point.defaultComponents.map((comp, i) => (
                  <li key={i}>{comp.name}</li>
                ))}
              </ul>
            </div>
          ) : (
            <p className="text-xs text-gray-500 dark:text-gray-400">
              확장이 등록되지 않았고 기본 컴포넌트도 없습니다.
            </p>
          )}
        </div>
      )}
    </div>
  );
}

// ============================================================================
// 메인 컴포넌트
// ============================================================================

export function ExtensionEditor({
  injectedComponents,
  extensionPoints,
  activeModules,
  activePlugins,
  onRemoveOverride,
}: ExtensionEditorProps) {
  const [openItems, setOpenItems] = useState<Set<string>>(new Set());
  const [activeTab, setActiveTab] = useState<'injected' | 'points' | 'sources'>('injected');

  // 아이템 토글
  const handleToggle = useCallback((id: string) => {
    setOpenItems((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  }, []);

  // 통계 계산
  const stats = useMemo(() => ({
    totalInjected: injectedComponents.length,
    totalPoints: extensionPoints.length,
    totalModules: activeModules.length,
    totalPlugins: activePlugins.length,
    overriddenCount: injectedComponents.filter((c) => c.hasOverride).length,
  }), [injectedComponents, extensionPoints, activeModules, activePlugins]);

  return (
    <div className="p-4 space-y-4">
      {/* 헤더 */}
      <div className="flex items-center justify-between">
        <div>
          <h4 className="text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase">
            확장 컴포넌트
          </h4>
          <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
            모듈/플러그인에서 주입된 UI를 관리합니다.
          </p>
        </div>
      </div>

      {/* 탭 */}
      <div className="flex gap-1 border-b border-gray-200 dark:border-gray-700">
        <button
          onClick={() => setActiveTab('injected')}
          className={`px-3 py-2 text-xs font-medium border-b-2 transition-colors ${
            activeTab === 'injected'
              ? 'border-blue-500 text-blue-600 dark:text-blue-400'
              : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'
          }`}
        >
          주입된 컴포넌트 ({stats.totalInjected})
        </button>
        <button
          onClick={() => setActiveTab('points')}
          className={`px-3 py-2 text-xs font-medium border-b-2 transition-colors ${
            activeTab === 'points'
              ? 'border-blue-500 text-blue-600 dark:text-blue-400'
              : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'
          }`}
        >
          Extension Points ({stats.totalPoints})
        </button>
        <button
          onClick={() => setActiveTab('sources')}
          className={`px-3 py-2 text-xs font-medium border-b-2 transition-colors ${
            activeTab === 'sources'
              ? 'border-blue-500 text-blue-600 dark:text-blue-400'
              : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'
          }`}
        >
          활성 소스 ({stats.totalModules + stats.totalPlugins})
        </button>
      </div>

      {/* 주입된 컴포넌트 탭 */}
      {activeTab === 'injected' && (
        <div className="space-y-2">
          {injectedComponents.length > 0 ? (
            <>
              {stats.overriddenCount > 0 && (
                <div className="p-2 bg-orange-50 dark:bg-orange-900/20 rounded text-xs text-orange-700 dark:text-orange-300">
                  {stats.overriddenCount}개의 컴포넌트가 오버라이드되었습니다.
                </div>
              )}
              {injectedComponents.map((item, index) => (
                <InjectedComponentItem
                  key={`${item.source.identifier}-${item.component.id || index}`}
                  item={item}
                  isOpen={openItems.has(`injected-${index}`)}
                  onToggle={() => handleToggle(`injected-${index}`)}
                  onRemoveOverride={
                    item.hasOverride && onRemoveOverride
                      ? () => onRemoveOverride(item.component.id || '')
                      : undefined
                  }
                />
              ))}
            </>
          ) : (
            <div className="p-4 text-center text-sm text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800 rounded">
              주입된 확장 컴포넌트가 없습니다.
            </div>
          )}
        </div>
      )}

      {/* Extension Points 탭 */}
      {activeTab === 'points' && (
        <div className="space-y-2">
          {extensionPoints.length > 0 ? (
            extensionPoints.map((point) => (
              <ExtensionPointItem
                key={point.id}
                point={point}
                isOpen={openItems.has(`point-${point.id}`)}
                onToggle={() => handleToggle(`point-${point.id}`)}
              />
            ))
          ) : (
            <div className="p-4 text-center text-sm text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800 rounded">
              정의된 Extension Point가 없습니다.
            </div>
          )}
        </div>
      )}

      {/* 활성 소스 탭 */}
      {activeTab === 'sources' && (
        <div className="space-y-4">
          {/* 모듈 */}
          <div>
            <h5 className="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-2">
              활성 모듈 ({activeModules.length})
            </h5>
            {activeModules.length > 0 ? (
              <div className="space-y-1">
                {activeModules.map((mod) => (
                  <div
                    key={mod.identifier}
                    className="flex items-center justify-between p-2 bg-gray-50 dark:bg-gray-800 rounded"
                  >
                    <div className="flex items-center gap-2">
                      <span className="text-purple-500">📦</span>
                      <span className="text-xs font-mono text-gray-700 dark:text-gray-300">
                        {mod.name || mod.identifier}
                      </span>
                    </div>
                    {mod.version && (
                      <span className="text-xs text-gray-500">v{mod.version}</span>
                    )}
                  </div>
                ))}
              </div>
            ) : (
              <p className="text-xs text-gray-500 dark:text-gray-400">활성화된 모듈이 없습니다.</p>
            )}
          </div>

          {/* 플러그인 */}
          <div>
            <h5 className="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-2">
              활성 플러그인 ({activePlugins.length})
            </h5>
            {activePlugins.length > 0 ? (
              <div className="space-y-1">
                {activePlugins.map((plugin) => (
                  <div
                    key={plugin.identifier}
                    className="flex items-center justify-between p-2 bg-gray-50 dark:bg-gray-800 rounded"
                  >
                    <div className="flex items-center gap-2">
                      <span className="text-green-500">🔌</span>
                      <span className="text-xs font-mono text-gray-700 dark:text-gray-300">
                        {plugin.name || plugin.identifier}
                      </span>
                    </div>
                    {plugin.version && (
                      <span className="text-xs text-gray-500">v{plugin.version}</span>
                    )}
                  </div>
                ))}
              </div>
            ) : (
              <p className="text-xs text-gray-500 dark:text-gray-400">활성화된 플러그인이 없습니다.</p>
            )}
          </div>
        </div>
      )}

      {/* 도움말 */}
      <div className="p-3 bg-blue-50 dark:bg-blue-900/20 rounded text-xs text-blue-700 dark:text-blue-300">
        <p className="font-medium mb-2">확장 컴포넌트 규칙:</p>
        <ul className="list-disc list-inside space-y-1 text-blue-600 dark:text-blue-400">
          <li>
            <strong>Overlay</strong>: 다른 레이아웃에 컴포넌트 주입
          </li>
          <li>
            <strong>Extension Point</strong>: 지정된 위치에 확장 등록
          </li>
          <li>
            <strong>오버라이드</strong>: 편집 시 템플릿 오버라이드로 저장
          </li>
          <li>
            <strong>복원</strong>: 오버라이드 삭제 시 원본 확장 복원
          </li>
          <li>
            <strong>출처 배지</strong>: 모듈/플러그인 출처 시각적 표시
          </li>
        </ul>
      </div>
    </div>
  );
}

export default ExtensionEditor;
