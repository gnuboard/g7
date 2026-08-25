// e2e:allow 테스트 전용 수정 — 프로브 판정 함수 추출 리팩토링 후 stale 앵커(detect→probe) 정정. 런타임 동작 무변경이며 대상 기능 E2E 는 tests/Playwright/specs/asset-url-mode.spec.ts 로 이미 커버됨.
/**
 * 자산 URL 자가 복구 불변식 가드 — 이슈 #486 단위 D (§12 L1~L9).
 *
 * 자가 복구는 "실패했으니 다시 시도한다" 는 구조라 루프 위험이 내재한다.
 * 계획서 §12 는 이를 구현 불변식으로 못박았고, 각각에 대응 테스트를 요구한다.
 * 여기서는 브라우저 없이 검증 가능한 불변식(L1·L5·L7)과, blade 인라인 복구기와
 * TS 빌더의 **규칙 드리프트**를 다룬다. 요청 횟수·리로드·폴백 UI(L2·L3·L4·L8)는
 * 브라우저 계층이므로 Playwright spec 이 담당한다.
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
 * blade 파샬의 인라인 `toExtensionless` 를 추출해 실행 가능한 함수로 만든다.
 *
 * 부트스트랩은 코어 번들 로드 전에 동작해야 해서 import 를 쓸 수 없고, 그래서 변환 규칙이
 * blade 인라인 JS 에 한 벌 더 존재한다. 두 구현이 갈라지면 서버가 만든 URL 과
 * 클라이언트가 만든 URL 이 어긋나 그 자산만 404 가 되므로, 실제 blade 소스에서
 * 함수를 뽑아 TS 구현과 대조한다.
 */
