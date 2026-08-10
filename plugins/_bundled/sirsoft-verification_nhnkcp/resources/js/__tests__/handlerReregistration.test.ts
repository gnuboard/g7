/**
 * @file handlerReregistration.test.ts
 * @description 코어 재초기화(로케일 전환 등) 후 startAuth 핸들러 재등록 회귀 검증
 *
 * TemplateApp 은 ActionDispatcher 를 새로 만든 뒤
 * `window.__[Plugin].initPlugin()` 을 호출해 플러그인이 핸들러를 다시 등록하게 한다.
 * 이 이름으로 노출하지 않으면 재초기화 이후 본인확인 시작 버튼이 무반응이 된다
 * (핸들러 미등록 → dispatch 무시 → 콘솔 에러도 토스트도 없음).
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { initPlugin, PLUGIN_IDENTIFIER } from '../index';

const HANDLER_NAME = `${PLUGIN_IDENTIFIER}.startAuth`;

/** 코어 ActionDispatcher 를 흉내낸다 — 재초기화 시 새 인스턴스가 만들어진다. */
function createDispatcher() {
    const handlers = new Map<string, unknown>();

    return {
        handlers,
        registerHandler: vi.fn((name: string, handler: unknown) => {
            handlers.set(name, handler);
        }),
    };
}

function installG7Core(dispatcher: ReturnType<typeof createDispatcher>): void {
    (window as any).G7Core = {
        getActionDispatcher: () => dispatcher,
    };
}

describe('코어 재초기화 시 핸들러 재등록', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
        delete (window as any).G7Core;
        vi.restoreAllMocks();
    });

    /**
     * @scenario locale=initial,dispatcher_ready=ready,e2e_environment=desktop
     * @effects exposes_init_plugin_entrypoint_on_window_global
     */
    it('window 전역에 initPlugin 을 노출한다 (코어가 이 이름으로만 재호출한다)', () => {
        const exposed = (window as any).__SirsoftVerificationNhnkcp;

        expect(exposed).toBeDefined();
        expect(typeof exposed.initPlugin).toBe('function');
    });

    /**
     * @scenario locale=switched,dispatcher_ready=ready,e2e_environment=desktop
     * @effects reregisters_start_auth_handler_on_new_dispatcher
     */
    it('새 ActionDispatcher 에 startAuth 핸들러를 다시 등록한다', () => {
        const first = createDispatcher();
        installG7Core(first);
        initPlugin();
        expect(first.handlers.has(HANDLER_NAME)).toBe(true);

        // 로케일 전환 — 코어가 ActionDispatcher 를 새로 만든다 (기존 핸들러 전부 소실)
        const second = createDispatcher();
        installG7Core(second);
        expect(second.handlers.has(HANDLER_NAME)).toBe(false);

        initPlugin();

        expect(second.handlers.has(HANDLER_NAME)).toBe(true);
        expect(typeof second.handlers.get(HANDLER_NAME)).toBe('function');
    });

    /**
     * @scenario locale=switched,dispatcher_ready=delayed,e2e_environment=desktop
     * @effects retries_registration_until_dispatcher_ready
     */
    it('재초기화 시점에 ActionDispatcher 가 아직 없으면 준비될 때까지 재시도한다', () => {
        (window as any).G7Core = undefined;

        initPlugin();

        const dispatcher = createDispatcher();
        installG7Core(dispatcher);
        vi.advanceTimersByTime(100);

        expect(dispatcher.handlers.has(HANDLER_NAME)).toBe(true);
    });
});
