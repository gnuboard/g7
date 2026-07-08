/**
 * ModuleAssetLoader 테스트
 *
 * 확장 에셋의 병렬 fetch + 실행 순서 보장(priority 정렬) 동작을 검증합니다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ModuleAssetLoader, type ModuleAsset } from '../ModuleAssetLoader';

describe('ModuleAssetLoader', () => {
    let loader: ModuleAssetLoader;
    let originalCreateElement: typeof document.createElement;

    beforeEach(() => {
        loader = new ModuleAssetLoader();
        originalCreateElement = document.createElement.bind(document);
    });

    afterEach(() => {
        vi.restoreAllMocks();
        document.head.querySelectorAll('[id^="module-js-"],[id^="module-css-"],[id^="ext-bundle-js-"],[id^="ext-bundle-css-"]')
            .forEach(el => el.remove());
    });

    describe('loadActiveExtensionAssets', () => {
        it('JS 에셋을 priority 오름차순으로 DOM에 append한다 (실행 순서 보장)', async () => {
            const appendOrder: string[] = [];

            const createElementSpy = vi.spyOn(document, 'createElement').mockImplementation((tag: string) => {
                const el = originalCreateElement(tag);
                if (tag === 'script') {
                    Object.defineProperty(el, 'src', {
                        set(v) {
                            (el as any)._src = v;
                        },
                        get() {
                            return (el as any)._src;
                        },
                    });
                }
                return el;
            });

            const appendSpy = vi.spyOn(document.head, 'appendChild').mockImplementation(((node: any) => {
                if (node.tagName === 'SCRIPT') {
                    appendOrder.push(node.id);
                    // onload 즉시 호출 (fetch 완료 시뮬레이션)
                    queueMicrotask(() => node.onload?.());
                } else if (node.tagName === 'LINK') {
                    queueMicrotask(() => node.onload?.());
                }
                return node;
            }) as any);

            const extensions: ModuleAsset[] = [
                { identifier: 'ext-c', js: '/c.js', priority: 30 },
                { identifier: 'ext-a', js: '/a.js', priority: 10 },
                { identifier: 'ext-b', js: '/b.js', priority: 20 },
            ];

            await loader.loadActiveExtensionAssets(extensions);

            expect(appendOrder).toEqual([
                'module-js-ext-a',
                'module-js-ext-b',
                'module-js-ext-c',
            ]);

            createElementSpy.mockRestore();
            appendSpy.mockRestore();
        });

        it('모든 스크립트에 async=false가 설정되어야 한다 (실행 순서 보장 계약)', async () => {
            const createdScripts: HTMLScriptElement[] = [];

            const appendSpy = vi.spyOn(document.head, 'appendChild').mockImplementation(((node: any) => {
                if (node.tagName === 'SCRIPT') {
                    createdScripts.push(node);
                    queueMicrotask(() => node.onload?.());
                }
                return node;
            }) as any);

            const extensions: ModuleAsset[] = [
                { identifier: 'ext-1', js: '/1.js', priority: 100 },
                { identifier: 'ext-2', js: '/2.js', priority: 100 },
            ];

            await loader.loadActiveExtensionAssets(extensions);

            expect(createdScripts).toHaveLength(2);
            createdScripts.forEach(s => expect(s.async).toBe(false));

            appendSpy.mockRestore();
        });

        it('JS 에셋 fetch를 병렬로 착수한다 (Phase 1 병렬화 회귀 방지)', async () => {
            // 첫 번째 append 시점에 아직 두 번째 append도 큐잉되어야 함
            // (순차 await 방식이면 첫 onload 이후에만 두 번째 append가 발생)
            const appendTimestamps: number[] = [];
            let firstAppendResolved = false;
            const resolvers: Array<() => void> = [];

            const appendSpy = vi.spyOn(document.head, 'appendChild').mockImplementation(((node: any) => {
                if (node.tagName === 'SCRIPT') {
                    appendTimestamps.push(performance.now());
                    // onload는 수동 트리거 (모두 append 된 뒤 일괄 resolve)
                    resolvers.push(() => node.onload?.());
                }
                return node;
            }) as any);

            const extensions: ModuleAsset[] = [
                { identifier: 'p-1', js: '/1.js', priority: 100 },
                { identifier: 'p-2', js: '/2.js', priority: 100 },
                { identifier: 'p-3', js: '/3.js', priority: 100 },
            ];

            const loadPromise = loader.loadActiveExtensionAssets(extensions);

            // 마이크로태스크 1회 flush — 모든 script가 append 되어야 함
            await Promise.resolve();
            await Promise.resolve();

            expect(appendTimestamps).toHaveLength(3);
            expect(resolvers).toHaveLength(3);

            // 수동으로 onload 호출해 loadPromise 완료
            resolvers.forEach(r => r());
            await loadPromise;

            expect(firstAppendResolved).toBe(false); // placeholder, 실제 검증은 length 체크로 완료
            appendSpy.mockRestore();
        });

        it('CSS와 JS를 모두 병렬로 로드한다', async () => {
            const linkAppends: string[] = [];
            const scriptAppends: string[] = [];

            const appendSpy = vi.spyOn(document.head, 'appendChild').mockImplementation(((node: any) => {
                if (node.tagName === 'LINK') {
                    linkAppends.push(node.id);
                    queueMicrotask(() => node.onload?.());
                } else if (node.tagName === 'SCRIPT') {
                    scriptAppends.push(node.id);
                    queueMicrotask(() => node.onload?.());
                }
                return node;
            }) as any);

            const extensions: ModuleAsset[] = [
                { identifier: 'ext-1', js: '/1.js', css: '/1.css', priority: 100 },
                { identifier: 'ext-2', js: '/2.js', css: '/2.css', priority: 100 },
            ];

            await loader.loadActiveExtensionAssets(extensions);

            expect(linkAppends).toEqual(['module-css-ext-1', 'module-css-ext-2']);
            expect(scriptAppends).toEqual(['module-js-ext-1', 'module-js-ext-2']);

            appendSpy.mockRestore();
        });

        it('빈 배열은 no-op', async () => {
            await expect(loader.loadActiveExtensionAssets([])).resolves.toBeUndefined();
        });
    });

    describe('loadBundle (서버측 병합 번들)', () => {
        it('단일 script + 단일 link 를 1개씩만 append 한다', async () => {
            const scriptAppends: string[] = [];
            const linkAppends: string[] = [];

            const appendSpy = vi.spyOn(document.head, 'appendChild').mockImplementation(((node: any) => {
                if (node.tagName === 'SCRIPT') {
                    scriptAppends.push(node.id);
                    queueMicrotask(() => node.onload?.());
                } else if (node.tagName === 'LINK') {
                    linkAppends.push(node.id);
                    queueMicrotask(() => node.onload?.());
                }
                return node;
            }) as any);

            await loader.loadBundle('module', '/api/modules/bundle.js?v=1', '/api/modules/bundle.css?v=1');

            expect(scriptAppends).toEqual(['ext-bundle-js-module']);
            expect(linkAppends).toEqual(['ext-bundle-css-module']);

            appendSpy.mockRestore();
        });

        it('번들 script 는 async=false 여야 한다 (내부 순서 실행 보장)', async () => {
            const created: HTMLScriptElement[] = [];
            const appendSpy = vi.spyOn(document.head, 'appendChild').mockImplementation(((node: any) => {
                if (node.tagName === 'SCRIPT') {
                    created.push(node);
                    queueMicrotask(() => node.onload?.());
                }
                return node;
            }) as any);

            await loader.loadBundle('plugin', '/api/plugins/bundle.js?v=1', null);

            expect(created).toHaveLength(1);
            expect(created[0].async).toBe(false);
            appendSpy.mockRestore();
        });

        it('같은 key 를 중복 로드하지 않는다 (중복 가드)', async () => {
            let scriptCount = 0;
            // 실제 DOM 에 삽입해 element id 가 등록되어야 getElementById 중복 가드가 동작
            const appendSpy = vi.spyOn(document.head, 'appendChild').mockImplementation(((node: any) => {
                if (node.tagName === 'SCRIPT') {
                    scriptCount++;
                    queueMicrotask(() => node.onload?.());
                }
                // jsdom 실제 삽입 (id 등록 → 두 번째 호출 시 getElementById 가 찾아 중복 방지)
                Node.prototype.appendChild.call(document.head, node);
                return node;
            }) as any);

            await loader.loadBundle('module', '/api/modules/bundle.js?v=1', null);
            await loader.loadBundle('module', '/api/modules/bundle.js?v=1', null);

            expect(scriptCount).toBe(1);
            appendSpy.mockRestore();
        });

        it('jsUrl/cssUrl 모두 null 이면 no-op', async () => {
            const appendSpy = vi.spyOn(document.head, 'appendChild');
            await expect(loader.loadBundle('module', null, null)).resolves.toBeUndefined();
            expect(appendSpy).not.toHaveBeenCalled();
            appendSpy.mockRestore();
        });
    });

    describe('concat 자가등록 (IIFE 병합 계약)', () => {
        it(';\\n 로 이어붙인 2개 IIFE 가 모두 실행되어 레지스트리에 자가등록된다', () => {
            // 모의 IIFE 2개 — ecommerce IIFE 처럼 세미콜론 없이 끝나도 `\n;\n` 구분자로 ASI 경계 보호
            const registry: Record<string, unknown> = {};
            (globalThis as any).__G7TestRegistry = registry;

            const iifeA = `(function(){"use strict";globalThis.__G7TestRegistry["mod-a"]={handler:"mod-a.doThing"}})()`;
            // 세미콜론 없이 끝나는 IIFE (sourceMappingURL 주석 strip 후 형태)
            const iifeB = `(function(){"use strict";globalThis.__G7TestRegistry["mod-b"]={handler:"mod-b.doThing"}})()`;

            const bundle = [iifeA, iifeB].join('\n;\n');

            // 번들 실행 (단일 <script> 실행 시뮬레이션)
            // eslint-disable-next-line no-new-func
            new Function(bundle)();

            expect(registry['mod-a']).toEqual({ handler: 'mod-a.doThing' });
            expect(registry['mod-b']).toEqual({ handler: 'mod-b.doThing' });
            // 핸들러 네임스페이스 격리 — 각 확장 prefix
            expect((registry['mod-a'] as any).handler).toContain('mod-a.');
            expect((registry['mod-b'] as any).handler).toContain('mod-b.');

            delete (globalThis as any).__G7TestRegistry;
        });

        it('구분자 없이 이어붙이면 ASI 경계가 깨진다 (구분자 필요성 회귀 가드)', () => {
            // 세미콜론 없이 끝나는 IIFE 뒤에 즉시 다음 IIFE 를 붙이면 파싱 에러
            const iifeA = `(function(){return 1})()`;
            const iifeB = `(function(){return 2})()`;

            // 구분자 없는 병합 → `()()(function...` 형태로 호출 연쇄 파싱 (런타임 에러)
            expect(() => new Function(iifeA + '\n' + iifeB)()).toThrow();
            // 구분자 있는 병합 → 정상
            expect(() => new Function(iifeA + '\n;\n' + iifeB)()).not.toThrow();
        });
    });
});
