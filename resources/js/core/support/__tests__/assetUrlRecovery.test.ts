// e2e:allow 테스트 전용 수정 — 프로브 판정 함수 추출 리팩토링 후 stale 앵커(detect→probe) 정정. 런타임 동작 무변경이며 대상 기능 E2E 는 tests/Playwright/specs/asset-url-mode.spec.ts 로 이미 커버됨.
/**
 * 자산 URL 자가 복구 불변식 가드.
 *
 * 자가 복구는 "실패했으니 다시 시도한다" 는 구조라 루프 위험이 내재한다.
 * 계획서 §12 는 이를 구현 불변식으로 못박았고, 각각에 대응 테스트를 요구한다.
 * 여기서는 브라우저 없이 검증 가능한 불변식(L1·L5·L7)과, 셸 head 복구기와
 * TS 빌더의 **규칙 드리프트**를 다룬다. 요청 횟수·리로드·폴백 UI(L2·L3·L4·L8)는
 * 브라우저 계층이므로 Playwright spec 이 담당한다.
 *
 * @effects public_storage_asset_url_conversion_matches_the_route_form
 */

// e2e:allow 불변식의 순수 로직 단위. 브라우저 계층(L2·L3·L4·L8)은 asset-url-mode.spec.ts 가 커버한다.

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { readFileSync, readdirSync } from 'node:fs';
import { resolve, join } from 'node:path';
import {
    MODE_EXTENSION,
    MODE_EXTENSIONLESS,
    getAssetUrlMode,
    setAssetUrlMode,
    convertToCurrentMode,
    staticToLegacy,
} from '../assetUrl';

/**
 * 셸 head 스크립트(public/assets/core/js/g7-shell-head.js — 종전 blade 인라인, #158)의 `toExtensionless` 를 추출해 실행 가능한 함수로 만든다.
 *
 * 부트스트랩은 코어 번들 로드 전에 동작해야 해서 import 를 쓸 수 없고, 그래서 변환 규칙이
 * 셸 head 스크립트에 한 벌 더 존재한다. 두 구현이 갈라지면 서버가 만든 URL 과
 * 클라이언트가 만든 URL 이 어긋나 그 자산만 404 가 되므로, 실제 셸 소스에서
 * 함수를 뽑아 TS 구현과 대조한다.
 */
function loadInlineConverter(basePath = ''): (url: string) => string | null {
    const shellPath = resolve(__dirname, '../../../../../public/assets/core/js/g7-shell-head.js');
    const source = readFileSync(shellPath, 'utf-8');

    /**
     * 셸 head 스크립트에서 함수 하나를 균형 중괄호로 잘라낸다.
     *
     * 변환기가 스크립트 안의 다른 함수를 부르면 그것도 함께 주입해야 셸 스코프가 재현된다.
     * 이름을 열거하는 대신 이 헬퍼로 뽑아 이어 붙인다 — 하나만 잘라 오면 그 호출이
     * `is not defined` 로 죽고, 그것은 「셸 구현이 틀렸다」와 구분되지 않는다.
     *
     * @param signature 함수 시그니처 시작 문자열
     * @returns 함수 소스
     */
    function sliceFunction(signature: string): string {
        const start = source.indexOf(signature);
        expect(start, `셸 head 스크립트에서 ${signature} 를 찾지 못했다`).toBeGreaterThan(-1);

        let depth = 0;
        let end = start;
        for (let i = source.indexOf('{', start); i < source.length; i += 1) {
            if (source[i] === '{') depth += 1;
            else if (source[i] === '}') {
                depth -= 1;
                if (depth === 0) {
                    end = i + 1;
                    break;
                }
            }
        }

        return source.slice(start, end);
    }

    const fnSource = [
        sliceFunction('function encodeFileQueryValue(filePath) {'),
        sliceFunction('function normalizeBasePath(value) {'),
        sliceFunction('function toExtensionless(url) {'),
    ].join('\n');

    // 함수가 참조하는 파샬 상단 상수를 함께 주입한다 (셸 스코프 재현)
    // eslint-disable-next-line no-new-func
    return new Function(
        'basePath',
        `var FILE_QUERY_PARAM = 'file';
var BASE_PATH = basePath;\n${fnSource}\nreturn toExtensionless;`,
    )(basePath) as (url: string) => string | null;
}

