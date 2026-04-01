/**
 * @file mockApiUtils.ts
 * @description API 모킹 유틸리티 - 데이터소스 테스트 지원
 */

import { vi, type Mock } from 'vitest';

export interface MockApiOptions {
  response: any;
  delay?: number;
  error?: { status: number; message: string };
  fallback?: any;
}

export interface MockApiRegistry {
  mocks: Map<string, MockApiOptions>;
  fetchSpy: Mock;
}

/**
 * API 모킹 레지스트리 생성
 */
export function createMockApiRegistry(): MockApiRegistry {
  const mocks = new Map<string, MockApiOptions>();

  const fetchSpy = vi.fn(async (url: string, _options?: RequestInit) => {
    // URL에서 데이터소스 ID 추출 (예: /api/admin/products → products)
    const dataSourceId = extractDataSourceId(url);
    const mockConfig = mocks.get(dataSourceId);

    if (!mockConfig) {
      // Mock이 없으면 빈 응답
      return createMockResponse({ data: [] });
    }

    // 에러 시뮬레이션
    if (mockConfig.error) {
      return createErrorResponse(mockConfig.error.status, mockConfig.error.message);
    }

    // 지연 시뮬레이션
    if (mockConfig.delay) {
      await new Promise(resolve => setTimeout(resolve, mockConfig.delay));
    }

    return createMockResponse(mockConfig.response);
  });

  return { mocks, fetchSpy };
}

/**
 * URL에서 데이터소스 ID 추출
 *
 * 지원하는 URL 패턴:
 * - /api/admin/products → products
 * - /api/admin/products/123 → products
 * - /api/ecommerce/categories → categories
 * - /api/modules/sirsoft-ecommerce/admin/product-notice-templates → product_notice_templates
 * - /api/modules/vendor-module/resource → resource
 *
 * 참고: URL은 하이픈(-)을 사용하고, data_source ID는 밑줄(_)을 사용하므로
 * 하이픈을 밑줄로 변환합니다.
 */
function extractDataSourceId(url: string): string {
  const parts = url.split('/').filter(Boolean);

  // 쿼리 파라미터 제거 헬퍼
  const removeQueryParams = (s: string) => s.split('?')[0];

  // 하이픈을 밑줄로 변환 (URL은 kebab-case, data_source ID는 snake_case)
  const normalizeId = (s: string) => s.replace(/-/g, '_');

  // 모듈 URL 패턴: /api/modules/{vendor-module}/.../{resource}
  if (parts[0] === 'api' && parts[1] === 'modules' && parts.length >= 4) {
    // 마지막 부분이 숫자(ID)가 아니면 그것이 리소스
    // 숫자이면 그 앞의 부분이 리소스
    const lastPart = removeQueryParams(parts[parts.length - 1]);
    if (/^\d+$/.test(lastPart)) {
      return normalizeId(removeQueryParams(parts[parts.length - 2]));
    }
    return normalizeId(lastPart);
  }

  // 표준 URL 패턴: /api/admin/resource 또는 /api/prefix/resource
  if (parts.length >= 3) {
    const resourcePart = removeQueryParams(parts[2]);
    // parts[2]가 숫자이면 parts[1]을 반환 (예: /api/products/123)
    if (/^\d+$/.test(resourcePart) && parts.length === 3) {
      return normalizeId(removeQueryParams(parts[1]));
    }
    return normalizeId(resourcePart);
  }

  // 짧은 URL: 마지막 부분 반환
  return normalizeId(removeQueryParams(parts[parts.length - 1] || ''));
}

/**
 * Mock Response 생성
 *
 * 실제 그누보드7 API 응답 구조를 모방합니다:
 * { success: true, data: { ... } }
 *
 * 테스트에서 mockApi('coupons', { response: { data: [], meta: {} } }) 설정 시
 * fetch().json() = { success: true, data: { data: [], meta: {} } }
 */
function createMockResponse(responseData: any): Response {
  // 실제 API 응답 구조로 래핑
  const apiResponse = {
    success: true,
    data: responseData,
  };

  return {
    ok: true,
    status: 200,
    json: async () => apiResponse,
    text: async () => JSON.stringify(apiResponse),
    headers: new Headers({ 'Content-Type': 'application/json' }),
  } as Response;
}

/**
 * 에러 Response 생성
 */
function createErrorResponse(status: number, message: string): Response {
  return {
    ok: false,
    status,
    json: async () => ({ message, errors: {} }),
    text: async () => JSON.stringify({ message }),
    headers: new Headers({ 'Content-Type': 'application/json' }),
  } as Response;
}

/**
 * Mock API 등록 헬퍼
 */
export function registerMockApi(
  registry: MockApiRegistry,
  dataSourceId: string,
  options: MockApiOptions | any
): Mock {
  const normalizedOptions: MockApiOptions =
    typeof options === 'object' && 'response' in options
      ? options
      : { response: options };

  registry.mocks.set(dataSourceId, normalizedOptions);

  // 해당 데이터소스 호출 추적을 위한 spy 반환
  return vi.fn();
}

/**
 * Mock API 에러 등록 헬퍼
 */
export function registerMockApiError(
  registry: MockApiRegistry,
  dataSourceId: string,
  status: number,
  message: string = 'Error'
): Mock {
  registry.mocks.set(dataSourceId, {
    response: null,
    error: { status, message }
  });
  return vi.fn();
}

/**
 * 글로벌 fetch 모킹 설정
 */
export function setupGlobalFetchMock(registry: MockApiRegistry): () => void {
  const originalFetch = globalThis.fetch;
  globalThis.fetch = registry.fetchSpy as unknown as typeof fetch;

  return () => {
    globalThis.fetch = originalFetch;
  };
}

/**
 * 모든 Mock 초기화
 */
export function clearMockApiRegistry(registry: MockApiRegistry): void {
  registry.mocks.clear();
  registry.fetchSpy.mockClear();
}
