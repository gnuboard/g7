/**
 * FormContext 유틸리티 함수 테스트
 *
 * parseDataKey, updateByScope, getValueByScope 등 isolated state 관련 헬퍼 테스트
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import {
  parseDataKey,
  updateByScope,
  getValueByScope,
  getNestedValue,
  setNestedValue,
  isAutoBindingEnabled,
  getAutoBindingPath,
  FormContextValue,
} from '../template-engine/FormContext';

describe('FormContext 유틸리티', () => {
  describe('parseDataKey', () => {
    it('should parse _global prefix correctly', () => {
      const result = parseDataKey('_global.settings');
      expect(result).toEqual({ scope: 'global', path: 'settings' });
    });

    it('should parse _isolated prefix correctly', () => {
      const result = parseDataKey('_isolated.miniForm');
      expect(result).toEqual({ scope: 'isolated', path: 'miniForm' });
    });

    it('should parse _local prefix correctly', () => {
      const result = parseDataKey('_local.formData');
      expect(result).toEqual({ scope: 'local', path: 'formData' });
    });

    it('should default to local scope when no prefix', () => {
      const result = parseDataKey('formData');
      expect(result).toEqual({ scope: 'local', path: 'formData' });
    });

    it('should handle nested paths with _global prefix', () => {
      const result = parseDataKey('_global.user.profile');
      expect(result).toEqual({ scope: 'global', path: 'user.profile' });
    });

    it('should handle nested paths with _isolated prefix', () => {
      const result = parseDataKey('_isolated.form.fields.email');
      expect(result).toEqual({ scope: 'isolated', path: 'form.fields.email' });
    });
  });

  describe('getNestedValue', () => {
    it('should get value at simple path', () => {
      const obj = { formData: { email: 'test@example.com' } };
      expect(getNestedValue(obj, 'formData.email')).toBe('test@example.com');
    });

    it('should return undefined for non-existent path', () => {
      const obj = { formData: {} };
      expect(getNestedValue(obj, 'formData.email')).toBeUndefined();
    });

    it('should return undefined when obj is undefined', () => {
      expect(getNestedValue(undefined, 'any.path')).toBeUndefined();
    });

    it('should handle deeply nested paths', () => {
      const obj = { a: { b: { c: { d: 'value' } } } };
      expect(getNestedValue(obj, 'a.b.c.d')).toBe('value');
    });
  });

  describe('setNestedValue', () => {
    it('should set value at simple path', () => {
      const obj = { formData: { name: '' } };
      const result = setNestedValue(obj, 'formData.email', 'test@example.com');
      expect(result.formData.email).toBe('test@example.com');
      expect(result.formData.name).toBe(''); // 기존 값 유지
    });

    it('should preserve immutability', () => {
      const obj = { formData: { email: '' } };
      const result = setNestedValue(obj, 'formData.email', 'new@example.com');
      expect(obj.formData.email).toBe(''); // 원본 불변
      expect(result.formData.email).toBe('new@example.com');
    });

    it('should handle single key path', () => {
      const obj = { email: '' };
      const result = setNestedValue(obj, 'email', 'test@example.com');
      expect(result.email).toBe('test@example.com');
    });

    it('should create nested structure if not exists', () => {
      const obj = {};
      const result = setNestedValue(obj, 'a.b.c', 'value');
      expect(result.a.b.c).toBe('value');
    });
  });

  describe('updateByScope - isolated', () => {
    let mockIsolatedContext: any;
    let context: FormContextValue;

    beforeEach(() => {
      mockIsolatedContext = {
        state: { miniForm: { email: '' } },
        mergeState: vi.fn(),
      };
      context = {
        dataKey: '_isolated.miniForm',
        isolatedContext: mockIsolatedContext,
        setState: vi.fn(),
        state: {},
      };
    });

    it('should update isolated state when scope is isolated', () => {
      updateByScope('_isolated.miniForm', 'email', 'test@example.com', context);

      expect(mockIsolatedContext.mergeState).toHaveBeenCalledWith({
        miniForm: { email: 'test@example.com' },
      });
      expect(context.setState).not.toHaveBeenCalled();
    });

    it('should handle empty path', () => {
      updateByScope('_isolated.miniForm', '', { email: 'test@example.com' }, context);

      expect(mockIsolatedContext.mergeState).toHaveBeenCalledWith({
        miniForm: { email: 'test@example.com' },
      });
    });
  });

  describe('updateByScope - local', () => {
    let context: FormContextValue;

    beforeEach(() => {
      context = {
        dataKey: 'formData',
        setState: vi.fn(),
        state: { formData: {} },
        isolatedContext: null,
      };
    });

    it('should update local state when scope is local', () => {
      updateByScope('formData', 'email', 'test@example.com', context);

      expect(context.setState).toHaveBeenCalledWith({
        formData: { email: 'test@example.com' },
      });
    });

    it('should handle _local prefix', () => {
      updateByScope('_local.formData', 'email', 'test@example.com', context);

      expect(context.setState).toHaveBeenCalledWith({
        formData: { email: 'test@example.com' },
      });
    });
  });

  describe('updateByScope - global', () => {
    let originalG7Core: any;

    beforeEach(() => {
      originalG7Core = (window as any).G7Core;
      (window as any).G7Core = {
        state: {
          set: vi.fn(),
        },
      };
    });

    afterEach(() => {
      (window as any).G7Core = originalG7Core;
    });

    it('should update global state when scope is global', () => {
      const context: FormContextValue = {
        dataKey: '_global.settings',
        setState: vi.fn(),
        state: {},
      };

      updateByScope('_global.settings', 'theme', 'dark', context);

      expect((window as any).G7Core.state.set).toHaveBeenCalledWith({
        settings: { theme: 'dark' },
      });
      expect(context.setState).not.toHaveBeenCalled();
    });
  });

  describe('getValueByScope - isolated', () => {
    it('should get value from isolated state', () => {
      const context: FormContextValue = {
        dataKey: '_isolated.miniForm',
        isolatedContext: {
          state: { miniForm: { email: 'test@example.com' } },
          setState: vi.fn(),
          getState: vi.fn(),
          mergeState: vi.fn(),
        },
        state: {},
      };

      const value = getValueByScope('_isolated.miniForm', 'email', context);
      expect(value).toBe('test@example.com');
    });

    it('should return undefined when isolatedContext is null', () => {
      const context: FormContextValue = {
        dataKey: '_isolated.miniForm',
        isolatedContext: null,
        state: {},
      };

      const value = getValueByScope('_isolated.miniForm', 'email', context);
      expect(value).toBeUndefined();
    });
  });

  describe('getValueByScope - local', () => {
    it('should get value from local state', () => {
      const context: FormContextValue = {
        dataKey: 'formData',
        state: { formData: { email: 'local@example.com' } },
      };

      const value = getValueByScope('formData', 'email', context);
      expect(value).toBe('local@example.com');
    });
  });

  describe('isAutoBindingEnabled', () => {
    it('should return true when all conditions met', () => {
      const context: FormContextValue = {
        dataKey: 'formData',
        setState: vi.fn(),
      };
      expect(isAutoBindingEnabled(context, 'email')).toBe(true);
    });

    it('should return false when dataKey is missing', () => {
      const context: FormContextValue = {
        setState: vi.fn(),
      };
      expect(isAutoBindingEnabled(context, 'email')).toBe(false);
    });

    it('should return false when name is missing', () => {
      const context: FormContextValue = {
        dataKey: 'formData',
        setState: vi.fn(),
      };
      expect(isAutoBindingEnabled(context, undefined)).toBe(false);
    });

    it('should return false when setState is missing', () => {
      const context: FormContextValue = {
        dataKey: 'formData',
      };
      expect(isAutoBindingEnabled(context, 'email')).toBe(false);
    });
  });

  describe('getAutoBindingPath', () => {
    it('should combine dataKey and name', () => {
      expect(getAutoBindingPath('formData', 'email')).toBe('formData.email');
    });

    it('should work with isolated prefix', () => {
      expect(getAutoBindingPath('_isolated.miniForm', 'email')).toBe('_isolated.miniForm.email');
    });
  });
});
