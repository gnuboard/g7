/**
 * useControllableState Hook (Phase 1-4)
 *
 * Controlled/Uncontrolled 컴포넌트 패턴을 단순화하는 훅입니다.
 * 외부에서 값이 제공되면 Controlled 모드로, 아니면 Uncontrolled 모드로 동작합니다.
 *
 * 이 훅은 DataGrid, SortableMenuList, PermissionTree 등에서
 * 이중 상태 관리 패턴(외부 props vs 내부 useState)을 해소합니다.
 *
 * @example
 * ```tsx
 * // DataGrid에서 사용 예시
 * const [selectedIds, setSelectedIds] = useControllableState(
 *   selectedIdsProp,          // 외부 제어 값 (undefined면 내부 상태 사용)
 *   defaultSelectedIds ?? [], // 기본값
 *   onSelectedIdsChange       // 변경 콜백 (옵션)
 * );
 *
 * // Controlled 모드: selectedIdsProp이 있으면 해당 값 사용
 * // Uncontrolled 모드: selectedIdsProp이 undefined면 내부 상태 사용
 * ```
 *
 * @example
 * ```tsx
 * // SortableMenuList에서 사용 예시
 * const [items, setItems] = useControllableState(
 *   itemsProp,
 *   initialItems,
 *   onChange
 * );
 *
 * // 내부 상태 변경과 콜백 호출이 자동으로 처리됨
 * const handleReorder = (newItems) => {
 *   setItems(newItems); // onChange도 자동 호출
 * };
 * ```
 *
 * @module hooks/useControllableState
 */

import { useState, useCallback, useRef, useEffect } from 'react';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Core:State')) ?? {
    log: (...args: unknown[]) => console.log('[Core:State]', ...args),
    warn: (...args: unknown[]) => console.warn('[Core:State]', ...args),
    error: (...args: unknown[]) => console.error('[Core:State]', ...args),
};

/**
 * useControllableState 옵션
 */
export interface UseControllableStateOptions<T> {
  /**
   * 외부 제어 값 변경 시 내부 상태 동기화 여부
   * 기본값: true
   *
   * true: 외부 값 변경 시 내부 상태도 업데이트 (controlled → uncontrolled 전환 시 유용)
   * false: 외부 값 변경 시 내부 상태 유지
   */
  syncOnControlledChange?: boolean;

  /**
   * 값 비교 함수 (같으면 onChange 호출 안 함)
   * 기본: 참조 비교 (===)
   */
  isEqual?: (a: T, b: T) => boolean;
}

/**
 * Controlled/Uncontrolled 상태를 통합 관리하는 훅
 *
 * @param controlledValue 외부에서 제어하는 값 (undefined면 내부 상태 사용)
 * @param defaultValue 기본값 (Uncontrolled 모드 초기값)
 * @param onChange 값 변경 시 호출할 콜백
 * @param options 추가 옵션
 * @returns [현재 값, 값 설정 함수]
 */
export function useControllableState<T>(
  controlledValue: T | undefined,
  defaultValue: T,
  onChange?: (value: T) => void,
  options?: UseControllableStateOptions<T>
): [T, (value: T | ((prev: T) => T)) => void] {
  const { syncOnControlledChange = true, isEqual } = options ?? {};

  // Controlled 모드 여부 판단 (첫 렌더링 시 결정)
  const isControlled = controlledValue !== undefined;
  const isControlledRef = useRef(isControlled);

  // 개발 모드에서 controlled/uncontrolled 전환 경고
  if (process.env.NODE_ENV !== 'production') {
    if (isControlledRef.current !== isControlled) {
      logger.warn(
        '컴포넌트가 controlled에서 uncontrolled로 (또는 반대로) 전환되었습니다. ' +
        '이는 예기치 않은 동작을 일으킬 수 있습니다. ' +
        'controlledValue가 항상 정의되어 있거나 항상 undefined인지 확인하세요.'
      );
    }
  }

  // 내부 상태 (초기값은 controlledValue가 있으면 그것을 사용)
  const [internalValue, setInternalValue] = useState<T>(
    controlledValue !== undefined ? controlledValue : defaultValue
  );

  // 현재 값: 항상 내부 상태 사용 (낙관적 업데이트)
  // Controlled 모드에서는 useEffect를 통해 외부 값과 동기화됨
  const value = internalValue;

  // 이전 controlled 값 추적 (동기화용)
  const prevControlledValueRef = useRef(controlledValue);

  // 내부 업데이트 중 플래그 (외부 동기화 무시용)
  const isInternalUpdateRef = useRef(false);

  // Controlled 값 변경 시 내부 상태 동기화
  useEffect(() => {
    // 내부 업데이트 중이면 외부 동기화 무시 (깜빡거림 방지)
    if (isInternalUpdateRef.current) {
      prevControlledValueRef.current = controlledValue;
      return;
    }

    if (
      syncOnControlledChange &&
      isControlled &&
      controlledValue !== prevControlledValueRef.current
    ) {
      // 값이 실제로 다른 경우에만 동기화 (isEqual 함수 사용)
      const isDifferent = isEqual
        ? !isEqual(internalValue, controlledValue)
        : internalValue !== controlledValue;

      if (isDifferent) {
        setInternalValue(controlledValue);
      }
    }
    prevControlledValueRef.current = controlledValue;
  }, [controlledValue, isControlled, syncOnControlledChange, isEqual, internalValue]);

  // 값 설정 함수
  const setValue = useCallback(
    (newValueOrUpdater: T | ((prev: T) => T)) => {
      // updater 함수 또는 직접 값 처리
      const newValue =
        typeof newValueOrUpdater === 'function'
          ? (newValueOrUpdater as (prev: T) => T)(value)
          : newValueOrUpdater;

      // 값이 같으면 무시
      if (isEqual ? isEqual(value, newValue) : value === newValue) {
        return;
      }

      // 내부 업데이트 플래그 설정 (외부 동기화 무시)
      isInternalUpdateRef.current = true;

      // 내부 상태 항상 업데이트 (Controlled 모드에서도 리렌더링 유발)
      // Controlled 모드: 외부 값이 업데이트될 때까지 낙관적 업데이트로 즉각 반응
      // Uncontrolled 모드: 내부 상태가 실제 값으로 사용됨
      setInternalValue(newValue);

      // onChange 콜백 호출 (Controlled/Uncontrolled 모두)
      onChange?.(newValue);

      // 다음 프레임에서 플래그 리셋 (외부 값이 업데이트된 후)
      requestAnimationFrame(() => {
        isInternalUpdateRef.current = false;
      });
    },
    [value, isControlled, onChange, isEqual]
  );

  return [value, setValue];
}

/**
 * 배열 값을 위한 특수 비교 함수
 * 얕은 비교로 배열 내용이 같으면 true 반환
 */
export function shallowArrayEqual<T>(a: T[], b: T[]): boolean {
  if (a === b) return true;
  if (a.length !== b.length) return false;
  return a.every((item, index) => item === b[index]);
}

/**
 * 객체 값을 위한 특수 비교 함수
 * 얕은 비교로 객체 1단계 속성이 같으면 true 반환
 */
export function shallowObjectEqual<T extends Record<string, any>>(
  a: T,
  b: T
): boolean {
  if (a === b) return true;
  const keysA = Object.keys(a);
  const keysB = Object.keys(b);
  if (keysA.length !== keysB.length) return false;
  return keysA.every((key) => a[key] === b[key]);
}

export default useControllableState;
