/**
 * ApiClient 테스트
 */

import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';
import ApiClient, { createApiClient, getApiClient } from '../ApiClient';
import axios from 'axios';
import { IdentityGuardInterceptor } from '../../identity/IdentityGuardInterceptor';

// Axios 모킹
vi.mock('axios');
const mockedAxios = axios as any;

// IdentityGuardInterceptor 모킹 (428 중앙 처리 검증용)
vi.mock('../../identity/IdentityGuardInterceptor', () => ({
  IdentityGuardInterceptor: {
    isIdentityRequired: vi.fn(),
    handle: vi.fn(),
  },
}));
const mockedGuard = IdentityGuardInterceptor as unknown as {
  isIdentityRequired: ReturnType<typeof vi.fn>;
  handle: ReturnType<typeof vi.fn>;
};

describe('ApiClient', () => {
  let apiClient: ApiClient;

  beforeEach(() => {
    // localStorage 모킹
    const localStorageMock = (() => {
      let store: Record<string, string> = {};
      return {
        getItem: (key: string) => store[key] || null,
        setItem: (key: string, value: string) => {
          store[key] = value;
        },
        removeItem: (key: string) => {
          delete store[key];
        },
        clear: () => {
          store = {};
        },
      };
    })();

    Object.defineProperty(window, 'localStorage', {
      value: localStorageMock,
      writable: true,
    });

    // Axios 인스턴스 모킹
    mockedAxios.create.mockReturnValue({
      interceptors: {
        request: {
          use: vi.fn(() => {
            return 0;
          }),
        },
        response: {
          use: vi.fn(() => {
            return 0;
          }),
        },
      },
      get: vi.fn(),
      post: vi.fn(),
      put: vi.fn(),
      patch: vi.fn(),
      delete: vi.fn(),
    });

    apiClient = new ApiClient();
  });

  afterEach(() => {
    vi.clearAllMocks();
    localStorage.clear();
  });

  describe('토큰 관리', () => {
    // @scenario disk_setting=none, hook=unregistered
    // @effects api_client_token_storage_roundtrip
    it('토큰을 저장할 수 있어야 함', () => {
      apiClient.setToken('test-token');
      expect(localStorage.getItem('auth_token')).toBe('test-token');
    });

    // @scenario disk_setting=none, hook=unregistered
    // @effects api_client_token_storage_roundtrip
    it('토큰을 조회할 수 있어야 함', () => {
      localStorage.setItem('auth_token', 'test-token');
      expect(apiClient.getToken()).toBe('test-token');
    });

    // @scenario disk_setting=none, hook=unregistered
    // @effects api_client_token_storage_roundtrip
    it('토큰을 삭제할 수 있어야 함', () => {
      localStorage.setItem('auth_token', 'test-token');
      apiClient.removeToken();
      expect(localStorage.getItem('auth_token')).toBeNull();
    });

    // @scenario disk_setting=none, hook=unregistered
    // @effects api_client_token_storage_roundtrip
    it('토큰이 없으면 null을 반환해야 함', () => {
      expect(apiClient.getToken()).toBeNull();
    });

    /** @effects api_client_refuses_to_store_non_string_or_empty_tokens */
    it('문자열이 아닌 토큰은 저장하지 않아야 함', () => {
      // localStorage 는 무엇을 넣든 문자열로 바꾼다 — undefined 를 넘기면 "undefined" 라는
      // truthy 문자열이 남아 이후 모든 요청이 Bearer undefined 로 나가고 401 로 튕긴다.
      apiClient.setToken(undefined as unknown as string);

      expect(localStorage.getItem('auth_token')).toBeNull();
      expect(apiClient.getToken()).toBeNull();
    });

    /** @effects api_client_refuses_to_store_non_string_or_empty_tokens */
    it('빈 문자열 토큰도 저장하지 않아야 함', () => {
      apiClient.setToken('');

      expect(localStorage.getItem('auth_token')).toBeNull();
    });

    /** @effects api_client_refuses_to_store_non_string_or_empty_tokens */
    it('기존 토큰이 있어도 잘못된 값으로 덮어쓰지 않아야 함', () => {
      apiClient.setToken('valid-token');
      apiClient.setToken(null as unknown as string);

      expect(apiClient.getToken()).toBe('valid-token');
    });
  });

  describe('HTTP 메서드', () => {
    // @scenario disk_setting=none, hook=unregistered
    // @effects api_client_http_methods_unwrap_response_data
    it('GET 요청을 수행할 수 있어야 함', async () => {
      const mockData = { data: 'test' };
      const mockResponse = { data: mockData };

      const instance = apiClient.getInstance();
      instance.get = vi.fn().mockResolvedValue(mockResponse);

      const result = await apiClient.get('/test');
      expect(result).toEqual(mockData);
      expect(instance.get).toHaveBeenCalledWith('/test', undefined);
    });

    // @scenario disk_setting=none, hook=unregistered
    // @effects api_client_http_methods_unwrap_response_data
    it('POST 요청을 수행할 수 있어야 함', async () => {
      const mockData = { data: 'test' };
      const mockResponse = { data: mockData };
      const postData = { name: 'test' };

      const instance = apiClient.getInstance();
      instance.post = vi.fn().mockResolvedValue(mockResponse);

      const result = await apiClient.post('/test', postData);
      expect(result).toEqual(mockData);
      expect(instance.post).toHaveBeenCalledWith('/test', postData, undefined);
    });

    // @scenario disk_setting=none, hook=unregistered
    // @effects api_client_http_methods_unwrap_response_data
    it('PUT 요청을 수행할 수 있어야 함', async () => {
      const mockData = { data: 'test' };
      const mockResponse = { data: mockData };
      const putData = { name: 'test' };

      const instance = apiClient.getInstance();
      instance.put = vi.fn().mockResolvedValue(mockResponse);

      const result = await apiClient.put('/test', putData);
      expect(result).toEqual(mockData);
      expect(instance.put).toHaveBeenCalledWith('/test', putData, undefined);
    });

    // @scenario disk_setting=none, hook=unregistered
    // @effects api_client_http_methods_unwrap_response_data
    it('PATCH 요청을 수행할 수 있어야 함', async () => {
      const mockData = { data: 'test' };
      const mockResponse = { data: mockData };
      const patchData = { name: 'test' };

      const instance = apiClient.getInstance();
      instance.patch = vi.fn().mockResolvedValue(mockResponse);

      const result = await apiClient.patch('/test', patchData);
      expect(result).toEqual(mockData);
      expect(instance.patch).toHaveBeenCalledWith('/test', patchData, undefined);
    });

    // @scenario disk_setting=none, hook=unregistered
    // @effects api_client_http_methods_unwrap_response_data
    it('DELETE 요청을 수행할 수 있어야 함', async () => {
      const mockData = { data: 'test' };
      const mockResponse = { data: mockData };

      const instance = apiClient.getInstance();
      instance.delete = vi.fn().mockResolvedValue(mockResponse);

      const result = await apiClient.delete('/test');
      expect(result).toEqual(mockData);
      expect(instance.delete).toHaveBeenCalledWith('/test', undefined);
    });
  });

  describe('싱글톤 인스턴스', () => {
    // @scenario disk_setting=none, hook=unregistered
    // @effects api_client_singleton_instance_is_shared
    it('createApiClient는 싱글톤 인스턴스를 반환해야 함', () => {
      const client1 = createApiClient();
      const client2 = createApiClient();
      expect(client1).toBe(client2);
    });

    // @scenario disk_setting=none, hook=unregistered
    // @effects api_client_singleton_instance_is_shared
    it('getApiClient는 싱글톤 인스턴스를 반환해야 함', () => {
      const client1 = getApiClient();
      const client2 = getApiClient();
      expect(client1).toBe(client2);
    });
  });

  describe('428 본인인증 중앙 처리 (IDV)', () => {
    /**
     * response 인터셉터의 onRejected 콜백을 캡처해 직접 호출하기 위한 헬퍼.
     * axios.create 가 use(onFulfilled, onRejected) 로 받은 onRejected 를 보관한다.
     */
    function buildClientCapturingRejected(): { onRejected: (e: any) => Promise<any> } {
      let captured: ((e: any) => Promise<any>) | null = null;
      mockedAxios.create.mockReturnValue({
        interceptors: {
          request: { use: vi.fn(() => 0) },
          response: {
            use: vi.fn((_onFulfilled: any, onRejected: any) => {
              captured = onRejected;
              return 0;
            }),
          },
        },
        get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(),
      });
      new ApiClient();
      return { onRejected: captured! };
    }

    const idv428Error = {
      config: { url: '/api/x', headers: { Authorization: 'Bearer t' }, data: JSON.stringify({ a: 1 }) },
      response: {
        status: 428,
        data: { error_code: 'identity_verification_required', verification: { return_request: { method: 'POST', url: '/api/x' } } },
      },
    };

    // @scenario disk_setting=none, hook=unregistered
    // @effects api_client_428_is_routed_through_identity_guard
    it('428 응답을 IdentityGuardInterceptor 로 인터셉트하고 verify 성공 시 재실행 응답을 반환한다', async () => {
      mockedGuard.isIdentityRequired.mockReturnValue(true);
      mockedGuard.handle.mockResolvedValue({
        ok: true, status: 200, json: async () => ({ success: true, data: { confirmed: true } }),
      } as any);

      const { onRejected } = buildClientCapturingRejected();
      const result = await onRejected(idv428Error);

      expect(mockedGuard.handle).toHaveBeenCalledOnce();
      expect(result.status).toBe(200);
      expect(result.data).toEqual({ success: true, data: { confirmed: true } });
    });

    // @scenario disk_setting=none, hook=unregistered
    // @effects api_client_428_is_routed_through_identity_guard
    it('428 인데 사용자가 본인인증을 취소(handle=null)하면 원 에러를 그대로 전파한다', async () => {
      mockedGuard.isIdentityRequired.mockReturnValue(true);
      mockedGuard.handle.mockResolvedValue(null);

      const { onRejected } = buildClientCapturingRejected();
      await expect(onRejected(idv428Error)).rejects.toBe(idv428Error);
    });

    // @scenario disk_setting=none, hook=unregistered
    // @effects api_client_428_is_routed_through_identity_guard
    it('428 이 아니면 IdentityGuard 를 호출하지 않고 일반 에러로 전파한다', async () => {
      mockedGuard.isIdentityRequired.mockReturnValue(false);

      const { onRejected } = buildClientCapturingRejected();
      const err422 = { config: { url: '/api/y' }, response: { status: 422, data: { message: 'invalid' } } };
      await expect(onRejected(err422)).rejects.toBe(err422);
      expect(mockedGuard.handle).not.toHaveBeenCalled();
    });

    /**
     * 신규(engine-v1.51.0) — axios(G7Core.api) 호출 경로도 config.identity_target 을 launcher 로 전달.
     * apiCall(handleApiCall, native fetch) 경로와 동일하게 모든 API 호출 방식에서 IDV target 지원.
     */
    // @scenario disk_setting=none, hook=unregistered
    // @effects api_client_passes_identity_target_to_identity_guard
    it('config.identity_target 을 IdentityGuardInterceptor.handle 의 target 인자로 전달한다', async () => {
      mockedGuard.isIdentityRequired.mockReturnValue(true);
      mockedGuard.handle.mockResolvedValue({
        ok: true, status: 200, json: async () => ({ success: true }),
      } as any);

      const errWithTarget = {
        config: {
          url: '/api/orders',
          headers: {},
          data: JSON.stringify({ a: 1 }),
          identity_target: { email: 'guest@example.com', phone: '01099998888' },
        },
        response: {
          status: 428,
          data: { error_code: 'identity_verification_required', verification: { return_request: { method: 'POST', url: '/api/orders' } } },
        },
      };

      const { onRejected } = buildClientCapturingRejected();
      await onRejected(errWithTarget);

      expect(mockedGuard.handle).toHaveBeenCalledWith(
        errWithTarget.response.data,
        expect.any(Object),
        { email: 'guest@example.com', phone: '01099998888' },
      );
    });
  });

  // 교차 출처 요청의 인증 헤더 차단. 이 단위가 재는 축(디스크, 소비처, 훅)만 각 시험에 표식하고,
  // 화면 축(e2e, override, row_state)까지 든 전체 조합은 화면에서 재는 public-asset-storage.spec.ts 가 맡는다.
  describe('교차 출처 요청의 인증 헤더 차단', () => {
    /**
     * 요청 인터셉터를 캡처해 임의 config 로 실행합니다.
     *
     * @returns 캡처된 요청 인터셉터
     */
    function buildClientCapturingRequest(): (config: any) => any {
      let captured: ((config: any) => any) | null = null;
      mockedAxios.create.mockReturnValue({
        interceptors: {
          request: {
            use: vi.fn((onFulfilled: any) => {
              captured = onFulfilled;
              return 0;
            }),
          },
          response: { use: vi.fn(() => 0) },
        },
        get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(),
      });
      new ApiClient();
      return captured!;
    }

    /**
     * axios `buildFullPath` 의 등가 조립 — **최종적으로 나가는 주소**를 만든다.
     *
     * 계약: `url` 이 절대 URL 이면 `baseURL` 을 무시하고, 아니면 슬래시 하나로 잇는다.
     * 인터셉터가 돌려주는 `url`·`baseURL` 두 필드를 따로 보면 「그 자리에서 멈춘 것」을
     * 재게 되므로, 축마다 이 조립을 거쳐 한 문자열로 비교한다.
     *
     * 종전에는 이 함수가 마지막 케이스 안에 갇혀 있었고, 그 케이스가 넘기는 두 입력이
     * **둘 다 절대 URL** 이라 `baseURL` 이 무엇이든 결과가 같았다 — 판별력이 0 이었다.
     * 형제 세 축은 순진한 연결(`${baseURL}${url}`)로 재고 있어 조립 규칙 자체가
     * 검사에 들어오지 않았다 (16차 G3 라운드 A-8).
     *
     * @param baseURL axios 인스턴스의 baseURL
     * @param requestedURL 요청 config 의 url
     * @returns 최종 요청 주소
     */
    function buildFullPath(baseURL: string | undefined, requestedURL: string): string {
      const isAbsolute = /^([a-z][a-z\d+\-.]*:)?\/\//i.test(requestedURL);
      if (isAbsolute || !baseURL) return requestedURL;

      return `${baseURL.replace(/\/+$/, '')}/${requestedURL.replace(/^\/+/, '')}`;
    }

    beforeEach(() => {
      localStorage.setItem('auth_token', 'secret-token');
    });

    // 하위 폴더 마운트(`https://host/g7/`) — 종전에는 이 축을 세우는 케이스가 0건이었다.
    // baseURL 기본값이 `withBasePrefix('/api')` 인데 중복 제거 조건이 리터럴 `/api` 와
    // 비교라 그 조건이 영원히 거짓이었고, 결과는 `/g7/api` + `/api/…` = `/g7/api/api/…`
    // 404 였다. **루트 설치에서는 정상**이라 개발 환경에서 재현되지 않는다 (15차 G3).
    // @scenario disk_setting=none, hook=unregistered
    // @effects api_client_api_path_is_neither_lost_nor_duplicated_under_subfolder
    it('하위 폴더 마운트에서 /api 로 시작하는 URL 이 접두사를 잃지도 중복되지도 않는다', () => {
        (globalThis as any).G7Config = { basePath: '/g7' };

        try {
            const onRequest = buildClientCapturingRequest();
            // axios 가 인스턴스 baseURL 을 병합해 인터셉터에 넘기는 상태를 재현한다.
            const config = onRequest({
                url: '/api/modules/x/bulk-update',
                headers: {},
                baseURL: '/g7/api',
            });

            // baseURL + url 이 최종 주소다. 접두사는 남고 `/api` 는 한 번만 나와야 한다.
            // 조립은 axios 와 **같은 규칙**으로 한다 — 순진한 연결은 슬래시 중복·절대 URL
            // 분기를 재현하지 못해 그 축이 검사에서 빠진다 (16차 G3 라운드 A-8).
            expect(buildFullPath(config.baseURL, config.url)).toBe('/g7/api/modules/x/bulk-update');
        } finally {
            delete (globalThis as any).G7Config;
        }
    });

    // 서버가 **이미 접두사를 붙여 준 값**이 그대로 들어오는 축 (회차 8 G3).
    // 회차 7 이 서버측 모델(게시판 첨부 `download_url` 등)에 `AssetUrl::basePrefix()` 를
    // 붙였는데, 그 값의 소비 지점이 `G7Core.api.get(url)` 이다. 종전 판정은
    // `url.startsWith('/api')` 라 접두사가 붙은 값(`/g7/api/…`)에는 **거짓**이고,
    // baseURL(`/g7/api`)이 남아 `/g7/api/g7/api/…` 가 됐다 — 15차가 고친 것의
    // **반대 방향 짝**이며 루트 설치에서는 접두사가 빈 문자열이라 재현되지 않는다.
    // @scenario disk_setting=none, hook=unregistered
    // @effects api_client_api_path_is_neither_lost_nor_duplicated_under_subfolder
    it('하위 폴더 마운트에서 접두사가 이미 붙은 URL 은 접두사가 두 번 붙지 않는다', () => {
        (globalThis as any).G7Config = { basePath: '/g7' };

        try {
            const onRequest = buildClientCapturingRequest();
            const config = onRequest({
                url: '/g7/api/modules/sirsoft-board/boards/notice/attachment/abc',
                headers: {},
                baseURL: '/g7/api',
            });

            expect(buildFullPath(config.baseURL, config.url)).toBe(
                '/g7/api/modules/sirsoft-board/boards/notice/attachment/abc',
            );
        } finally {
            delete (globalThis as any).G7Config;
        }
    });

    // @scenario disk_setting=none, hook=unregistered
    // @effects api_client_api_path_is_neither_lost_nor_duplicated_under_subfolder
    it('루트 설치에서는 /api 중복 제거 동작이 종전과 같다', () => {
        const onRequest = buildClientCapturingRequest();
        const config = onRequest({
            url: '/api/modules/x/bulk-update',
            headers: {},
            baseURL: '/api',
        });

        expect(buildFullPath(config.baseURL, config.url)).toBe('/api/modules/x/bulk-update');
    });

    // @scenario disk_setting=public, hook=unregistered
    // @effects same_origin_request_attaches_session_token
    it('동일 출처 상대 경로에는 Authorization 을 첨부한다', () => {
      const onRequest = buildClientCapturingRequest();

      const config = onRequest({ url: '/api/attachments/abc', headers: {}, baseURL: '/api' });

      expect(config.headers.Authorization).toBe('Bearer secret-token');
    });

    // @scenario disk_setting=public, hook=unregistered
    // @effects same_origin_request_attaches_session_token
    it('동일 출처 절대 URL 에는 Authorization 을 첨부한다', () => {
      const onRequest = buildClientCapturingRequest();

      const config = onRequest({
        url: `${window.location.origin}/api/attachments/abc`,
        headers: {},
        baseURL: '/api',
      });

      expect(config.headers.Authorization).toBe('Bearer secret-token');
    });

    // 인터셉터의 **세 번째 입력 축** — 절대 URL. 접두사 있는/없는 두 축은 위에서
    // 재는데 이 축은 `Authorization` 헤더만 보고 최종 주소를 재지 않았고, 그나마도
    // 접두사가 없는 루트 설치에서만 돌았다. 인터셉터가 절대 URL 을 만나 baseURL 을
    // 잘못 건드리면 그 요청은 사이트 밖으로 나가는데 그 회귀를 잡을 것이 없었다
    // (9차 G3 라운드 A-6).
    // @scenario disk_setting=fake_cdn, hook=unregistered
    // @effects api_client_absolute_urls_are_left_untouched_by_interceptor
    it('하위 폴더 마운트에서도 절대 URL 은 인터셉터가 손대지 않는다', () => {
        (globalThis as any).G7Config = { basePath: '/g7' };

        try {
            const onRequest = buildClientCapturingRequest();

            // 교차 출처 — CDN 자산. baseURL 이 무엇이든 axios 는 절대 URL 이면 그것을
            // 그대로 쓴다(`buildFullPath` 의 `isAbsoluteURL` 분기). 인터셉터가 url 을
            // 바꾸지 않는 것이 계약이다.
            const cross = onRequest({
                url: 'https://cdn.example.com/bucket/products/a.png',
                headers: {},
                baseURL: '/g7/api',
            });
            expect(cross.url).toBe('https://cdn.example.com/bucket/products/a.png');

            // 동일 출처 절대 URL 도 마찬가지 — 접두사를 덧붙이면 `/g7` 이 두 번 된다.
            const same = onRequest({
                url: `${window.location.origin}/g7/api/attachments/abc`,
                headers: {},
                baseURL: '/g7/api',
            });
            expect(same.url).toBe(`${window.location.origin}/g7/api/attachments/abc`);
            expect(same.url).not.toContain('/g7/g7/');

            // **최종 주소**를 잰다 — `url` 필드만 보면 그 자리에서 멈춘 것이고,
            // 실제로 나가는 주소는 axios 가 `baseURL` 과 합쳐서 만든다. 위 주석은
            // 「최종 주소를 단언한다」고 적었는데 단언은 `url` 뿐이었다
            // (10차 G3 라운드 X-7).
            //
            // axios `buildFullPath` 의 계약: `url` 이 절대 URL 이면 `baseURL` 을 무시한다.
            // 그 성질에 기대는 대신 describe 스코프의 등가 조립으로 **한 문자열로** 비교한다.
            expect(buildFullPath(cross.baseURL, cross.url)).toBe('https://cdn.example.com/bucket/products/a.png');
            expect(buildFullPath(same.baseURL, same.url)).toBe(`${window.location.origin}/g7/api/attachments/abc`);
            expect(buildFullPath(same.baseURL, same.url)).not.toContain('/g7/g7/');

            // **판별력 대조.** 위 두 단언은 입력이 둘 다 절대 URL 이라 `baseURL` 을 무엇으로
            // 넣어도 결과가 같다 — 즉 그 조립이 `baseURL` 을 잘못 다뤄도 잡지 못한다.
            // 상대 `url` 을 한 번 통과시켜 그 축을 세운다 (16차 G3 라운드 A-8).
            const relative = onRequest({
                url: '/api/attachments/abc',
                headers: {},
                baseURL: '/g7/api',
            });
            expect(buildFullPath(relative.baseURL, relative.url)).toBe('/g7/api/attachments/abc');
            expect(buildFullPath(relative.baseURL, relative.url)).not.toContain('/g7/g7/');
            expect(buildFullPath(relative.baseURL, relative.url)).not.toContain('/api/api/');

            // 조립 자신이 `baseURL` 을 실제로 읽는다 — 읽지 않는 구현이면 이 단언이 red 다.
            expect(buildFullPath('/other', '/api/attachments/abc')).toBe('/other/api/attachments/abc');
        } finally {
            delete (globalThis as any).G7Config;
        }
    });

    // @scenario disk_setting=fake_cdn, consumer=product, hook=unregistered
    // @effects cross_origin_asset_request_omits_session_token
    it('교차 출처 절대 URL(공개 자산 CDN)에는 Authorization 을 첨부하지 않는다', () => {
      const onRequest = buildClientCapturingRequest();

      const config = onRequest({
        url: 'https://cdn.example.com/bucket/products/a.png',
        headers: {},
        baseURL: '/api',
      });

      expect(config.headers.Authorization).toBeUndefined();
    });

    // @scenario disk_setting=fake_cdn, consumer=product, hook=unregistered
    // @effects cross_origin_asset_request_omits_session_token
    it('프로토콜 상대 URL(//cdn) 도 교차 출처면 Authorization 을 첨부하지 않는다', () => {
      const onRequest = buildClientCapturingRequest();

      const config = onRequest({
        url: '//cdn.example.com/bucket/products/a.png',
        headers: {},
        baseURL: '/api',
      });

      expect(config.headers.Authorization).toBeUndefined();
    });
  });
});
