/**
 * 사용자 추가 에셋(`custom/`) 로드
 *
 * 운영자가 덧붙인 자산은 확장 병합 번들 **뒤**에 붙어야 한다 — CSS 는 나중에 온 규칙이
 * 이기므로, 앞에 붙으면 운영자의 재정의가 확장 스타일에 밀린다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ModuleAssetLoader, parseCustomAssetsFromConfig } from '../modules/ModuleAssetLoader';
import { getAssetFailures, clearAllAssetFailures } from '../assets/AssetFailureNotice';

describe('ModuleAssetLoader.loadCustomAssets', () => {
    let loader: ModuleAssetLoader;
    let requested: Array<{ tag: string; url: string }>;

    /**
     * head.appendChild 를 가로채 결과를 발화시킵니다.
     *
     * @param decide url 을 받아 결과를 돌려주는 판정 함수
     * @return void
     */
    function stub(decide: (url: string) => 'load' | 'error' = () => 'load'): void {
        vi.spyOn(document.head, 'appendChild').mockImplementation(((node: any) => {
            if (node.tagName !== 'SCRIPT' && node.tagName !== 'LINK') {
                return node;
            }

            const url = String(node.getAttribute('src') ?? node.getAttribute('href') ?? '');
            requested.push({ tag: node.tagName, url });
            document.body.appendChild(node);

            const outcome = decide(url);

            queueMicrotask(() => {
                if (outcome === 'error') {
                    node.onerror?.(new Event('error'));
                } else {
                    node.onload?.(new Event('load'));
                }
            });

            return node;
        }) as any);
    }

    beforeEach(() => {
        loader = new ModuleAssetLoader();
        requested = [];
        clearAllAssetFailures();
        delete (window as any).G7Config;
    });

    afterEach(() => {
        vi.restoreAllMocks();
        clearAllAssetFailures();
        document.querySelectorAll('[id^="g7-custom-"]').forEach(el => el.remove());
        delete (window as any).G7Config;
    });

    describe('parseCustomAssetsFromConfig', () => {
        it('설정이 없으면 빈 배열', () => {
            expect(parseCustomAssetsFromConfig()).toEqual([]);
        });

        it('형식이 어긋난 항목은 버린다', () => {
            (window as any).G7Config = {
                customAssets: [
                    { id: 'a', type: 'style', url: '/a.css' },
                    { id: 'b', type: 'unknown', url: '/b.css' },
                    { id: 'c', type: 'script' },
                    null,
                ],
            };

            expect(parseCustomAssetsFromConfig().map(a => a.id)).toEqual(['a']);
        });
    });

    /**
     * @scenario custom_source=convention_scan, custom_asset=css
     * @effects custom_asset_loaded_after_extension_bundles
     */
    it('목록이 비면 아무 요청도 하지 않는다', async () => {
        stub();

        await loader.loadCustomAssets();

        expect(requested).toHaveLength(0);
    });

    it('선언 순서대로 로드하고 타입에 맞는 태그를 쓴다', async () => {
        (window as any).G7Config = {
            customAssets: [
                { id: 'custom:templates:t:a.css', type: 'style', url: '/a.css' },
                { id: 'custom:templates:t:b.js', type: 'script', url: '/b.js' },
            ],
        };
        stub();

        await loader.loadCustomAssets();

        expect(requested.map(r => r.tag)).toEqual(['LINK', 'SCRIPT']);
        expect(requested.map(r => r.url)).toEqual(['/a.css', '/b.js']);
    });

    it('이미 로드된 항목은 다시 요청하지 않는다', async () => {
        (window as any).G7Config = {
            customAssets: [{ id: 'custom:templates:t:a.css', type: 'style', url: '/a.css' }],
        };
        stub();

        await loader.loadCustomAssets();
        await loader.loadCustomAssets();

        expect(requested).toHaveLength(1);
    });

    /**
     * @scenario custom_source=convention_scan, custom_asset=css, outcome=failed
     * @effects failed_asset_shows_retry_notice
     */
    it('실패한 항목은 안내로 표면화하되 나머지는 계속 로드한다', async () => {
        (window as any).G7Config = {
            customAssets: [
                { id: 'custom:templates:t:bad.css', type: 'style', url: '/bad.css' },
                { id: 'custom:templates:t:ok.css', type: 'style', url: '/ok.css' },
            ],
        };
        stub(url => (url.includes('/bad.css') ? 'error' : 'load'));

        await expect(loader.loadCustomAssets()).resolves.toBeUndefined();

        expect(loader.getFailedCssAssets()).toEqual([]);
        expect(getAssetFailures().map(f => f.id)).toContain('custom-asset:custom:templates:t:bad.css');
        expect(requested.some(r => r.url === '/ok.css')).toBe(true);
    });

    /**
     * 게시본이 GC 로 사라지면 정적 URL 은 404 가 확정된 주소다. 같은 URL 을 재시도해도
     * 복구되지 않으므로 종전 API URL 로 전환해야 한다 — 형제 경로(`loadBundleCss`)가
     * 이미 갖춘 계층이다. 이 계층이 없으면 화면은 정상인데 운영자 자산만 조용히 빠진다.
     *
     * 세 확장 타입 전부를 건다. 템플릿만 역변환하던 상태에서는 모듈·플러그인이 통과하지
     * 못한다.
     */
    it.each([
        ['templates', 'sirsoft-basic', '/api/templates/assets/sirsoft-basic/custom/x.css'],
        ['modules', 'sirsoft-page', '/api/modules/assets/sirsoft-page/custom/x.css'],
        ['plugins', 'sirsoft-gdpr', '/api/plugins/assets/sirsoft-gdpr/custom/x.css'],
    ])('정적 게시 미스는 %s 도 종전 API URL 로 전환한다', async (type, identifier, expectedApi) => {
        const staticUrl = `/build/ext/1234/${type}/${identifier}/assets/custom/x.css`;

        (window as any).G7Config = {
            customAssets: [
                { id: `custom:${type}:${identifier}:x.css`, type: 'style', url: staticUrl },
            ],
        };
        stub(url => (url.startsWith('/build/ext/') ? 'error' : 'load'));

        await loader.loadCustomAssets();

        // 정적 → API 순서로 정확히 두 번 요청한다
        expect(requested.map(r => r.url)).toEqual([staticUrl, `${expectedApi}?v=1234`]);

        // 폴백이 성공했으므로 실패 배너는 뜨지 않는다
        expect(getAssetFailures()).toEqual([]);
    });

    it('정적 게시 URL 이 아니면 폴백 없이 그대로 재시도한다', async () => {
        (window as any).G7Config = {
            customAssets: [
                { id: 'custom:templates:t:x.css', type: 'style', url: '/api/templates/assets/t/custom/x.css' },
            ],
        };
        stub(() => 'load');

        await loader.loadCustomAssets();

        expect(requested.map(r => r.url)).toEqual(['/api/templates/assets/t/custom/x.css']);
    });
});
