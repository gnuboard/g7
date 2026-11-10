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
    // @scenario area=parse_data_key
    // @effects form_data_key_scope_parsed
    it('should parse _global prefix correctly', () => {
      const result = parseDataKey('_global.settings');
      expect(result).toEqual({ scope: 'global', path: 'settings' });
    });

    // @scenario area=parse_data_key
    // @effects form_data_key_scope_parsed
    it('should parse _isolated prefix correctly', () => {
      const result = parseDataKey('_isolated.miniForm');
      expect(result).toEqual({ scope: 'isolated', path: 'miniForm' });
    });

    // @scenario area=parse_data_key
    // @effects form_data_key_scope_parsed
    it('should parse _local prefix correctly', () => {
      const result = parseDataKey('_local.formData');
      expect(result).toEqual({ scope: 'local', path: 'formData' });
    });

    // @scenario area=parse_data_key
    // @effects form_data_key_scope_parsed
    it('should default to local scope when no prefix', () => {
      const result = parseDataKey('formData');
      expect(result).toEqual({ scope: 'local', path: 'formData' });
    });

    // @scenario area=parse_data_key
    // @effects form_data_key_scope_parsed
    it('should handle nested paths with _global prefix', () => {
      const result = parseDataKey('_global.user.profile');
      expect(result).toEqual({ scope: 'global', path: 'user.profile' });
    });

    // @scenario area=parse_data_key
    // @effects form_data_key_scope_parsed
    it('should handle nested paths with _isolated prefix', () => {
      const result = parseDataKey('_isolated.form.fields.email');
      expect(result).toEqual({ scope: 'isolated', path: 'form.fields.email' });
    });
  });

  describe('getNestedValue', () => {
    // @scenario area=nested_value
    // @effects form_nested_value_rw
    it('should get value at simple path', () => {
      const obj = { formData: { email: 'test@example.com' } };
      expect(getNestedValue(obj, 'formData.email')).toBe('test@example.com');
    });

    // @scenario area=nested_value
    // @effects form_nested_value_rw
    it('should return undefined for non-existent path', () => {
      const obj = { formData: {} };
      expect(getNestedValue(obj, 'formData.email')).toBeUndefined();
    });

    // @scenario area=nested_value
    // @effects form_nested_value_rw
    it('should return undefined when obj is undefined', () => {
      expect(getNestedValue(undefined, 'any.path')).toBeUndefined();
    });

    // @scenario area=nested_value
    // @effects form_nested_value_rw
    it('should handle deeply nested paths', () => {
      const obj = { a: { b: { c: { d: 'value' } } } };
      expect(getNestedValue(obj, 'a.b.c.d')).toBe('value');
    });
  });

  describe('setNestedValue', () => {
    // @scenario area=nested_value
    // @effects form_nested_value_rw
    it('should set value at simple path', () => {
      const obj = { formData: { name: '' } };
      const result = setNestedValue(obj, 'formData.email', 'test@example.com');
      expect(result.formData.email).toBe('test@example.com');
      expect(result.formData.name).toBe(''); // 기존 값 유지
    });

    // @scenario area=nested_value
    // @effects form_nested_value_rw
    it('should preserve immutability', () => {
      const obj = { formData: { email: '' } };
      const result = setNestedValue(obj, 'formData.email', 'new@example.com');
      expect(obj.formData.email).toBe(''); // 원본 불변
      expect(result.formData.email).toBe('new@example.com');
    });

    // @scenario area=nested_value
    // @effects form_nested_value_rw
    it('should handle single key path', () => {
      const obj = { email: '' };
      const result = setNestedValue(obj, 'email', 'test@example.com');
      expect(result.email).toBe('test@example.com');
    });

    // @scenario area=nested_value
    // @effects form_nested_value_rw
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

    // @scenario area=update_scope
    // @effects form_update_routes_by_scope
    it('should update isolated state when scope is isolated', () => {
      updateByScope('_isolated.miniForm', 'email', 'test@example.com', context);

      expect(mockIsolatedContext.mergeState).toHaveBeenCalledWith({
        miniForm: { email: 'test@example.com' },
      });
      expect(context.setState).not.toHaveBeenCalled();
    });

    // @scenario area=update_scope
    // @effects form_update_routes_by_scope
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

    // @scenario area=update_scope
    // @effects form_update_routes_by_scope
    it('should update local state when scope is local', () => {
      updateByScope('formData', 'email', 'test@example.com', context);

      expect(context.setState).toHaveBeenCalledWith({
        formData: { email: 'test@example.com' },
      });
    });

    // @scenario area=update_scope
    // @effects form_update_routes_by_scope
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

    // @scenario area=update_scope
    // @effects form_update_routes_by_scope
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
    // @scenario area=read_scope
    // @effects form_read_routes_by_scope
    it('should get value from isolated state', () => {
      const isolatedState = { miniForm: { email: 'test@example.com' } };
      const context: FormContextValue = {
        dataKey: '_isolated.miniForm',
        isolatedContext: {
          state: isolatedState,
          setState: vi.fn(),
          getState: vi.fn(),
          mergeState: vi.fn(),
          stateRef: { current: isolatedState },
          scopeId: 'mini-form',
        },
        state: {},
      };

      const value = getValueByScope('_isolated.miniForm', 'email', context);
      expect(value).toBe('test@example.com');
    });

    // @scenario area=read_scope
    // @effects form_read_routes_by_scope
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
    // @scenario area=read_scope
    // @effects form_read_routes_by_scope
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
    // @scenario area=auto_binding
    // @effects form_auto_binding_path
    it('should return true when all conditions met', () => {
      const context: FormContextValue = {
        dataKey: 'formData',
        setState: vi.fn(),
      };
      expect(isAutoBindingEnabled(context, 'email')).toBe(true);
    });

    // @scenario area=auto_binding
    // @effects form_auto_binding_path
    it('should return false when dataKey is missing', () => {
      const context: FormContextValue = {
        setState: vi.fn(),
      };
      expect(isAutoBindingEnabled(context, 'email')).toBe(false);
    });

    // @scenario area=auto_binding
    // @effects form_auto_binding_path
    it('should return false when name is missing', () => {
      const context: FormContextValue = {
        dataKey: 'formData',
        setState: vi.fn(),
      };
      expect(isAutoBindingEnabled(context, undefined)).toBe(false);
    });

    // @scenario area=auto_binding
    // @effects form_auto_binding_path
    it('should return false when setState is missing', () => {
      const context: FormContextValue = {
        dataKey: 'formData',
      };
      expect(isAutoBindingEnabled(context, 'email')).toBe(false);
    });
  });

  describe('getAutoBindingPath', () => {
    // @scenario area=auto_binding
    // @effects form_auto_binding_path
    it('should combine dataKey and name', () => {
      expect(getAutoBindingPath('formData', 'email')).toBe('formData.email');
    });

    // @scenario area=auto_binding
    // @effects form_auto_binding_path
    it('should work with isolated prefix', () => {
      expect(getAutoBindingPath('_isolated.miniForm', 'email')).toBe('_isolated.miniForm.email');
    });
  });
});
