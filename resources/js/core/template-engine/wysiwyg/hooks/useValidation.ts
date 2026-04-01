/**
 * useValidation.ts
 *
 * G7 위지윅 레이아웃 편집기의 유효성 검증 훅
 *
 * 역할:
 * - 실시간 레이아웃 유효성 검증
 * - 검증 결과 캐싱
 * - 컴포넌트별 에러/경고 추적
 * - 저장 전 검증
 */

import { useState, useCallback, useMemo, useEffect, useRef } from 'react';
import { useEditorState } from './useEditorState';
import {
  validateLayout,
  validateComponent,
  quickValidate,
} from '../utils/validationUtils';
import type {
  LayoutData,
  ComponentDefinition,
  ValidationResult,
  ValidationError,
  ValidationWarning,
  ComponentMetadata,
} from '../types/editor';

// ============================================================================
// 타입 정의
// ============================================================================

export interface ComponentValidationState {
  /** 컴포넌트 ID */
  componentId: string;
  /** 에러 목록 */
  errors: ValidationError[];
  /** 경고 목록 */
  warnings: ValidationWarning[];
  /** 유효 여부 */
  isValid: boolean;
}

export interface UseValidationOptions {
  /** 실시간 검증 활성화 */
  realTimeValidation?: boolean;
  /** 검증 디바운스 시간 (ms) */
  debounceMs?: number;
  /** 경고도 에러로 처리 */
  treatWarningsAsErrors?: boolean;
}

export interface UseValidationReturn {
  /** 전체 레이아웃 유효성 */
  isValid: boolean;

  /** 검증 결과 */
  validationResult: ValidationResult | null;

  /** 전체 에러 목록 */
  errors: ValidationError[];

  /** 전체 경고 목록 */
  warnings: ValidationWarning[];

  /** 에러 개수 */
  errorCount: number;

  /** 경고 개수 */
  warningCount: number;

  /** 컴포넌트별 검증 상태 */
  componentValidation: Map<string, ComponentValidationState>;

  /** 특정 컴포넌트의 에러 가져오기 */
  getComponentErrors: (componentId: string) => ValidationError[];

  /** 특정 컴포넌트의 경고 가져오기 */
  getComponentWarnings: (componentId: string) => ValidationWarning[];

  /** 특정 컴포넌트가 유효한지 */
  isComponentValid: (componentId: string) => boolean;

  /** 전체 레이아웃 검증 실행 */
  validate: () => ValidationResult;

  /** 특정 컴포넌트만 검증 */
  validateComponent: (component: ComponentDefinition) => ValidationResult;

  /** 저장 전 검증 (에러 시 false) */
  validateBeforeSave: () => boolean;

  /** 검증 결과 초기화 */
  clearValidation: () => void;

  /** 검증 중 여부 */
  isValidating: boolean;

  /** 마지막 검증 시간 */
  lastValidatedAt: Date | null;
}

// ============================================================================
// 훅 구현
// ============================================================================

/**
 * 유효성 검증 훅
 *
 * 레이아웃의 실시간 유효성 검증을 관리합니다.
 */
