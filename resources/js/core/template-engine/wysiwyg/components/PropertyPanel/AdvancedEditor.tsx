/**
 * AdvancedEditor.tsx
 *
 * G7 위지윅 레이아웃 편집기의 고급 설정 편집 컴포넌트
 *
 * 역할:
 * - 컴포넌트 ID, 슬롯 설정
 * - classMap 편집
 * - $switch 표현식 편집
 * - $get 헬퍼 편집
 * - computed 속성 편집 (레이아웃 수준)
 * - Raw JSON 보기
 */

import React, { useCallback, useState } from 'react';
import type { ComponentDefinition } from '../../types/editor';
import { ClassMapEditor, type ClassMapValue } from './ClassMapEditor';
import { SwitchExpressionEditor, type SwitchExpressionValue } from './SwitchExpressionEditor';
import { GetHelperAutocomplete } from './GetHelperAutocomplete';
import { PipeAutocomplete } from './PipeAutocomplete';
import { createLogger } from '../../../../utils/Logger';

const logger = createLogger('AdvancedEditor');

// ============================================================================
// 타입 정의
// ============================================================================

export interface AdvancedEditorProps {
  component: ComponentDefinition;
  onChange: (updates: Partial<ComponentDefinition>) => void;
}

// ============================================================================
// 섹션 헤더 컴포넌트
// ============================================================================

function SectionHeader({
  title,
  badge,
  isExpanded,
  onToggle,
}: {
  title: string;
  badge?: string;
  isExpanded: boolean;
  onToggle: () => void;
}) {
  return (
    <button
      type="button"
      className="w-full flex items-center justify-between p-2 text-left bg-gray-50 dark:bg-gray-800 hover:bg-gray-100 dark:hover:bg-gray-700 rounded transition-colors"
      onClick={onToggle}
    >
      <div className="flex items-center gap-2">
        <svg
          xmlns="http://www.w3.org/2000/svg"
          className={`w-4 h-4 text-gray-500 dark:text-gray-400 transition-transform ${isExpanded ? 'rotate-90' : ''}`}
          viewBox="0 0 20 20"
          fill="currentColor"
        >
          <path fillRule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clipRule="evenodd" />
        </svg>
        <span className="text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase">
          {title}
        </span>
      </div>
      {badge && (
        <span className="px-1.5 py-0.5 text-xs bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded">
          {badge}
        </span>
      )}
    </button>
  );
}

// ============================================================================
// 메인 컴포넌트
// ============================================================================

