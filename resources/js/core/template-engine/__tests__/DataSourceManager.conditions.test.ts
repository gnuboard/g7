/**
 * DataSourceManager conditions 기능 테스트
 *
 * 데이터 소스의 conditions 속성을 통한 조건부 로딩 테스트
 *
 * @since engine-v1.10.0
 */

import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';
import { DataSourceManager, DataSource, ConditionContext } from '../DataSourceManager';

// ApiClient 모킹
const mockApiClientInstance = {
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
  patch: vi.fn(),
  delete: vi.fn(),
  getInstance: vi.fn(() => ({
    interceptors: {
      response: {
        use: vi.fn(),
        handlers: [],
      },
    },
  })),
};

vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(() => mockApiClientInstance),
}));

// fetch 모킹
global.fetch = vi.fn();

describe('DataSourceManager conditions 기능', () => {
  let manager: DataSourceManager;

  beforeEach(() => {
    manager = new DataSourceManager();
    vi.clearAllMocks();
  });

  afterEach(() => {
    manager.clearCache();
  });

  describe('기존 if 속성 (하위 호환)', () => {
    it('if 속성이 없으면 항상 포함되어야 함', () => {
      const sources: DataSource[] = [
        { id: 'test', type: 'static', data: { value: 1 } },
      ];

      const context: ConditionContext = {
        route: {},
        query: {},
        _global: {},
      };

      const filtered = manager.filterByCondition(sources, context);
      expect(filtered).toHaveLength(1);
      expect(filtered[0].id).toBe('test');
    });

    it('if 속성이 true로 평가되면 포함되어야 함', () => {
      const sources: DataSource[] = [
        { id: 'test', type: 'static', data: { value: 1 }, if: '{{route.id}}' },
      ];

      const context: ConditionContext = {
        route: { id: '123' },
        query: {},
        _global: {},
      };

      const filtered = manager.filterByCondition(sources, context);
      expect(filtered).toHaveLength(1);
      expect(filtered[0].id).toBe('test');
    });

    it('if 속성이 false로 평가되면 제외되어야 함', () => {
      const sources: DataSource[] = [
        { id: 'test', type: 'static', data: { value: 1 }, if: '{{route.id}}' },
      ];

      const context: ConditionContext = {
        route: {},
        query: {},
        _global: {},
      };

      const filtered = manager.filterByCondition(sources, context);
      expect(filtered).toHaveLength(0);
    });
  });

  describe('conditions - 단순 문자열', () => {
    it('단순 문자열 conditions가 true면 포함되어야 함', () => {
      const sources: DataSource[] = [
        {
          id: 'product',
          type: 'api',
          endpoint: '/api/products/{{route.id}}',
          conditions: '{{!!route.id}}',
        },
      ];

      const context: ConditionContext = {
        route: { id: '123' },
        query: {},
        _global: {},
      };

      const filtered = manager.filterByCondition(sources, context);
      expect(filtered).toHaveLength(1);
    });

    it('단순 문자열 conditions가 false면 제외되어야 함', () => {
      const sources: DataSource[] = [
        {
          id: 'product',
          type: 'api',
          endpoint: '/api/products/{{route.id}}',
          conditions: '{{!!route.id}}',
        },
      ];

      const context: ConditionContext = {
        route: {},
        query: {},
        _global: {},
      };

      const filtered = manager.filterByCondition(sources, context);
      expect(filtered).toHaveLength(0);
    });
  });

  describe('conditions - AND 그룹', () => {
    it('AND 그룹의 모든 조건이 true면 포함되어야 함', () => {
      const sources: DataSource[] = [
        {
          id: 'product',
          type: 'api',
          endpoint: '/api/products/{{route.id}}',
          conditions: {
            and: ['{{!!route.id}}', '{{_global.hasPermission}}'],
          },
        },
      ];

      const context: ConditionContext = {
        route: { id: '123' },
        query: {},
        _global: { hasPermission: true },
      };

      const filtered = manager.filterByCondition(sources, context);
      expect(filtered).toHaveLength(1);
    });

    it('AND 그룹의 하나라도 false면 제외되어야 함', () => {
      const sources: DataSource[] = [
        {
          id: 'product',
          type: 'api',
          endpoint: '/api/products/{{route.id}}',
          conditions: {
            and: ['{{!!route.id}}', '{{_global.hasPermission}}'],
          },
        },
      ];

      const context: ConditionContext = {
        route: { id: '123' },
        query: {},
        _global: { hasPermission: false },
      };

      const filtered = manager.filterByCondition(sources, context);
      expect(filtered).toHaveLength(0);
    });
  });

  describe('conditions - OR 그룹', () => {
    it('OR 그룹의 하나라도 true면 포함되어야 함', () => {
      const sources: DataSource[] = [
        {
          id: 'dashboard',
          type: 'api',
          endpoint: '/api/admin/dashboard',
          conditions: {
            or: ["{{_global.user?.role === 'admin'}}", "{{_global.user?.role === 'manager'}}"],
          },
        },
      ];

      const context: ConditionContext = {
        route: {},
        query: {},
        _global: { user: { role: 'manager' } },
      };

      const filtered = manager.filterByCondition(sources, context);
      expect(filtered).toHaveLength(1);
    });

    it('OR 그룹의 모든 조건이 false면 제외되어야 함', () => {
      const sources: DataSource[] = [
        {
          id: 'dashboard',
          type: 'api',
          endpoint: '/api/admin/dashboard',
          conditions: {
            or: ["{{_global.user?.role === 'admin'}}", "{{_global.user?.role === 'manager'}}"],
          },
        },
      ];

      const context: ConditionContext = {
        route: {},
        query: {},
        _global: { user: { role: 'user' } },
      };

      const filtered = manager.filterByCondition(sources, context);
      expect(filtered).toHaveLength(0);
    });
  });

  describe('conditions - 중첩 AND/OR', () => {
    it('중첩 조건을 올바르게 평가해야 함', () => {
      const sources: DataSource[] = [
        {
          id: 'sales-data',
          type: 'api',
          endpoint: '/api/sales',
          conditions: {
            or: [
              '{{_global.user?.isSuperAdmin}}',
              {
                and: ['{{_global.user?.isAdmin}}', "{{_global.user?.department === 'sales'}}"],
              },
            ],
          },
        },
      ];

      // admin + sales 부서
      const context1: ConditionContext = {
        route: {},
        query: {},
        _global: { user: { isSuperAdmin: false, isAdmin: true, department: 'sales' } },
      };
      expect(manager.filterByCondition(sources, context1)).toHaveLength(1);

      // admin + hr 부서
      const context2: ConditionContext = {
        route: {},
        query: {},
        _global: { user: { isSuperAdmin: false, isAdmin: true, department: 'hr' } },
      };
      expect(manager.filterByCondition(sources, context2)).toHaveLength(0);

      // superAdmin
      const context3: ConditionContext = {
        route: {},
        query: {},
        _global: { user: { isSuperAdmin: true, isAdmin: false, department: 'hr' } },
      };
      expect(manager.filterByCondition(sources, context3)).toHaveLength(1);
    });
  });

  describe('if vs conditions 우선순위', () => {
    it('if와 conditions가 둘 다 있으면 if가 우선되어야 함', () => {
      const sources: DataSource[] = [
        {
          id: 'test',
          type: 'static',
          data: { value: 1 },
          if: '{{ifValue}}',
          conditions: '{{condValue}}',
        },
      ];

      // if: false, conditions: true → 제외 (if 우선)
      const context1: ConditionContext = {
        route: {},
        query: {},
        _global: {},
        ifValue: false,
        condValue: true,
      } as any;
      expect(manager.filterByCondition(sources, context1)).toHaveLength(0);

      // if: true, conditions: false → 포함 (if 우선)
      const context2: ConditionContext = {
        route: {},
        query: {},
        _global: {},
        ifValue: true,
        condValue: false,
      } as any;
      expect(manager.filterByCondition(sources, context2)).toHaveLength(1);
    });
  });

  describe('실제 사용 사례', () => {
    it('생성/수정 모드에 따라 다른 데이터 소스 로드', () => {
      const sources: DataSource[] = [
        {
          id: 'existing_product',
          type: 'api',
          endpoint: '/api/products/{{route.id}}',
          conditions: '{{!!route.id}}',
        },
        {
          id: 'product_template',
          type: 'api',
          endpoint: '/api/products/template',
          conditions: '{{!route.id}}',
        },
      ];

      // 수정 모드 (route.id 있음)
      const editContext: ConditionContext = {
        route: { id: '123' },
        query: {},
        _global: {},
      };
      const editFiltered = manager.filterByCondition(sources, editContext);
      expect(editFiltered).toHaveLength(1);
      expect(editFiltered[0].id).toBe('existing_product');

      // 생성 모드 (route.id 없음)
      const createContext: ConditionContext = {
        route: {},
        query: {},
        _global: {},
      };
      const createFiltered = manager.filterByCondition(sources, createContext);
      expect(createFiltered).toHaveLength(1);
      expect(createFiltered[0].id).toBe('product_template');
    });

    it('복사 모드 지원 (route.id 없고 query.copy_id 있음)', () => {
      const sources: DataSource[] = [
        {
          id: 'existing_product',
          type: 'api',
          endpoint: '/api/products/{{route.id}}',
          conditions: '{{!!route.id}}',
        },
        {
          id: 'copy_source',
          type: 'api',
          endpoint: '/api/products/{{query.copy_id}}',
          conditions: {
            and: ['{{!route.id}}', '{{!!query.copy_id}}'],
          },
        },
        {
          id: 'product_template',
          type: 'api',
          endpoint: '/api/products/template',
          conditions: {
            and: ['{{!route.id}}', '{{!query.copy_id}}'],
          },
        },
      ];

      // 복사 모드
      const copyContext: ConditionContext = {
        route: {},
        query: { copy_id: '456' },
        _global: {},
      };
      const copyFiltered = manager.filterByCondition(sources, copyContext);
      expect(copyFiltered).toHaveLength(1);
      expect(copyFiltered[0].id).toBe('copy_source');
    });
  });
});
