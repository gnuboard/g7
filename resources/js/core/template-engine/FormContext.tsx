/**
 * FormContext.tsx
 *
 * Form 컴포넌트의 자동 바인딩 설정을 자식 컴포넌트에 전달하기 위한 Context
 *
 * 주요 기능:
 * - dataKey: 폼 데이터가 저장될 경로
 *   - "formData" → _local.formData
 *   - "_global.settings" → _global.settings
 *   - "_isolated.miniForm" → _isolated.miniForm
 * - trackChanges: 변경 사항 추적 여부 (hasChanges 자동 관리)
 * - setState: 상태 업데이트 함수 전달
 * - isolatedContext: 격리된 상태 컨텍스트 (target: "isolated" 지원)
 *
 * @module FormContext
 */

import React, { createContext, useContext } from 'react';
import type { IsolatedContextValue } from './IsolatedStateContext';

/**
 * Form 컨텍스트 값 인터페이스
 */
export interface FormContextValue {
  /**
   * 폼 데이터가 저장될 경로
   *
   * 지원되는 형식:
   * - "formData" → _local.formData (기본값)
   * - "_global.settings" → _global.settings
   * - "_isolated.miniForm" → _isolated.miniForm
   */
  dataKey?: string;

  /**
   * 변경 사항 추적 여부
   *
   * true이면 입력 시 _local.hasChanges를 자동으로 true로 설정
   */
  trackChanges?: boolean;

  /**
   * 자동 바인딩 debounce 설정 (밀리초)
   *
   * 설정되면 onChange 핸들러가 지정된 시간만큼 debounce됩니다.
   * 빈번한 입력 시 성능 최적화에 사용됩니다.
   *
   * @example
   * debounce: 300 // 300ms debounce
   */
  debounce?: number;

  /**
   * 상태 업데이트 함수
   *
   * DynamicRenderer에서 주입되며, 자동 바인딩 시 사용
   * 객체 또는 함수형 업데이트(prev => newState)를 지원합니다.
   */
  setState?: (updates: Record<string, any> | ((prev: Record<string, any>) => Record<string, any>)) => void;

  /**
   * 현재 상태
   *
   * 폼 데이터 접근을 위해 사용
   */
  state?: Record<string, any>;

  /**
   * 격리된 상태 컨텍스트
   *
   * dataKey가 "_isolated."로 시작할 때 사용
   * IsolatedStateProvider 내부에서만 유효
   */
  isolatedContext?: IsolatedContextValue | null;
}

/**
 * Form Context
 *
 * 기본값은 빈 객체로, Form 컴포넌트 외부에서 사용 시 자동 바인딩이 비활성화됨
 */
export const FormContext = createContext<FormContextValue>({});

/**
 * Form Context Provider Props
 */
export interface FormProviderProps {
  children: React.ReactNode;
  value: FormContextValue;
}

/**
 * Form Context Provider
 *
 * Form 컴포넌트에서 사용하여 자식들에게 바인딩 설정을 전달
 */
export const FormProvider: React.FC<FormProviderProps> = ({ children, value }) => {
  return (
    <FormContext.Provider value={value}>
      {children}
    </FormContext.Provider>
  );
};

/**
 * Form Context Hook
 *
 * Input, Select, Textarea 등에서 자동 바인딩 설정을 가져올 때 사용
 *
 * @example
 * ```tsx
 * const { dataKey, trackChanges, setState } = useFormContext();
 *
 * if (dataKey && name && setState) {
 *   // 자동 바인딩 활성화
 *   const path = `${dataKey}.${name}`;
 *   // ...
 * }
 * ```
 */
export const useFormContext = (): FormContextValue => {
  return useContext(FormContext);
};

/**
 * 자동 바인딩 활성화 여부 확인 헬퍼
 *
 * dataKey가 설정되어 있고, name prop이 있으며, setState가 제공된 경우 true
 *
 * @param context Form 컨텍스트
 * @param name Input의 name prop
 * @returns 자동 바인딩 활성화 여부
 */
export const isAutoBindingEnabled = (
  context: FormContextValue,
  name?: string
): boolean => {
  return !!(context.dataKey && name && context.setState);
};

