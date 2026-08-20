/**
 * 자산 URL 빌더(프론트) 가드 — 이슈 #486 단위 C.
 *
 * 단위 C 의 회귀 조건은 "기본 모드에서 치환 이전 URL 문자열과 바이트 동일" 이다.
 * 아래 확장자 모드 기대값은 치환 이전 소스에 하드코딩되어 있던 문자열을 그대로 옮긴 것으로,
 * 빌더 도입이 URL 을 한 글자도 바꾸지 않았음을 고정한다.
 *
 * 서버측 `App\Support\AssetUrl` 와 규칙이 일치해야 한다 — 한쪽만 바뀌면 그 자산만 404 가 된다.
 */

// e2e:allow 순수 URL 문자열 생성 유닛. 브라우저 거동은 단위 D(자가 복구) spec 이 커버한다.

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import {
    MODE_EXTENSION,
    MODE_EXTENSIONLESS,
    getAssetUrlMode,
    isExtensionless,
    setAssetUrlMode,
    restoreCachedMode,
    templateAsset,
    moduleAsset,
    pluginAsset,
    extensionBundle,
    suffixed,
    layoutUrl,
    layoutPreviewUrl,
} from '../assetUrl';

/**
 * G7Config 를 지정 모드로 초기화합니다.
 */
function setConfig(mode?: string, cacheVersion = 7): void {
    // 자가 복구가 기록하는 독립 전역까지 초기화한다 — 남아 있으면 이후 케이스가
    // 앞 케이스의 전환 결과를 물려받아 거짓 실패/거짓 통과가 난다.
    delete (globalThis as any).__g7AssetUrlMode;

    (globalThis as any).G7Config = {
        cache_version: cacheVersion,
        ...(mode ? { assetUrlMode: mode } : {}),
    };
}

