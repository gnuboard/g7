/**
 * SlotEditor.tsx
 *
 * G7 위지윅 레이아웃 편집기 - 슬롯 편집기
 *
 * 역할:
 * - 레이아웃의 extends 및 slots 관리
 * - 부모 레이아웃 선택 및 슬롯 구성
 * - 슬롯별 컴포넌트 할당
 * - 상속 체인 시각화
 *
 * Phase 3: 고급 기능
 */

import React, { useState, useCallback, useMemo } from 'react';
import type { ComponentDefinition } from '../../types/editor';

// ============================================================================
// 타입 정의
// ============================================================================

export interface SlotDefinition {
  id: string;
  type: 'slot';
  name: string;
  default?: ComponentDefinition[];
}

export interface LayoutInfo {
  name: string;
  title?: string;
  slots?: SlotDefinition[];
}

export interface SlotEditorProps {
  /** 현재 extends 값 */
  extendsLayout: string | null;
  /** 현재 slots 객체 */
  slots: Record<string, ComponentDefinition[]>;
  /** 사용 가능한 부모 레이아웃 목록 */
  availableLayouts: LayoutInfo[];
  /** 부모 레이아웃의 슬롯 정보 */
  parentSlots: SlotDefinition[];
  /** 변경 시 콜백 */
  onExtendsChange: (layout: string | null) => void;
  /** 슬롯 변경 시 콜백 */
  onSlotsChange: (slots: Record<string, ComponentDefinition[]>) => void;
}

// ============================================================================
// 슬롯 아이템 컴포넌트
// ============================================================================

interface SlotItemProps {
  slot: SlotDefinition;
  components: ComponentDefinition[];
  isOpen: boolean;
  onToggle: () => void;
  onChange: (components: ComponentDefinition[]) => void;
  onClearSlot: () => void;
}

function SlotItem({
  slot,
  components,
  isOpen,
  onToggle,
  onChange,
  onClearSlot,
}: SlotItemProps) {
  const hasComponents = components && components.length > 0;
  const hasDefault = slot.default && slot.default.length > 0;

  return (
    <div className="border border-gray-200 dark:border-gray-700 rounded overflow-hidden">
      {/* 헤더 */}
      <div
        className="flex items-center justify-between px-3 py-2 bg-gray-50 dark:bg-gray-800 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-750 transition-colors"
        onClick={onToggle}
      >
        <div className="flex items-center gap-2">
          <span className="text-gray-500">📌</span>
          <span className="text-xs font-mono bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 px-1.5 py-0.5 rounded">
            {slot.name}
          </span>
          {hasComponents ? (
            <span className="text-xs text-green-600 dark:text-green-400">
              ✓ {components.length}개 컴포넌트
            </span>
          ) : hasDefault ? (
            <span className="text-xs text-gray-500 dark:text-gray-400">
              (기본값 사용)
            </span>
          ) : (
            <span className="text-xs text-yellow-600 dark:text-yellow-400">
              (비어있음)
            </span>
          )}
        </div>

        <div className="flex items-center gap-2">
          {hasComponents && (
            <button
              onClick={(e) => {
                e.stopPropagation();
                onClearSlot();
              }}
              className="text-xs text-red-500 hover:text-red-700"
            >
              초기화
            </button>
          )}
          <span className={`text-gray-400 transition-transform ${isOpen ? 'rotate-180' : ''}`}>
            ▼
          </span>
        </div>
      </div>

      {/* 상세 정보 */}
      {isOpen && (
        <div className="p-3 space-y-3 border-t border-gray-200 dark:border-gray-700">
          {/* 슬롯 ID */}
          <div className="flex items-center gap-2">
            <span className="text-xs text-gray-500 dark:text-gray-400">슬롯 ID:</span>
            <code className="text-xs bg-gray-100 dark:bg-gray-700 px-1 rounded">
              {slot.id}
            </code>
          </div>

          {/* 현재 할당된 컴포넌트 */}
          <div>
            <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
              할당된 컴포넌트
            </label>
            {hasComponents ? (
              <div className="p-2 bg-gray-50 dark:bg-gray-800 rounded text-xs text-gray-600 dark:text-gray-400">
                <ul className="list-disc list-inside space-y-1">
                  {components.map((comp, i) => (
                    <li key={i}>
                      {comp.name}
                      {comp.id && (
                        <span className="text-gray-400"> ({comp.id})</span>
                      )}
                    </li>
                  ))}
                </ul>
              </div>
            ) : hasDefault ? (
              <div className="p-2 bg-yellow-50 dark:bg-yellow-900/20 rounded text-xs text-yellow-700 dark:text-yellow-300">
                <p className="font-medium mb-1">기본값이 사용됩니다:</p>
                <ul className="list-disc list-inside">
                  {slot.default?.map((comp, i) => (
                    <li key={i}>{comp.name}</li>
                  ))}
                </ul>
              </div>
            ) : (
              <p className="text-xs text-gray-500 dark:text-gray-400">
                컴포넌트가 할당되지 않았습니다.
              </p>
            )}
          </div>

          {/* 안내 메시지 */}
          <div className="p-2 bg-blue-50 dark:bg-blue-900/20 rounded text-xs text-blue-700 dark:text-blue-300">
            <p>
              📝 슬롯에 컴포넌트를 추가하려면 레이아웃 트리에서
              해당 슬롯 위치에 컴포넌트를 드래그하세요.
            </p>
          </div>
        </div>
      )}
    </div>
  );
}

