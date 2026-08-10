/**
 * ActionDispatcher 액션 params 파이프 회귀 테스트
 *
 * 액션 params 의 `{{...}}` 는 `evaluateExpression` 으로 평가되는데, 이 경로는
 * `|` 를 JS 비트 OR 로 본다. 그래서 파이프가 붙으면
 *   - 인자 있는 파이프: 예외 → catch 가 **원본 `{{...}}` 문자열을 그대로 반환** → 서버로 전송
 *   - 인자 없는 파이프: 날짜 문자열 | 0 → `0` 같은 조용한 오답
 * 이 되었다. 화면에는 아무 표시도 나지 않고 서버 요청 본문만 오염된다.
 *
 * @since engine-v1.54.10
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher, ActionDefinition } from '../ActionDispatcher';
import { Logger } from '../../utils/Logger';

vi.mock('../../auth/AuthManager', () => ({
  AuthManager: {
    getInstance: vi.fn(() => ({
      login: vi.fn(),
      logout: vi.fn(),
    })),
  },
}));

vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(() => ({
    getToken: vi.fn(() => null),
  })),
}));

describe('ActionDispatcher — 액션 params 의 파이프', () => {
  let dispatcher: ActionDispatcher;
  let mockFetch: ReturnType<typeof vi.fn>;
  let originalFetch: typeof fetch;

  const dataContext = {
    row: {
      created_at: '2024-01-15T14:30:00',
      price: 1234567,
      tags: ['a', 'b', 'c'],
    },
  };

  beforeEach(() => {
    dispatcher = new ActionDispatcher({ navigate: vi.fn() });
    Logger.getInstance().setDebug(false);

    originalFetch = globalThis.fetch;
    mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ success: true, data: {} }),
    });
    globalThis.fetch = mockFetch as unknown as typeof fetch;

    Object.defineProperty(document, 'cookie', {
      value: 'XSRF-TOKEN=test-csrf-token',
      writable: true,
    });
  });

  afterEach(() => {
    globalThis.fetch = originalFetch;
    Logger.getInstance().setDebug(false);
  });

  const createMockEvent = () =>
    ({ preventDefault: vi.fn(), type: 'click', target: null } as unknown as Event);

  it('인자 있는 파이프가 서식된 값으로 전송된다 (원본 {{...}} 전송 금지)', async () => {
    const action: ActionDefinition = {
      type: 'click',
      handler: 'apiCall',
      target: '/api/test/pipe-body',
      params: {
        method: 'POST',
        auth_required: false,
        body: {
          date: "{{row.created_at | datetime('YYYY-MM-DD')}}",
        },
      },
    };

    await dispatcher.createHandler(action, dataContext)(createMockEvent());

    const call = mockFetch.mock.calls.find((c: unknown[]) => c[0] === '/api/test/pipe-body');
    expect(call).toBeDefined();
    const body = String((call![1] as RequestInit).body);
    expect(body).not.toContain('{{');
    expect(JSON.parse(body)).toEqual({ date: '2024-01-15' });
  });

  it('인자 없는 파이프가 비트 OR 오답으로 전송되지 않는다', async () => {
    const action: ActionDefinition = {
      type: 'click',
      handler: 'apiCall',
      target: '/api/test/pipe-body-noarg',
      params: {
        method: 'POST',
        auth_required: false,
        body: { count: '{{row.tags | length}}' },
      },
    };

    await dispatcher.createHandler(action, dataContext)(createMockEvent());

    const call = mockFetch.mock.calls.find(
      (c: unknown[]) => c[0] === '/api/test/pipe-body-noarg'
    );
    expect(call).toBeDefined();
    const body = JSON.parse(String((call![1] as RequestInit).body));
    expect(body.count).toBe(3);
    expect(body.count).not.toBe(0);
  });

  it('GET 쿼리스트링에 {{ 리터럴이 실리지 않는다', async () => {
    const action: ActionDefinition = {
      type: 'click',
      handler: 'apiCall',
      target: '/api/test/pipe-query',
      params: {
        method: 'GET',
        auth_required: false,
        query: { date: "{{row.created_at | date('YYYY-MM-DD')}}" },
      },
    };

    await dispatcher.createHandler(action, dataContext)(createMockEvent());

    const call = mockFetch.mock.calls.find((c: unknown[]) =>
      String(c[0]).startsWith('/api/test/pipe-query')
    );
    expect(call).toBeDefined();
    const url = String(call![0]);
    expect(url).not.toContain('%7B%7B');
    expect(url).toContain('date=2024-01-15');
  });

  it('파이프 없는 params 는 종전 경로 그대로 (회귀 보호)', async () => {
    const action: ActionDefinition = {
      type: 'click',
      handler: 'apiCall',
      target: '/api/test/no-pipe',
      params: {
        method: 'POST',
        auth_required: false,
        body: { tags: '{{row.tags}}', total: '{{row.price}}' },
      },
    };

    await dispatcher.createHandler(action, dataContext)(createMockEvent());

    const call = mockFetch.mock.calls.find((c: unknown[]) => c[0] === '/api/test/no-pipe');
    const body = JSON.parse(String((call![1] as RequestInit).body));
    expect(body.tags).toEqual(['a', 'b', 'c']);
    expect(body.total).toBe(1234567);
  });

  it('논리 OR(||)는 파이프로 오인되지 않는다 (회귀 보호)', async () => {
    const action: ActionDefinition = {
      type: 'click',
      handler: 'apiCall',
      target: '/api/test/logical-or',
      params: {
        method: 'POST',
        auth_required: false,
        body: { name: "{{row.missing || 'fallback'}}" },
      },
    };

    await dispatcher.createHandler(action, dataContext)(createMockEvent());

    const call = mockFetch.mock.calls.find((c: unknown[]) => c[0] === '/api/test/logical-or');
    expect(JSON.parse(String((call![1] as RequestInit).body)).name).toBe('fallback');
  });
});
