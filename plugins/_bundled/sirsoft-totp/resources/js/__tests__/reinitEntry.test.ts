/**
 * @vitest-environment jsdom
 *
 * 코어 재초기화(로케일 전환) 뒤 핸들러 재등록 (#639 ④)
 *
 * 로케일을 바꾸면 코어가 ActionDispatcher 를 새로 만들고 `window.__{Name}.initPlugin()` 을 부른다. 이 플러그인은 그
 * 전역을 두지 않아 ko→en 전환 뒤 `sirsoft-totp.copyToClipboard` 가 디스패처에서 빠졌다(실측 2026-10-05).
 *
 * @scenario case=reinit
 *
 * @effects handlers_survive_locale_reinit
 */

import { beforeEach, describe, expect, it, vi } from 'vitest';

/** 디스패처 흉내 — 등록된 핸들러 이름을 모은다(수신자 this 가 살아 있어야 등록된다). */
function createDispatcher() {
    return {
        customHandlers: new Map<string, unknown>(),
        registerHandler(this: { customHandlers: Map<string, unknown> }, name: string, handler: unknown) {
            this.customHandlers.set(name, handler);
        },
    };
}

describe('재등록 진입점', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        vi.resetModules();
    });

    // @scenario case=reinit
    // @effects handlers_survive_locale_reinit
    it('window.__SirsoftTotp.initPlugin 을 노출하고, 새 디스패처에 핸들러를 다시 꽂는다', async () => {
        const first = createDispatcher();
        (window as any).G7Core = { getActionDispatcher: () => first };
        await import('../index');

        expect(first.customHandlers.has('sirsoft-totp.copyToClipboard')).toBe(true);

        const exposed = (window as any).__SirsoftTotp;
        expect(exposed?.identifier).toBe('sirsoft-totp');
        expect(typeof exposed?.initPlugin).toBe('function');

        // 로케일 전환 — 코어가 디스패처를 새로 만든다
        const second = createDispatcher();
        (window as any).G7Core = { getActionDispatcher: () => second };
        exposed.initPlugin();

        expect(second.customHandlers.has('sirsoft-totp.copyToClipboard')).toBe(true);
    });
});