export function AdvancedEditor({ component, onChange }: AdvancedEditorProps) {
  // 섹션 확장 상태
  const [expandedSections, setExpandedSections] = useState<Record<string, boolean>>({
    basic: true,
    classMap: false,
    expressionHelpers: false,
    rawJson: false,
  });

  const toggleSection = useCallback((section: string) => {
    setExpandedSections((prev) => ({
      ...prev,
      [section]: !prev[section],
    }));
  }, []);

  // ID 변경 핸들러
  const handleIdChange = useCallback(
    (value: string) => {
      if (value && value !== component.id) {
        onChange({ id: value });
      }
    },
    [component.id, onChange]
  );

  // classMap 변경 핸들러
  const handleClassMapChange = useCallback(
    (classMap: ClassMapValue | undefined) => {
      const newProps = { ...component.props };
      if (classMap) {
        (newProps as any).classMap = classMap;
      } else {
        delete (newProps as any).classMap;
      }
      onChange({ props: newProps });
    },
    [component.props, onChange]
  );

  // blur_until_loaded 변경 핸들러
  const handleBlurUntilLoadedChange = useCallback(
    (checked: boolean) => {
      onChange({ blur_until_loaded: checked || undefined });
    },
    [onChange]
  );

  // classMap 값
  const classMapValue = (component.props as any)?.classMap as ClassMapValue | undefined;

  return (
    <div className="p-4 space-y-4">
      {/* 기본 설정 섹션 */}
      <div>
        <SectionHeader
          title="기본 설정"
          isExpanded={expandedSections.basic}
          onToggle={() => toggleSection('basic')}
        />

        {expandedSections.basic && (
          <div className="mt-3 space-y-4 pl-2">
            {/* 컴포넌트 ID */}
            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                컴포넌트 ID
              </label>
              <input
                type="text"
                value={component.id}
                onChange={(e) => handleIdChange(e.target.value)}
                className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white font-mono"
              />
              <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                레이아웃 내에서 고유해야 합니다
              </p>
            </div>

            {/* 슬롯 */}
            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                슬롯 (Slot)
              </label>
              <input
                type="text"
                value={component.slot || ''}
                readOnly
                placeholder="(없음)"
                className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300"
              />
            </div>

            {/* 슬롯 순서 */}
            {component.slot && (
              <div>
                <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                  슬롯 순서
                </label>
                <input
                  type="number"
                  value={component.slotOrder || 0}
                  readOnly
                  className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300"
                />
              </div>
            )}

            {/* 로딩 시 블러 */}
            <div>
              <label className="flex items-center gap-2 cursor-pointer">
                <input
                  type="checkbox"
                  checked={component.blur_until_loaded || false}
                  onChange={(e) => handleBlurUntilLoadedChange(e.target.checked)}
                  className="w-4 h-4 text-blue-600 border-gray-300 dark:border-gray-600 rounded focus:ring-blue-500"
                />
                <span className="text-sm text-gray-700 dark:text-gray-300">
                  데이터 로드 전까지 블러 처리
                </span>
              </label>
            </div>

            {/* Lifecycle */}
            {component.lifecycle && (
              <div>
                <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                  Lifecycle
                </label>
                <div className="space-y-2">
                  {component.lifecycle.onMount && (
                    <div className="p-2 bg-gray-50 dark:bg-gray-800 rounded">
                      <span className="text-xs text-gray-600 dark:text-gray-400">onMount:</span>
                      <span className="text-xs text-gray-700 dark:text-gray-300 ml-2">
                        {component.lifecycle.onMount.length}개 액션
                      </span>
                    </div>
                  )}
                  {component.lifecycle.onUnmount && (
                    <div className="p-2 bg-gray-50 dark:bg-gray-800 rounded">
                      <span className="text-xs text-gray-600 dark:text-gray-400">onUnmount:</span>
                      <span className="text-xs text-gray-700 dark:text-gray-300 ml-2">
                        {component.lifecycle.onUnmount.length}개 액션
                      </span>
                    </div>
                  )}
                </div>
              </div>
            )}
          </div>
        )}
      </div>

      {/* classMap 섹션 */}
      <div>
        <SectionHeader
          title="classMap (조건부 스타일)"
          badge={classMapValue ? '활성화' : undefined}
          isExpanded={expandedSections.classMap}
          onToggle={() => toggleSection('classMap')}
        />

        {expandedSections.classMap && (
          <div className="mt-3">
            <ClassMapEditor
              value={classMapValue}
              onChange={handleClassMapChange}
              label="조건부 클래스 매핑"
              description="상태에 따라 다른 CSS 클래스를 적용합니다. 중첩 삼항 연산자 대신 사용하세요."
            />
          </div>
        )}
      </div>

      {/* 표현식 헬퍼 섹션 */}
      <div>
        <SectionHeader
          title="표현식 헬퍼"
          isExpanded={expandedSections.expressionHelpers}
          onToggle={() => toggleSection('expressionHelpers')}
        />

        {expandedSections.expressionHelpers && (
          <div className="mt-3 space-y-4">
            {/* $switch 표현식 도움말 */}
            <div className="p-3 bg-orange-50 dark:bg-orange-900/20 rounded text-xs">
              <div className="font-medium text-orange-700 dark:text-orange-300 mb-2">
                $switch 표현식
              </div>
              <p className="text-orange-600 dark:text-orange-400 mb-2">
                중첩 삼항 연산자 대신 선언적 switch-case 패턴을 사용할 수 있습니다.
              </p>
              <pre className="bg-white dark:bg-gray-800 p-2 rounded font-mono overflow-x-auto text-gray-700 dark:text-gray-300">
{`{
  "$switch": "{{status}}",
  "$cases": {
    "active": "text-green-500",
    "pending": "text-yellow-500"
  },
  "$default": "text-gray-500"
}`}
              </pre>
            </div>

            {/* $get 헬퍼 도움말 */}
            <div className="p-3 bg-cyan-50 dark:bg-cyan-900/20 rounded text-xs">
              <div className="font-medium text-cyan-700 dark:text-cyan-300 mb-2">
                $get 헬퍼
              </div>
              <p className="text-cyan-600 dark:text-cyan-400 mb-2">
                동적 키를 포함한 깊은 객체 경로 접근과 폴백을 지원합니다.
              </p>
              <pre className="bg-white dark:bg-gray-800 p-2 rounded font-mono overflow-x-auto text-gray-700 dark:text-gray-300">
{`{{$get(product.prices, [currency, 'formatted'], product.price_formatted)}}`}
              </pre>
            </div>

            {/* 파이프 함수 도움말 */}
            <div className="p-3 bg-purple-50 dark:bg-purple-900/20 rounded text-xs">
              <div className="font-medium text-purple-700 dark:text-purple-300 mb-2">
                파이프 함수
              </div>
              <p className="text-purple-600 dark:text-purple-400 mb-2">
                체이닝 방식으로 데이터를 변환할 수 있습니다.
              </p>
              <div className="bg-white dark:bg-gray-800 p-2 rounded space-y-1 font-mono text-gray-700 dark:text-gray-300">
                <div>{'{{value | date}}'}</div>
                <div>{'{{text | truncate(50)}}'}</div>
                <div>{'{{name | default(\'Unknown\')}}'}</div>
                <div>{'{{items | first}}'}</div>
              </div>
            </div>

            {/* computed 도움말 */}
            <div className="p-3 bg-green-50 dark:bg-green-900/20 rounded text-xs">
              <div className="font-medium text-green-700 dark:text-green-300 mb-2">
                computed 속성
              </div>
              <p className="text-green-600 dark:text-green-400 mb-2">
                레이아웃 수준에서 재사용 가능한 계산 값을 정의합니다.
                레이아웃 JSON의 최상위에 computed 섹션을 추가하세요.
              </p>
              <pre className="bg-white dark:bg-gray-800 p-2 rounded font-mono overflow-x-auto text-gray-700 dark:text-gray-300">
{`// 레이아웃 JSON
{
  "computed": {
    "displayPrice": "{{$get(product.prices, [currency, 'formatted'])}}",
    "canEdit": "{{!deleted && permissions.edit}}"
  }
}

// 사용
{{$computed.displayPrice}}
{{$computed.canEdit}}`}
              </pre>
            </div>
          </div>
        )}
      </div>

      {/* Raw JSON 섹션 */}
      <div>
        <SectionHeader
          title="Raw JSON"
          isExpanded={expandedSections.rawJson}
          onToggle={() => toggleSection('rawJson')}
        />

        {expandedSections.rawJson && (
          <div className="mt-3">
            <pre className="p-3 bg-gray-50 dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700 text-xs text-gray-600 dark:text-gray-400 overflow-auto max-h-96">
              {JSON.stringify(component, null, 2)}
            </pre>
          </div>
        )}
      </div>
    </div>
  );
}

export default AdvancedEditor;