/**
 * 컴포넌트의 change actions에 해당 form 필드를 대상으로 하는 명시적 setState가 있는지 확인
 *
 * change 이벤트에서 setState로 동일 필드(dataKey.name)를 직접 관리하는 경우,
 * 자동 바인딩이 value/onChange를 덮어쓰면 충돌이 발생합니다.
 * 이 함수는 해당 충돌 조건을 탐지합니다.
 *
 * @param actions 컴포넌트의 actions 배열
 * @param dataKey Form의 dataKey (예: "form")
 * @param name Input의 name prop (예: "purchase_restriction")
 * @returns true이면 자동 바인딩 스킵 권장
 */
export const hasExplicitSetStateForField = (
  actions: any[] | undefined,
  dataKey: string,
  name: string
): boolean => {
  if (!actions || !Array.isArray(actions)) return false;

  const fieldPath = `${dataKey}.${name}`;

  return actions.some((action: any) => {
    if (!action || action.type !== 'change') return false;

    // 직접 setState
    if (action.handler === 'setState' && action.params) {
      if (Object.keys(action.params).some(key => key === fieldPath)) return true;
    }

    // sequence 내부 setState
    if (action.handler === 'sequence' && Array.isArray(action.actions)) {
      return action.actions.some((sub: any) =>
        sub.handler === 'setState' &&
        sub.params &&
        Object.keys(sub.params).some(key => key === fieldPath)
      );
    }

    return false;
  });
};

/**
 * 자동 바인딩 경로 생성 헬퍼
 *
 * @param dataKey Form의 dataKey (예: "formData")
 * @param name Input의 name (예: "email")
 * @returns 전체 경로 (예: "formData.email")
 */
export const getAutoBindingPath = (dataKey: string, name: string): string => {
  return `${dataKey}.${name}`;
};

/**
 * 중첩 경로에서 값 가져오기 헬퍼
 *
 * @param obj 대상 객체
 * @param path dot notation 경로
 * @returns 해당 경로의 값 또는 undefined
 *
 * @example
 * getNestedValue({ formData: { email: 'test@example.com' } }, 'formData.email')
 * // 결과: 'test@example.com'
 */
export const getNestedValue = (obj: Record<string, any> | undefined, path: string): any => {
  if (!obj) return undefined;

  const keys = path.split('.');
  let current: any = obj;

  for (const key of keys) {
    if (current === null || current === undefined) {
      return undefined;
    }
    current = current[key];
  }

  return current;
};

/**
 * 중첩 경로에 값 설정하기 헬퍼 (불변 업데이트)
 *
 * @param obj 대상 객체
 * @param path dot notation 경로
 * @param value 설정할 값
 * @returns 새로운 객체 (불변성 유지)
 *
 * @example
 * setNestedValue({ formData: { email: '', name: '' } }, 'formData.email', 'test@example.com')
 * // 결과: { formData: { email: 'test@example.com', name: '' } }
 */
export const setNestedValue = (
  obj: Record<string, any>,
  path: string,
  value: any
): Record<string, any> => {
  const keys = path.split('.');

  if (keys.length === 1) {
    return { ...obj, [keys[0]]: value };
  }

  const result: Record<string, any> = { ...obj };
  let current = result;

  for (let i = 0; i < keys.length - 1; i++) {
    const key = keys[i];
    const nextKey = keys[i + 1];
    const isNextKeyArrayIndex = /^\d+$/.test(nextKey);

    if (Array.isArray(current[key]) && !isNextKeyArrayIndex) {
      // 배열이지만 다음 키가 문자열(숫자 인덱스가 아님) → 객체로 변환
      // PHP json_decode(assoc=true)에서 {} → [] 변환 시 복원 + 배열에 문자열 속성 추가 방지
      current[key] = { ...current[key] };
    } else if (Array.isArray(current[key])) {
      // 배열이고 다음 키가 숫자 인덱스 → 배열로 복사
      current[key] = [...current[key]];
    } else if (isNextKeyArrayIndex) {
      // 다음 키가 숫자(배열 인덱스)인데 현재 값이 배열이 아니면 배열로 초기화
      current[key] = Array.isArray(current[key]) ? [...current[key]] : [];
    } else {
      // 객체인 경우 객체로 복사
      current[key] = { ...(current[key] || {}) };
    }
    current = current[key];
  }

  current[keys[keys.length - 1]] = value;

  return result;
};

