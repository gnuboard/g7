/**
 * useValidation.test.ts
 *
 * 유효성 검증 훅 테스트
 */

import { renderHook, act, waitFor } from '@testing-library/react';
import { vi, describe, it, expect, beforeEach, afterEach } from 'vitest';
import { useValidation } from '../hooks/useValidation';
import { useEditorState } from '../hooks/useEditorState';
import type { LayoutData, ComponentDefinition } from '../types/editor';

// useEditorState 모킹
vi.mock('../hooks/useEditorState', () => ({
  useEditorState: vi.fn(),
}));

// validationUtils 모킹
vi.mock('../utils/validationUtils', () => ({
  validateLayout: vi.fn().mockImplementation((layoutData) => {
    const errors: Array<{ code: string; message: string; path: string; severity: string }> = [];
    const warnings: Array<{ code: string; message: string; path: string; severity: string }> = [];

    // 간단한 검증 로직
    if (!layoutData.layout_name) {
      errors.push({
        code: 'MISSING_LAYOUT_NAME',
        message: 'Layout name is required',
        path: 'layout_name',
        severity: 'error',
      });
    }

    if (layoutData.components.length === 0) {
      warnings.push({
        code: 'EMPTY_COMPONENTS',
        message: 'Layout has no components',
        path: 'components',
        severity: 'warning',
      });
    }

    return {
      valid: errors.length === 0,
      errors,
      warnings,
    };
  }),
  validateComponent: vi.fn(),
  quickValidate: vi.fn(),
}));

