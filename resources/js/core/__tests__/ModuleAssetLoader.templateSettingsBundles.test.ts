// e2e:allow 묶음 하나의 적재 실패는 스크립트 요청 실패를 강제해야 재현되는 경로라 단위 시험(실물 ModuleAssetLoader)으로 잠근다 — 정상 적재는 tests/Playwright/specs/admin/template-settings-menu-editor.spec.ts 가 hello_admin 을 켠 상태로 덮는다
/**
 * 템플릿 설정 화면 부품 묶음 적재 (#434 D28·D30)
 *
 * 템플릿 설정 화면은 그 템플릿 자기 부품으로 그린다 — 서버가 관리자 화면에만 싣는 `G7Config.templateSettingsAssets`
 * 를 템플릿마다 묶음으로 싣고, 한 템플릿이 실패해도 다른 템플릿 묶음은 실린다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ModuleAssetLoader, parseTemplateSettingsBundlesFromConfig } from '../modules/ModuleAssetLoader';
import { clearAllAssetFailures, getAssetFailures } from '../assets/AssetFailureNotice';

describe('ModuleAssetLoader.loadTemplateSettingsBundles', () => {
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
        delete (window as any).G7Config;
    });

    afterEach(() => {
        vi.restoreAllMocks();
        clearAllAssetFailures();
        document.querySelectorAll('[id^="ext-bundle-js-template-settings-"], [id^="ext-bundle-css-template-settings-"]').forEach(el => el.remove());
        delete (window as any).G7Config;
    });

    // @scenario case=settings_bundle_load
    // @effects settings_bundles_loaded_on_admin
    it('설정이 없거나 식별자가 없는 항목은 버린다', () => {
        expect(parseTemplateSettingsBundlesFromConfig()).toEqual([]);

        (window as any).G7Config = {
            templateSettingsAssets: [
                { identifier: 'sirsoft-basic', js: '/api/templates/assets/sirsoft-basic/settings/settings.iife.js?v=3', css: '/api/templates/assets/sirsoft-basic/settings/settings.css?v=3' },
                { identifier: '', js: '/x.js' },
                null,
                { js: '/y.js' },
                { identifier: 'gnuboard7-hello_user_template', js: '/api/templates/assets/gnuboard7-hello_user_template/settings/settings.iife.js?v=3' },
            ],
        };

        const bundles = parseTemplateSettingsBundlesFromConfig();

        expect(bundles.map((bundle) => bundle.identifier)).toEqual(['sirsoft-basic', 'gnuboard7-hello_user_template']);
        expect(bundles[0].css).toContain('/settings/settings.css');
        expect(bundles[1].css).toBeNull();
    });

    // @scenario case=settings_bundle_load
    // @effects settings_bundles_loaded_on_admin
    it('템플릿마다 묶음을 싣고, 한 템플릿 묶음이 실패해도 다음 템플릿 묶음은 실린다', async () => {
        stub((url) => (url.includes('/broken/') ? 'error' : 'load'));
        (window as any).G7Config = {
            templateSettingsAssets: [
                { identifier: 'broken', js: '/api/templates/assets/broken/settings/settings.iife.js' },
                { identifier: 'sirsoft-basic', js: '/api/templates/assets/sirsoft-basic/settings/settings.iife.js', css: '/api/templates/assets/sirsoft-basic/settings/settings.css' },
            ],
        };

        await expect(loader.loadTemplateSettingsBundles()).resolves.toBeUndefined();

        expect(requested.some((url) => url.includes('/sirsoft-basic/settings/settings.iife.js'))).toBe(true);
        expect(requested.some((url) => url.includes('/sirsoft-basic/settings/settings.css'))).toBe(true);
        expect(document.getElementById('ext-bundle-js-template-settings-sirsoft-basic')).not.toBeNull();
        // 설정 묶음은 핸들러를 등록하지 않는다 — 실패가 「확장 핸들러를 기다리지 않는다」 판정(troubleshooting-network 사례 3)을 켜면 안 된다
        expect(loader.hasFailedJsAssets()).toBe(false);
        // 그 설정 화면만 비므로 자산 실패 안내로 알린다 — 다시 시도할 수 있게
        const notice = getAssetFailures().find((failure) => failure.id === 'template-settings-broken');
        expect(notice?.label).toBe('템플릿 설정 화면');
        expect(notice?.retry).toBeTypeOf('function');
    });

    // @scenario case=settings_bundle_load
    // @effects settings_bundles_loaded_on_admin
    it('사용자 화면처럼 목록이 비면 아무것도 싣지 않는다', async () => {
        stub();
        (window as any).G7Config = { templateSettingsAssets: [] };

        await loader.loadTemplateSettingsBundles();

        expect(requested).toEqual([]);
    });
});
