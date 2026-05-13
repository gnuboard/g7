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
        document.head.querySelectorAll('[id^="module-js-"],[id^="module-css-"]')
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
});