// ============================================================================
// 메인 컴포넌트
// ============================================================================

export function SlotEditor({
  extendsLayout,
  slots,
  availableLayouts,
  parentSlots,
  onExtendsChange,
  onSlotsChange,
}: SlotEditorProps) {
  const [openSlots, setOpenSlots] = useState<Set<string>>(new Set());

  // 슬롯 토글
  const handleToggle = useCallback((slotName: string) => {
    setOpenSlots((prev) => {
      const next = new Set(prev);
      if (next.has(slotName)) {
        next.delete(slotName);
      } else {
        next.add(slotName);
      }
      return next;
    });
  }, []);

  // extends 변경
  const handleExtendsChange = useCallback(
    (value: string) => {
      if (value === '') {
        onExtendsChange(null);
        onSlotsChange({});
      } else {
        onExtendsChange(value);
        // 부모 레이아웃 변경 시 슬롯 초기화
        onSlotsChange({});
      }
    },
    [onExtendsChange, onSlotsChange]
  );

  // 슬롯 컴포넌트 변경
  const handleSlotChange = useCallback(
    (slotName: string, components: ComponentDefinition[]) => {
      const newSlots = { ...slots };
      if (components.length > 0) {
        newSlots[slotName] = components;
      } else {
        delete newSlots[slotName];
      }
      onSlotsChange(newSlots);
    },
    [slots, onSlotsChange]
  );

  // 슬롯 초기화
  const handleClearSlot = useCallback(
    (slotName: string) => {
      const newSlots = { ...slots };
      delete newSlots[slotName];
      onSlotsChange(newSlots);
    },
    [slots, onSlotsChange]
  );

  // 정의된 슬롯 개수 및 채워진 슬롯 개수
  const slotStats = useMemo(() => {
    const total = parentSlots.length;
    const filled = Object.keys(slots).length;
    return { total, filled };
  }, [parentSlots, slots]);

  return (
    <div className="p-4 space-y-4">
      {/* 헤더 */}
      <div className="flex items-center justify-between">
        <div>
          <h4 className="text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase">
            레이아웃 상속
          </h4>
          <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
            베이스 레이아웃을 상속하고 슬롯을 채웁니다.
          </p>
        </div>
        {extendsLayout && (
          <span className="text-xs text-gray-500 dark:text-gray-400">
            {slotStats.filled}/{slotStats.total} 슬롯 사용
          </span>
        )}
      </div>

      {/* 부모 레이아웃 선택 */}
      <div>
        <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
          부모 레이아웃 (extends)
        </label>
        <select
          value={extendsLayout || ''}
          onChange={(e) => handleExtendsChange(e.target.value)}
          className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
        >
          <option value="">상속하지 않음 (base 레이아웃)</option>
          {availableLayouts.map((layout) => (
            <option key={layout.name} value={layout.name}>
              {layout.title || layout.name}
            </option>
          ))}
        </select>
        <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
          상속 시 부모의 구조를 사용하고 슬롯만 커스터마이즈합니다.
        </p>
      </div>

      {/* 슬롯 목록 */}
      {extendsLayout && (
        <>
          {parentSlots.length > 0 ? (
            <div className="space-y-2">
              <h5 className="text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase">
                사용 가능한 슬롯
              </h5>
              {parentSlots.map((slot) => (
                <SlotItem
                  key={slot.name}
                  slot={slot}
                  components={slots[slot.name] || []}
                  isOpen={openSlots.has(slot.name)}
                  onToggle={() => handleToggle(slot.name)}
                  onChange={(components) => handleSlotChange(slot.name, components)}
                  onClearSlot={() => handleClearSlot(slot.name)}
                />
              ))}
            </div>
          ) : (
            <div className="p-4 text-center text-sm text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800 rounded">
              부모 레이아웃에 정의된 슬롯이 없습니다.
            </div>
          )}
        </>
      )}

      {/* 상속 안 함 안내 */}
      {!extendsLayout && (
        <div className="p-4 text-center text-sm text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800 rounded">
          <p>상속하지 않는 base 레이아웃입니다.</p>
          <p className="mt-1 text-xs">
            components 배열에 직접 컴포넌트를 정의합니다.
          </p>
        </div>
      )}

      {/* 도움말 */}
      <div className="p-3 bg-blue-50 dark:bg-blue-900/20 rounded text-xs text-blue-700 dark:text-blue-300">
        <p className="font-medium mb-2">레이아웃 상속 규칙:</p>
        <ul className="list-disc list-inside space-y-1 text-blue-600 dark:text-blue-400">
          <li>
            <strong>extends</strong>: 부모 레이아웃명 지정
          </li>
          <li>
            <strong>slots</strong>: 슬롯별 컴포넌트 배열 정의
          </li>
          <li>
            <strong>병합</strong>: data_sources, modals 자동 병합
          </li>
          <li>
            <strong>제한</strong>: 최대 상속 깊이 10단계
          </li>
          <li>
            <strong>순환 참조</strong>: A → B → A 패턴 금지
          </li>
        </ul>
      </div>

      {/* 상속 체인 시각화 */}
      {extendsLayout && (
        <div className="space-y-2">
          <h5 className="text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase">
            상속 체인
          </h5>
          <div className="p-3 bg-gray-50 dark:bg-gray-800 rounded">
            <div className="flex items-center gap-2 text-xs">
              <span className="px-2 py-1 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 rounded">
                현재 레이아웃
              </span>
              <span className="text-gray-400">→</span>
              <span className="px-2 py-1 bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 rounded">
                {extendsLayout}
              </span>
              <span className="text-gray-400">→ ...</span>
            </div>
            <p className="text-xs text-gray-500 dark:text-gray-400 mt-2">
              전체 상속 체인은 런타임에 해석됩니다.
            </p>
          </div>
        </div>
      )}

      {/* JSON 미리보기 */}
      {extendsLayout && Object.keys(slots).length > 0 && (
        <div className="space-y-2">
          <h5 className="text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase">
            Slots JSON 미리보기
          </h5>
          <pre className="p-3 bg-gray-50 dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700 text-xs text-gray-600 dark:text-gray-400 overflow-auto max-h-48">
            {JSON.stringify(
              {
                extends: extendsLayout,
                slots,
              },
              null,
              2
            )}
          </pre>
        </div>
      )}
    </div>
  );
}

export default SlotEditor;
