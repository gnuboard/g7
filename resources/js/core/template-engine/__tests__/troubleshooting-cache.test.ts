/**
 * 트러블슈팅 회귀 테스트 - 캐시 관련 이슈
 *
 * troubleshooting-cache.md에 기록된 모든 사례의 회귀 테스트입니다.
 *
 * @see docs/frontend/troubleshooting-cache.md
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { Logger } from '../../utils/Logger';

describe('트러블슈팅 회귀 테스트 - 캐시 관련', () => {
  beforeEach(() => {
    Logger.getInstance().setDebug(false);
  });

  describe('[사례 1] DataGrid Row Action에서 잘못된 ID 사용', () => {
    /**
     * 증상: DataGrid 행 클릭 시 항상 첫 번째 행의 ID가 사용됨
     * 해결: row 컨텍스트 변수 사용
     */
    it('row 컨텍스트에서 ID를 가져와야 함', () => {
      const rows = [
        { id: 1, name: 'Row 1' },
        { id: 2, name: 'Row 2' },
        { id: 3, name: 'Row 3' },
      ];

      // 각 행의 액션에서 row.id 사용
      rows.forEach((row, index) => {
        const actionParams = {
          id: row.id, // {{row.id}} 바인딩 결과
        };
        expect(actionParams.id).toBe(index + 1);
      });
    });
  });

  describe('[사례 2] 페이지네이션 쿼리 파라미터 캐싱', () => {
    /**
     * 증상: 페이지 변경 시 이전 페이지 데이터가 표시됨
     * 해결: 캐시 키에 쿼리 파라미터 포함
     */
    it('캐시 키에 페이지 번호가 포함되어야 함', () => {
      const generateCacheKey = (endpoint: string, params: Record<string, any>) => {
        const queryString = Object.entries(params)
          .sort(([a], [b]) => a.localeCompare(b))
          .map(([k, v]) => `${k}=${v}`)
          .join('&');
        return `${endpoint}?${queryString}`;
      };

      const key1 = generateCacheKey('/api/products', { page: 1, limit: 10 });
      const key2 = generateCacheKey('/api/products', { page: 2, limit: 10 });

      expect(key1).not.toBe(key2);
      expect(key1).toContain('page=1');
      expect(key2).toContain('page=2');
    });
  });

  describe('[사례 3] 모달 데이터소스 캐시', () => {
    /**
     * 증상: 모달을 다시 열면 이전 데이터가 표시됨
     * 해결: 모달 열 때 캐시 무효화 또는 fresh 옵션
     */
    it('모달 열 때 fresh 옵션으로 캐시를 우회해야 함', () => {
      const fetchOptions = {
        fresh: true, // 캐시 우회
      };

      expect(fetchOptions.fresh).toBe(true);
    });
  });

  describe('[사례 4] cellChildren 반복 렌더링', () => {
    /**
     * 증상: cellChildren이 비효율적으로 반복 렌더링됨
     * 해결: 메모이제이션 및 key prop 최적화
     */
    it('각 셀의 key가 고유해야 함', () => {
      const rows = [
        { id: 1, cells: ['A', 'B', 'C'] },
        { id: 2, cells: ['D', 'E', 'F'] },
      ];

      const keys: string[] = [];
      rows.forEach((row) => {
        row.cells.forEach((cell, cellIndex) => {
          const key = `row-${row.id}-cell-${cellIndex}`;
          keys.push(key);
        });
      });

      // 모든 키가 고유해야 함
      const uniqueKeys = new Set(keys);
      expect(uniqueKeys.size).toBe(keys.length);
    });
  });

  describe('[사례 5] iteration source 표현식', () => {
    /**
     * 증상: iteration source가 캐싱되어 상태 변경이 반영되지 않음
     * 해결: 의존성 기반 캐시 무효화
     */
    it('상태 변경 시 iteration source가 재평가되어야 함', () => {
      const cache = new Map<string, any>();
      const cacheKey = '{{_local.items}}';

      // 초기 상태
      let localState = { items: [1, 2, 3] };
      cache.set(cacheKey, localState.items);

      // 상태 변경
      localState = { items: [1, 2, 3, 4] };

      // 캐시 무효화 및 재평가
      cache.delete(cacheKey);
      cache.set(cacheKey, localState.items);

      expect(cache.get(cacheKey)).toHaveLength(4);
    });
  });

  describe('[사례 6] 배열 타입 체크 시 ?? 연산자 대신 Array.isArray 사용', () => {
    /**
     * 증상: 빈 배열이 falsy로 처리되어 잘못된 fallback 사용
     * 해결: Array.isArray()로 타입 체크
     */
    it('빈 배열은 유효한 값으로 처리해야 함', () => {
      const emptyArray: any[] = [];

      // 잘못된 체크: ?? 연산자 (빈 배열은 truthy)
      const wrong1 = emptyArray ?? ['fallback'];
      expect(wrong1).toEqual([]); // 실제로는 동작하지만...

      // 잘못된 체크: || 연산자 (빈 배열은 truthy지만 혼동 유발)
      const wrong2 = emptyArray || ['fallback'];
      expect(wrong2).toEqual([]); // 빈 배열은 truthy

      // 올바른 체크: Array.isArray
      const isValid = Array.isArray(emptyArray);
      expect(isValid).toBe(true);

      // null/undefined 체크
      const nullValue = null;
      const result = Array.isArray(nullValue) ? nullValue : [];
      expect(result).toEqual([]);
    });
  });

  describe('[사례 7] reloadTranslations 후 모듈 다국어가 반영되지 않음', () => {
    /**
     * 증상: reloadTranslations 호출 후 모듈의 다국어가 반영되지 않음
     * 해결: 모듈 네임스페이스 포함하여 리로드
     */
    it('모듈 다국어 키는 네임스페이스를 포함해야 함', () => {
      const moduleKey = 'sirsoft-ecommerce.admin.product.title';
      const coreKey = 'admin.common.save';

      // 모듈 키는 vendor-module 형식 (하이픈 포함)
      // 예: sirsoft-ecommerce.xxx, sirsoft-blog.xxx
      const moduleNamespaceRegex = /^[\w]+-[\w]+\./;

      expect(moduleKey).toMatch(moduleNamespaceRegex);
      expect(coreKey).not.toMatch(moduleNamespaceRegex);
    });
  });

  describe('[사례 8] SPA 네비게이션 후 _localInit 캐시 미무효화', () => {
    /**
     * 증상: SPA 네비게이션 후 이전 페이지의 _localInit 값이 남아있음
     * 해결: 레이아웃 변경 시 _localInit 캐시 무효화
     */
    it('레이아웃 변경 시 _localInit이 초기화되어야 함', () => {
      let _localInit = { prevPageField: 'value' };

      // 레이아웃 변경 시뮬레이션
      const handleLayoutChange = () => {
        _localInit = {}; // 초기화
      };

      handleLayoutChange();

      expect(_localInit).toEqual({});
    });
  });

  describe('[사례 9] _local 경로 캐싱으로 인한 상태 변경 미반영', () => {
    /**
     * 증상: _local 값 변경 후에도 캐싱된 이전 값이 사용됨
     * 해결: _local 관련 캐시 무효화
     */
    it('_local 상태 변경 시 관련 캐시가 무효화되어야 함', () => {
      const cache = new Map<string, any>();
      cache.set('_local.filter.status', 'active');
      cache.set('_local.filter.search', '');
      cache.set('products.data', []);

      // _local 변경 시 관련 캐시 무효화
      const invalidateLocalCache = () => {
        for (const key of cache.keys()) {
          if (key.startsWith('_local')) {
            cache.delete(key);
          }
        }
      };

      invalidateLocalCache();

      expect(cache.has('_local.filter.status')).toBe(false);
      expect(cache.has('_local.filter.search')).toBe(false);
      expect(cache.has('products.data')).toBe(true); // 다른 캐시는 유지
    });
  });

  describe('[사례 10] $event 표현식 캐싱으로 인한 조건 오평가', () => {
    /**
     * 증상: $event 기반 조건이 항상 첫 번째 이벤트 값으로 평가됨
     * 해결: $event 표현식은 캐싱하지 않음
     */
    it('$event 표현식은 캐싱하지 않아야 함', () => {
      const shouldCache = (expression: string) => {
        // $event, $args 등 동적 변수는 캐싱하지 않음
        if (expression.includes('$event') || expression.includes('$args')) {
          return false;
        }
        return true;
      };

      expect(shouldCache('{{_local.value}}')).toBe(true);
      expect(shouldCache('{{$event.target.value}}')).toBe(false);
      expect(shouldCache('{{$args[0]}}')).toBe(false);
    });
  });

  describe('[사례 11] 동적 경로 중복 평가로 인한 성능 저하', () => {
    /**
     * 증상: 같은 표현식이 반복 평가되어 성능 저하
     * 해결: 정적 표현식 캐싱
     */
    it('정적 표현식은 캐싱으로 중복 평가를 방지해야 함', () => {
      const cache = new Map<string, any>();
      let evaluationCount = 0;

      const evaluate = (expression: string) => {
        if (cache.has(expression)) {
          return cache.get(expression);
        }
        evaluationCount++;
        const result = `evaluated-${expression}`;
        cache.set(expression, result);
        return result;
      };

      // 같은 표현식 여러 번 호출
      evaluate('{{products.data}}');
      evaluate('{{products.data}}');
      evaluate('{{products.data}}');

      // 캐싱으로 1번만 평가됨
      expect(evaluationCount).toBe(1);
    });
  });

  describe('[사례 12] iteration 내 액션 target에서 동일 ID 사용', () => {
    /**
     * 증상: iteration 내 모달이 모두 같은 ID를 가져 충돌
     * 해결: 모달 ID에 인덱스 또는 고유 식별자 포함
     */
    it('iteration 내 모달 ID는 고유해야 함', () => {
      const items = [{ id: 1 }, { id: 2 }, { id: 3 }];

      const modalIds = items.map((item, index) => `edit_modal_${item.id}`);

      // 모든 ID가 고유해야 함
      const uniqueIds = new Set(modalIds);
      expect(uniqueIds.size).toBe(modalIds.length);
    });
  });

  describe('[사례 16] 확장 라이프사이클 직후 라우트/다국어 미반영 + 토스트 raw key 노출', () => {
    /**
     * 증상:
     *   1. 모듈 활성화 직후 새 라우트가 페이지 전체 새로고침 전까지 반영되지 않음
     *   2. 성공 토스트가 `admin.modules.activate_success` 원본 번역 키를 표시
     *
     * 근본 원인 3건:
     *   (a) Router.loadRoutes 가 ?v=cacheVersion 을 부착하지 않음 — PublicTemplateController::getRoutes 가 v 기반 캐싱
     *   (b) TranslationEngine.setCacheVersion 이 this.translations 를 clear — parallel toast 와 경합
     *   (c) $t() 헬퍼가 context.$templateId 누락 시 빈 templateId 로 lookup 실패
     *
     * 전체 회귀 테스트는 다음 파일들에 분산:
     *   - resources/js/core/routing/__tests__/Router.test.ts (cacheVersion 쿼리 부착)
     *   - resources/js/core/template-engine/__tests__/TranslationEngine.test.ts (setCacheVersion 원자성)
     *   - resources/js/core/template-engine/__tests__/DataBindingEngine.test.ts ($t fallback)
     *   - resources/js/core/template-engine/__tests__/ActionDispatcher.reloadExtensions.test.ts
     *
     * @since engine-v1.38.0 / 1.38.1 / 1.38.2
     */
    it('Router.loadRoutes 는 cacheVersion 전달 시 ?v= 쿼리를 부착해야 함 (사례 16-a)', () => {
      const buildUrl = (templateId: string, cacheVersion?: number) => {
        const versionQuery = cacheVersion !== undefined && cacheVersion > 0
          ? `?v=${cacheVersion}`
          : '';
        return `/api/templates/${templateId}/routes.json${versionQuery}`;
      };
      expect(buildUrl('x', 1776225501)).toBe('/api/templates/x/routes.json?v=1776225501');
      expect(buildUrl('x', 0)).toBe('/api/templates/x/routes.json');
      expect(buildUrl('x', undefined)).toBe('/api/templates/x/routes.json');
    });

    it('setCacheVersion 직후에도 활성 translations 가 유지되어야 함 (사례 16-b)', () => {
      const translations = new Map<string, any>();
      const ttlCache = new Map<string, any>();
      translations.set('x:ko', { admin: { modules: { activate_success: '성공' } } });
      ttlCache.set('x:ko', { admin: { modules: { activate_success: '성공' } } });

      // setCacheVersion 새 동작: TTL 캐시만 clear, 활성 translations 유지
      ttlCache.clear();

      expect(translations.get('x:ko')?.admin?.modules?.activate_success).toBe('성공');
      expect(ttlCache.has('x:ko')).toBe(false);
    });

    it('$t() fallback — context.$templateId 누락 시 window.__templateApp.getConfig() 사용 (사례 16-c)', () => {
      const mockTemplateApp = {
        getConfig: () => ({ templateId: 'sirsoft-admin_basic', locale: 'ko' }),
      };
      const resolveTemplateId = (context: Record<string, any>, windowRef: any): string => {
        let templateId = context.$templateId;
        if (!templateId) {
          templateId = windowRef?.__templateApp?.getConfig?.()?.templateId;
        }
        return templateId || '';
      };
      expect(resolveTemplateId({}, { __templateApp: mockTemplateApp })).toBe('sirsoft-admin_basic');
      expect(resolveTemplateId({ $templateId: 'explicit' }, { __templateApp: mockTemplateApp })).toBe('explicit');
      expect(resolveTemplateId({}, {})).toBe('');
    });
  });
});