describe('assetUrl (프론트 자산 URL 빌더)', () => {
    beforeEach(() => {
        globalThis.localStorage?.clear();
        setConfig(MODE_EXTENSION);
    });

    afterEach(() => {
        delete (globalThis as any).G7Config;
        delete (globalThis as any).__g7AssetUrlMode;
        globalThis.localStorage?.clear();
    });

    describe('모드 판정', () => {
        it('G7Config 부재 시 기본 모드는 확장자 유지', () => {
            delete (globalThis as any).G7Config;

            expect(getAssetUrlMode()).toBe(MODE_EXTENSION);
            expect(isExtensionless()).toBe(false);
        });

        it('런타임 키가 설정값보다 우선한다', () => {
            (globalThis as any).G7Config = {
                assetUrlMode: MODE_EXTENSIONLESS,
                settings: { general: { asset_url_mode: MODE_EXTENSION } },
            };

            expect(getAssetUrlMode()).toBe(MODE_EXTENSIONLESS);
        });

        it('런타임 키가 없으면 설정값을 따른다', () => {
            (globalThis as any).G7Config = {
                settings: { general: { asset_url_mode: MODE_EXTENSIONLESS } },
            };

            expect(getAssetUrlMode()).toBe(MODE_EXTENSIONLESS);
        });

        it('알 수 없는 값은 기본 모드로 폴백한다', () => {
            (globalThis as any).G7Config = { assetUrlMode: 'nonsense' };

            expect(getAssetUrlMode()).toBe(MODE_EXTENSION);
        });
    });

    describe('확장자 모드 — 치환 이전 문자열과 바이트 동일', () => {
        it('Router.ts:113 routes.json (버전 있음/없음)', () => {
            expect(suffixed('/api/templates/sirsoft-basic/routes', 'json', 7)).toBe(
                '/api/templates/sirsoft-basic/routes.json?v=7',
            );
            expect(suffixed('/api/templates/sirsoft-basic/routes', 'json', null)).toBe(
                '/api/templates/sirsoft-basic/routes.json',
            );
        });

        it('ComponentRegistry.ts:250 components.json', () => {
            expect(suffixed('/api/templates/sirsoft-basic/components', 'json')).toBe(
                '/api/templates/sirsoft-basic/components.json',
            );
        });

        it('TemplateApp.ts:2810 config.json + 캐시버스트 쿼리', () => {
            expect(suffixed('/api/templates/sirsoft-basic/config', 'json', null, '_=1699999999')).toBe(
                '/api/templates/sirsoft-basic/config.json?_=1699999999',
            );
        });

        it('LayoutLoader.ts:750,752 레이아웃 / 미리보기', () => {
            expect(layoutUrl('sirsoft-basic', 'home', 7)).toBe('/api/layouts/sirsoft-basic/home.json?v=7');
            expect(layoutPreviewUrl('abc-def')).toBe('/api/layouts/preview/abc-def.json');
        });

        it('편집기 문서 로드 — with_source_meta 가 v 보다 앞 (캐시 키 보존)', () => {
            expect(
                suffixed('/api/layouts/sirsoft-basic/home', 'json', '7.3', 'with_source_meta=1'),
            ).toBe('/api/layouts/sirsoft-basic/home.json?with_source_meta=1&v=7.3');
        });

        it('자산 URL — 템플릿/모듈/플러그인', () => {
            expect(templateAsset('sirsoft-basic', 'js/components.iife.js', 7)).toBe(
                '/api/templates/assets/sirsoft-basic/js/components.iife.js?v=7',
            );
            expect(moduleAsset('sirsoft-ecommerce', 'dist/js/module.iife.js', 7)).toBe(
                '/api/modules/assets/sirsoft-ecommerce/dist/js/module.iife.js?v=7',
            );
            expect(pluginAsset('sirsoft-gdpr', 'dist/css/plugin.css')).toBe(
                '/api/plugins/assets/sirsoft-gdpr/dist/css/plugin.css',
            );
        });

        it('병합 번들', () => {
            expect(extensionBundle('modules', 'js', 7)).toBe('/api/modules/bundle.js?v=7');
            expect(extensionBundle('plugins', 'css', 7)).toBe('/api/plugins/bundle.css?v=7');
        });
    });

    describe('확장자 없는 모드', () => {
        beforeEach(() => setConfig(MODE_EXTENSIONLESS));

        it('고정 접미사를 제거한다', () => {
            expect(suffixed('/api/templates/sirsoft-basic/routes', 'json', 7)).toBe(
                '/api/templates/sirsoft-basic/routes?v=7',
            );
            expect(layoutUrl('sirsoft-basic', 'home', 7)).toBe('/api/layouts/sirsoft-basic/home?v=7');
        });

        it('자산 경로를 file 쿼리로 옮긴다', () => {
            expect(templateAsset('sirsoft-basic', 'js/components.iife.js', 7)).toBe(
                '/api/templates/assets/sirsoft-basic?file=js%2Fcomponents.iife.js&v=7',
            );
        });

        it('번들 접미사를 경로 세그먼트로 내린다', () => {
            expect(extensionBundle('modules', 'js', 7)).toBe('/api/modules/bundle/js?v=7');
            expect(extensionBundle('plugins', 'css')).toBe('/api/plugins/bundle/css');
        });

        it('생성된 모든 URL 의 경로에 정적 확장자가 남지 않는다', () => {
            const urls = [
                suffixed('/api/templates/t/routes', 'json', 7),
                layoutUrl('t', 'home', 7),
                layoutPreviewUrl('abc'),
                templateAsset('t', 'js/a.js', 7),
                moduleAsset('m', 'dist/js/a.js'),
                pluginAsset('p', 'dist/css/a.css'),
                extensionBundle('modules', 'js', 7),
            ];

            for (const url of urls) {
                const path = url.split('?')[0];
                expect(path, `경로에 정적 확장자 잔존: ${url}`).not.toMatch(/\.(js|mjs|css|json|map)$/i);
            }
        });
    });

    describe('런타임 전환 (§12 L1 — 단방향 1회)', () => {
        it('extension → extensionless 전환은 허용된다', () => {
            expect(setAssetUrlMode(MODE_EXTENSIONLESS)).toBe(true);
            expect(getAssetUrlMode()).toBe(MODE_EXTENSIONLESS);
        });

        it('역방향 전환은 거부된다 (무한 왕복 차단)', () => {
            setAssetUrlMode(MODE_EXTENSIONLESS);

            expect(setAssetUrlMode(MODE_EXTENSION as any)).toBe(false);
            expect(getAssetUrlMode()).toBe(MODE_EXTENSIONLESS);
        });

        it('이미 전환된 상태에서 재전환은 false 를 반환한다 (1회 제한)', () => {
            expect(setAssetUrlMode(MODE_EXTENSIONLESS)).toBe(true);
            expect(setAssetUrlMode(MODE_EXTENSIONLESS)).toBe(false);
        });
    });

    describe('localStorage 캐시 (§12 L7)', () => {
        it('전환 결과가 캐시되어 재방문 시 복원된다', () => {
            setAssetUrlMode(MODE_EXTENSIONLESS);

            setConfig(MODE_EXTENSION);
            expect(restoreCachedMode()).toBe(true);
            expect(getAssetUrlMode()).toBe(MODE_EXTENSIONLESS);
        });

        it('cache_version 이 바뀌면 옛 캐시가 무시된다', () => {
            setAssetUrlMode(MODE_EXTENSIONLESS);

            setConfig(MODE_EXTENSION, 8);
            expect(restoreCachedMode()).toBe(false);
            expect(getAssetUrlMode()).toBe(MODE_EXTENSION);
        });

        it('TTL 을 넘긴 캐시는 무시되고 제거된다', () => {
            const stale = JSON.stringify({ mode: MODE_EXTENSIONLESS, at: Date.now() - 25 * 60 * 60 * 1000 });
            globalThis.localStorage.setItem('g7_asset_url_mode:7', stale);

            setConfig(MODE_EXTENSION);
            expect(restoreCachedMode()).toBe(false);
            expect(globalThis.localStorage.getItem('g7_asset_url_mode:7')).toBeNull();
        });
    });
});