describe('useValidation', () => {
  const mockLayoutData: LayoutData = {
    version: '1.0.0',
    layout_name: 'test-layout',
    components: [
      {
        id: 'test-component',
        type: 'basic',
        name: 'Button',
        props: { label: 'Test' },
      },
    ],
    data_sources: [],
  };

  const mockEmptyLayoutData: LayoutData = {
    version: '1.0.0',
    layout_name: 'empty-layout',
    components: [],
    data_sources: [],
  };

  beforeEach(() => {
    vi.useFakeTimers();
    (useEditorState as unknown as ReturnType<typeof vi.fn>).mockImplementation((selector) => {
      const state = {
        layoutData: mockLayoutData,
        componentCategories: {
          basic: [],
          composite: [],
          layout: [],
        },
      };
      return selector(state);
    });
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.clearAllMocks();
  });

  // ==========================================================================
  // 기본 기능 테스트
  // ==========================================================================

  describe('basic functionality', () => {
    it('should initialize with valid state', () => {
      const { result } = renderHook(() => useValidation());

      expect(result.current.isValid).toBe(true);
      expect(result.current.validationResult).toBeNull();
      expect(result.current.errors).toEqual([]);
      expect(result.current.warnings).toEqual([]);
      expect(result.current.isValidating).toBe(false);
    });

    it('should return error count and warning count', () => {
      const { result } = renderHook(() => useValidation());

      expect(result.current.errorCount).toBe(0);
      expect(result.current.warningCount).toBe(0);
    });

    it('should provide validate function', () => {
      const { result } = renderHook(() => useValidation());

      expect(typeof result.current.validate).toBe('function');
    });

    it('should provide validateBeforeSave function', () => {
      const { result } = renderHook(() => useValidation());

      expect(typeof result.current.validateBeforeSave).toBe('function');
    });
  });

  // ==========================================================================
  // validate 함수 테스트
  // ==========================================================================

  describe('validate function', () => {
    it('should validate layout and return result', () => {
      const { result } = renderHook(() => useValidation());

      let validationResult;
      act(() => {
        validationResult = result.current.validate();
      });

      expect(validationResult).toBeDefined();
      expect(validationResult!.valid).toBe(true);
      expect(result.current.validationResult).not.toBeNull();
    });

    it('should return error when no layout data', () => {
      (useEditorState as unknown as ReturnType<typeof vi.fn>).mockImplementation((selector) => {
        const state = {
          layoutData: null,
          componentCategories: null,
        };
        return selector(state);
      });

      const { result } = renderHook(() => useValidation());

      let validationResult;
      act(() => {
        validationResult = result.current.validate();
      });

      expect(validationResult!.valid).toBe(false);
      expect(validationResult!.errors).toHaveLength(1);
      expect(validationResult!.errors[0].code).toBe('NO_LAYOUT');
    });

    it('should update lastValidatedAt after validation', () => {
      const { result } = renderHook(() => useValidation());

      expect(result.current.lastValidatedAt).toBeNull();

      act(() => {
        result.current.validate();
      });

      expect(result.current.lastValidatedAt).toBeInstanceOf(Date);
    });
  });

  // ==========================================================================
  // validateBeforeSave 테스트
  // ==========================================================================

  describe('validateBeforeSave', () => {
    it('should return true for valid layout', () => {
      const { result } = renderHook(() => useValidation());

      let isValid;
      act(() => {
        isValid = result.current.validateBeforeSave();
      });

      expect(isValid).toBe(true);
    });

    it('should return false for invalid layout', () => {
      (useEditorState as unknown as ReturnType<typeof vi.fn>).mockImplementation((selector) => {
        const state = {
          layoutData: null,
          componentCategories: null,
        };
        return selector(state);
      });

      const { result } = renderHook(() => useValidation());

      let isValid;
      act(() => {
        isValid = result.current.validateBeforeSave();
      });

      expect(isValid).toBe(false);
    });
  });

  // ==========================================================================
  // clearValidation 테스트
  // ==========================================================================

  describe('clearValidation', () => {
    it('should clear all validation state', () => {
      const { result } = renderHook(() => useValidation());

      // 먼저 검증 실행
      act(() => {
        result.current.validate();
      });

      expect(result.current.validationResult).not.toBeNull();

      // 검증 결과 초기화
      act(() => {
        result.current.clearValidation();
      });

      expect(result.current.validationResult).toBeNull();
      expect(result.current.lastValidatedAt).toBeNull();
    });
  });

  // ==========================================================================
  // 컴포넌트별 검증 테스트
  // ==========================================================================

  describe('component validation helpers', () => {
    it('should return empty errors for unknown component', () => {
      const { result } = renderHook(() => useValidation());

      const errors = result.current.getComponentErrors('unknown-id');
      expect(errors).toEqual([]);
    });

    it('should return empty warnings for unknown component', () => {
      const { result } = renderHook(() => useValidation());

      const warnings = result.current.getComponentWarnings('unknown-id');
      expect(warnings).toEqual([]);
    });

    it('should return true for unknown component validity', () => {
      const { result } = renderHook(() => useValidation());

      const isValid = result.current.isComponentValid('unknown-id');
      expect(isValid).toBe(true);
    });
  });

  // ==========================================================================
  // 옵션 테스트
  // ==========================================================================

  describe('options', () => {
    it('should respect treatWarningsAsErrors option', () => {
      (useEditorState as unknown as ReturnType<typeof vi.fn>).mockImplementation((selector) => {
        const state = {
          layoutData: mockEmptyLayoutData,
          componentCategories: {
            basic: [],
            composite: [],
            layout: [],
          },
        };
        return selector(state);
      });

      const { result } = renderHook(() =>
        useValidation({ treatWarningsAsErrors: true })
      );

      let validationResult;
      act(() => {
        validationResult = result.current.validate();
      });

      // 경고가 에러로 처리되어 유효하지 않음
      expect(validationResult!.valid).toBe(false);
      expect(validationResult!.errors.length).toBeGreaterThan(0);
    });

    it('should respect realTimeValidation option', () => {
      const { result } = renderHook(() =>
        useValidation({ realTimeValidation: false })
      );

      // 실시간 검증이 비활성화되어도 수동 검증은 가능
      expect(typeof result.current.validate).toBe('function');
    });
  });

  // ==========================================================================
  // validateComponent 테스트
  // ==========================================================================

  describe('validateComponent', () => {
    it('should validate single component', () => {
      const { result } = renderHook(() => useValidation());

      const component: ComponentDefinition = {
        id: 'single-button',
        type: 'basic',
        name: 'Button',
        props: { label: 'Click' },
      };

      let validationResult;
      act(() => {
        validationResult = result.current.validateComponent(component);
      });

      expect(validationResult).toBeDefined();
      expect(validationResult).toHaveProperty('valid');
      expect(validationResult).toHaveProperty('errors');
      expect(validationResult).toHaveProperty('warnings');
    });
  });
});
