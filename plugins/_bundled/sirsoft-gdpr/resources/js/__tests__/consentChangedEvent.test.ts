/**
 * @file consentChangedEvent.test.ts
 * @description 동의 저장 직후 `gdpr:consent-changed` 발신 회귀 검증
 *
 * 이 플러그인 자신(blocker)과 코어 방문자 통계 트래커가 이 이벤트만 바라보고 동의 판정을
 * 갱신한다. 보내는 쪽이 없으면 배너에서 동의해도 새로고침 전까지 옛 판정이 그대로 쓰여
 * 화면에는 아무 증상이 없다 — 통계는 그 사이 방문을 통째로 버린다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { initPlugin, PLUGIN_IDENTIFIER } from '../index';

const HANDLER_NAME = `${PLUGIN_IDENTIFIER}.syncConsent`;

/** 코어 ActionDispatcher 를 흉내낸다. */
function createDispatcher() {
    const handlers = new Map<string, (...args: unknown[]) => unknown>();

    return {
        handlers,
        registerHandler: vi.fn((name: string, handler: (...args: unknown[]) => unknown) => {
            handlers.set(name, handler);
        }),
    };
}

describe('동의 저장 직후 알림', () => {
    beforeEach(() => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                success: true,
                data: { consents: { necessary: true, functional: true, analytics: true }, needs_renewal: false },
            }),
        }));
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        delete (window as any).G7Core;
        vi.restoreAllMocks();
    });

    it('syncConsent 가 gdpr:consent-changed 를 보낸다', async () => {
        const dispatcher = createDispatcher();
        (window as any).G7Core = { getActionDispatcher: () => dispatcher };
        initPlugin();

        const received: CustomEvent[] = [];
        const listener = (event: Event) => received.push(event as CustomEvent);
        window.addEventListener('gdpr:consent-changed', listener);

        await dispatcher.handlers.get(HANDLER_NAME)!();

        window.removeEventListener('gdpr:consent-changed', listener);

        expect(received).toHaveLength(1);
        expect(received[0].detail.categories.analytics).toBe(true);
    });
});