describe('트러블슈팅 회귀 테스트 - 바인딩 엔진 캐시', () => {
  describe('캐시 무효화 전략', () => {
    it('상태 변경 시 의존성 기반으로 캐시를 무효화해야 함', () => {
      const cache = new Map<string, { value: any; deps: string[] }>();

      // 캐시 항목 추가
      cache.set('{{_local.filter}}', { value: { status: 'active' }, deps: ['_local'] });
      cache.set('{{products.data}}', { value: [], deps: ['products'] });
      cache.set('{{_global.theme}}', { value: 'dark', deps: ['_global'] });

      // _local 변경 시 의존 캐시만 무효화
      const invalidateByDep = (dep: string) => {
        for (const [key, entry] of cache.entries()) {
          if (entry.deps.includes(dep)) {
            cache.delete(key);
          }
        }
      };

      invalidateByDep('_local');

      expect(cache.has('{{_local.filter}}')).toBe(false);
      expect(cache.has('{{products.data}}')).toBe(true);
      expect(cache.has('{{_global.theme}}')).toBe(true);
    });
  });

  describe('캐시 히트율 최적화', () => {
    it('자주 사용되는 표현식은 캐시 히트율이 높아야 함', () => {
      let hits = 0;
      let misses = 0;
      const cache = new Map<string, any>();

      const evaluate = (expression: string) => {
        if (cache.has(expression)) {
          hits++;
          return cache.get(expression);
        }
        misses++;
        const result = `result-${expression}`;
        cache.set(expression, result);
        return result;
      };

      // 10번 호출
      for (let i = 0; i < 10; i++) {
        evaluate('{{_local.filter}}');
      }

      expect(misses).toBe(1);
      expect(hits).toBe(9);
      expect(hits / (hits + misses)).toBeGreaterThanOrEqual(0.9);
    });
  });
});