describe('자산 URL 자가 복구 불변식 (§12)', () => {
    beforeEach(() => {
        delete (globalThis as any).__g7AssetUrlMode;
        (globalThis as any).G7Config = { cache_version: 7 };
        globalThis.localStorage?.clear();
    });

    afterEach(() => {
        delete (globalThis as any).__g7AssetUrlMode;
        delete (globalThis as any).G7Config;
        globalThis.localStorage?.clear();
    });

    describe('L1 — 전환은 단방향 1회', () => {
        // @scenario asset_url_mode=extensionless
        // @effects frontend_asset_url_mode_switch_is_one_way_and_once
        /** @effects asset_url_mode_switch_one_way */
        it('extension → extensionless 는 1회만 성공한다', () => {
            expect(setAssetUrlMode(MODE_EXTENSIONLESS)).toBe(true);
            expect(setAssetUrlMode(MODE_EXTENSIONLESS)).toBe(false);
        });

        // @scenario asset_url_mode=extensionless
        // @effects frontend_asset_url_mode_switch_is_one_way_and_once
        /** @effects asset_url_mode_switch_one_way */
        it('역방향 전환 경로가 존재하지 않는다', () => {
            setAssetUrlMode(MODE_EXTENSIONLESS);

            expect(setAssetUrlMode(MODE_EXTENSION as any)).toBe(false);
            expect(getAssetUrlMode()).toBe(MODE_EXTENSIONLESS);
        });

        // @scenario asset_url_mode=extensionless
        // @effects frontend_asset_url_mode_switch_is_one_way_and_once
        /** @effects asset_url_mode_switch_one_way */
        it('G7Config 가 재대입되어도 전환 결과가 유지된다', () => {
            setAssetUrlMode(MODE_EXTENSIONLESS);

            // <body> 의 `window.G7Config = {...}` 재대입 모사
            (globalThis as any).G7Config = { cache_version: 7, assetUrlMode: MODE_EXTENSION };

            expect(getAssetUrlMode()).toBe(MODE_EXTENSIONLESS);
        });
    });

    describe('L5 — 클라이언트는 서버 설정을 쓰지 않는다', () => {
        // @scenario asset_url_mode=extensionless
        // @effects asset_url_mode_switch_leaves_server_settings_untouched
        /** @effects asset_url_mode_client_never_writes_settings */
        it('전환이 settings 원본을 변경하지 않는다', () => {
            (globalThis as any).G7Config = {
                cache_version: 7,
                settings: { general: { asset_url_mode: MODE_EXTENSION } },
            };

            setAssetUrlMode(MODE_EXTENSIONLESS);

            expect((globalThis as any).G7Config.settings.general.asset_url_mode).toBe(MODE_EXTENSION);
        });
    });

    describe('L7 — 캐시는 cache_version 을 포함하고 TTL 을 둔다', () => {
        // @scenario asset_url_mode=extensionless
        // @effects frontend_asset_url_mode_cache_is_scoped_by_cache_version_and_ttl
        /** @effects asset_url_mode_cache_key_versioned */
        it('캐시 키에 cache_version 이 포함된다', () => {
            setAssetUrlMode(MODE_EXTENSIONLESS);

            expect(globalThis.localStorage.getItem('g7_asset_url_mode:7')).not.toBeNull();
        });
    });

    describe('변환 규칙 — 셸 head 복구기와 TS 빌더 동등성', () => {
        const cases = [
            '/api/templates/assets/sirsoft-basic/js/components.iife.js?v=7',
            '/api/templates/assets/sirsoft-basic/css/components.css?v=7',
            '/api/modules/assets/sirsoft-ecommerce/dist/js/module.iife.js?v=7',
            '/api/plugins/assets/sirsoft-gdpr/dist/css/plugin.css',
            '/api/modules/bundle.js?v=7',
            '/api/plugins/bundle.css',
            '/api/templates/sirsoft-basic/routes.json?v=7',
            '/api/layouts/sirsoft-basic/home.json?with_source_meta=1&v=7',
            // 공개 스토리지 대체 서빙(B-3) — 링크가 없는 서버에서 `/storage/...` 를 대신한다.
            // 두 구현 모두 이 규칙이 **없었고**, 대조 목록에도 없어서 「양쪽이 같이 틀린」
            // 상태가 초록으로 통과했다. `.css` 형태는 특히 위험했다 — 접미사 제거 규칙에
            // 먼저 걸려 `.../theme` 으로 오변환됐다(그 라우트의 무확장자 형태는 `?file=` 이다).
            '/api/system/public-storage/uploads/logo.png',
            '/api/system/public-storage/theme/custom.css?v=7',
        ];

        // @scenario asset_url_mode=extensionless, base_path=root
        // @effects blade_inline_recovery_converter_matches_ts_builder
        /** @effects shell_head_converter_matches_ts_builder */
        it('모든 대표 URL 에서 두 구현의 결과가 동일하다', () => {
            const inline = loadInlineConverter();
            (globalThis as any).__g7AssetUrlMode = MODE_EXTENSIONLESS;

            for (const url of cases) {
                expect(inline(url), `셸 head 변환기가 ${url} 을 변환하지 못했다`).not.toBeNull();
                expect(inline(url), `변환 규칙 드리프트: ${url}`).toBe(convertToCurrentMode(url));
            }
        });

        // @scenario asset_url_mode=extensionless, base_path=root
        // @effects public_storage_asset_url_conversion_matches_the_route_form
        it('공개 스토리지는 접미사 제거가 아니라 ?file= 로 변환된다 (규칙 순서)', () => {
            // 두 구현이 **같이** 틀리면 위의 드리프트 대조는 초록이다. 그래서 결과값 자체를
            // 잠근다 — 접미사 제거 규칙이 먼저 걸리면 `/api/system/public-storage/theme/custom.css` 가 `/api/system/public-storage/theme/custom` 이 되고,
            // 그 주소에는 파일이 없어 404 가 된다(그 라우트의 무확장자 형태는 `?file=` 이다).
            const inline = loadInlineConverter();
            (globalThis as any).__g7AssetUrlMode = MODE_EXTENSIONLESS;

            const cssUrl = '/api/system/public-storage/theme/custom.css?v=7';
            const expected = '/api/system/public-storage?file=theme%2Fcustom.css&v=7';

            expect(convertToCurrentMode(cssUrl)).toBe(expected);
            expect(inline(cssUrl)).toBe(expected);
        });

        // @scenario asset_url_mode=extensionless, base_path=root
        // @effects public_storage_asset_url_conversion_matches_the_route_form, blade_inline_recovery_converter_matches_ts_builder
        it('경로에 리터럴 %가 있어도 던지지 않는다', () => {
            // 서버의 확장자 모드는 경로를 **인코딩하지 않는다**(형제 `AssetUrl::asset()` 과
            // 동형이고 정적 `/storage/…` 도 그렇다). 그래서 `50%off.png` 같은 파일명이 그대로
            // 실려 오는데, `decodeURIComponent('50%off.png')` 는 `URIError` 를 던진다.
            //
            // 두 구현이 **똑같이** 던지므로 위의 드리프트 대조는 초록이다 — 앞서 기록한
            // 「양쪽이 같이 틀려서 초록」이 다른 입력에서 그대로 재현된 자리다. 던지는 지점은
            // 모듈 자산 로딩 전체(ModuleAssetLoader)와 CSS 자가 복구 핸들러라, 그 페이지의
            // 확장 자산이 통째로 멈추거나 스타일이 조용히 사라진다.
            const inline = loadInlineConverter();
            (globalThis as any).__g7AssetUrlMode = MODE_EXTENSIONLESS;

            const raw = [
                '/api/system/public-storage/uploads/50%off.png',
                '/api/templates/assets/sirsoft-basic/css/100%wide.css',
            ];

            for (const url of raw) {
                expect(() => convertToCurrentMode(url), `TS 구현이 ${url} 에서 던진다`).not.toThrow();
                expect(() => inline(url), `셸 head 구현이 ${url} 에서 던진다`).not.toThrow();
                expect(inline(url), `변환 규칙 드리프트: ${url}`).toBe(convertToCurrentMode(url));
            }

            // 던지지 않는 것으로 끝내지 않는다 — 서버가 그 파일에 붙이는 이름과 같아야 한다.
            // `rawurlencode('uploads/50%off.png')` = `uploads%2F50%25off.png`.
            expect(convertToCurrentMode('/api/system/public-storage/uploads/50%off.png'))
                .toBe('/api/system/public-storage?file=uploads%2F50%25off.png');

            // 이미 인코딩된 경로는 이중 인코딩하지 않는다 (기존 동작 보존).
            expect(convertToCurrentMode('/api/system/public-storage/uploads/%EC%82%AC%EC%A7%84.png'))
                .toBe('/api/system/public-storage?file=uploads%2F%EC%82%AC%EC%A7%84.png');
        });

        /**
         * 사이트가 하위 폴더에 마운트된 배치(`https://host/g7/`)에서도 두 구현이 같은 규칙을 쓴다.
         *
         * 서버는 그 배치에서 `/g7/api/…` 를 만든다. 변환기가 접두사를 벗기지 않으면 어느
         * 규칙에도 매치되지 않아 **자가 복구가 통째로 무력**해지고, 붙여 주지 않으면 변환
         * 결과가 사이트 밖(`/api/…`)을 가리킨다. 값의 출처는 서버 하나다.
         *
         * @effects asset_url_recovery_follows_the_site_base_path_in_both_implementations
         */
        it('하위 폴더 마운트에서 두 구현이 접두사를 벗기고 다시 붙인다', () => {
            const basePath = '/g7';
            const inline = loadInlineConverter(basePath);
            (globalThis as any).__g7AssetUrlMode = MODE_EXTENSIONLESS;
            (globalThis as any).G7Config = { cache_version: 7, basePath };

            const pairs: Array<[string, string]> = [
                ['/g7/api/system/public-storage/uploads/logo.png', '/g7/api/system/public-storage?file=uploads%2Flogo.png'],
                ['/g7/api/templates/assets/sirsoft-basic/dist/js/a.js', '/g7/api/templates/assets/sirsoft-basic?file=dist%2Fjs%2Fa.js'],
                ['/g7/api/modules/bundle.js', '/g7/api/modules/bundle/js'],
            ];

            for (const [input, expected] of pairs) {
                expect(convertToCurrentMode(input), `TS: ${input}`).toBe(expected);
                expect(inline(input), `셸 head 드리프트: ${input}`).toBe(expected);
            }

            // 접두사 밖 주소는 대상이 아니다 — 다른 사이트를 가리키는 URL 을 건드리지 않는다.
            expect(convertToCurrentMode('/other/api/modules/bundle.js')).toBe('/other/api/modules/bundle.js');
            expect(inline('/other/api/modules/bundle.js')).toBeNull();
        });

        /**
         * 접두사 값이 **정규형이 아닐 때**도 두 구현이 같은 규칙을 쓴다.
         *
         * 종전 대조는 양쪽에 이미 정규화된 같은 값(`'/g7'`)을 주입해서 이 축을 원리상 볼
         * 수 없었다 — TS 판본만 `normalizeBasePath` 를 거치고 셸 판본은 주입값을 그대로
         * 이어 붙이는 비대칭이 그대로 남아 있었다. 루트 설치의 `'/'` 가 특히 나쁘다:
         * 정규화 없이 이어 붙이면 `//api/…` 가 되어 **프로토콜 상대 URL**(다른 호스트)이 된다.
         *
         * @effects asset_url_recovery_follows_the_site_base_path_in_both_implementations
         */
        it('정규화되지 않은 접두사 값에도 두 구현이 같은 결과를 낸다', () => {
            (globalThis as any).__g7AssetUrlMode = MODE_EXTENSIONLESS;

            for (const raw of ['/g7', '/g7/', 'g7']) {
                const inline = loadInlineConverter(raw);
                (globalThis as any).G7Config = { cache_version: 7, basePath: raw };

                expect(convertToCurrentMode('/g7/api/modules/bundle.js'), `TS: ${raw}`)
                    .toBe('/g7/api/modules/bundle/js');
                expect(inline('/g7/api/modules/bundle.js'), `셸 head 드리프트: ${raw}`)
                    .toBe('/g7/api/modules/bundle/js');
            }

            // 루트 설치를 `'/'` 로 표현한 값은 「접두사 없음」과 같아야 한다.
            const rootInline = loadInlineConverter('/');
            (globalThis as any).G7Config = { cache_version: 7, basePath: '/' };

            expect(convertToCurrentMode('/api/modules/bundle.js')).toBe('/api/modules/bundle/js');
            expect(rootInline('/api/modules/bundle.js'), '`//api/…` 는 다른 호스트를 가리킨다')
                .toBe('/api/modules/bundle/js');
        });

        // @scenario asset_url_mode=extensionless, base_path=root
        // @effects core_engine_bundle_is_never_converted_by_recovery
        /** @effects shell_head_converter_matches_ts_builder */
        it('코어 엔진 번들은 두 구현 모두 변환하지 않는다', () => {
            const inline = loadInlineConverter();
            (globalThis as any).__g7AssetUrlMode = MODE_EXTENSIONLESS;

            const coreBundle = '/build/core/template-engine.min.js?v=123';

            // public/ 의 실물 정적 파일 — 변환하면 오히려 깨진다
            expect(inline(coreBundle)).toBeNull();
            expect(convertToCurrentMode(coreBundle)).toBe(coreBundle);
        });

        // @scenario asset_url_mode=extension, base_path=root
        // @effects extension_mode_recovery_conversion_is_identity
        /** @effects shell_head_converter_matches_ts_builder */
        it('확장자 모드에서는 TS 빌더가 원본을 그대로 돌려준다', () => {
            (globalThis as any).__g7AssetUrlMode = undefined;
            (globalThis as any).G7Config = { cache_version: 7, assetUrlMode: MODE_EXTENSION };

            for (const url of cases) {
                expect(convertToCurrentMode(url)).toBe(url);
            }
        });
    });

    describe('정적 게시 역변환 — 셸 head 복구기와 TS 구현 동등성 (#122 F15)', () => {
        /**
         * 셸 head 스크립트(public/assets/core/js/g7-shell-head.js — 종전 blade 인라인, #158)의 `staticToLegacy` 를 추출해 TS `staticToLegacy` 와 대조한다.
         * 역변환 규칙은 서버측 `AssetUrl` 의 정적 게시 트리 규약과 1:1 이어야 하며,
         * 두 사본이 갈라지면 태그 계층의 서빙 시점 404 복구가 그 자산만 조용히 죽는다.
         *
         * @effects static_asset_tag_recovers_to_api_url
         */
        function loadInlineStaticToLegacy(basePath = ''): (url: string) => string | null {
            const shellPath = resolve(__dirname, '../../../../../public/assets/core/js/g7-shell-head.js');
            const source = readFileSync(shellPath, 'utf-8');

            const slice = (signature: string): string => {
                const start = source.indexOf(signature);
                expect(start, `셸 head 스크립트에서 ${signature} 를 찾지 못했다`).toBeGreaterThan(-1);

                let depth = 0;
                let end = start;
                for (let i = source.indexOf('{', start); i < source.length; i += 1) {
                    if (source[i] === '{') depth += 1;
                    else if (source[i] === '}') {
                        depth -= 1;
                        if (depth === 0) {
                            end = i + 1;
                            break;
                        }
                    }
                }

                return source.slice(start, end);
            };

            // 역변환기가 부르는 셸 스코프(접두사 정규화·서버 접두사 값)를 함께 주입한다.
            // eslint-disable-next-line no-new-func
            return new Function(
                'basePath',
                `var BASE_PATH = basePath;\n${slice('function normalizeBasePath(value) {')}\n${slice('function staticToLegacy(url) {')}\nreturn staticToLegacy;`,
            )(basePath) as (url: string) => string | null;
        }

        const staticCases = [
            '/build/ext/1234/templates/sirsoft-basic/assets/css/components.css',
            '/build/ext/1234/templates/sirsoft-basic/assets/js/components.iife.js',
            '/build/ext/1234/bundles/modules.js',
            '/build/ext/1234/bundles/plugins.css',
            '/build/ext/1234/templates/sirsoft-basic/routes.json',
            '/build/ext/1234/templates/sirsoft-basic/components.json',
            '/build/ext/1234/templates/sirsoft-basic/lang/ko.json',
            // 운영자 추가 에셋 — 모듈·플러그인도 `{type}/{id}/assets/**` 로 게시된다.
            // 이 케이스가 없으면 한 타입만 역변환하는 드리프트가 잡히지 않는다.
            '/build/ext/1234/templates/sirsoft-basic/assets/custom/custom.css',
            '/build/ext/1234/modules/sirsoft-page/assets/custom/10-override.css',
            '/build/ext/1234/plugins/sirsoft-gdpr/assets/custom/custom.js',
        ];

        // @scenario publish_state=partial, environment=production
        // @effects static_asset_tag_recovers_to_api_url
        /** @effects static_asset_tag_recovers_to_api_url */
        it('모든 정적 게시 URL 에서 두 구현의 결과가 동일하다', () => {
            const inline = loadInlineStaticToLegacy();

            for (const url of staticCases) {
                expect(inline(url), `셸 head 역변환기가 ${url} 을 변환하지 못했다`).not.toBeNull();
                expect(inline(url), `역변환 규칙 드리프트: ${url}`).toBe(staticToLegacy(url));
            }
        });

        // @scenario publish_state=partial, environment=production
        // @effects static_asset_tag_recovers_to_api_url
        /** @effects static_asset_tag_recovers_to_api_url */
        it('정적 게시 경로가 아닌 URL 은 두 구현 모두 변환하지 않는다', () => {
            const inline = loadInlineStaticToLegacy();

            for (const url of ['/build/core/template-engine.min.js', '/api/templates/t/routes.json', '']) {
                expect(inline(url)).toBeNull();
                expect(staticToLegacy(url)).toBeNull();
            }
        });

        /**
         * 하위 폴더 설치에서 정적 게시본은 `/g7/build/ext/{v}/…` 로 나간다.
         *
         * 역변환기가 접두사를 벗기지 않으면 그 실패 URL 을 몰라보고(복구 없음), 벗기기만 하고
         * 결과에 다시 붙이지 않으면 `/api/…` 가 사이트 밖을 가리켜 복구 시도가 또 404 가 된다.
         * 첫 방문의 템플릿 CSS 가 그 경로였다 — 정적 404 → 접두사 없는 API 404 → 서식 없는 화면.
         * 접두사 없이 남은 옛 HTML 의 정적 URL 도 같은 사이트의 API 로 되돌린다.
         *
         * @effects static_publish_recovery_keeps_the_site_base_prefix
         */
        // @scenario base_path=subpath, asset_url_mode=extension
        // @effects static_publish_recovery_keeps_the_site_base_prefix
        it('하위 폴더 마운트에서 두 구현이 접두사를 벗겨 판정하고 결과에 다시 붙인다', () => {
            const basePath = '/g7';
            const inline = loadInlineStaticToLegacy(basePath);
            (globalThis as any).G7Config = { cache_version: 7, basePath };

            const pairs: Array<[string, string]> = [
                [
                    '/g7/build/ext/1234/templates/sirsoft-basic/assets/css/components.css',
                    '/g7/api/templates/assets/sirsoft-basic/css/components.css?v=1234',
                ],
                [
                    '/g7/build/ext/1234/templates/sirsoft-admin_basic/assets/vendor/font-awesome/6.4.0/css/all.inlined.css',
                    '/g7/api/templates/assets/sirsoft-admin_basic/vendor/font-awesome/6.4.0/css/all.inlined.css?v=1234',
                ],
                ['/g7/build/ext/1234/bundles/modules.js', '/g7/api/modules/bundle.js?v=1234'],
                ['/g7/build/ext/1234/bundles/plugins.css', '/g7/api/plugins/bundle.css?v=1234'],
                ['/g7/build/ext/1234/templates/sirsoft-basic/routes.json', '/g7/api/templates/sirsoft-basic/routes.json?v=1234'],
                ['/g7/build/ext/1234/templates/sirsoft-basic/lang/ko.json', '/g7/api/templates/sirsoft-basic/lang/ko.json?v=1234'],
                ['/g7/build/ext/1234/modules/sirsoft-page/assets/custom/a.css', '/g7/api/modules/assets/sirsoft-page/custom/a.css?v=1234'],
                // 같은 사이트의 절대 URL(DOM 프로퍼티 형태)
                [
                    `${window.location.origin}/g7/build/ext/1234/bundles/modules.js`,
                    '/g7/api/modules/bundle.js?v=1234',
                ],
                // 접두사 없이 남은 옛 HTML 의 정적 URL — 같은 사이트의 API 로 되돌린다
                ['/build/ext/1234/bundles/modules.js', '/g7/api/modules/bundle.js?v=1234'],
            ];

            for (const [input, expected] of pairs) {
                expect(staticToLegacy(input), `TS: ${input}`).toBe(expected);
                expect(inline(input), `셸 head 드리프트: ${input}`).toBe(expected);
            }

            // 접두사가 경계 없이 이어진 다른 경로·다른 사이트의 게시본은 대상이 아니다
            for (const url of ['/g7x/build/ext/1234/bundles/modules.js', '/other/build/ext/1234/bundles/modules.js', '/g7/build/core/template-engine.min.js']) {
                expect(staticToLegacy(url), `TS: ${url}`).toBeNull();
                expect(inline(url), `셸 head: ${url}`).toBeNull();
            }
        });

        // @scenario base_path=root, asset_url_mode=extension
        // @effects static_publish_recovery_keeps_the_site_base_prefix
        it('루트 설치에서는 두 구현이 종전 문자열을 낸다 (접두사 `/` 표기 포함)', () => {
            for (const raw of ['', '/']) {
                const inline = loadInlineStaticToLegacy(raw);
                (globalThis as any).G7Config = { cache_version: 7, basePath: raw };

                expect(staticToLegacy('/build/ext/1234/bundles/modules.js')).toBe('/api/modules/bundle.js?v=1234');
                expect(inline('/build/ext/1234/bundles/modules.js'), '`//api/…` 는 다른 호스트다')
                    .toBe('/api/modules/bundle.js?v=1234');
            }
        });
    });

    describe('정적 게시 역변환 × 자산 URL 모드 (#122 F15 — 확장자 404 서버)', () => {
        /**
         * 확장자 형태를 404 로 돌려주는 서버(이 복구의 대상)에서는 역변환 결과인
         * `/api/…/style.css` 가 **다시** 404 다. `recoverStylesheet` 는 링크당 1회만
         * 교체하므로, 그 한 번이 서빙 불가능한 형태로 끝나면 `toExtensionless` 가
         * 실행될 기회 없이 CSS 가 영구히 붙지 않는다. `<script>` 는 재시도 예산이
         * 남아 다음 시도에서 자연 복구되지만 `<link>` 는 예산이 1회다.
         *
         * @effects static_asset_tag_recovers_to_api_url
         */
        function loadPartialApi(): any {
            // 셸 head 스크립트(종전 blade 인라인 — #158)를 그대로 실행한다. 서버 값
            // (`#g7-shell-head-data`)이 없으면 cacheVersion 0, 마운트 접두사는 빈 문자열(루트 배치)로
            // 동작한다 — 아래 케이스들의 URL 이 모두 접두사 없는 형태라 그 전제와 맞는다.
            const shellPath = resolve(__dirname, '../../../../../public/assets/core/js/g7-shell-head.js');
            const js = readFileSync(shellPath, 'utf-8');

            // eslint-disable-next-line no-new-func
            new Function(js)();

            return (globalThis as any).window.__g7AssetUrl;
        }

        const staticHref = '/build/ext/1234/templates/sirsoft-basic/assets/css/components.css';

        beforeEach(() => {
            (globalThis as any).window.__g7AssetUrl = undefined;
            (globalThis as any).window.__g7AssetUrlMode = undefined;
        });

        // @scenario asset_url_mode=extension, publish_state=partial, environment=production
        // @effects static_asset_tag_recovers_to_api_url
        /** @effects static_asset_tag_recovers_to_api_url */
        it('확장자 모드에서는 확장자 형태 API URL 로 교체한다', () => {
            const api = loadPartialApi();

            const link = document.createElement('link');
            link.setAttribute('href', staticHref);
            api.recoverStylesheet(link);

            expect(link.getAttribute('href')).toBe(
                '/api/templates/assets/sirsoft-basic/css/components.css?v=1234',
            );
        });

        // @scenario asset_url_mode=extensionless, publish_state=partial, environment=production
        // @effects static_asset_tag_recovers_to_api_url
        /** @effects static_asset_tag_recovers_to_api_url */
        it('extensionless 모드에서는 확장자 없는 형태까지 도달한다', () => {
            (globalThis as any).window.__g7AssetUrlMode = MODE_EXTENSIONLESS;
            const api = loadPartialApi();

            const link = document.createElement('link');
            link.setAttribute('href', staticHref);
            api.recoverStylesheet(link);

            expect(
                link.getAttribute('href'),
                '확장자 형태로 끝나면 그 서버가 다시 404 를 돌려주고 재시도 예산은 이미 소진된다',
            ).toBe('/api/templates/assets/sirsoft-basic?file=css%2Fcomponents.css&v=1234');
        });

        /**
         * 첫 방문(저장소 빈 상태)의 템플릿 CSS 복구 — 하위 폴더 설치.
         *
         * 서버 값(`#g7-shell-head-data` 의 `basePath`)을 실제 셸처럼 읽게 해 둔다. 교체 예산이
         * 링크당 1회라, 그 한 번이 사이트 밖 주소로 끝나면 첫 방문 화면 전체가 서식 없이 남는다.
         */
        // @scenario base_path=subpath, asset_url_mode=extension
        // @effects static_publish_recovery_keeps_the_site_base_prefix
        it('하위 폴더 설치에서 정적 CSS 실패는 같은 사이트의 API 주소로 교체된다', () => {
            const data = document.createElement('script');
            data.type = 'application/json';
            data.id = 'g7-shell-head-data';
            data.textContent = JSON.stringify({ cacheVersion: 1234, basePath: '/g7' });
            document.head.appendChild(data);

            try {
                const api = loadPartialApi();

                const link = document.createElement('link');
                link.setAttribute('href', `/g7${staticHref}`);
                api.recoverStylesheet(link);

                expect(link.getAttribute('href')).toBe(
                    '/g7/api/templates/assets/sirsoft-basic/css/components.css?v=1234',
                );

                // 확장자 없는 형태로 확정된 브라우저도 같은 사이트 안으로 간다
                (globalThis as any).window.__g7AssetUrlMode = MODE_EXTENSIONLESS;
                const api2 = loadPartialApi();
                const link2 = document.createElement('link');
                link2.setAttribute('href', `/g7${staticHref}`);
                api2.recoverStylesheet(link2);

                expect(link2.getAttribute('href')).toBe(
                    '/g7/api/templates/assets/sirsoft-basic?file=css%2Fcomponents.css&v=1234',
                );
            } finally {
                data.remove();
            }
        });

        // @scenario asset_url_mode=extension, publish_state=partial, environment=production
        // @effects static_asset_tag_recovers_to_api_url
        /** @effects static_asset_tag_recovers_to_api_url */
        it('교체는 링크당 1회로 유지된다 (L1)', () => {
            const api = loadPartialApi();

            const link = document.createElement('link');
            link.setAttribute('href', staticHref);
            api.recoverStylesheet(link);

            const first = link.getAttribute('href');
            api.recoverStylesheet(link);

            expect(link.getAttribute('href')).toBe(first);
        });
    });

    describe('L6 — 인스톨러 프로브도 본문 토큰 + Content-Type 으로 판정', () => {
        /**
         * 프로브 판정 로직은 두 곳에 존재한다 — 관리자 핸들러(`detectAssetUrlModeHandler`)와
         * 인스톨러(`installer.js`). 전자는 동작 테스트로 red 증명까지 마쳤지만,
         * 인스톨러는 코어 번들 이전에 도는 순수 브라우저 스크립트라 vitest 하네스가 없다.
         *
         * 하네스를 새로 만드는 대신 소스 수준 드리프트 가드를 둔다 — 두 검사 중 하나라도
         * 사라지면 이 테스트가 red 가 된다. `200 + 에러 HTML` 을 반환하는 서버에서
         * 설치가 영원히 `extension` 으로 오판되는 것을 막는 검사다.
         */
        // @scenario asset_url_mode=extensionless
        // @effects installer_asset_url_probe_checks_body_token_and_content_type
        /** @effects installer_probe_checks_mime_and_token */
        it('인스톨러 프로브가 두 검사를 모두 수행한다', () => {
            const installerPath = resolve(
                __dirname,
                '../../../../../public/install/assets/js/installer.js',
            );
            const source = readFileSync(installerPath, 'utf-8');

            const start = source.indexOf('async function probeAssetUrlMode(');
            expect(start, 'installer.js 에서 probeAssetUrlMode 를 찾지 못했다').toBeGreaterThan(-1);

            // 주석은 제거하고 실행 코드만 본다 — 주석에 남은 "Content-Type" 문구가
            // 검사 삭제를 가려주면 가드가 무력해진다(실제로 겪은 오탐).
            const body = source
                .slice(start, start + 2500)
                .replace(/\/\*[\s\S]*?\*\//g, '')
                .replace(/\/\/[^\n]*/g, '');

            expect(
                /headers\s*\.\s*get\(\s*['"]content-type['"]/i.test(body),
                'L6 위반: 인스톨러 프로브가 Content-Type 을 읽지 않는다 (200+HTML 오판)',
            ).toBe(true);

            expect(
                /javascript|ecmascript/i.test(body),
                'L6 위반: 인스톨러 프로브에 스크립트 MIME 판정이 없다',
            ).toBe(true);

            expect(
                /includes\(\s*TOKEN\s*\)/.test(body),
                'L6 위반: 인스톨러 프로브에 매직 토큰 검사가 없다 (무관한 200 응답 오판)',
            ).toBe(true);
        });
    });

    describe('L9 — 감지 주체는 부트스트랩 하나', () => {
        /**
         * 엔진 레이어(Router/LayoutLoader/ComponentRegistry/TranslationEngine 등)는
         * 확정된 모드를 **읽기만** 해야 한다. 계층마다 독립적으로 재감지하면
         * 감지 캐스케이드가 중첩되어 요청이 폭증한다.
         *
         * 프로브 엔드포인트를 호출하는 코드가 엔진에 새로 들어오면 이 테스트가 잡는다.
         */
        // @scenario asset_url_mode=extensionless
        // @effects engine_layers_never_call_the_asset_url_probe
        /** @effects engine_layer_never_probes_asset_mode */
        it('엔진 소스에 프로브 엔드포인트 호출이 없다', () => {
            const engineRoot = resolve(__dirname, '../../');
            const repoRoot = resolve(__dirname, '../../../../../');

            // 프로브 경로는 **서버 라우트가 SSoT** 다. 종전에는 이 파일에 `'asset-probe'`
            // 리터럴을 적어 두었는데, 그러면 엔드포인트 이름이 바뀌는 순간 이 게이트가
            // 영구히 0건이 되고 아무도 알 수 없다 — 그 이름은 이 저장소의 프론트 소스
            // 어디에도 없어 대조할 상대가 없다. 라우트에서 도출해, 못 찾으면 그 자체가
            // red 가 되게 한다.
            const routes = readFileSync(join(repoRoot, 'routes/api.php'), 'utf-8');
            const declared = /dualSuffix\(\s*'([^']*asset-probe[^']*)'/.exec(routes);

            expect(
                declared,
                'routes/api.php 에서 프로브 라우트를 찾지 못했다 — 이름이 바뀌었다면 이 게이트가 재는 대상도 함께 옮겨야 한다',
            ).not.toBeNull();

            const token = (declared as RegExpExecArray)[1].split('/').pop() as string;

            /** 엔진 소스 한 벌이 프로브를 직접 호출하는지 판정한다. */
            const callsProbe = (src: string): boolean => src.includes(token);

            // 양성 대조 — 판정식이 실제 위반 문자열을 잡는지 확인한다. 이것이 없으면
            // 판정이 사문이 되어도(빈 토큰 등) 그대로 초록이다.
            expect(callsProbe(`fetch(suffixed('/api/system/${token}', 'js'));`)).toBe(true);
            expect(callsProbe('fetch(withBasePrefix("/api/me"));')).toBe(false);

            const offenders: string[] = [];
            let scanned = 0;

            const walk = (dir: string): void => {
                for (const entry of readdirSync(dir, { withFileTypes: true })) {
                    const full = join(dir, entry.name);

                    if (entry.isDirectory()) {
                        if (entry.name === '__tests__' || entry.name === 'node_modules') continue;
                        walk(full);

                        continue;
                    }

                    if (!/\.(ts|tsx)$/.test(entry.name)) continue;

                    scanned += 1;
                    const src = readFileSync(full, 'utf-8');
                    if (callsProbe(src)) {
                        offenders.push(full.replace(engineRoot, ''));
                    }
                }
            };

            walk(engineRoot);

            expect(
                offenders,
                `엔진 레이어가 프로브를 직접 호출한다 (L9 위반 — 감지는 부트스트랩 단독):\n${offenders.join('\n')}`,
            ).toEqual([]);
            // 모집단 대조 — 한 파일도 훑지 못하면 위 비교는 공허하다.
            expect(scanned).toBeGreaterThan(50);
        });
    });

    describe('convertToCurrentMode 변환 결과', () => {
        beforeEach(() => {
            (globalThis as any).__g7AssetUrlMode = MODE_EXTENSIONLESS;
        });

        // @scenario asset_url_mode=extensionless, base_path=root
        // @effects frontend_extensionless_mode_urls_carry_no_static_extension
        /** @effects convert_to_current_mode_shapes */
        it('자산은 file 쿼리로, 번들은 세그먼트로, 접미사는 제거로', () => {
            expect(convertToCurrentMode('/api/templates/assets/t/js/a.js?v=7')).toBe(
                '/api/templates/assets/t?file=js%2Fa.js&v=7',
            );
            expect(convertToCurrentMode('/api/modules/bundle.js?v=7')).toBe('/api/modules/bundle/js?v=7');
            expect(convertToCurrentMode('/api/templates/t/routes.json')).toBe('/api/templates/t/routes');
        });

        // @scenario asset_url_mode=extensionless, base_path=root
        // @effects frontend_extensionless_mode_urls_carry_no_static_extension
        /** @effects convert_to_current_mode_shapes */
        it('변환 결과 경로에 정적 확장자가 남지 않는다', () => {
            for (const url of [
                '/api/templates/assets/t/js/a.js?v=7',
                '/api/modules/bundle.js',
                '/api/templates/t/routes.json',
                '/api/layouts/t/home.json?v=7',
            ]) {
                const path = convertToCurrentMode(url).split('?')[0];
                expect(path, `경로에 확장자 잔존: ${url}`).not.toMatch(/\.(js|css|json)$/i);
            }
        });

        // @scenario asset_url_mode=extensionless
        // @effects recovery_conversion_leaves_external_origin_urls_untouched
        /** @effects convert_to_current_mode_shapes */
        it('외부 origin URL 은 건드리지 않는다', () => {
            const external = 'https://cdn.example.com/lib.js';

            expect(convertToCurrentMode(external)).toBe(external);
        });

        // 회귀: `<script>.src` / `<link>.href` 는 절대 URL 을 돌려준다. same-origin
        // 접두사를 벗기지 않으면 부트스트랩 재시도에서 변환이 항상 null 이 되어
        // 자가 복구가 통째로 죽는다 — 외형은 "3회 재시도 후 폴백 UI" 라 원인이 안 보인다.
        // @scenario asset_url_mode=extensionless
        // @effects recovery_conversion_handles_same_origin_absolute_urls
        /** @effects convert_to_current_mode_shapes */
        it('same-origin 절대 URL 도 변환한다 (DOM 프로퍼티 형태)', () => {
            const origin = globalThis.location.origin;

            expect(convertToCurrentMode(`${origin}/api/templates/assets/t/js/a.js?v=7`)).toBe(
                '/api/templates/assets/t?file=js%2Fa.js&v=7',
            );
            expect(convertToCurrentMode(`${origin}/api/modules/bundle.js?v=7`)).toBe(
                '/api/modules/bundle/js?v=7',
            );
        });

        // @scenario asset_url_mode=extensionless
        // @effects recovery_conversion_handles_same_origin_absolute_urls
        /** @effects shell_head_converter_matches_ts_builder */
        it('셸 head 복구기도 same-origin 절대 URL 을 변환한다', () => {
            const inline = loadInlineConverter();
            const origin = globalThis.location.origin;

            expect(inline(`${origin}/api/templates/assets/t/js/a.js?v=7`)).toBe(
                '/api/templates/assets/t?file=js%2Fa.js&v=7',
            );
        });
    });
});
