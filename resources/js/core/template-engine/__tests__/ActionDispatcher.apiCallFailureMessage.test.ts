/**
 * apiCall 실패 응답에 서버 메시지가 없을 때 화면 문구가 **현재 언어**로 나오는지 고정한다.
 *
 * 종전에는 응답 본문에 `message` 가 없으면(빈 본문 500, `errors` 만 있는 422, JSON 이 아닌 응답)
 * `API call failed: ${statusText}` 영어 문구가 오류 문맥(`{{error.message}}`)과 기본 토스트에 그대로 실려
 * 한국어 관리자 화면에도 영어 토스트가 떴다(2025-11 엔진 첫 구현부터). HTTP/2 응답은 statusText 가 비어
 * `API call failed: ` 로 끝나기도 했다. 이제 다국어 키 `core.errors.api_request_failed` 를 쓰고,
 * 오류 문맥을 만들 때 번역한다(네트워크 실패의 `core.errors.network_request_failed` 와 같은 방식).
 *
 * @since engine-v1.87.0
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher } from '../ActionDispatcher';
import { TranslationEngine } from '../TranslationEngine';
import { Logger } from '../../utils/Logger';

vi.mock('../../api/ApiClient', () => ({
    getApiClient: vi.fn(() => ({
        getToken: vi.fn(() => null),
    })),
}));

vi.mock('../../auth/AuthManager', () => ({
    AuthManager: {
        getInstance: vi.fn(() => ({
            login: vi.fn(),
            logout: vi.fn(),
        })),
    },
}));

const KO_FAILED = '요청을 처리하지 못했습니다. 잠시 뒤 다시 시도해 주세요.';
const ko = { templateId: 'sirsoft-admin_basic', locale: 'ko' };

interface FakeResponse {
    status: number;
    statusText: string;
    body?: unknown;
    invalidJson?: boolean;
}

describe('ActionDispatcher - apiCall 실패 문구 (engine-v1.87.0)', () => {
    let dispatcher: ActionDispatcher;
    let captured: string[];
    let originalFetch: typeof fetch;

    const respondWith = (r: FakeResponse) => {
        globalThis.fetch = vi.fn(async () => ({
            ok: false,
            status: r.status,
            statusText: r.statusText,
            headers: new Headers({ 'content-type': 'application/json' }),
            json: async () => {
                if (r.invalidJson) {
                    throw new SyntaxError('Unexpected token < in JSON');
                }
                return r.body;
            },
        })) as unknown as typeof fetch;
    };

    beforeEach(() => {
        const translationEngine = new TranslationEngine();
        (translationEngine as any).translations.set('sirsoft-admin_basic:ko', {
            core: {
                errors: {
                    api_request_failed: KO_FAILED,
                    network_request_failed: '요청을 보내지 못했습니다. 네트워크 연결을 확인한 뒤 다시 시도해 주세요.',
                },
            },
        });
        dispatcher = new ActionDispatcher({ navigate: vi.fn() }, translationEngine, ko);
        Logger.getInstance().setDebug(false);
        captured = [];
        dispatcher.registerHandler(
            'captureError',
            (action) => {
                captured.push(String((action.params as any)?.message));
            },
            { source: 'test' }
        );
        originalFetch = globalThis.fetch;
    });

    afterEach(() => {
        globalThis.fetch = originalFetch;
    });

    const runWithOnError = () =>
        (dispatcher as any).executeAction(
            {
                handler: 'apiCall',
                target: '/api/admin/settings',
                params: { method: 'PUT', body: {} },
                onError: [{ handler: 'captureError', params: { message: '{{error.message}}' } }],
            },
            { data: { _local: {} }, state: {}, setState: vi.fn() }
        );

    /**
     * @scenario case=empty_body_500, case=errors_only_422, case=empty_status_text, case=non_json_502, case=empty_message_string
     * @effects api_call_failure_message_localized
     */
    it.each([
        ['빈 본문 500', { status: 500, statusText: 'Internal Server Error', body: {} }],
        ['errors 만 있는 422', { status: 422, statusText: 'Unprocessable Content', body: { errors: { name: ['x'] } } }],
        ['statusText 가 빈 HTTP/2 응답', { status: 500, statusText: '', body: {} }],
        ['JSON 이 아닌 응답', { status: 502, statusText: 'Bad Gateway', invalidJson: true }],
        ['message 가 빈 문자열', { status: 500, statusText: 'Internal Server Error', body: { message: '' } }],
    ])('서버 메시지가 없는 %s 는 오류 문맥 문구가 현재 언어다', async (_label, response) => {
        respondWith(response as FakeResponse);

        const result = await runWithOnError();

        expect(result.success).toBe(false);
        expect(captured).toEqual([KO_FAILED]);
        expect(captured[0]).not.toContain('API call failed');
        expect(captured[0]).not.toContain('$t:');
    });

    /**
     * @scenario case=error_handling_code
     * @effects api_call_failure_message_localized
     */
    it('errorHandling[코드] 경로도 같은 문구를 받는다', async () => {
        respondWith({ status: 422, statusText: 'Unprocessable Content', body: {} });

        await (dispatcher as any).executeAction(
            {
                handler: 'apiCall',
                target: '/api/admin/settings',
                params: { method: 'PUT', body: {} },
                errorHandling: { 422: { handler: 'captureError', params: { message: '{{error.message}}' } } },
            },
            { data: { _local: {} }, state: {}, setState: vi.fn() }
        );

        expect(captured).toEqual([KO_FAILED]);
    });

    /**
     * @scenario case=server_message_kept
     * @effects server_message_kept
     */
    it('서버가 메시지를 주면 그 메시지를 그대로 쓴다 (대조군)', async () => {
        respondWith({ status: 422, statusText: 'Unprocessable Content', body: { message: '이름을 입력해 주세요.' } });

        await runWithOnError();

        expect(captured).toEqual(['이름을 입력해 주세요.']);
    });

    /**
     * @scenario case=error_status_preserved
     * @effects error_status_preserved
     */
    it('오류 객체에는 상태 코드와 statusText 가 그대로 남는다 (진단 정보 보존)', async () => {
        respondWith({ status: 500, statusText: 'Internal Server Error', body: {} });

        const result = await runWithOnError();
        const original = result.error?.originalError as any;

        expect(original?.status).toBe(500);
        expect(original?.statusText).toBe('Internal Server Error');
    });
});
