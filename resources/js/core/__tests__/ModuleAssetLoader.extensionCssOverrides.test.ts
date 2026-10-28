// e2e:allow 덮기 CSS 로드 실패 배너는 CSS 요청 실패를 강제해야 재현되는 경로라 단위 시험(실물 ModuleAssetLoader·AssetFailureNotice)으로 잠근다 — 정상 로드 순서는 S2 브라우저 실측·SeoRendererExtensionCssTest 가 덮는다
/**
 * 템플릿 확장 CSS 덮기 파일 로드
 *
 * 덮기 파일은 확장 번들 CSS 와 같은 특이도로 쓰이므로 **확장 번들 뒤, 운영자 추가 자산 앞**에
 * 붙어야 덮기가 성립하고 운영자 재정의도 살아 있다. 서버가 호환 판정을 끝낸 항목만 내려준다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ModuleAssetLoader, parseExtensionCssOverridesFromConfig } from '../modules/ModuleAssetLoader';
import { getAssetFailures, clearAllAssetFailures } from '../assets/AssetFailureNotice';

describe('ModuleAssetLoader.loadExtensionCssOverrides', () => {
    let loader: ModuleAssetLoader;
    let requested: string[];

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
            requested.push(url);
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
        document.querySelectorAll('[id^="g7-ext-css-override-"], [id^="g7-custom-"], [id^="ext-bundle-css-"]').forEach(el => el.remove());
        delete (window as any).G7Config;
    });

    // @scenario case=override_load_c01
    // @effects css_overrides_loaded_after_bundles_before_custom
    it('설정이 없거나 모양이 틀린 항목은 버린다', () => {
        expect(parseExtensionCssOverridesFromConfig()).toEqual([]);

        (window as any).G7Config = {
            extensionCssOverrides: [
                { target: 'gnuboard7-hello_module', url: '/a.css' },
                { target: 1, url: '/b.css' },
                null,
                { target: 'x' },
            ],
        };

        expect(parseExtensionCssOverridesFromConfig()).toEqual([{ target: 'gnuboard7-hello_module', url: '/a.css' }]);
    });

    // @scenario case=override_load_c02
    // @effects css_overrides_loaded_after_bundles_before_custom
    it('선언 순서대로 링크를 붙이고 같은 대상은 다시 붙이지 않는다', async () => {
        stub();
        (window as any).G7Config = {
            extensionCssOverrides: [
                { target: 'gnuboard7-hello_module', url: '/api/templates/assets/t/css/overrides/hello.css' },
                { target: 'sirsoft-board', url: '/api/templates/assets/t/css/overrides/board.css' },
            ],
        };

        await loader.loadExtensionCssOverrides();
        await loader.loadExtensionCssOverrides();

        expect(requested).toEqual([
            '/api/templates/assets/t/css/overrides/hello.css',
            '/api/templates/assets/t/css/overrides/board.css',
        ]);
        expect(document.getElementById('g7-ext-css-override-gnuboard7-hello_module')).not.toBeNull();
    });

    // @scenario case=override_load_c03
    // @effects css_overrides_loaded_after_bundles_before_custom
    it('확장 번들 CSS 뒤, 운영자 추가 자산 앞에 붙는다', async () => {
        stub();
        (window as any).G7Config = {
            extensionCssOverrides: [{ target: 'gnuboard7-hello_module', url: '/override.css' }],
            customAssets: [{ id: 'custom:templates:t:custom.css', type: 'style', url: '/custom.css' }],
        };

        await loader.loadBundle('module', null, '/api/modules/bundle.css');
        await loader.loadBundle('plugin', null, '/api/plugins/bundle.css');
        await loader.loadExtensionCssOverrides();
        await loader.loadCustomAssets();

        expect(requested).toEqual(['/api/modules/bundle.css', '/api/plugins/bundle.css', '/override.css', '/custom.css']);
    });

    // @scenario case=override_load_c04
    // @effects css_overrides_loaded_after_bundles_before_custom
    it('로드에 실패해도 throw 하지 않고 실패를 표면화한다', async () => {
        stub(() => 'error');
        (window as any).G7Config = {
            extensionCssOverrides: [{ target: 'gnuboard7-hello_module', url: '/missing.css' }],
        };

        await expect(loader.loadExtensionCssOverrides()).resolves.toBeUndefined();
        expect(loader.getFailedCssAssets()).toContain('override-gnuboard7-hello_module');
        expect(getAssetFailures().length).toBeGreaterThan(0);
    });

    // 배너 항목명은 사용자 어휘여야 한다(AssetFailureNotice 계약) — 확장 식별자 원문이 「gnuboard7-hello_module 불러오기에
    // 실패했습니다」로 노출되면 안 된다. [다시 시도]는 같은 id 로 다시 붙여 다음 로드의 중복 가드가 그대로 동작하게 한다.
    // @scenario case=override_load_c05
    // @effects css_overrides_loaded_after_bundles_before_custom
    it('실패 안내 항목명은 확장 식별자가 아닌 사용자 어휘이고 재시도는 같은 id 로 다시 붙인다', async () => {
        let fail = true;
        stub(() => (fail ? 'error' : 'load'));
        (window as any).G7Config = {
            extensionCssOverrides: [{ target: 'gnuboard7-hello_module', url: '/missing.css' }],
        };

        await loader.loadExtensionCssOverrides();
        const failure = getAssetFailures().find(f => f.id === 'ext-css:override-gnuboard7-hello_module');

        expect(failure).toBeDefined();
        expect(failure!.label).not.toContain('gnuboard7-hello_module');
        expect(failure!.label).toBe('템플릿 확장 스타일 조정');

        fail = false;
        await failure!.retry!();

        expect(document.querySelectorAll('#g7-ext-css-override-gnuboard7-hello_module')).toHaveLength(1);
        expect(loader.getFailedCssAssets()).not.toContain('override-gnuboard7-hello_module');
    });
});