export function useValidation(options: UseValidationOptions = {}): UseValidationReturn {
  const {
    realTimeValidation = true,
    debounceMs = 500,
    treatWarningsAsErrors = false,
  } = options;

  // Zustand 상태
  const layoutData = useEditorState((state) => state.layoutData);
  const componentCategories = useEditorState((state) => state.componentCategories);

  // 로컬 상태
  const [validationResult, setValidationResult] = useState<ValidationResult | null>(null);
  const [componentValidation, setComponentValidation] = useState<Map<string, ComponentValidationState>>(new Map());
  const [isValidating, setIsValidating] = useState(false);
  const [lastValidatedAt, setLastValidatedAt] = useState<Date | null>(null);

  // Ref
  const debounceTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const lastLayoutRef = useRef<string | null>(null);

  // 컴포넌트 레지스트리 (메타데이터 배열로 변환)
  const componentRegistry = useMemo(() => {
    if (!componentCategories) return undefined;
    return [
      ...componentCategories.basic,
      ...componentCategories.composite,
      ...componentCategories.layout,
    ];
  }, [componentCategories]);

  // 전체 레이아웃 검증
  const validate = useCallback((): ValidationResult => {
    if (!layoutData) {
      const emptyResult: ValidationResult = {
        valid: false,
        errors: [{
          code: 'NO_LAYOUT',
          message: 'No layout data to validate',
          path: '',
          severity: 'error',
        }],
        warnings: [],
      };
      setValidationResult(emptyResult);
      return emptyResult;
    }

    setIsValidating(true);

    try {
      const result = validateLayout(layoutData, componentRegistry);

      // 경고를 에러로 처리하는 옵션
      if (treatWarningsAsErrors && result.warnings.length > 0) {
        result.valid = false;
        result.errors = [
          ...result.errors,
          ...result.warnings.map((w) => ({
            ...w,
            severity: 'error' as const,
          })),
        ];
      }

      setValidationResult(result);
      setLastValidatedAt(new Date());

      // 컴포넌트별 검증 상태 업데이트
      updateComponentValidation(result);

      return result;
    } finally {
      setIsValidating(false);
    }
  }, [layoutData, componentRegistry, treatWarningsAsErrors]);

  // 컴포넌트별 검증 상태 업데이트
  const updateComponentValidation = useCallback((result: ValidationResult) => {
    const newMap = new Map<string, ComponentValidationState>();

    // 경로에서 컴포넌트 ID 추출하는 함수
    const extractComponentId = (path: string): string | null => {
      // components[0].id 또는 components[0].children[1] 형태에서 ID 추출
      const match = path.match(/components\[(\d+)\]/);
      if (!match) return null;
      // 실제로는 레이아웃에서 해당 인덱스의 컴포넌트 ID를 가져와야 함
      return null; // TODO: 구현 필요
    };

    // 에러 매핑
    for (const error of result.errors) {
      const componentId = extractComponentId(error.path);
      if (!componentId) continue;

      if (!newMap.has(componentId)) {
        newMap.set(componentId, {
          componentId,
          errors: [],
          warnings: [],
          isValid: true,
        });
      }

      const state = newMap.get(componentId)!;
      state.errors.push(error);
      state.isValid = false;
    }

    // 경고 매핑
    for (const warning of result.warnings) {
      const componentId = extractComponentId(warning.path);
      if (!componentId) continue;

      if (!newMap.has(componentId)) {
        newMap.set(componentId, {
          componentId,
          errors: [],
          warnings: [],
          isValid: true,
        });
      }

      const state = newMap.get(componentId)!;
      state.warnings.push(warning);
    }

    setComponentValidation(newMap);
  }, []);

  // 특정 컴포넌트 검증
  const validateComponentFn = useCallback(
    (component: ComponentDefinition): ValidationResult => {
      const errors: ValidationError[] = [];
      const warnings: ValidationWarning[] = [];

      // 내부 함수 호출을 위한 래퍼
      // validateComponent는 내부적으로 errors, warnings 배열을 채움
      const tempResult = validateLayout({
        version: '1.0.0',
        layout_name: 'temp',
        components: [component],
        data_sources: [],
      } as LayoutData, componentRegistry);

      return tempResult;
    },
    [componentRegistry]
  );

  // 저장 전 검증
  const validateBeforeSave = useCallback((): boolean => {
    const result = validate();
    return result.valid;
  }, [validate]);

  // 검증 결과 초기화
  const clearValidation = useCallback(() => {
    setValidationResult(null);
    setComponentValidation(new Map());
    setLastValidatedAt(null);
  }, []);

  // 특정 컴포넌트의 에러 가져오기
  const getComponentErrors = useCallback(
    (componentId: string): ValidationError[] => {
      return componentValidation.get(componentId)?.errors || [];
    },
    [componentValidation]
  );

  // 특정 컴포넌트의 경고 가져오기
  const getComponentWarnings = useCallback(
    (componentId: string): ValidationWarning[] => {
      return componentValidation.get(componentId)?.warnings || [];
    },
    [componentValidation]
  );

  // 특정 컴포넌트가 유효한지
  const isComponentValid = useCallback(
    (componentId: string): boolean => {
      const state = componentValidation.get(componentId);
      if (!state) return true; // 검증 데이터 없으면 유효로 간주
      return state.isValid;
    },
    [componentValidation]
  );

  // 실시간 검증 (레이아웃 변경 시)
  useEffect(() => {
    if (!realTimeValidation || !layoutData) return;

    const layoutJson = JSON.stringify(layoutData);

    // 레이아웃이 변경되지 않았으면 스킵
    if (layoutJson === lastLayoutRef.current) return;
    lastLayoutRef.current = layoutJson;

    // 이전 타이머 취소
    if (debounceTimerRef.current) {
      clearTimeout(debounceTimerRef.current);
    }

    // 디바운스된 검증
    debounceTimerRef.current = setTimeout(() => {
      validate();
    }, debounceMs);

    return () => {
      if (debounceTimerRef.current) {
        clearTimeout(debounceTimerRef.current);
      }
    };
  }, [layoutData, realTimeValidation, debounceMs, validate]);

  // 계산된 값
  const isValid = validationResult?.valid ?? true;
  const errors = validationResult?.errors ?? [];
  const warnings = validationResult?.warnings ?? [];
  const errorCount = errors.length;
  const warningCount = warnings.length;

  return {
    isValid,
    validationResult,
    errors,
    warnings,
    errorCount,
    warningCount,
    componentValidation,
    getComponentErrors,
    getComponentWarnings,
    isComponentValid,
    validate,
    validateComponent: validateComponentFn,
    validateBeforeSave,
    clearValidation,
    isValidating,
    lastValidatedAt,
  };
}

// ============================================================================
// Export
// ============================================================================

export default useValidation;
