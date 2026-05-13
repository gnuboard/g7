/**
 * validationUtils.test.ts
 *
 * G7 위지윅 레이아웃 편집기 - 유효성 검증 유틸리티 테스트
 *
 * 테스트 항목:
 * 1. validateLayout - 전체 레이아웃 검증
 * 2. formatValidationResult - 검증 결과 포맷팅
 * 3. quickValidate - 빠른 유효성 체크
 */

import { describe, it, expect } from 'vitest';
import {
  validateLayout,
  formatValidationResult,
  quickValidate,
} from '../utils/validationUtils';
import type { LayoutData, ComponentDefinition, DataSource, ActionDefinition } from '../types/editor';

describe('validationUtils', () => {
  describe('validateLayout', () => {
    it('유효한 레이아웃 데이터를 통과해야 함', () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'test-layout',
        data_sources: [],
        components: [
          {
            id: 'root',
            type: 'basic',
            name: 'Div',
          },
        ],
      };

      const result = validateLayout(layoutData);
      expect(result.valid).toBe(true);
      expect(result.errors).toHaveLength(0);
    });

    it('필수 필드(version) 누락 시 에러를 반환해야 함', () => {
      const layoutData = {
        layout_name: 'test',
        data_sources: [],
        components: [],
      } as LayoutData;

      const result = validateLayout(layoutData);
      expect(result.valid).toBe(false);
      expect(result.errors.some((e) => e.path === 'version')).toBe(true);
    });

    it('필수 필드(layout_name) 누락 시 에러를 반환해야 함', () => {
      const layoutData = {
        version: '1.0.0',
        data_sources: [],
        components: [],
      } as LayoutData;

      const result = validateLayout(layoutData);
      expect(result.valid).toBe(false);
      expect(result.errors.some((e) => e.path === 'layout_name')).toBe(true);
    });

    it('잘못된 레이아웃 이름에 대해 에러를 반환해야 함', () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'Invalid Layout Name!',
        data_sources: [],
        components: [],
      };

      const result = validateLayout(layoutData);
      expect(result.valid).toBe(false);
      expect(result.errors.some((e) => e.path === 'layout_name')).toBe(true);
    });

    it('중복 컴포넌트 ID에 대해 에러를 반환해야 함', () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'test-layout',
        data_sources: [],
        components: [
          { id: 'duplicate', type: 'basic', name: 'Div' },
          { id: 'duplicate', type: 'basic', name: 'Span' },
        ],
      };

      const result = validateLayout(layoutData);
      expect(result.valid).toBe(false);
      expect(result.errors.some((e) => e.code === 'DUPLICATE_COMPONENT_ID')).toBe(true);
    });

    it('컴포넌트 필수 필드 누락 시 에러를 반환해야 함', () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'test-layout',
        data_sources: [],
        components: [
          {
            id: 'test',
          } as ComponentDefinition,
        ],
      };

      const result = validateLayout(layoutData);
      expect(result.valid).toBe(false);
      expect(result.errors.some((e) => e.code === 'MISSING_COMPONENT_TYPE')).toBe(true);
      expect(result.errors.some((e) => e.code === 'MISSING_COMPONENT_NAME')).toBe(true);
    });

    it('잘못된 컴포넌트 타입에 대해 에러를 반환해야 함', () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'test-layout',
        data_sources: [],
        components: [
          {
            id: 'test',
            type: 'invalid' as any,
            name: 'Button',
          },
        ],
      };

      const result = validateLayout(layoutData);
      expect(result.valid).toBe(false);
      expect(result.errors.some((e) => e.code === 'INVALID_COMPONENT_TYPE')).toBe(true);
    });

    it('자식 컴포넌트도 검증해야 함', () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'test-layout',
        data_sources: [],
        components: [
          {
            id: 'parent',
            type: 'layout',
            name: 'Container',
            children: [
              {
                id: 'child',
                type: 'basic',
                name: '', // 빈 이름
              },
            ],
          },
        ],
      };

      const result = validateLayout(layoutData);
      expect(result.valid).toBe(false);
      expect(result.errors.some((e) => e.code === 'MISSING_COMPONENT_NAME')).toBe(true);
    });

    it('문자열 직접 children 배치를 금지해야 함', () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'test-layout',
        data_sources: [],
        components: [
          {
            id: 'parent',
            type: 'layout',
            name: 'Container',
            children: ['직접 문자열'] as any,
          },
        ],
      };

      const result = validateLayout(layoutData);
      expect(result.valid).toBe(false);
      expect(result.errors.some((e) => e.code === 'STRING_IN_CHILDREN')).toBe(true);
    });
  });

  describe('데이터 소스 검증', () => {
    it('유효한 API 데이터소스를 통과해야 함', () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'test-layout',
        data_sources: [
          {
            id: 'users',
            type: 'api',
            endpoint: '/api/users',
            auto_fetch: true,
          },
        ],
        components: [
          { id: 'root', type: 'basic', name: 'Div' },
        ],
      };

      const result = validateLayout(layoutData);
      expect(result.valid).toBe(true);
    });

    it('API 데이터소스에 endpoint 누락 시 에러를 반환해야 함', () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'test-layout',
        data_sources: [
          {
            id: 'users',
            type: 'api',
          } as DataSource,
        ],
        components: [
          { id: 'root', type: 'basic', name: 'Div' },
        ],
      };

      const result = validateLayout(layoutData);
      expect(result.valid).toBe(false);
      expect(result.errors.some((e) => e.code === 'MISSING_DATASOURCE_ENDPOINT')).toBe(true);
    });

    it('데이터소스 ID 중복 시 에러를 반환해야 함', () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'test-layout',
        data_sources: [
          { id: 'users', type: 'api', endpoint: '/api/users' },
          { id: 'users', type: 'api', endpoint: '/api/users2' },
        ],
        components: [
          { id: 'root', type: 'basic', name: 'Div' },
        ],
      };

      const result = validateLayout(layoutData);
      expect(result.valid).toBe(false);
      expect(result.errors.some((e) => e.code === 'DUPLICATE_DATASOURCE_ID')).toBe(true);
    });
  });

  describe('액션 검증', () => {
    it('유효한 액션을 통과해야 함', () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'test-layout',
        data_sources: [],
        components: [
          {
            id: 'button',
            type: 'basic',
            name: 'Button',
            actions: [
              {
                type: 'click',
                handler: 'navigate',
                params: { path: '/home' },
              },
            ],
          },
        ],
      };

      const result = validateLayout(layoutData);
      expect(result.valid).toBe(true);
    });

    it('핸들러 누락 시 에러를 반환해야 함', () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'test-layout',
        data_sources: [],
        components: [
          {
            id: 'button',
            type: 'basic',
            name: 'Button',
            actions: [
              {
                type: 'click',
              } as ActionDefinition,
            ],
          },
        ],
      };

      const result = validateLayout(layoutData);
      expect(result.valid).toBe(false);
      expect(result.errors.some((e) => e.code === 'MISSING_ACTION_HANDLER')).toBe(true);
    });

    it('apiCall 핸들러에 target 누락 시 에러를 반환해야 함', () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'test-layout',
        data_sources: [],
        components: [
          {
            id: 'button',
            type: 'basic',
            name: 'Button',
            actions: [
              {
                type: 'click',
                handler: 'apiCall',
                params: { method: 'GET' },
              },
            ],
          },
        ],
      };

      const result = validateLayout(layoutData);
      expect(result.valid).toBe(false);
      expect(result.errors.some((e) => e.code === 'MISSING_API_TARGET')).toBe(true);
    });

    it('navigate 핸들러에 path 누락 시 에러를 반환해야 함', () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'test-layout',
        data_sources: [],
        components: [
          {
            id: 'button',
            type: 'basic',
            name: 'Button',
            actions: [
              {
                type: 'click',
                handler: 'navigate',
                params: {},
              },
            ],
          },
        ],
      };

      const result = validateLayout(layoutData);
      expect(result.valid).toBe(false);
      expect(result.errors.some((e) => e.code === 'MISSING_NAVIGATE_PATH')).toBe(true);
    });

    it('알 수 없는 핸들러에 경고를 표시해야 함', () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'test-layout',
        data_sources: [],
        components: [
          {
            id: 'button',
            type: 'basic',
            name: 'Button',
            actions: [
              {
                type: 'click',
                handler: 'unknownHandler',
              },
            ],
          },
        ],
      };

      const result = validateLayout(layoutData);
      expect(result.warnings.some((w) => w.code === 'UNKNOWN_HANDLER')).toBe(true);
    });
  });

  describe('표현식 검증', () => {
    it('빈 표현식에 에러를 반환해야 함', () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'test-layout',
        data_sources: [],
        components: [
          {
            id: 'test',
            type: 'basic',
            name: 'Div',
            if: '{{}}',
          },
        ],
      };

      const result = validateLayout(layoutData);
      expect(result.valid).toBe(false);
      expect(result.errors.some((e) => e.code === 'EMPTY_EXPRESSION')).toBe(true);
    });

    it('위험한 표현식에 에러를 반환해야 함', () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'test-layout',
        data_sources: [],
        components: [
          {
            id: 'test',
            type: 'basic',
            name: 'Div',
            if: '{{eval("malicious")}}',
          },
        ],
      };

      const result = validateLayout(layoutData);
      expect(result.valid).toBe(false);
      expect(result.errors.some((e) => e.code === 'DANGEROUS_EXPRESSION')).toBe(true);
    });

    it('빈 번역 키에 에러를 반환해야 함', () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'test-layout',
        data_sources: [],
        components: [
          {
            id: 'test',
            type: 'basic',
            name: 'Div',
            data_binding: {
              text: '$t:',
            },
          },
        ],
      };

      const result = validateLayout(layoutData);
      expect(result.valid).toBe(false);
      expect(result.errors.some((e) => e.code === 'EMPTY_TRANSLATION_KEY')).toBe(true);
    });
  });

  describe('다크 모드 클래스 검증', () => {
    it('다크 모드 클래스 누락 시 경고를 표시해야 함', () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'test-layout',
        data_sources: [],
        components: [
          {
            id: 'test',
            type: 'basic',
            name: 'Div',
            props: {
              className: 'bg-white text-gray-900',
            },
          },
        ],
      };

      const result = validateLayout(layoutData);
      expect(result.warnings.some((w) => w.code === 'MISSING_DARK_MODE_CLASS')).toBe(true);
    });

    it('올바른 다크 모드 클래스 쌍은 경고 없이 통과해야 함', () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'test-layout',
        data_sources: [],
        components: [
          {
            id: 'test',
            type: 'basic',
            name: 'Div',
            props: {
              className: 'bg-white dark:bg-gray-800 text-gray-900 dark:text-white',
            },
          },
        ],
      };

      const result = validateLayout(layoutData);
      const darkModeWarnings = result.warnings.filter((w) => w.code === 'MISSING_DARK_MODE_CLASS');
      expect(darkModeWarnings).toHaveLength(0);
    });

    it('바인딩 표현식이 포함된 className은 검증을 스킵해야 함', () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'test-layout',
        data_sources: [],
        components: [
          {
            id: 'test',
            type: 'basic',
            name: 'Div',
            props: {
              className: '{{dynamicClass}}',
            },
          },
        ],
      };

      const result = validateLayout(layoutData);
      const darkModeWarnings = result.warnings.filter((w) => w.code === 'MISSING_DARK_MODE_CLASS');
      expect(darkModeWarnings).toHaveLength(0);
    });
  });

  describe('iteration 검증', () => {
    it('source 누락 시 에러를 반환해야 함', () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'test-layout',
        data_sources: [],
        components: [
          {
            id: 'test',
            type: 'basic',
            name: 'Div',
            iteration: {
              source: '',
              item_var: 'item',
            },
          },
        ],
      };

      const result = validateLayout(layoutData);
      expect(result.valid).toBe(false);
      expect(result.errors.some((e) => e.code === 'MISSING_ITERATION_SOURCE')).toBe(true);
    });

    it('item_var 누락 시 에러를 반환해야 함', () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'test-layout',
        data_sources: [],
        components: [
          {
            id: 'test',
            type: 'basic',
            name: 'Div',
            iteration: {
              source: '{{items}}',
              item_var: '',
            },
          },
        ],
      };

      const result = validateLayout(layoutData);
      expect(result.valid).toBe(false);
      expect(result.errors.some((e) => e.code === 'MISSING_ITERATION_ITEM_VAR')).toBe(true);
    });

    it('예약어 사용 시 에러를 반환해야 함', () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'test-layout',
        data_sources: [],
        components: [
          {
            id: 'test',
            type: 'basic',
            name: 'Div',
            iteration: {
              source: '{{items}}',
              item_var: '_global',
            },
          },
        ],
      };

      const result = validateLayout(layoutData);
      expect(result.valid).toBe(false);
      expect(result.errors.some((e) => e.code === 'RESERVED_ITEM_VAR')).toBe(true);
    });
  });

  describe('모달 검증', () => {
    it('모달 ID 중복 시 에러를 반환해야 함', () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'test-layout',
        data_sources: [],
        components: [
          { id: 'root', type: 'basic', name: 'Div' },
        ],
        modals: [
          { id: 'modal1', components: [] },
          { id: 'modal1', components: [] },
        ],
      };

      const result = validateLayout(layoutData);
      expect(result.valid).toBe(false);
      expect(result.errors.some((e) => e.code === 'DUPLICATE_MODAL_ID')).toBe(true);
    });
  });

  describe('formatValidationResult', () => {
    it('유효한 결과를 포맷팅해야 함', () => {
      const result = {
        valid: true,
        errors: [],
        warnings: [],
      };

      const formatted = formatValidationResult(result);
      expect(formatted).toContain('Validation passed');
    });

    it('에러를 포함한 결과를 포맷팅해야 함', () => {
      const result = {
        valid: false,
        errors: [
          {
            code: 'TEST_ERROR',
            message: 'Test error message',
            path: 'test.path',
            severity: 'error' as const,
          },
        ],
        warnings: [],
      };

      const formatted = formatValidationResult(result);
      expect(formatted).toContain('Validation failed');
      expect(formatted).toContain('TEST_ERROR');
      expect(formatted).toContain('Test error message');
    });

    it('경고를 포함한 결과를 포맷팅해야 함', () => {
      const result = {
        valid: true,
        errors: [],
        warnings: [
          {
            code: 'TEST_WARNING',
            message: 'Test warning message',
            path: 'test.path',
            severity: 'warning' as const,
          },
        ],
      };

      const formatted = formatValidationResult(result);
      expect(formatted).toContain('TEST_WARNING');
      expect(formatted).toContain('Test warning message');
    });
  });

  describe('quickValidate', () => {
    it('유효한 레이아웃에 true를 반환해야 함', () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'test-layout',
        data_sources: [],
        components: [
          { id: 'root', type: 'basic', name: 'Div' },
        ],
      };

      expect(quickValidate(layoutData)).toBe(true);
    });

    it('유효하지 않은 레이아웃에 false를 반환해야 함', () => {
      const layoutData = {
        layout_name: 'test',
      } as LayoutData;

      expect(quickValidate(layoutData)).toBe(false);
    });
  });
});
