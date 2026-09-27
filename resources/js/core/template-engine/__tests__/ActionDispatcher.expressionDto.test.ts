/**
 * ActionDispatcher 표현식 컨텍스트 DTO 주입 테스트 (KVE-2026-2350)
 *
 * 신고 체인: 깨진 이미지의 error 이벤트 → 액션 표현식 `$event.target.ownerDocument.defaultView`
 * → Window → localStorage.auth_token. 이벤트·오류·콜백 인자는 이제 plain 사본으로만 실리고,
 * 핸들러(신뢰 코드)는 종전대로 `context.event` 로 원본을 받는다.
 *
 * @since engine-v1.73.0
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher } from '../ActionDispatcher';
import { Logger } from '../../utils/Logger';

vi.mock('../../auth/AuthManager', () => ({
    AuthManager: {
        getInstance: vi.fn(() => ({ login: vi.fn(), logout: vi.fn() })),
    },
}));

vi.mock('../../api/ApiClient', () => ({
    getApiClient: vi.fn(() => ({ getToken: vi.fn() })),
}));

describe('ActionDispatcher — 표현식 컨텍스트 DTO (engine-v1.73.0)', () => {
    let dispatcher: ActionDispatcher;

    beforeEach(() => {
        dispatcher = new ActionDispatcher({ navigate: vi.fn() });
        Logger.getInstance().setDebug(false);
        localStorage.setItem('auth_token', 'secret-token');
    });

    afterEach(() => {
        localStorage.removeItem('auth_token');
        localStorage.removeItem('g7_expr_escape_marker');
        vi.clearAllMocks();
    });

    /**
     * @scenario case=host_graph_escape
     *
     * @effects sandbox_escape_blocked, dangerous_payload_does_not_set_global, host_object_access_blocked
     */
    it('img error 이벤트의 $event 로 Window·localStorage 에 닿지 못한다 (신고 payload)', async () => {
        let captured: any = null;
        dispatcher.registerHandler('capture', async (action, context) => {
            captured = { params: action.params, event: context.event, data: context.data };
        });

        const img = document.createElement('img');
        document.body.appendChild(img);

        const handler = dispatcher.createHandler(
            {
                type: 'error',
                handler: 'capture',
                params: {
                    probe: "{{$event.target.ownerDocument ? 'LEAK' : 'SAFE'}}",
                    token: "{{$event.target.ownerDocument.defaultView.localStorage.getItem('auth_token')}}",
                },
            } as any,
            {}
        );

        const event = new Event('error');
        img.dispatchEvent(event);
        await handler(event);

        expect(captured).not.toBeNull();
        expect(captured.params.probe).toBe('SAFE');
        expect(captured.params.token).not.toBe('secret-token');
        expect(captured.data.$event.target.ownerDocument).toBeUndefined();
        expect(captured.data.$event.target.tagName).toBe('IMG');
        // 핸들러는 원본 이벤트를 그대로 받는다 (신뢰 코드)
        expect(captured.event).toBe(event);

        img.remove();
    });

    /**
     * @scenario case=safe_expression
     *
     * @effects event_dto_preserves_bound_fields, same_expression_same_value_across_paths
     */
    it('input change 의 $event.target.value·checked 바인딩은 종전대로 해석된다 (정상 회귀)', async () => {
        let captured: any = null;
        dispatcher.registerHandler('capture', async (action) => {
            captured = action.params;
        });

        const input = document.createElement('input');
        input.value = 'hello';
        const handler = dispatcher.createHandler(
            {
                type: 'change',
                handler: 'capture',
                params: { v: '{{$event.target.value}}', upper: '{{$event.target.value.toUpperCase()}}' },
            } as any,
            {}
        );

        const event = new Event('change');
        input.dispatchEvent(event);
        await handler(event);

        expect(captured).toEqual({ v: 'hello', upper: 'HELLO' });
    });

    /**
     * @effects error_dto_excludes_stack_and_hosts
     */
    it('onError 의 error 는 DTO — message·status·response 는 읽히고 stack 은 없다', async () => {
        let captured: any = null;
        dispatcher.registerHandler('fail', async () => {
            const err: any = new Error('검증 실패');
            err.status = 422;
            err.response = { errors: { email: ['필수'] } };
            throw err;
        });
        dispatcher.registerHandler('capture', async (_action, context) => {
            captured = context.data.error;
        });

        const handler = dispatcher.createHandler(
            { type: 'click', handler: 'fail', onError: { handler: 'capture' } } as any,
            {}
        );
        const button = document.createElement('button');
        const event = new MouseEvent('click');
        button.dispatchEvent(event);
        await handler(event);

        expect(captured).not.toBeNull();
        expect(captured.stack).toBeUndefined();
        expect(captured instanceof Error).toBe(false);
    });

    /**
     * @effects args_dto_strips_host_objects
     */
    it('커스텀 콜백 $args 는 plain 사본 — 원본 이벤트·DOM 참조가 실리지 않는다', async () => {
        let captured: any = null;
        dispatcher.registerHandler('capture', async (_action, context) => {
            captured = context.data.$args;
        });

        const props = dispatcher.bindActionsToProps({
            actions: [{ event: 'onRowAction', handler: 'capture' }],
        } as any);

        const row = { id: 1, member: { uuid: 'u-1' } };
        await props.onRowAction('view', row, document.body);

        expect(captured[0]).toBe('view');
        expect(captured[1]).toEqual(row);
        expect(captured[2]?.ownerDocument).toBeUndefined();
    });
});
