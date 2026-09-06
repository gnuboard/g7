/**
 * `reloadModuleHandlers` / `reloadPluginHandlers` 자산 로드 계약 테스트.
 *
 * 두 핸들러는 확장 활성화 응답이 지시하는 URL 을 그대로 `<script>`/`<link>` 로 붙인다.
 * 그래서 레이아웃 `scripts[]` 와 같은 출처 정책을 받아야 하고(미신뢰 URL 차단),
 * script 가 이미 있다는 이유로 **CSS 로드까지 건너뛰면 안 된다**(종전 결함).
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher, ActionDefinition } from '../ActionDispatcher';

vi.mock('../../auth/AuthManager', () => ({
    AuthManager: {
        getInstance: vi.fn(() => ({
            login: vi.fn(),
            logout: vi.fn(),
        })),
    },
}));

vi.mock('../../api/ApiClient', () => ({
    getApiClient: vi.fn(() => ({ getToken: vi.fn() })),
}));

describe('확장 자산 재로드 핸들러 (reloadModuleHandlers / reloadPluginHandlers)', () => {
    let dispatcher: ActionDispatcher;
    let created: HTMLElement[];
    let originalCreateElement: typeof document.createElement;

    const lastResult = (r: any) => r;

    beforeEach(() => {
        dispatcher = new ActionDispatcher({ navigate: vi.fn() });
        created = [];
        originalCreateElement = document.createElement.bind(document);
        document.head.innerHTML = '';

        (window as any).G7Config = { trustedScriptHosts: [], moduleAssets: {}, pluginAssets: {} };

        vi.spyOn(document, 'createElement').mockImplementation((tagName: string) => {
            const el = originalCreateElement(tagName);
            if (tagName === 'script' || tagName === 'link') {
                created.push(el as HTMLElement);
            }
            return el;
        });

        vi.spyOn(document.head, 'appendChild').mockImplementation((node: Node) => {
            setTimeout(() => {
                const el = node as HTMLElement & { onload?: ((e: Event) => void) | null };
                el.onload?.(new Event('load'));
            }, 0);
            // 실제 DOM 에도 넣어야 getElementById 로 기존재 판정이 가능하다
            return HTMLHeadElement.prototype.appendChild.call(document.head, node) as Node;
        });
    });

    afterEach(() => {
        vi.restoreAllMocks();
        document.head.innerHTML = '';
        delete (window as any).G7Config;
    });

    const run = (handler: string, params: Record<string, any>) =>
        dispatcher.executeAction(
            { type: 'click', handler, params } as ActionDefinition,
            {} as any
        );

    describe.each([
        ['reloadModuleHandlers', 'moduleInfo', 'module', 'moduleAssets'],
        ['reloadPluginHandlers', 'pluginInfo', 'plugin', 'pluginAssets'],
    ])('%s', (handlerName, infoKey, prefix, assetsKey) => {
        const identifier = 'vendor-ext';

        const add = (assets: Record<string, string>) =>
            run(handlerName, { [infoKey]: { identifier, assets }, action: 'add' });

        it('same-origin js/css 는 정상 로드된다', async () => {
            const result = lastResult(await add({ js: '/api/x/bundle.js', css: '/api/x/bundle.css' }));

            expect(result.success).toBe(true);
            expect(document.getElementById(`${prefix}-${identifier}`)).not.toBeNull();
            expect(document.getElementById(`${prefix}-css-${identifier}`)).not.toBeNull();
            expect((window as any).G7Config[assetsKey][identifier]).toBeDefined();
        });

        it('미신뢰 외부 js 는 차단된다 (태그 미생성)', async () => {
            const result = lastResult(await add({ js: 'https://cdn.evil.com/x.js' }));

            expect(result.success).toBe(false);
            expect((result.error as Error)?.message).toMatch(/Blocked untrusted/);
            expect(created.filter(el => el.tagName === 'SCRIPT')).toHaveLength(0);
        });

        it('미신뢰 외부 css 는 차단된다 (태그 미생성)', async () => {
            const result = lastResult(
                await add({ js: '/api/x/bundle.js', css: 'https://cdn.evil.com/x.css' })
            );

            expect(result.success).toBe(false);
            expect((result.error as Error)?.message).toMatch(/Blocked untrusted/);
            expect(created.filter(el => el.tagName === 'LINK')).toHaveLength(0);
        });

        it('script 가 이미 있어도 CSS 로드는 진행된다', async () => {
            const existing = originalCreateElement('script');
            existing.id = `${prefix}-${identifier}`;
            HTMLHeadElement.prototype.appendChild.call(document.head, existing);

            const result = lastResult(await add({ js: '/api/x/bundle.js', css: '/api/x/bundle.css' }));

            expect(result.success).toBe(true);
            expect(document.getElementById(`${prefix}-css-${identifier}`)).not.toBeNull();
            // 새 script 태그는 만들지 않는다
            expect(created.filter(el => el.tagName === 'SCRIPT')).toHaveLength(0);
        });

        it('css 만 있는 확장도 로드된다 (js 없음)', async () => {
            const result = lastResult(await add({ css: '/api/x/bundle.css' }));

            expect(result.success).toBe(true);
            expect(document.getElementById(`${prefix}-css-${identifier}`)).not.toBeNull();
        });

        it('신뢰 호스트로 선언된 외부 자산은 통과한다', async () => {
            (window as any).G7Config.trustedScriptHosts = ['cdn.trusted.com'];

            const result = lastResult(await add({ js: 'https://cdn.trusted.com/x.js' }));

            expect(result.success).toBe(true);
            expect(document.getElementById(`${prefix}-${identifier}`)).not.toBeNull();
        });
    });
});