/**
 * dataKey 파싱 결과 인터페이스
 */
export interface ParsedDataKey {
  /** 상태 스코프: 'local' | 'global' | 'isolated' */
  scope: 'local' | 'global' | 'isolated';
  /** 스코프 접두사를 제외한 경로 */
  path: string;
}

/**
 * dataKey를 파싱하여 스코프와 경로를 분리하는 헬퍼
 *
 * @param dataKey Form의 dataKey (예: "_isolated.miniForm")
 * @returns 파싱된 스코프와 경로 객체
 *
 * @example
 * parseDataKey('_global.settings')
 * // 결과: { scope: 'global', path: 'settings' }
 *
 * parseDataKey('_isolated.miniForm')
 * // 결과: { scope: 'isolated', path: 'miniForm' }
 *
 * parseDataKey('formData')
 * // 결과: { scope: 'local', path: 'formData' }
 */
export const parseDataKey = (dataKey: string): ParsedDataKey => {
  if (dataKey.startsWith('_global.')) {
    return { scope: 'global', path: dataKey.slice(8) };
  } else if (dataKey.startsWith('_isolated.')) {
    return { scope: 'isolated', path: dataKey.slice(10) };
  } else if (dataKey.startsWith('_local.')) {
    return { scope: 'local', path: dataKey.slice(7) };
  }
  // 접두사가 없으면 기본적으로 _local로 간주
  return { scope: 'local', path: dataKey };
};

/**
 * dataKey 스코프에 따른 상태 업데이트 헬퍼
 *
 * dataKey의 스코프를 파싱하여 적절한 상태 업데이터를 호출합니다.
 *
 * @param dataKey Form의 dataKey
 * @param path 업데이트할 필드 경로 (dataKey 이후 부분)
 * @param value 설정할 값
 * @param context Form 컨텍스트
 *
 * @example
 * // _isolated.miniForm 스코프에서 email 필드 업데이트
 * updateByScope('_isolated.miniForm', 'email', 'test@example.com', context);
 */
export const updateByScope = (
  dataKey: string,
  path: string,
  value: any,
  context: FormContextValue
): void => {
  const { scope, path: scopePath } = parseDataKey(dataKey);
  const fullPath = path ? `${scopePath}.${path}` : scopePath;

  if (scope === 'isolated' && context.isolatedContext) {
    // 격리된 상태 업데이트
    const update = setNestedValue({}, fullPath, value);
    context.isolatedContext.mergeState(update);
  } else if (scope === 'global') {
    // 전역 상태 업데이트 (G7Core.state.set 사용)
    if ((window as any).G7Core?.state?.set) {
      const update = setNestedValue({}, fullPath, value);
      (window as any).G7Core.state.set(update);
    }
  } else if (context.setState) {
    // 로컬 상태 업데이트
    const update = setNestedValue({}, fullPath, value);
    context.setState(update);
  }
};

/**
 * dataKey 스코프에 따른 상태 값 조회 헬퍼
 *
 * @param dataKey Form의 dataKey
 * @param path 조회할 필드 경로 (dataKey 이후 부분)
 * @param context Form 컨텍스트
 * @returns 해당 경로의 값 또는 undefined
 *
 * @example
 * // _isolated.miniForm 스코프에서 email 필드 조회
 * const email = getValueByScope('_isolated.miniForm', 'email', context);
 */
export const getValueByScope = (
  dataKey: string,
  path: string,
  context: FormContextValue
): any => {
  const { scope, path: scopePath } = parseDataKey(dataKey);
  const fullPath = path ? `${scopePath}.${path}` : scopePath;

  if (scope === 'isolated' && context.isolatedContext) {
    return getNestedValue(context.isolatedContext.state, fullPath);
  } else if (scope === 'global') {
    const globalState = (window as any).G7Core?.state?.get?.();
    return getNestedValue(globalState, fullPath);
  } else {
    return getNestedValue(context.state, fullPath);
  }
};