function loadInlineConverter(): (url: string) => string | null {
    const bladePath = resolve(__dirname, '../../../../views/partials/asset-url-recovery.blade.php');
    const source = readFileSync(bladePath, 'utf-8');

    const start = source.indexOf('function toExtensionless(url) {');
    expect(start, 'blade 파샬에서 toExtensionless 를 찾지 못했다').toBeGreaterThan(-1);

    // 균형 중괄호로 함수 본문 끝을 찾는다
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

    const fnSource = source.slice(start, end);

    // 함수가 참조하는 파샬 상단 상수를 함께 주입한다 (blade 스코프 재현)
    // eslint-disable-next-line no-new-func
    return new Function(
        `var FILE_QUERY_PARAM = 'file';\n${fnSource}\nreturn toExtensionless;`,
    )() as (url: string) => string | null;
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
        it('extension → extensionless 는 1회만 성공한다', () => {
            expect(setAssetUrlMode(MODE_EXTENSIONLESS)).toBe(true);
            expect(setAssetUrlMode(MODE_EXTENSIONLESS)).toBe(false);
        });

        it('역방향 전환 경로가 존재하지 않는다', () => {
            setAssetUrlMode(MODE_EXTENSIONLESS);

            expect(setAssetUrlMode(MODE_EXTENSION as any)).toBe(false);
            expect(getAssetUrlMode()).toBe(MODE_EXTENSIONLESS);
        });

        it('G7Config 가 재대입되어도 전환 결과가 유지된다', () => {
            setAssetUrlMode(MODE_EXTENSIONLESS);

            // <body> 의 `window.G7Config = {...}` 재대입 모사
            (globalThis as any).G7Config = { cache_version: 7, assetUrlMode: MODE_EXTENSION };

            expect(getAssetUrlMode()).toBe(MODE_EXTENSIONLESS);
        });
    });

    describe('L5 — 클라이언트는 서버 설정을 쓰지 않는다', () => {
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
        it('캐시 키에 cache_version 이 포함된다', () => {
            setAssetUrlMode(MODE_EXTENSIONLESS);

            expect(globalThis.localStorage.getItem('g7_asset_url_mode:7')).not.toBeNull();
        });
    });

    describe('변환 규칙 — blade 인라인 복구기와 TS 빌더 동등성', () => {
        const cases = [
            '/api/templates/assets/sirsoft-basic/js/components.iife.js?v=7',
            '/api/templates/assets/sirsoft-basic/css/components.css?v=7',
            '/api/modules/assets/sirsoft-ecommerce/dist/js/module.iife.js?v=7',
            '/api/plugins/assets/sirsoft-gdpr/dist/css/plugin.css',
            '/api/modules/bundle.js?v=7',
            '/api/plugins/bundle.css',
            '/api/templates/sirsoft-basic/routes.json?v=7',
            '/api/layouts/sirsoft-basic/home.json?with_source_meta=1&v=7',
        ];

        it('모든 대표 URL 에서 두 구현의 결과가 동일하다', () => {
            const inline = loadInlineConverter();
            (globalThis as any).__g7AssetUrlMode = MODE_EXTENSIONLESS;

            for (const url of cases) {
                expect(inline(url), `blade 인라인 변환기가 ${url} 을 변환하지 못했다`).not.toBeNull();
                expect(inline(url), `변환 규칙 드리프트: ${url}`).toBe(convertToCurrentMode(url));
            }
        });

        it('코어 엔진 번들은 두 구현 모두 변환하지 않는다', () => {
            const inline = loadInlineConverter();
            (globalThis as any).__g7AssetUrlMode = MODE_EXTENSIONLESS;

            const coreBundle = '/build/core/template-engine.min.js?v=123';

            // public/ 의 실물 정적 파일 — 변환하면 오히려 깨진다
            expect(inline(coreBundle)).toBeNull();
            expect(convertToCurrentMode(coreBundle)).toBe(coreBundle);
        });

        it('확장자 모드에서는 TS 빌더가 원본을 그대로 돌려준다', () => {
            (globalThis as any).__g7AssetUrlMode = undefined;
            (globalThis as any).G7Config = { cache_version: 7, assetUrlMode: MODE_EXTENSION };

            for (const url of cases) {
                expect(convertToCurrentMode(url)).toBe(url);
            }
        });
    });

    describe('정적 게시 역변환 — blade 인라인 복구기와 TS 구현 동등성 (#122 F15)', () => {
        /**
         * blade 파샬의 인라인 `staticToLegacy` 를 추출해 TS `staticToLegacy` 와 대조한다.
         * 역변환 규칙은 서버측 `AssetUrl` 의 정적 게시 트리 규약과 1:1 이어야 하며,
         * 두 사본이 갈라지면 태그 계층의 서빙 시점 404 복구가 그 자산만 조용히 죽는다.
         *
         * @effects static_asset_tag_recovers_to_api_url
         */
        function loadInlineStaticToLegacy(): (url: string) => string | null {
            const bladePath = resolve(__dirname, '../../../../views/partials/asset-url-recovery.blade.php');
            const source = readFileSync(bladePath, 'utf-8');

            const start = source.indexOf('function staticToLegacy(url) {');
            expect(start, 'blade 파샬에서 staticToLegacy 를 찾지 못했다').toBeGreaterThan(-1);

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

            // eslint-disable-next-line no-new-func
            return new Function(
                `${source.slice(start, end)}\nreturn staticToLegacy;`,
            )() as (url: string) => string | null;
        }

        const staticCases = [
            '/build/ext/1234/templates/sirsoft-basic/assets/css/components.css',
            '/build/ext/1234/templates/sirsoft-basic/assets/js/components.iife.js',
            '/build/ext/1234/bundles/modules.js',
            '/build/ext/1234/bundles/plugins.css',
            '/build/ext/1234/templates/sirsoft-basic/routes.json',
            '/build/ext/1234/templates/sirsoft-basic/components.json',
            '/build/ext/1234/templates/sirsoft-basic/lang/ko.json',
        ];

        it('모든 정적 게시 URL 에서 두 구현의 결과가 동일하다', () => {
            const inline = loadInlineStaticToLegacy();

            for (const url of staticCases) {
                expect(inline(url), `blade 인라인 역변환기가 ${url} 을 변환하지 못했다`).not.toBeNull();
                expect(inline(url), `역변환 규칙 드리프트: ${url}`).toBe(staticToLegacy(url));
            }
        });

        it('정적 게시 경로가 아닌 URL 은 두 구현 모두 변환하지 않는다', () => {
            const inline = loadInlineStaticToLegacy();

            for (const url of ['/build/core/template-engine.min.js', '/api/templates/t/routes.json', '']) {
                expect(inline(url)).toBeNull();
                expect(staticToLegacy(url)).toBeNull();
            }
        });
    });

    describe('정적 게시 역변환 × 자산 URL 모드 (#122 F15 — 확장자 404 서버)', () => {
        /**
         * 확장자 형태를 404 로 돌려주는 서버(#486 의 대상)에서는 역변환 결과인
         * `/api/…/style.css` 가 **다시** 404 다. `recoverStylesheet` 는 링크당 1회만
         * 교체하므로, 그 한 번이 서빙 불가능한 형태로 끝나면 `toExtensionless` 가
         * 실행될 기회 없이 CSS 가 영구히 붙지 않는다. `<script>` 는 재시도 예산이
         * 남아 다음 시도에서 자연 복구되지만 `<link>` 는 예산이 1회다.
         *
         * @effects static_asset_tag_recovers_to_api_url
         */
        function loadPartialApi(): any {
            const bladePath = resolve(__dirname, '../../../../views/partials/asset-url-recovery.blade.php');
            const source = readFileSync(bladePath, 'utf-8');

            // 파샬 상단 blade 주석이 태그 리터럴을 본문에 담고 있어, 맨 앞부터 찾으면
            // 주석 속 리터럴을 먼저 잡는다. 주석 종료(`--}}`) 뒤에서 실제 태그를 찾는다.
            const afterComment = source.indexOf('--}}');
            const open = source.indexOf('<script>', afterComment === -1 ? 0 : afterComment);
            const close = source.indexOf('</script>', open);
            expect(open, 'blade 파샬에서 스크립트 태그를 찾지 못했다').toBeGreaterThan(-1);
            expect(close, 'blade 파샬에서 스크립트 종료 태그를 찾지 못했다').toBeGreaterThan(open);

            // blade echo(`{{ ... }}`)는 JS 가 아니므로 리터럴로 치환한다
            const js = source.slice(open + '<script>'.length, close).replace(/{{[^}]*}}/g, '1234');

            // eslint-disable-next-line no-new-func
            new Function(js)();

            return (globalThis as any).window.__g7AssetUrl;
        }

        const staticHref = '/build/ext/1234/templates/sirsoft-basic/assets/css/components.css';

        beforeEach(() => {
            (globalThis as any).window.__g7AssetUrl = undefined;
            (globalThis as any).window.__g7AssetUrlMode = undefined;
        });

        it('확장자 모드에서는 확장자 형태 API URL 로 교체한다', () => {
            const api = loadPartialApi();

            const link = document.createElement('link');
            link.setAttribute('href', staticHref);
            api.recoverStylesheet(link);

            expect(link.getAttribute('href')).toBe(
                '/api/templates/assets/sirsoft-basic/css/components.css?v=1234',
            );
        });

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
        it('엔진 소스에 프로브 엔드포인트 호출이 없다', () => {
            const engineRoot = resolve(__dirname, '../../');
            const offenders: string[] = [];

            const walk = (dir: string): void => {
                for (const entry of readdirSync(dir, { withFileTypes: true })) {
                    const full = join(dir, entry.name);

                    if (entry.isDirectory()) {
                        if (entry.name === '__tests__' || entry.name === 'node_modules') continue;
                        walk(full);

                        continue;
                    }

                    if (!/\.(ts|tsx)$/.test(entry.name)) continue;

                    const src = readFileSync(full, 'utf-8');
                    if (src.includes('asset-probe')) {
                        offenders.push(full.replace(engineRoot, ''));
                    }
                }
            };

            walk(engineRoot);

            expect(
                offenders,
                `엔진 레이어가 프로브를 직접 호출한다 (L9 위반 — 감지는 부트스트랩 단독):\n${offenders.join('\n')}`,
            ).toEqual([]);
        });
    });

    describe('convertToCurrentMode 변환 결과', () => {
        beforeEach(() => {
            (globalThis as any).__g7AssetUrlMode = MODE_EXTENSIONLESS;
        });

        it('자산은 file 쿼리로, 번들은 세그먼트로, 접미사는 제거로', () => {
            expect(convertToCurrentMode('/api/templates/assets/t/js/a.js?v=7')).toBe(
                '/api/templates/assets/t?file=js%2Fa.js&v=7',
            );
            expect(convertToCurrentMode('/api/modules/bundle.js?v=7')).toBe('/api/modules/bundle/js?v=7');
            expect(convertToCurrentMode('/api/templates/t/routes.json')).toBe('/api/templates/t/routes');
        });

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

        it('외부 origin URL 은 건드리지 않는다', () => {
            const external = 'https://cdn.example.com/lib.js';

            expect(convertToCurrentMode(external)).toBe(external);
        });

        // 회귀: `<script>.src` / `<link>.href` 는 절대 URL 을 돌려준다. same-origin
        // 접두사를 벗기지 않으면 부트스트랩 재시도에서 변환이 항상 null 이 되어
        // 자가 복구가 통째로 죽는다 — 외형은 "3회 재시도 후 폴백 UI" 라 원인이 안 보인다.
        it('same-origin 절대 URL 도 변환한다 (DOM 프로퍼티 형태)', () => {
            const origin = globalThis.location.origin;

            expect(convertToCurrentMode(`${origin}/api/templates/assets/t/js/a.js?v=7`)).toBe(
                '/api/templates/assets/t?file=js%2Fa.js&v=7',
            );
            expect(convertToCurrentMode(`${origin}/api/modules/bundle.js?v=7`)).toBe(
                '/api/modules/bundle/js?v=7',
            );
        });

        it('blade 인라인 복구기도 same-origin 절대 URL 을 변환한다', () => {
            const inline = loadInlineConverter();
            const origin = globalThis.location.origin;

            expect(inline(`${origin}/api/templates/assets/t/js/a.js?v=7`)).toBe(
                '/api/templates/assets/t?file=js%2Fa.js&v=7',
            );
        });
    });
});
