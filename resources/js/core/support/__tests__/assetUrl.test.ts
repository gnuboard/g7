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
    extensionAsset,
    templateAsset,
    moduleAsset,
    pluginAsset,
    extensionBundle,
    suffixed,
    withBasePrefix,
    stripBasePrefix,
    layoutUrl,
    layoutPreviewUrl,
    basePrefix,
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

/** 접두사를 이미 적용해 돌려주는 `assetUrl` 생성기들. */
const PREFIXING_HELPERS = [
    'withBasePrefix(',
    'suffixed(',
    'templateAsset(',
    'moduleAsset(',
    'pluginAsset(',
    'extensionAsset(',
    'extensionBundle(',
    // 정적 게시본(bake) 주소. 서버가 내려준 게시 base(`G7Config.ext.static_base`)에서
    // 조립하므로 접두사가 이미 실려 있다 — `fetchStaticFirst` 의 첫 인자가 이 형태다.
    'extStaticUrl(',
    // 확장 번들은 코어와 별도 IIFE 라 `import` 를 할 수 없다 — 확장이 쓰는
    // 공식 수단(`G7Core.url.api` 위임 + 같은 SSoT 폴백)이 이 이름이다.
    'apiUrl(',
    'G7Core.url.api(',
    // 화면을 **이동**하는 주소의 짝. 연산은 `apiUrl` 과 같고 부르는 자리가 다르다
    // (13차 G3 라운드).
    'sitePath(',
    // 편집기 주소 조립의 단일 지점 — 내부에서 `withBasePrefix` 를 적용해 돌려준다
    // (`useEditorMode.ts::buildEditorUrl`). `pushState`/`<a href>` 에 그대로 실리는
    // 값이므로 이동 축에서도 허용된다.
    'buildEditorUrl(',
    // `G7Core.url.route(` 는 여기 두지 않는다 — 그것은 접두사를 **벗기는**
    // (`stripBasePrefix`) 함수다(`G7CoreGlobals.ts` 의 `initUrlAPI`). 13차가 붙이는
    // 헬퍼 목록에 함께 올려 두어, 그 이름을 쓰면 게이트를 통과하면서도 접두사가
    // 없는 주소가 나가는 상태였다 — 13차가 닫은 이동 축이 게이트를 지나 되열린다.
    // 현재 호출부는 0건이라 실제 위반은 없었고, 되열릴 구멍만 있었다(14차 G3 라운드).
    //
    // 같은 이유로 **두 이름을 더 뺐다** (15차 G3 라운드):
    //  · `G7Core.url.base(` — 구현은 `base: (): string => basePrefix()` 로 **인자를
    //    받지 않는다.** `fetch(G7Core.url.base(path))` 는 path 를 버리고 접두사만
    //    부르는데 게이트는 통과한다. 14차가 `route(` 를 뺀 것과 **같은 형태의
    //    구멍**이 세 줄 위에 남아 있었다.
    //  · `publicStorage(` — 프론트에 그 이름의 구현이 **아예 없다**(정의·호출 0건).
    //    누군가 접두사를 붙이지 않는 동명 함수를 만들면 그대로 통과하는 유령 허용이다.
    //
    // 아래 「허용목록 구성」 검사가 이 목록 자신을 잰다 — 세 번 반복된 구멍이라
    // 사유를 주석으로 남기는 것만으로는 재발을 막지 못한다.
];

/**
 * 번들 확장 소스 컨테이너 — **측정기와 대조가 같은 표를 본다.**
 *
 * 종전에는 이 표가 네 벌의 독립 리터럴이었다(위반 스캔 / 빈 모집단 대조 / 사본 수집 /
 * 사본 필요 판정). 그래서 **스캔 표만 망가뜨리면 대조 표는 여전히 제 수를 세어 초록**
 * 이었다 — 14차가 「합계 하나로 재면 안 된다」며 컨테이너별로 쪼갠 그 개선이, 정작
 * 측정 대상과 측정기가 서로 다른 사본이라는 더 근본적인 형태를 남겼다. 저장소 규정이
 * 확장 헬퍼 사본에 대해 금지한 것을 게이트 자신이 하고 있었다 (15차 G3 라운드).
 */
/**
 * 소스 파일 판정과 순회 제외 디렉토리 — **측정기와 대조가 같은 표를 본다.**
 *
 * 종전에는 위반 스캔이 `.ts|.tsx|.js|.mjs` 를, 비공허성 계수기가 `.ts|.tsx` 를 보는
 * 별개의 리터럴이었다. 그래서 10차가 진입 스크립트(`app.js`·`bootstrap.js`)를 축 안으로
 * 넣으려고 확장자를 넓혔는데, **그 파일들을 세는 쪽은 넓어지지 않아** 두 스캔의 모집단이
 * 갈렸다 — 계수 루트까지 한 단계 아래(`resources/js/core`)여서 그 두 파일은 결국
 * 스캔에도 계수에도 없었고, 배선을 되돌려도 red 가 없었다 (16차 G3 라운드 A-6).
 */
const SOURCE_FILE_RE = /\.(ts|tsx|js|mjs)$/;

/** 순회에서 제외하는 디렉토리 — 세 스캐너가 공유한다. */
const SKIP_DIRS = ['__tests__', 'node_modules', 'dist'];

/**
 * 코어 스캔 루트(`resources/js`)와 계수 루트는 **같아야 한다.**
 *
 * 계수 루트가 스캔 루트보다 아래에 있으면, 그 사이의 파일은 「스캔 대상인데 세지 않는」
 * 상태가 되어 어느 축에도 잡히지 않는다.
 */
const CORE_SCAN_ROOT = ['..', '..', '..'] as const;

const EXTENSION_SOURCE_CONTAINERS = [
    { base: 'templates/_bundled', sub: 'src', helper: ['src', 'support', 'apiUrl.ts'] },
    { base: 'modules/_bundled', sub: 'resources/js', helper: ['resources', 'js', 'apiUrl.ts'] },
    { base: 'plugins/_bundled', sub: 'resources/js', helper: ['resources', 'js', 'apiUrl.ts'] },
] as const;

/**
 * 설치본 version 이 `_bundled` version 보다 **새로운가**.
 *
 * 문서화된 확장 업데이트 감지 우선순위는 `GitHub > _bundled` 다. 그래서 설치본이 앞선 것은
 * 비정상이 아니라 정상 상태일 수 있고, 그 상태에서 `{type}:update --force` 처방은 성립하지
 * 않는다 — `--force` 는 버전 비교와 확인 프롬프트만 건너뛸 뿐 소스 선택을 바꾸지 않는다.
 *
 * 정적 검사도 같은 판정을 한다. 이 파일은 공개 배포 대상이라 그 판정기를 import 할 수 없으므로
 * 같은 규칙을 여기 다시 적는다 — pre-release 순서 규칙(정식 > 같은 번호의 pre-release,
 * beta.10 > beta.9)까지 동형이다.
 *
 * @param installed 설치본 version
 * @param bundled `_bundled` version
 * @returns 설치본이 더 새로우면 true. 파싱 불가하면 false (fail-closed — 위반으로 남긴다)
 */
function installedIsNewer(installed: string, bundled: string): boolean {
    const parse = (v: string) => {
        const m = /^(\d+)\.(\d+)\.(\d+)(?:-([a-z]+)\.(\d+))?$/i.exec(v.trim().replace(/^v/, ''));

        return m
            ? { nums: [Number(m[1]), Number(m[2]), Number(m[3])], pre: m[4] || null, preNum: m[5] ? Number(m[5]) : null }
            : null;
    };
    const a = parse(installed);
    const b = parse(bundled);
    if (!a || !b) return false;

    for (let i = 0; i < 3; i++) {
        if (a.nums[i] !== b.nums[i]) return a.nums[i] > b.nums[i];
    }
    if (a.pre === null && b.pre === null) return false;
    if (a.pre === null) return true;
    if (b.pre === null) return false;
    if (a.pre !== b.pre) return a.pre > b.pre;

    return (a.preNum ?? 0) > (b.preNum ?? 0);
}

/**
 * `// base-path:allow <사유>` 면제 표식을 판정한다.
 *
 * 규정은 **사유**를 요구하는데(개발 규정 문서), 종전 판정은 두 줄을
 * 이어 붙인 문자열에 `includes('base-path:allow')` 를 걸 뿐이었다 — 사유 없는
 * 맨 표식도, **문자열 리터럴 안**의 같은 글자도 면제로 인정됐다. 표식이 「사유가
 * 사라진 면제」로 퇴화해도 아무것도 울지 않는 상태였다 (7차 G3 라운드 E11).
 *
 * 판정 축 셋: ① 줄 단위로 본다(이어 붙이면 앞줄의 `//` 가 뒷줄의 리터럴을 덮는다)
 * ② `//` 주석 안이어야 한다 — 앞에 따옴표가 없어야 하고, `http://` 처럼 `:` 뒤에
 * 붙은 것은 주석이 아니다 ③ 표식 뒤에 비어 있지 않은 사유가 있어야 한다.
 */
const BASE_PATH_ALLOW_RE = /(?:^|[^:'"`])\/\/[^'"`]*base-path:allow[ \t]+\S/;

/**
 * 지정한 줄이 사유를 갖춘 면제 표식으로 덮이는지 판정한다.
 *
 * @param lines 파일 전체 줄 배열
 * @param lineNo 1-기반 줄 번호
 */
const hasBasePathAllowMarker = (lines: string[], lineNo: number): boolean =>
    [lines[lineNo - 1] ?? '', lines[lineNo - 2] ?? ''].some((text) =>
        BASE_PATH_ALLOW_RE.test(text)
    );

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

        it('ComponentRegistry loadManifest components.json (버전 있음/없음 — #122 작업 B)', () => {
            // 편집기 경로(버전 미전달)는 무버전 URL 유지 (서버가 현재 버전 폴백 — #588)
            expect(suffixed('/api/templates/sirsoft-basic/components', 'json')).toBe(
                '/api/templates/sirsoft-basic/components.json',
            );
            // 런타임 경로는 캐시 버전 부착 (stale 매니페스트 방지)
            expect(suffixed('/api/templates/sirsoft-basic/components', 'json', 7)).toBe(
                '/api/templates/sirsoft-basic/components.json?v=7',
            );
        });

        it('TemplateApp config.json + 캐시버스트 쿼리', () => {
            expect(suffixed('/api/templates/sirsoft-basic/config', 'json', null, '_=1699999999')).toBe(
                '/api/templates/sirsoft-basic/config.json?_=1699999999',
            );
        });

        it('LayoutLoader 레이아웃 / 미리보기', () => {
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

        it('확장 식별자는 양쪽 모드에서 인코딩된다 (서버 `rawurlencode` 와 동형)', () => {
            // 식별자는 슬러그라 발현은 없지만, 서버(`AssetUrl::asset()`)가 미인코딩이던
            // 시절 규칙 사본이 갈려 있었다 — 어느 쪽이 맞는가를 여기서 고정한다.
            expect(templateAsset('a b', 'js/x.js')).toBe('/api/templates/assets/a%20b/js/x.js');

            setConfig(MODE_EXTENSIONLESS);
            expect(moduleAsset('a b', 'dist/x.js')).toBe('/api/modules/assets/a%20b?file=dist%2Fx.js');
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

    describe('하위 폴더 마운트 — 브라우저가 만드는 주소도 접두사를 갖는다', () => {
        beforeEach(() => {
            (globalThis as any).G7Config = { ...(globalThis as any).G7Config, basePath: '/g7' };
        });

        afterEach(() => {
            if ((globalThis as any).G7Config) {
                delete (globalThis as any).G7Config.basePath;
            }
        });

        it('자산·번들·접미사 생성기 전부가 마운트 접두사를 붙인다', () => {
            // 서버가 렌더한 URL 은 `AssetUrl::basePrefix()` 로 접두사를 갖는데 브라우저가
            // 만드는 URL 은 루트 상대였다 — 같은 화면에서 두 규칙이 공존했고, 하위 폴더
            // 배치에서는 SPA 의 첫 fetch 가 사이트 밖으로 나가 화면이 빈 채로 떴다.
            expect(templateAsset('t', 'js/app.js')).toBe('/g7/api/templates/assets/t/js/app.js');
            expect(moduleAsset('m', 'dist/js/m.js')).toBe('/g7/api/modules/assets/m/dist/js/m.js');
            expect(pluginAsset('p', 'dist/js/p.js')).toBe('/g7/api/plugins/assets/p/dist/js/p.js');
            expect(extensionBundle('modules', 'js')).toBe('/g7/api/modules/bundle.js');
            expect(suffixed('/api/templates/t/routes', 'json')).toBe('/g7/api/templates/t/routes.json');
        });

        it('이미 접두사를 가진 경로에 다시 붙이지 않는다', () => {
            // 이중 접두(`/g7/g7/api/…`)는 오류 없이 404 가 되는 형태다.
            expect(suffixed('/g7/api/templates/t/routes', 'json')).toBe('/g7/api/templates/t/routes.json');
        });

        /**
         * 경계 판정의 기대표는 `tests/Fixtures/AssetUrl/base-prefix-boundary.json` 하나다.
         *
         * 종전에는 이 표가 여기와 서버 `AssetUrlTest.php` 에 **각자 손으로 쓴 두 벌**로
         * 있었다 — 멱등(`/g7/api/x`) · 비대상(`//host`·절대 URL·상대 경로) · 경계(`?`·`#`·
         * 경계 없는 이어짐) · 공백 전처리 행이 양쪽에 따로 적혀 있었고, 사본은 갈려도 울지
         * 않는다: 한쪽만 고치면 같은 입력이 서버에서는 접두사를 얻고 화면에서는 얻지 못한다
         * (15차 G3 라운드 W4-3). 행은 회차 15 가 양쪽에서 실측한 값이다.
         */
        const readBoundaryFixture = async () => {
            const fs = await import('node:fs');
            const path = await import('node:path');
            const repoRoot = path.resolve(__dirname, '..', '..', '..', '..', '..');

            return JSON.parse(
                fs.readFileSync(path.join(repoRoot, 'tests/Fixtures/AssetUrl/base-prefix-boundary.json'), 'utf8'),
            ) as {
                boundary: { prefix: string; cases: { input: string; with: string; strip: string; has: boolean; why: string }[] }[];
                base_path: { frontend: unknown; app_url: string | null; prefix: string; why: string }[];
            };
        };

        it('붙이는 쪽·벗기는 쪽이 공유 기대표의 전 행을 만족한다 (서버 `sitePath()`/`hasBasePrefix()` 와 같은 표)', async () => {
            const fixture = await readBoundaryFixture();
            expect(fixture.boundary.length).toBeGreaterThan(1);

            let rows = 0;
            for (const group of fixture.boundary) {
                (globalThis as any).G7Config.basePath = group.prefix;
                expect(group.cases.length).toBeGreaterThan(0);

                for (const c of group.cases) {
                    rows += 1;
                    const label = `${c.why} — ${JSON.stringify(c.input)} @ ${group.prefix}`;

                    expect(withBasePrefix(c.input), `withBasePrefix: ${label}`).toBe(c.with);
                    expect(stripBasePrefix(c.input), `stripBasePrefix: ${label}`).toBe(c.strip);
                    // 보유 판정(`has`)은 붙이는 쪽이 항등인지로 드러난다 — 원문 그대로 판정하므로
                    // 공백·비대상 행은 `has=false` 이면서 항등일 수 있어 한 방향만 잰다.
                    if (c.has) expect(withBasePrefix(c.input), `has: ${label}`).toBe(c.input.trim());

                    // 왕복 — 붙인 뒤 벗긴 결과는 처음부터 벗긴 결과와 같다 (`/g7?x` 와 `/g7/?x` 는
                    // 같은 라우트 `/?x` 로 떨어진다).
                    expect(stripBasePrefix(withBasePrefix(c.input)), `round-trip: ${label}`).toBe(stripBasePrefix(c.input));
                }
            }

            // 빈 모집단 대조 — 회차 15 가 실측한 24 입력 + 다단 접두사 행.
            expect(rows).toBeGreaterThanOrEqual(28);
        });

        it('접두사 자체의 정규화도 공유 기대표를 따른다 (`G7Config.basePath` ↔ 서버 `app.url` 경로)', async () => {
            const fixture = await readBoundaryFixture();
            let rows = 0;

            for (const c of fixture.base_path) {
                // `frontend: null` 이면서 `app_url` 만 있는 행은 서버 축 전용이다.
                if (c.frontend === null && c.app_url !== null && c.app_url !== '') continue;
                rows += 1;
                (globalThis as any).G7Config.basePath = c.frontend;
                expect(basePrefix(), `${c.why} — ${JSON.stringify(c.frontend)}`).toBe(c.prefix);
            }

            expect(rows).toBeGreaterThanOrEqual(10);
        });

        it('루트 설치에서는 아무것도 붙지 않는다 (종전 동작 불변)', () => {
            delete (globalThis as any).G7Config.basePath;

            expect(templateAsset('t', 'js/app.js')).toBe('/api/templates/assets/t/js/app.js');
        });
    });

    describe('접두사 우회 지점이 남아 있지 않다 (소스 계약)', () => {
        /** 개행 문자. 정규식·문자열 이스케이프가 섞이는 자리라 상수로 고정한다. */
        const LINE_FEED = String.fromCharCode(10);


        /**
         * 문자 위치가 몇 번째 줄인지 센다.
         *
         * 두 수집기가 공유한다 — 줄 계산을 사본으로 두면 한쪽만 어긋나도
         * 그 수집기의 보고 위치가 조용히 틀린다.
         */
        const lineNumberAt = (body: string, index: number): number =>
            body.slice(0, index).split(LINE_FEED).length;


        /**
         * 주석·문자열을 걷어낸 본문에서 **서버 요청**의 첫 인자를 모은다.
         *
         * `fetch(` 만 보면 안 된다 — 이 저장소는 재시도를 감싼 래퍼(`fetchWithRetry`)를
         * 쓰는데, 그 이름은 `fetch` 뒤가 `(` 가 아니라 종전 정규식에 걸리지 않았다.
         * 그래서 래퍼를 거치는 호출 네 곳이 **통째로 수집 밖**이었고, 그 자리에 루트
         * 상대 주소를 넣어도 이 계약 검사와 정적 룰이 모두 초록이었다(변이 실측).
         * 하필 확장자 없는 API 경로(`/api/me/avatar`)가 그 형태라 형제 룰
         * (`asset-url-builder-required`)도 잡지 못한다 — 그 룰은 자산 확장자를 본다.
         *
         * 래퍼는 **이름을 열거**한다. `fetch\w*\(` 로 넓히면 URL 이 아니라 옵션 객체를
         * 받는 내부 헬퍼(`fetchSources({sources: …})` 등)가 무더기로 걸려 검사가
         * 오탐으로 채워지고, 그러면 사람이 그 검사를 곧 믿지 않게 된다. 목록이 낡으면
         * 아래 `RESILIENT_FETCH_WRAPPERS` 존재 단언이 red 로 알려 준다.
         */
        const RESILIENT_FETCH_WRAPPERS = ['fetchWithRetry', 'fetchStaticFirst'];

        /**
         * 그 파일 안에서 **fetch 참조를 담은 식별자**를 도출한다.
         *
         * 이름 열거로는 닿지 않는 형태가 있다 — 주입 seam 이다. 테스트에서 갈아끼우려고
         * `fetchImpl: typeof fetch = fetch` 처럼 파라미터로 받아 `fetchImpl(url)` 로 부르면
         * 호출부 이름이 `fetch` 도 `fetchWithRetry` 도 아니라 여섯 수집기 어디에도
         * 걸리지 않는다. 실제로 그 사각에 코어 1곳(레이아웃 편집기 교차 저장)과
         * 확장 1곳(KG이니시스 JPY 결제수단 제한)이 접두사 없이 남아 있었다.
         *
         * 그래서 이름을 **더 적지 않고 도출한다.** 근거는 선언 자신이다 —
         * `typeof fetch` 로 타입이 붙었거나 `fetch`/`globalThis.fetch`/`window.fetch` 를
         * 대입받은 식별자면 그것은 fetch 다. 새 seam 이름이 생겨도 목록을 고칠 필요가 없다.
         *
         * 도출은 **이행적**이다. 종전 판본은 한 겹만 봐서 세 형태를 놓쳤고, 그 셋이 전부
         * 이 저장소의 실물이었다 (9차 G3 라운드 A-3):
         *
         *   · `fetchImpl?: typeof fetch` — 선택 프로퍼티. 식별자와 `:` 사이의 `?` 를
         *     허용하지 않아 코어 3선언이 통째로 밖이었다.
         *   · `const orig = window.fetch.bind(window)` — `fetch` 뒤에 `[,;)]` 를 요구해
         *     `.bind(` 형태를 못 봤다. 결제 플러그인 2곳이 그 형태로 전역을 몽키패치한다.
         *   · `const f = fetchImpl ?? (… ? fetch : undefined)` — **별칭에서 파생된 별칭**.
         *     한 겹만 보면 `f` 가 도출되지 않고, 그 사각에 편집기 봇 미리보기가 있었다.
         *
         * 이행 단계는 **우변의 모양**으로 좁힌다. 「우변이 아는 이름을 언급한다」로 넓히면
         * 곧바로 오탐이 난다 — 넓힌 첫 판본이 devtools 의 `copyToClipboard(layoutJson)`
         * 3곳을 fetch 호출로 신고했다(실측). 판정을 넓힌 직후에 무엇이 걸렸는지 전수로
         * 읽으라는 규율이 이 자리다.
         *
         * 그래서 우변이 **fetch 참조만으로 이루어진 식**일 때만 따라간다: 아는 이름,
         * `globalThis|window|self` 수식, `.bind(...)`, `??`/삼항, `typeof … !== 'undefined'`,
         * `undefined`, 괄호. 화살표 함수 본문이나 임의 호출이 섞이면 도출하지 않는다.
         */
        const deriveFetchAliases = (body: string): string[] => {
            const aliases = new Set<string>();

            // 1차 — 선언 자신이 fetch 임을 말하는 형태.
            const seedPatterns = [
                // `fetchImpl: typeof fetch` · `fetchImpl?: typeof fetch`
                /([A-Za-z_$][\w$]*)\s*\??\s*:\s*typeof\s+fetch\b/g,
                // `= fetch` · `= window.fetch` · `= window.fetch.bind(window)`
                /([A-Za-z_$][\w$]*)\s*=\s*(?:globalThis\.|window\.|self\.)?fetch\b\s*(?:\.bind\s*\([^)]*\))?\s*[,;)\r\n]/g,
            ];

            for (const re of seedPatterns) {
                let m: RegExpExecArray | null;
                while ((m = re.exec(body)) !== null) {
                    if (m[1] !== 'fetch') aliases.add(m[1]);
                }
            }

            // 2차 이후 — 우변이 fetch 참조만으로 이루어진 선언을 고정점까지 따라간다.
            // 상한을 두는 것은 무한 루프 방지용이며, 실제 파일은 1~2회에 수렴한다.
            const declRe = /(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*(?::[^=\r\n]*)?=\s*([^;\r\n]*)/g;
            for (let pass = 0; pass < 5; pass++) {
                const before = aliases.size;
                const known = ['fetch', ...aliases];
                // 아는 이름 · 수식자 · 순수 결합자만 남기고 지운다. 남는 것이 없으면
                // 그 우변은 fetch 참조 그 자체다.
                const residueRe = new RegExp(
                    `(?<![.\\w])(?:${known.join('|')})(?![\\w$])` +
                        `|\\b(?:globalThis|window|self|undefined|typeof)\\b` +
                        `|\\.bind\\s*\\([^)]*\\)` +
                        `|'undefined'|"undefined"` +
                        `|[()?:!=.\\s&|]`,
                    'g'
                );

                declRe.lastIndex = 0;
                let m: RegExpExecArray | null;
                while ((m = declRe.exec(body)) !== null) {
                    if (m[1] === 'fetch' || aliases.has(m[1])) continue;
                    const rhs = m[2].trim();
                    if (rhs === '') continue;
                    // 아는 이름을 실제로 참조해야 한다(순수 결합자만 남는 우변 제외).
                    if (!new RegExp(`(?<![.\\w])(?:${known.join('|')})(?![\\w$])`).test(rhs)) continue;
                    if (rhs.replace(residueRe, '').trim() === '') aliases.add(m[1]);
                }

                if (aliases.size === before) break;
            }

            return [...aliases];
        };

        const collectFetchArguments = (body: string): { arg: string; line: number }[] => {
            const found: { arg: string; line: number }[] = [];
            const names = ['fetch', ...RESILIENT_FETCH_WRAPPERS, ...deriveFetchAliases(body)].join('|');
            // 앞은 식별자 문자·점이 아니어야 한다(`obj.fetch(`·`prefetch(` 제외).
            // 래퍼의 **정의**(`function fetchWithRetry(url: string, …)`)도 제외한다 —
            // 그 자리의 첫 인자는 주소가 아니라 파라미터 이름이다.
            const re = new RegExp(`(?<!function\\s)(?<![.\\w])(?:${names})\\(\\s*([^\\s,)]+)`, 'g');
            let m: RegExpExecArray | null;

            while ((m = re.exec(body)) !== null) {
                found.push({ arg: m[1], line: lineNumberAt(body, m.index) });
            }

            return found;
        };

        /**
         * 주석·문자열을 걷어낸 본문에서 **브라우저가 스스로 받아오는 주소**를 모은다.
         *
         * `script.src` · `link.href` · `img.src` · `form.action` 대입이다. 이 통로는
         * 「형태 / 방향 / 통로 / 표현」 네 차원 중 **통로** 축에 속하는데, 종전 수집기는
         * 통로를 fetch·이동·주소창·링크 다섯으로 고정하고 이 축을 세지 않았다. 현재
         * 저장소의 실제 지점(코어 8 · 확장 6)은 전부 서버가 준 값을 쓰고 있어 위반이
         * 0 이지만, **그 0 을 고정하는 것이 없다** — 「없다고 판정한 축은 게이트를 만들어
         * 0 을 고정한다」는 규율이 이 통로에만 적용되지 않았다.
         *
         * `location.href` 는 이동 축이 이미 잡으므로 여기서는 `.href` 앞에 `location`
         * 이 오는 형태를 제외한다.
         *
         * **리터럴만 본다.** 형제 축(fetch·이동)은 변수도 모아 `base-path:allow` 표식을
         * 요구하는데, 이 통로의 실제 지점 14곳은 전부 서버·설정이 준 값(PG 엔드포인트 ·
         * 빌드 자산 URL)을 담은 변수다. 그 14곳에 표식을 요구하는 것은 이 회차가 측정한
         * 결함(루트 상대 **리터럴**이 어느 수집기에도 없다)을 넘어서고, 번들 확장 열두
         * 파일의 버전·CHANGELOG 를 함께 끌고 온다. 변수 축을 열려면 그 표식 작업을
         * 별도 단위로 다루는 편이 옳다 — 여기서는 실측된 구멍만 닫는다.
         */
        const collectOutboundAssetTargets = (body: string): { arg: string; line: number }[] => {
            const found: { arg: string; line: number }[] = [];
            const patterns = [
                // 프로퍼티 대입. 식별자 앞의 `.` 를 막지 않는다 — 막으면 `this.iframe.src = …`
                // · `getElementById(x).src = …` 같은 **체인 형태가 통째로 수집 밖**이 된다
                // (7차 G3 라운드 B-5). `location.href` 는 이동 축이 이미 잡으므로 여기서는
                // 바로 앞 식별자가 `location` 인 형태만 제외한다.
                /(?!location)[A-Za-z_$][\w$]*\s*\.\s*(?:src|action)\s*(?<![=!<>])=(?!=)\s*(['"`][^'"`\s]*)/g,
                /(?!location)[A-Za-z_$][\w$]*\s*\.\s*href\s*(?<![=!<>])=(?!=)\s*(['"`][^'"`\s]*)/g,
                // `setAttribute('src'|'href'|'action', …)` — 같은 통로의 다른 표기다.
                // 프로퍼티 대입만 보면 이 형태가 안 보이고, 실제로 두 결제 플러그인이
                // `form.setAttribute('action', …)` 로 폼 목적지를 세운다(현재 값은 인증기관
                // 절대 URL 이라 위반 0). 통로가 통째로 안 보이는 상태를 닫는다.
                /\.\s*setAttribute\s*\(\s*['"](?:src|href|action)['"]\s*,\s*(['"`][^'"`\s]*)/g,
            ];

            for (const re of patterns) {
                let m: RegExpExecArray | null;
                while ((m = re.exec(body)) !== null) {
                    found.push({ arg: m[1], line: lineNumberAt(body, m.index) });
                }
            }

            return found;
        };

        /**
         * 주석·문자열을 걷어낸 본문에서 **화면 이동** 지점의 대상 주소를 모은다.
         *
         * `location.href` 대입 · `location.assign()` · `location.replace()` ·
         * `window.open()` 넷이다. 읽기(`new URL(x, window.location.href)`)와 비교
         * (`location.href === x`)는 대상이 아니므로 대입 `=` 만 잡는다.
         */
        const collectNavigationTargets = (body: string): { arg: string; line: number }[] => {
            const found: { arg: string; line: number }[] = [];
            const patterns = [
                // `location.href = <값>` (`==`·`===`·`!=`·`>=`·`<=` 제외)
                /location\s*\.\s*href\s*(?<![=!<>])=(?!=)\s*([^\s,;)]+)/g,
                /location\s*\.\s*(?:assign|replace)\s*\(\s*([^\s,)]+)/g,
                /window\s*\.\s*open\s*\(\s*([^\s,)]+)/g,
            ];

            for (const re of patterns) {
                let m: RegExpExecArray | null;
                while ((m = re.exec(body)) !== null) {
                    found.push({ arg: m[1], line: lineNumberAt(body, m.index) });
                }
            }

            return found;
        };

        /**
         * 주석·문자열을 걷어낸 본문에서 **`fetch` 가 아닌 전송 통로**의 주소를 모은다.
         *
         * `XMLHttpRequest.open(method, url)` · `new EventSource(url)` · `new WebSocket(url)` ·
         * `new Worker(url)` / `new SharedWorker(url)` 넷이다. 전부 라우터도 `fetch` 래퍼도
         * 거치지 않고 브라우저가 직접 여는 주소라 접두사를 붙일 주체가 호출부뿐인데, 어느
         * 수집기에도 없어 루트 상대 리터럴을 심어도 이 계약 검사가 초록이었다. 현재 실물은
         * 0 이고 그 0 을 「빈 모집단」이 아니라 「루트 상대 리터럴 0」으로 고정한다
         * (15차 G3 라운드 W4-6). 형제 축(자산 대입)과 같은 이유로 **리터럴만** 본다.
         *
         * `.open(` 은 `window.open(` 과 구분해야 한다 — XHR 은 첫 인자가 HTTP 메서드
         * 문자열이라 그 형태(`'GET'` 등)를 요구하고 **둘째** 인자를 주소로 잡는다.
         */
        const collectTransportTargets = (body: string): { arg: string; line: number }[] => {
            const found: { arg: string; line: number }[] = [];
            const patterns = [
                /\.\s*open\s*\(\s*['"][A-Za-z]+['"]\s*,\s*(['"`][^'"`\s]*)/g,
                /new\s+(?:EventSource|WebSocket|Worker|SharedWorker)\s*\(\s*(['"`][^'"`\s]*)/g,
            ];

            for (const re of patterns) {
                let m: RegExpExecArray | null;
                while ((m = re.exec(body)) !== null) {
                    found.push({ arg: m[1], line: lineNumberAt(body, m.index) });
                }
            }

            return found;
        };

        /**
         * `(` 다음 위치에서 인자를 최상위 쉼표로 갈라 돌려준다.
         *
         * 괄호·중괄호·대괄호 깊이를 세므로 `pushState({a: 1, b: 2}, '', url)` 처럼
         * 인자 안에 쉼표가 있어도 정확히 3개로 갈린다.
         */
        const splitArguments = (body: string, openParen: number): string[] => {
            const args: string[] = [];
            let depth = 0;
            let start = openParen + 1;

            for (let i = openParen + 1; i < body.length; i += 1) {
                const c = body[i];

                if (c === '(' || c === '{' || c === '[') depth += 1;
                else if (c === ')' || c === '}' || c === ']') {
                    if (c === ')' && depth === 0) {
                        args.push(body.slice(start, i).trim());
                        return args;
                    }
                    depth -= 1;
                } else if (c === ',' && depth === 0) {
                    args.push(body.slice(start, i).trim());
                    start = i + 1;
                }
            }

            return args;
        };

        /**
         * 주소창을 갈아 끼우는 `history.pushState` / `history.replaceState` 의
         * **세 번째** 인자(= 브라우저가 보게 될 주소)를 모은다.
         *
         * 이 축은 13차까지 게이트가 보지 못했다. `location.replace(` 와
         * `history.replaceState(` 는 다른 문자열이라 이동 수집기의 세 패턴 어디에도
         * 걸리지 않았고, 그 사각에 `TemplateApp.updateQueryParams` 가 남아 있었다 —
         * 목록의 검색·정렬·페이지 이동(`navigate` + `replace: true`)이 전부 그 경로라
         * 하위 폴더 사이트에서 주소창이 마운트 밖으로 교체됐다(14차 G3 라운드).
         */
        const collectHistoryTargets = (body: string): { arg: string; line: number }[] => {
            const found: { arg: string; line: number }[] = [];
            const re = /history\s*\.\s*(?:push|replace)State\s*\(/g;
            let m: RegExpExecArray | null;

            while ((m = re.exec(body)) !== null) {
                const openParen = m.index + m[0].length - 1;
                const args = splitArguments(body, openParen);

                // 인자가 3개 미만이면 주소를 싣지 않는 호출이다(형태상 무해).
                if (args.length < 3) continue;

                found.push({ arg: args[2], line: lineNumberAt(body, m.index) });
            }

            return found;
        };

        /**
         * **소문자** `<a href=…>` — 접두사 계층을 건너뛰는 날 앵커 — 의 목적지를 모은다.
         *
         * 화면 링크는 브라우저가 그대로 여는 주소이고 앵커 클릭을 가로채는 지점이
         * 코어·템플릿 어디에도 없다. 그래서 접두사를 붙이는 자리를 템플릿의 `A`
         * 컴포넌트 한 곳으로 모았다 — 레이아웃 JSON 의 `"href": "/…"` 36곳과 템플릿
         * TSX 의 하드코딩 링크가 전부 그 경로를 지난다.
         *
         * 따라서 판정 대상은 그 한 곳을 **지나지 않는** 형태, 즉 대문자 `A`(컴포넌트)가
         * 아닌 소문자 `a`(날 DOM 앵커)다. JSX 는 대소문자를 구분하므로 태그 이름으로
         * 정확히 갈린다. `A` 컴포넌트 자신의 구현부는 접두사를 붙이는 그 지점이라
         * 허용목록(`sitePath(`)으로 통과한다 (14차 G3 라운드).
         */
        const collectAnchorTargets = (body: string): { arg: string; line: number }[] => {
            const found: { arg: string; line: number }[] = [];
            // `<a` + (같은 태그 안의 다른 속성) + `href=` — `>` 를 넘지 않는다.
            const re = /<a\s[^>]*?href\s*=\s*(\{[^}]*\}|['"`][^'"`]*['"`])/g;
            let m: RegExpExecArray | null;

            while ((m = re.exec(body)) !== null) {
                found.push({ arg: m[1], line: lineNumberAt(body, m.index) });
            }

            return found;
        };

        /**
         * **오리진에 이어 붙여 밖으로 내보내는 사이트 경로**를 모은다.
         *
         * 앞의 네 수집기는 전부 「브라우저가 지금 여는 주소」를 본다. 그런데 같은
         * 접두사가 필요한 값이 하나 더 있다 — **우리가 만들어 외부 시스템에 건네고,
         * 나중에 그쪽이 브라우저를 되돌려 보내는 주소**다(PG 승인 복귀·본인인증 복귀·
         * 입금 통보). 그 값은 `fetch` 도 `location` 대입도 `<a>` 도 아니라
         * `window.location.origin + <사이트 경로>` 로 조립되므로 네 수집기 어디에도
         * 걸리지 않았다.
         *
         * 증상이 가장 나쁜 축이다: 결제는 **승인되고** 복귀만 사이트 밖으로 나가
         * 주문이 완결되지 않으며, 문서 루트에 다른 사이트가 있으면 그쪽이 결제 결과를
         * 받는다. 오류도 로그도 남지 않는다 (15차 G3 라운드).
         *
         * 판정 대상은 `origin` 바로 뒤에 이어 붙는 피연산자다. 그 자리가 접두사를
         * 붙이는 헬퍼로 감싸여 있으면 통과한다. 오리진 자체를 **비교**하는 형태
         * (`ev.origin !== window.location.origin`)는 `+` 를 요구하므로 걸리지 않는다.
         *
         * 이 축이 **변수도** 위반으로 보는 이유: 오리진에 이어 붙는 값은 정의상
         * 사이트 경로이므로, 리터럴이든 변수든 접두사를 거쳐야 한다. 앵커 축이
         * 표현식을 건너뛰는 것과 반대인데, 그쪽은 값이 완전한 외부 URL 일 수 있어
         * 판정이 서지 않기 때문이다.
         *
         * 반대로 폼 제출 목적지(`form.action = …`)는 **이 축에 넣지 않는다.** 그 자리에는
         * PG 가 내려준 완전한 절대 URL 이 그대로 오는 것이 정상이라(실측: 결제·본인인증
         * 플러그인 8곳이 전부 그 형태) 변수를 위반으로 보면 오탐만 남고, 리터럴로 좁히면
         * 실물이 0건이라 아무것도 막지 못한다. 그 통로의 사이트 경로는 오리진 결합
         * 시점에 이미 이 축이 판정한다.
         */
        const collectOriginJoinTargets = (body: string): { arg: string; line: number }[] => {
            const found: { arg: string; line: number }[] = [];
            const patterns = [
                // `window.location.origin + <피연산자>` / `origin + <피연산자>`
                /(?:window\s*\.\s*)?location\s*\.\s*origin\s*\+\s*([^\s,;)]+)/g,
                /(?<![.\w])origin\s*\+\s*([^\s,;)]+)/g,
                // `` `${origin}${<피연산자>}` `` — 템플릿 리터럴 결합
                /\$\{\s*(?:window\s*\.\s*)?location\s*\.\s*origin\s*\}\s*\$\{\s*([^}]+)\}/g,
            ];

            for (const re of patterns) {
                let m: RegExpExecArray | null;

                while ((m = re.exec(body)) !== null) {
                    found.push({ arg: m[1], line: lineNumberAt(body, m.index) });
                }
            }

            return found;
        };

        /**
         * 줄/블록 주석을 공백으로 바꿔 길이·줄 수를 보존한다.
         *
         * 문자열·템플릿 리터럴 **안**의 `//`·`/*` 는 주석이 아니므로 건너뛴다.
         * 정규식 두 번으로 걷어내던 종전 판본은 그 구분이 없어, `accept = 'image/*'`
         * 한 줄이 다음 `*&#47;` 까지를 통째로 주석으로 삼켰다 — `AvatarUploader.tsx` 에서
         * **260줄**(107~366)이 그렇게 지워졌고, 그 안에 있던 루트 상대 `fetch(uploadEndpoint)`·
         * `fetch(deleteEndpoint)` 두 건이 게이트에 한 번도 보이지 않았다. 저장소 전체로는
         * 165파일 1,360줄이 판정 밖이었다(14차 G3 라운드 실측). 같은 형태로 `"https://…"` 의
         * `//` 도 그 줄 나머지를 지운다.
         *
         * 문자열 **내용은 지우지 않는다** — 수집기가 첫 인자로 읽어야 하는 값이 그 자리에
         * 있기 때문이다(`fetch('/api/x')`). 여기서 하는 일은 「주석의 시작을 문자열 안에서
         * 찾지 않는 것」뿐이다.
         */
        const stripComments = (body: string): string => {
            const out = body.split('');
            const n = body.length;
            const blank = (from: number, to: number): void => {
                for (let k = from; k < to && k < n; k += 1) {
                    if (out[k] !== '\n') out[k] = ' ';
                }
            };

            let i = 0;
            while (i < n) {
                const c = body[i];

                if (c === '/' && body[i + 1] === '/') {
                    let j = i;
                    while (j < n && body[j] !== '\n') j += 1;
                    blank(i, j);
                    i = j;
                    continue;
                }

                if (c === '/' && body[i + 1] === '*') {
                    const end = body.indexOf('*/', i + 2);
                    const j = end === -1 ? n : end + 2;
                    blank(i, j);
                    i = j;
                    continue;
                }

                if (c === '"' || c === "'" || c === '`') {
                    let j = i + 1;
                    while (j < n) {
                        if (body[j] === '\\') {
                            j += 2;
                            continue;
                        }
                        if (body[j] === c) {
                            j += 1;
                            break;
                        }
                        // 미종결 따옴표가 파일 끝까지 삼키지 않도록 방어한다. 템플릿
                        // 리터럴만 개행을 넘는다.
                        if (c !== '`' && body[j] === '\n') break;
                        j += 1;
                    }
                    i = j;
                    continue;
                }

                i += 1;
            }

            return out.join('');
        };

        // @scenario base_path=subpath, fetch_origin=extension_bundle, navigation_direction=request
        // @effects extension_bundles_have_an_official_base_path_helper
        it('코어·확장 JS 의 서버 호출·화면 이동 주소는 전부 접두사를 거친 값이다', async () => {
            // 종전 이 검사는 **리터럴** `fetch('/…')` 한 형태만 봤다. 그래서 같은 파일에서
            // `const url = \`/api/…\`` 로 조립한 뒤 `fetch(url)` 하는 형태가 통째로 사각이었고,
            // 실제로 24곳이 그 상태로 남아 있었다 — 그중 하나는 `apiCall` 액션의 타깃이라
            // 번들 레이아웃의 API 주소 387건이 전부 그 경로를 탄다. 하위 폴더 배치에서는
            // 오류도 콘솔 메시지도 없이 **버튼이 반응하지 않는 것**이 유일한 증상이었다.
            //
            // 그래서 형태를 열거하는 대신 **첫 인자가 무엇인가**를 본다: 접두사 헬퍼를 거친
            // 값이거나, 루트 상대가 아닌 리터럴이어야 한다. 그 밖의 형태(변수·표현식)는
            // 판정할 수 없으므로 바로 위 줄의 `// base-path:allow <사유>` 로 의도를 남긴다
            // (11차 G3 라운드).
            const fs = await import('node:fs');
            const path = await import('node:path');

            const repoRoot = path.resolve(__dirname, '..', '..', '..', '..', '..');
            // 시작점은 `resources/js` 다 — `core` 하나로 두면 형제 진입 스크립트
            // (`app.js`·`bootstrap.js`)가 이 축 밖에 남는다. 지금은 그 둘에 주소가 없지만
            // 「없다」를 고정하는 것이 없었다 (7차 G3 라운드 B-7). `resources/js/installer`
            // 는 `__tests__` 뿐이고, 그 화면의 실제 스크립트는 `public/install/assets/js`
            // 에 있어 이 walk 밖이다 — 그 축은 아래 별도 단언이 잰다.
            const root = path.resolve(__dirname, ...CORE_SCAN_ROOT);
            const offenders: string[] = [];
            const navOffenders: string[] = [];
            // 전송 통로 축의 모집단 — 「루트 상대 리터럴 0」이 빈 모집단의 0 이 아님을 잰다.
            // **컨테이너별**로 센다 — 합계 하한 하나(`> 300`)는 코어 301 만으로 채워져 확장
            // 세 컨테이너를 통째로 잃어도 초록이었다 (16차 G3 라운드 F13).
            const transportFilesScanned: Record<string, number> = {};
            let transportContainer = 'resources/js';

            const walk = (dir: string): void => {
                for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
                    const full = path.join(dir, entry.name);
                    if (entry.isDirectory()) {
                        if (SKIP_DIRS.includes(entry.name)) continue;
                        walk(full);
                        continue;
                    }
                    // `.js`/`.mjs` 도 본다 — 바로 위 문단이 진입 스크립트
                    // (`app.js`·`bootstrap.js`)를 이 축 안이라고 적었는데 이 필터가
                    // 그것을 전부 걸러 내고 있었다. 그래서 그 두 파일에 루트 상대
                    // 주소를 넣어도 이 축은 초록이었다 — 「없다를 고정한다」는 서술이
                    // 고정하고 있지 않았다 (10차 G3 라운드 X-5).
                    if (!SOURCE_FILE_RE.test(entry.name)) continue;

                    const raw = fs.readFileSync(full, 'utf8');
                    const lines = raw.split('\n');
                    const body = stripComments(raw);

                    for (const { arg, line } of collectFetchArguments(body)) {
                        if (PREFIXING_HELPERS.some((h) => arg.startsWith(h))) continue;

                        // 루트 상대가 아닌 리터럴(절대 URL·상대 경로·blob: 등)은 대상이 아니다.
                        if (/^['"`]/.test(arg) && !/^['"`]\//.test(arg)) continue;

                        if (hasBasePathAllowMarker(lines, line)) continue;

                        offenders.push(`${path.relative(repoRoot, full)}:${line} → fetch(${arg}`);
                    }

                    // **이동 통로도 같은 모집단이다.** 12차는 「확장의 비-fetch 통로는
                    // 없다」고 판정하고 게이트를 fetch 축으로 유지했는데, 사실은 코어 1곳 +
                    // 번들 확장 7곳이 접두사 없이 남아 있었다. 그중 소셜 로그인은 폴백이
                    // 아니라 **유일 경로** 라 하위 폴더 사이트에서 무조건 사이트 밖으로
                    // 나갔다 (13차 G3 라운드).
                    for (const { arg, line } of collectNavigationTargets(body)) {
                        if (PREFIXING_HELPERS.some((h) => arg.startsWith(h))) continue;

                        // 루트 상대가 아닌 리터럴(절대 URL·상대 경로·`about:blank` 등)는 대상이 아니다.
                        if (/^['"`]/.test(arg) && !/^['"`]\//.test(arg)) continue;

                        if (hasBasePathAllowMarker(lines, line)) continue;

                        navOffenders.push(
                            `${path.relative(repoRoot, full)}:${line} → navigate(${arg}`
                        );
                    }

                    // **브라우저가 스스로 받아오는 주소도 같은 모집단이다.** `script.src` ·
                    // `link.href` · `img.src` · `form.action` 은 라우터를 거치지 않고
                    // 브라우저가 직접 요청하므로 접두사를 붙일 주체가 호출부밖에 없다.
                    // 이 통로는 어느 수집기에도 없어, 루트 상대 리터럴을 심어도 이 계약
                    // 검사와 218개 정적 룰이 전부 초록이었다 (변이 실측, 회차 6).
                    for (const { arg, line } of collectOutboundAssetTargets(body)) {
                        if (PREFIXING_HELPERS.some((h) => arg.startsWith(h))) continue;
                        if (/^['"`]/.test(arg) && !/^['"`]\//.test(arg)) continue;

                        if (hasBasePathAllowMarker(lines, line)) continue;

                        navOffenders.push(
                            `${path.relative(repoRoot, full)}:${line} → outbound(${arg}`
                        );
                    }

                    // **주소창 교체도 이동이다.** `history.pushState`/`replaceState` 의 세
                    // 번째 인자는 브라우저가 그대로 보게 될 주소인데, 13차까지 이 축은
                    // 어느 수집기에도 없었다 (14차 G3 라운드).
                    for (const { arg, line } of collectHistoryTargets(body)) {
                        if (PREFIXING_HELPERS.some((h) => arg.startsWith(h))) continue;
                        if (/^['"`]/.test(arg) && !/^['"`]\//.test(arg)) continue;

                        if (hasBasePathAllowMarker(lines, line)) continue;

                        navOffenders.push(
                            `${path.relative(repoRoot, full)}:${line} → history(${arg}`
                        );
                    }

                    // **`fetch` 가 아닌 전송 통로도 같은 모집단이다.** XHR·EventSource·
                    // WebSocket·Worker 는 브라우저가 직접 여는 주소다 (15차 G3 라운드 W4-6).
                    transportFilesScanned[transportContainer] = (transportFilesScanned[transportContainer] ?? 0) + 1;
                    for (const { arg, line } of collectTransportTargets(body)) {
                        if (!/^['"`]\//.test(arg) || /^['"`]\/\//.test(arg)) continue;

                        if (hasBasePathAllowMarker(lines, line)) continue;

                        navOffenders.push(
                            `${path.relative(repoRoot, full)}:${line} → transport(${arg}`
                        );
                    }

                    // **우리가 만들어 밖에 건네는 주소도 같은 접두사가 필요하다.**
                    // PG·인증기관이 그 주소로 브라우저를 되돌려 보낸다 (15차 G3 라운드).
                    for (const { arg, line } of collectOriginJoinTargets(body)) {
                        if (PREFIXING_HELPERS.some((h) => arg.startsWith(h))) continue;
                        // 루트 상대가 아닌 리터럴은 대상이 아니다.
                        if (/^['"`]/.test(arg) && !/^['"`]\//.test(arg)) continue;

                        if (hasBasePathAllowMarker(lines, line)) continue;

                        navOffenders.push(
                            `${path.relative(repoRoot, full)}:${line} → outbound(${arg}`
                        );
                    }

                    // **링크도 이동이다.** `A` 컴포넌트는 `href` 를 그대로 `<a>` 에 넘기고
                    // 앵커 클릭을 가로채는 지점이 없다 (14차 G3 라운드).
                    for (const { arg, line } of collectAnchorTargets(body)) {
                        const inner = arg.startsWith('{') ? arg.slice(1, -1).trim() : arg;

                        if (PREFIXING_HELPERS.some((h) => inner.startsWith(h))) continue;
                        if (/^['"`]/.test(inner) && !/^['"`]\//.test(inner)) continue;
                        // 표현식 형태(`{href}`·`{`${x}`}`)는 판정할 수 없다 — 리터럴만 본다.
                        if (!/^['"`]/.test(inner)) continue;

                        if (hasBasePathAllowMarker(lines, line)) continue;

                        navOffenders.push(
                            `${path.relative(repoRoot, full)}:${line} → href(${arg}`
                        );
                    }
                }
            };

            walk(root);

            // **확장도 같은 모집단이다.** 종전에는 코어 폴더 하나만 걸었고, 그래서 번들
            // 확장(결제 4종·본인인증·GDPR·사용자/관리자 템플릿·이커머스)의 서버 호출
            // 28곳이 한 번도 판정된 적이 없었다 — 전부 접두사가 없었고, 하위 폴더
            // 배치에서 결제·본인인증·반응 버튼이 조용히 무반응이 됐다. 게이트의
            // 모집단이 곧 그 라운드가 닫은 범위다 (11차가 남긴 규율, 12차 G3 라운드).
            for (const { base, sub } of EXTENSION_SOURCE_CONTAINERS) {
                const container = path.join(repoRoot, base);
                if (!fs.existsSync(container)) continue;
                transportContainer = base;

                for (const extension of fs.readdirSync(container, { withFileTypes: true })) {
                    if (!extension.isDirectory()) continue;
                    const dir = path.join(container, extension.name, sub);
                    if (fs.existsSync(dir)) walk(dir);
                }
            }

            // 두 배열을 **함께** 단언한다. 따로 단언하면 앞의 것이 실패하는 순간 뒤의
            // 축은 평가되지 않아, 한 번의 실행이 보여 주는 목록이 반쪽이 된다 — 실제로
            // 이동·주소창·링크 세 축을 신설한 직후 fetch 축 2건이 먼저 던져 나머지가
            // 통째로 가려졌다(14차 G3 라운드).
            expect([...offenders, ...navOffenders]).toEqual([]);
            // 전송 통로 축은 실물이 0 이라 offenders 만으로는 「빈 모집단」과 구분되지 않는다 —
            // 코어 + 번들 확장 전체를 실제로 걸었음을 **컨테이너별** 하한(실측 301/284/42/70 의
            // 80%)으로 잰다. 합계 하한 하나는 코어만으로 채워져 확장 셋을 잃어도 초록이었다.
            const TRANSPORT_FLOORS: Record<string, number> = {
                'resources/js': 240,
                'templates/_bundled': 227,
                'modules/_bundled': 33,
                'plugins/_bundled': 56,
            };
            for (const [container, floor] of Object.entries(TRANSPORT_FLOORS)) {
                expect(transportFilesScanned[container] ?? 0, `전송 통로 모집단이 비었다: ${container}`).toBeGreaterThanOrEqual(floor);
            }
        });

        // @scenario base_path=subpath, fetch_origin=extension_bundle, navigation_direction=request
        // @effects base_path_gate_population_includes_bundled_extensions
        it('확장 모집단이 실제로 걸려 있다 (빈 모집단 대조 — 컨테이너별)', async () => {
            // 위 검사는 offenders 가 비면 초록이므로, 확장 경로를 통째로 빠뜨려도
            // 똑같이 초록이다. 그 상태로 굳는 것을 막으려고 **모집단 자체**를 잰다.
            //
            // 합계 하나로 재면 안 된다 — 종전 판본은 `scanned > 50` 이었는데 세 컨테이너의
            // 실제 규모가 각각 282 / 42 / 67 이라, 모듈과 플러그인을 **둘 다** 잃어도
            // 282 > 50 으로 초록이었다. 12차가 당한 것이 바로 그 형태(모집단이 코어
            // 폴더 하나뿐이라 확장 28곳이 한 번도 판정된 적 없음)이므로, 컨테이너마다
            // 따로 세어 하나라도 비면 red 가 되게 한다 (14차 G3 라운드).
            const fs = await import('node:fs');
            const path = await import('node:path');

            const repoRoot = path.resolve(__dirname, '..', '..', '..', '..', '..');

            // 필터는 **위반 스캔과 같은 표**에서 도출한다. 리터럴 사본으로 두면 한쪽만
            // 넓어져 「스캔은 하는데 세지는 않는」 파일이 생긴다 (16차 G3 라운드 A-6).
            //
            // 개수가 아니라 **목록**을 돌려준다 — 아래 진입 스크립트 대조가 「몇 개인가」가
            // 아니라 「무엇이 들어 있는가」를 물어야 하기 때문이다.
            const listIn = (dir: string): string[] => {
                const found: string[] = [];
                for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
                    const full = path.join(dir, entry.name);
                    if (entry.isDirectory()) {
                        if (SKIP_DIRS.includes(entry.name)) continue;
                        found.push(...listIn(full));
                        continue;
                    }
                    if (SOURCE_FILE_RE.test(entry.name)) found.push(full);
                }
                return found;
            };

            const perContainer: Record<string, number> = {};

            for (const { base, sub } of EXTENSION_SOURCE_CONTAINERS) {
                let n = 0;
                const container = path.join(repoRoot, base);
                if (fs.existsSync(container)) {
                    for (const extension of fs.readdirSync(container, { withFileTypes: true })) {
                        if (!extension.isDirectory()) continue;
                        const dir = path.join(container, extension.name, sub);
                        if (fs.existsSync(dir)) n += listIn(dir).length;
                    }
                }
                perContainer[base] = n;
            }

            // 코어도 같은 이유로 함께 잰다 — walk 의 시작점이 사라져도 확장 합계만으로는
            // 드러나지 않는다.
            //
            // 루트는 **위반 스캔과 같은 곳**(`resources/js`)이다. 종전에는 한 단계 아래
            // (`resources/js/core`)를 세어, 그 사이에 있는 진입 스크립트
            // (`app.js`·`bootstrap.js`)가 어느 축에도 없었다 (16차 G3 라운드 A-6).
            const coreRoot = path.resolve(__dirname, ...CORE_SCAN_ROOT);
            const coreFiles = listIn(coreRoot);
            perContainer['resources/js'] = coreFiles.length;

            for (const [container, n] of Object.entries(perContainer)) {
                expect(n, `모집단이 비었다: ${container}`).toBeGreaterThan(10);
            }

            // **개수 하한만으로는 이 축이 서지 않는다.**
            //
            // 계수 루트를 한 단계 아래(`resources/js/core`)로 좁혀도, 확장자를
            // `.ts|.tsx` 로 되돌려도 코어 계수는 여전히 수백이라 `>10` 을 넘는다. 실제로
            // 종전 판본이 그 상태였고 — 계수 루트는 `core`, 필터는 `.ts|.tsx` — 스캔 루트
            // 바로 아래의 진입 스크립트(`app.js`·`bootstrap.js`)는 **어느 검사에도** 없었다.
            // 그래서 「몇 개인가」가 아니라 「무엇이 들어 있는가」를 잰다
            // (16차 G3 라운드 A-6).
            // 루트 자체가 옮겨지면 그 아래에서 도출한 목록도 함께 옮겨져 자기충족이 된다
            // — 실제로 계수 루트를 `core` 로 좁히는 변이가 이 대조를 통과했다. 그래서
            // 루트의 **위치**를 먼저 고정한다: 코어 디렉토리를 진부분집합으로 품어야 한다.
            const coreDir = path.resolve(__dirname, '..', '..');

            expect(
                coreRoot !== coreDir && coreDir.startsWith(coreRoot + path.sep),
                `스캔 루트가 코어 디렉토리 안으로 좁혀졌다 (${coreRoot}) — 형제 진입 스크립트가 축 밖이 된다`
            ).toBe(true);

            const rootEntryScripts = fs
                .readdirSync(coreRoot, { withFileTypes: true })
                .filter((e) => e.isFile() && SOURCE_FILE_RE.test(e.name))
                .map((e) => e.name);

            expect(
                rootEntryScripts.length,
                '스캔 루트 바로 아래에서 소스 파일을 하나도 찾지 못했다 — 루트가 옮겨졌다'
            ).toBeGreaterThan(0);

            const coreRelative = coreFiles.map((f) => path.relative(coreRoot, f));

            for (const name of rootEntryScripts) {
                expect(
                    coreRelative,
                    `진입 스크립트 ${name} 가 모집단 밖이다 — 스캔 루트나 확장자 필터가 갈렸다`
                ).toContain(name);
            }
        });

        it('열거한 fetch 래퍼가 실재하고, 저장소에 다른 래퍼가 생기지 않았다', async () => {
            const fs = await import('node:fs');
            const path = await import('node:path');

            // 이 목록이 낡으면 그 래퍼를 거치는 호출이 **통째로 수집 밖**이 된다 —
            // 실제로 `fetchWithRetry` 네 곳이 그 상태였고, 그 자리에 루트 상대 주소를
            // 넣어도 이 검사와 정적 룰이 전부 초록이었다(변이 실측, 회차 6).
            // 그래서 ① 열거한 이름이 실재하는가 ② 열거되지 않은 래퍼가 생겼는가 를 함께 잰다.
            const wrapperDefinition = /export\s+(?:async\s+)?function\s+(fetch[A-Za-z]\w*)\s*\(/g;
            const defined = new Set<string>();

            // 읽은 파일 수를 **컨테이너별로** 센다.
            //
            // 종전 비공허성은 `defined.size > 0` 하나였다. 그런데 실질 고정인
            // `NON_URL_FETCH_EXPORTS` 네 이름이 전부 `plugins/_bundled/**` 에 살고
            // 코어에도 `fetchWithRetry` 가 있어, **템플릿·모듈 컨테이너를 스캔에서
            // 통째로 빼도** 그 조건이 충족되고 아래 두 단언도 전부 초록이었다
            // (16차 G3 라운드 A-7).
            let scanned = 0;

            const scan = (dir: string): void => {
                if (!fs.existsSync(dir)) return;
                for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
                    const full = path.join(dir, entry.name);
                    if (entry.isDirectory()) {
                        if (SKIP_DIRS.includes(entry.name)) continue;
                        scan(full);
                        continue;
                    }
                    if (!SOURCE_FILE_RE.test(entry.name)) continue;
                    scanned += 1;

                    const src = fs.readFileSync(full, 'utf8');
                    let m: RegExpExecArray | null;
                    wrapperDefinition.lastIndex = 0;
                    while ((m = wrapperDefinition.exec(src)) !== null) defined.add(m[1]);
                }
            };

            // 모집단은 **위반 스캔과 같은 표**에서 도출한다.
            //
            // 종전에는 코어의 `core/` 아래 `.ts` 만 봤다. 그런데 위반 스캔은 코어
            // (`resources/js`) + 번들 확장 3컨테이너를 전부 훑는다 — 즉 「위반이 있는가」와
            // 「새 래퍼가 생겼는가」의 모집단이 서로 달랐다. 확장에 URL 을 첫 인자로 받는
            // 래퍼가 생기는 순간 그 호출부가 통째로 수집 밖이 되고 **아무것도 울지 않는다**
            // (6차가 `fetchWithRetry` 로 당한 형태 그대로) (10차 G3 라운드 X-4).
            const scanRepoRoot = path.resolve(__dirname, '..', '..', '..', '..', '..');

            const perContainer: Record<string, number> = {};

            scanned = 0;
            scan(path.resolve(__dirname, ...CORE_SCAN_ROOT));
            perContainer['resources/js'] = scanned;

            for (const { base, sub } of EXTENSION_SOURCE_CONTAINERS) {
                scanned = 0;
                const container = path.join(scanRepoRoot, base);
                if (fs.existsSync(container)) {
                    for (const ext of fs.readdirSync(container, { withFileTypes: true })) {
                        if (!ext.isDirectory()) continue;
                        scan(path.join(container, ext.name, sub));
                    }
                }
                perContainer[base] = scanned;
            }

            expect(defined.size, '`fetch…` 로 시작하는 export 함수를 하나도 찾지 못했다 — 이 축이 아무것도 재지 못한다')
                .toBeGreaterThan(0);

            // 합계 하나로 재면 한 컨테이너가 통째로 빠져도 드러나지 않는다 — 컨테이너마다
            // 따로 세어 하나라도 비면 red 가 되게 한다 (빈 모집단 대조와 같은 형태).
            for (const [container, n] of Object.entries(perContainer)) {
                expect(n, `래퍼 인벤토리 모집단이 비었다: ${container}`).toBeGreaterThan(10);
            }

            for (const name of RESILIENT_FETCH_WRAPPERS) {
                expect(defined.has(name), `열거한 래퍼 \`${name}\` 가 코어에 없다 — 이름이 바뀌었다면 목록도 함께 고친다`)
                    .toBe(true);
            }

            // `fetch…` export 중 **URL 을 첫 인자로 받는** 것만 래퍼다. 그 판정은 정적으로
            // 할 수 없으므로, 새 이름이 생기면 사람이 분류하도록 여기서 멈춘다.
            //
            // 모집단을 번들 확장까지 넓힌 직후 걸린 넷을 **전수로 읽어** 분류했다 —
            // 넓히는 것과 좁히는 것은 같은 작업의 두 단계이고, 분류하지 않고 두면 그
            // 게이트를 사람이 곧 믿지 않게 된다 (9차 A-3 이 세운 규율).
            const NON_URL_FETCH_EXPORTS: Record<string, string> = {
                // 인자가 없다. 주소는 내부에서 `getJson('/plugins/…')` → `G7Core.api`
                // (또는 확장 `apiUrl()` 폴백)로 만들어지므로 그 축은 이미 재고 있다.
                fetchPublicSettings: 'sirsoft-gdpr — 무인자, 주소는 내부에서 apiUrl 경유',
                fetchConsentSnapshot: 'sirsoft-gdpr — 무인자, 주소는 내부에서 apiUrl 경유',
                // 첫 인자가 주문번호다(주소가 아니다).
                fetchKginicisReceiptInfoDetailed: 'sirsoft-pay_kginicis — 첫 인자는 orderNumber',
                fetchKginicisReceiptInfo: 'sirsoft-pay_kginicis — 첫 인자는 orderNumber (위 함수에 위임)',
            };

            const unlisted = [...defined].filter(
                (n) => !RESILIENT_FETCH_WRAPPERS.includes(n) && !(n in NON_URL_FETCH_EXPORTS)
            );

            expect(unlisted, '새 `fetch…` export 가 생겼다 — URL 을 첫 인자로 받는다면 RESILIENT_FETCH_WRAPPERS 에 넣고, 아니면 NON_URL_FETCH_EXPORTS 에 사유와 함께 추가한다')
                .toEqual([]);

            // 분류표가 사문화되지 않게 한다 — 이름이 사라지면 그 행도 지운다.
            for (const name of Object.keys(NON_URL_FETCH_EXPORTS)) {
                expect(defined.has(name), `NON_URL_FETCH_EXPORTS 의 \`${name}\` 가 저장소에 없다 — 사라진 이름은 표에서도 지운다`)
                    .toBe(true);
            }
        });

        it('그 검사 자신이 위반을 실제로 잡는다 (음성 대조)', () => {
            // 게이트가 아무것도 잡지 못하는 상태로 굳는 것을 막는다 — 정규식을 무력화해도
            // 위 검사는 초록이므로, 심어 둔 위반이 검출되는지 여기서 확인한다.
            const sample = [
                "const url = `/api/admin/x`;",
                'const res = await fetch(url, { method: "GET" });',
            ].join('\n');

            const args = collectFetchArguments(stripComments(sample));

            expect(args).toHaveLength(1);
            expect(args[0].arg).toBe('url');
            expect(PREFIXING_HELPERS.some((h) => args[0].arg.startsWith(h))).toBe(false);
        });

        it('전송 통로 수집기가 XHR·EventSource·WebSocket·Worker 의 루트 상대 리터럴을 잡는다 (음성 대조)', () => {
            // 실물이 0 인 축은 수집기가 죽어도 초록이다 — 심어 둔 네 형태가 검출되는지 여기서 본다.
            const sample = [
                "const xhr = new XMLHttpRequest(); xhr.open('POST', '/api/upload');",
                "const es = new EventSource('/api/events');",
                "const ws = new WebSocket('/ws');",
                "const w = new Worker('/build/worker.js');",
                // `window.open(` 은 이동 축의 것이다 — 여기서 잡히면 두 축이 겹친다.
                "window.open('/popup');",
                // 절대 URL 은 대상이 아니다.
                "new WebSocket('wss://example.com/ws');",
            ].join('\n');

            const args = collectTransportTargets(stripComments(sample)).map((a) => a.arg);

            // 형제 수집기와 같은 형태 — 여는 따옴표까지 포함한 리터럴 머리만 잡는다.
            expect(args).toEqual(["'/api/upload", "'/api/events", "'/ws", "'/build/worker.js", "'wss://example.com/ws"]);
            expect(args.filter((a) => /^['"`]\//.test(a) && !/^['"`]\/\//.test(a))).toHaveLength(4);
        });

        it('이행 도출이 닿지 않는 형태를 명시한다 (사각 선언)', () => {
            // 이 검사는 **결함을 잡지 않는다.** 도출이 어디까지 닿는지를 코드로 적어
            // 두는 것이 목적이다.
            //
            // 도출은 두 가정 위에 서 있다 — ① 파일 단위 ② `const/let/var` 선언의
            // 우변. 그 밖의 형태는 원리적으로 닿지 않는데, 그 사실이 어디에도 없어서
            // 회차마다 같은 사각을 다시 파냈다. 전 저장소 실측 결과 아래 네 형태의
            // **실물은 0건**이므로 지금 넓히지 않는다 — 넓히면 옵션 객체를 받는 내부
            // 헬퍼가 무더기로 걸려 오탐이 된다(9차 A-3 이 실제로 겪은 것).
            //
            // 실물이 생기면 이 케이스가 red 로 알려 준다 (10차 G3 라운드 X-8).
            const blindSpots = [
                // ① 구조분해
                'const { fetch: f } = window;\nf("/api/x");',
                // ② 멤버 호출 — 수집기의 `(?<![.\w])` 가 막는다
                'this.fetchImpl("/api/x");',
                // ③ 선언 없는 재대입
                'let f;\nf = fetchImpl;\nf("/api/x");',
                // ④ 전역 직접 호출
                'globalThis.fetch("/api/x");',
            ];

            for (const sample of blindSpots) {
                const args = collectFetchArguments(stripComments(sample));
                const rootRelative = args.filter((a) => a.arg.startsWith('"/') || a.arg.startsWith("'/"));

                expect(
                    rootRelative,
                    `이 형태가 이제 도출된다 — 사각이 닫혔으니 이 케이스를 위반 축으로 옮긴다:\n${sample}`
                ).toEqual([]);
            }
        });

        // @scenario base_path=subpath, fetch_origin=extension_bundle, navigation_direction=navigation
        // @effects browser_built_navigation_targets_carry_the_mount_prefix
        it('그 검사 자신이 이동 위반을 실제로 잡는다 (음성 대조)', () => {
            // 이동 축은 12차까지 게이트가 없었다 — 새 수집기가 아무것도 잡지 못하는
            // 상태로 굳는 것을 막는다.
            const sample = [
                "window.location.href = `/api/auth/${p}`;",
                "location.assign('/admin/login');",
                "window.open('/receipt');",
            ].join(String.fromCharCode(10));

            const args = collectNavigationTargets(stripComments(sample)).map((f) => f.arg);

            expect(args).toHaveLength(3);
            expect(args.every((a) => !PREFIXING_HELPERS.some((h) => a.startsWith(h)))).toBe(true);
        });

        it('읽기·비교는 이동이 아니다 (오탐 대조)', () => {
            // `new URL(x, window.location.href)` · `location.href === y` 는 주소를 **읽는**
            // 자리다. 이것까지 잡으면 게이트가 오탐으로 덮여 실제 위반이 묻힌다.
            const sample = [
                'const u = new URL(x, window.location.href);',
                "if (location.href === '/a') return;",
                "if (location.href !== '/b') return;",
                'const r = { return_url: window.location.href };',
            ].join(String.fromCharCode(10));

            expect(collectNavigationTargets(stripComments(sample))).toEqual([]);
        });

        it('주석 안의 fetch( 는 위반이 아니다 (오탐 대조)', () => {
            const sample = ['// fetch(/api/x) 를 설명하는 주석', 'const a = 1;'].join('\n');

            expect(collectFetchArguments(stripComments(sample))).toEqual([]);
        });

        // @scenario base_path=subpath, fetch_origin=extension_bundle, navigation_direction=navigation
        // @effects browser_built_navigation_targets_carry_the_mount_prefix
        it('문자열 안의 주석 기호가 뒤 코드를 가리지 않는다 (마스킹 회귀)', () => {
            // 종전 판본은 주석을 정규식 두 번으로 걷어내 **문자열 안**의 `/*`·`//` 도
            // 주석 시작으로 삼았다. `accept = 'image/*'` 한 줄이 다음 `*` + `/` 까지를
            // 통째로 지워 `AvatarUploader.tsx` 에서 260줄(107~366)이 사라졌고, 그 안의
            // 루트 상대 `fetch(uploadEndpoint)`·`fetch(deleteEndpoint)` 두 건이 게이트에
            // 한 번도 보이지 않았다. 저장소 전체로 165파일 1,360줄이 판정 밖이었다.
            const blockOpener = ['/', '*'].join('');
            const blockCloser = ['*', '/'].join('');

            // ① 문자열 안의 블록 주석 시작 — 그 뒤 여러 줄이 살아 있어야 한다.
            const masked = [
                `const accept = 'image${blockOpener}';`,
                'const res = await fetch(uploadEndpoint, { method: "POST" });',
                `${blockOpener} 여기서부터가 진짜 주석 ${blockCloser}`,
            ].join(String.fromCharCode(10));

            expect(collectFetchArguments(stripComments(masked)).map((f) => f.arg)).toEqual([
                'uploadEndpoint',
            ]);

            // ② 문자열 안의 `//` — 같은 줄 뒤쪽이 살아 있어야 한다.
            const inlineUrl = 'const cdn = "https://cdn.example.com"; const r = fetch(target);';

            expect(collectFetchArguments(stripComments(inlineUrl)).map((f) => f.arg)).toEqual([
                'target',
            ]);

            // ③ 진짜 주석은 여전히 걷어낸다 (①의 반대 방향).
            const realComment = `${blockOpener} fetch(hidden) ${blockCloser} const a = 1;`;

            expect(collectFetchArguments(stripComments(realComment))).toEqual([]);
        });

        // @scenario base_path=subpath, fetch_origin=extension_bundle, navigation_direction=navigation
        // @effects browser_built_navigation_targets_carry_the_mount_prefix
        it('그 검사 자신이 주소창 교체를 실제로 잡는다 (음성 대조)', () => {
            // `location.replace(` 와 `history.replaceState(` 는 다른 문자열이라 이동
            // 수집기 셋 중 어느 것에도 걸리지 않았다. 그 사각에 `updateQueryParams` 가
            // 남아 목록의 검색·정렬·페이지 이동이 주소창을 마운트 밖으로 바꿨다.
            const sample = [
                "window.history.replaceState(null, '', newPath);",
                "window.history.pushState({ source: 'x', routePath: p }, '', nextUrl);",
                "history.replaceState(null, '', '/admin/users?page=2');",
                // 주소를 싣지 않는 2인자 호출은 대상이 아니다.
                'window.history.replaceState(null, undefined);',
            ].join(String.fromCharCode(10));

            const args = collectHistoryTargets(stripComments(sample)).map((f) => f.arg);

            expect(args).toEqual(['newPath', 'nextUrl', "'/admin/users?page=2'"]);
        });

        // @scenario base_path=subpath, fetch_origin=extension_bundle, navigation_direction=navigation
        // @effects browser_built_navigation_targets_carry_the_mount_prefix
        it('그 검사 자신이 날 앵커를 잡고 A 컴포넌트는 통과시킨다 (음성·오탐 대조)', () => {
            // 접두사를 붙이는 자리는 템플릿 `A` 컴포넌트 한 곳이다. 따라서 판정 대상은
            // 그 자리를 지나지 않는 **소문자** 날 앵커뿐이고, 대문자 `A`(컴포넌트)는
            // 통과해야 한다 — 잡으면 오탐으로 덮여 실제 위반이 묻힌다.
            const sample = [
                '<a href="/admin/users">회원</a>',
                '<a className="x" href={"/admin/roles"}>역할</a>',
                '<A href="/admin/settings">설정</A>',
                '<a href="https://example.com">외부</a>',
                '<a href="#top">앵커</a>',
            ].join(String.fromCharCode(10));

            const args = collectAnchorTargets(stripComments(sample)).map((f) => f.arg);

            // 소문자 앵커 넷만 수집된다 (대문자 `A` 는 제외).
            expect(args).toEqual(['"/admin/users"', '{"/admin/roles"}', '"https://example.com"', '"#top"']);

            // 그중 루트 상대인 둘만 위반으로 남는다 (외부 URL·앵커는 대상 아님).
            const offending = args.filter((a) => {
                const inner = a.startsWith('{') ? a.slice(1, -1).trim() : a;
                if (!/^['"`]/.test(inner)) return false;
                return /^['"`]\//.test(inner);
            });

            expect(offending).toEqual(['"/admin/users"', '{"/admin/roles"}']);
        });

        /**
         * 「이 값이 이미 절대인가」를 호출부가 직접 판정하고 **접두사 없이** 오리진을
         * 붙이는 형태를 모은다 — 서버 룰 `asset-url-site-absolute-builder` 의 프론트 짝.
         *
         *   PRED ? x : location.origin + x
         *   PRED ? x : new URL(x, location.origin)
         *   if (PRED) { return x; } … location.origin + x
         *
         * PRED 는 `x.startsWith('http')` · `/^https?:/.test(x)`. `location.origin +
         * sitePath(x)` 처럼 접두사 헬퍼를 거친 형태는 대상이 아니다 — 그 값은 이미
         * 접두사를 갖고 있다.
         *
         * 사각 선언: 정규식 리터럴 안에 `//` 가 든 형태(`/^https?:\/\//`)는 위
         * `stripComments` 가 그 자리부터 줄 끝까지 주석으로 걷어내므로 이 수집기에
         * 닿지 않는다. `startsWith` 형태가 이 저장소의 실물 전부라 그 축은 열어 둔다.
         *
         * 왜 막는가: 그 3항식은 `http` 로 시작하는 한 형태만 안다. 프로토콜 상대·상대
         * 경로·`data:` 는 오리진에 그대로 이어 붙고, 루트 상대에는 마운트 접두사가
         * 채워지지 않아 하위 폴더 사이트에서 사이트 밖을 가리킨다 (13차 G3 라운드 W3-7).
         */
        const NAIVE_ABSOLUTE_PREDICATE =
            String.raw`(?:([A-Za-z_$][\w$]*)\s*\.\s*startsWith\(\s*['"]https?[^'"]*['"]\s*\)|\/\^https?[^\/\n]*\/[a-z]*\s*\.\s*test\(\s*([A-Za-z_$][\w$]*)\s*\))`;

        const collectNaiveAbsoluteJudgments = (body: string): Array<{ line: number; arg: string }> => {
            const found: Array<{ line: number; arg: string }> = [];
            const seen = new Set<number>();

            const ternary = new RegExp(
                NAIVE_ABSOLUTE_PREDICATE +
                    String.raw`[^;]{0,200}?\?\s*([A-Za-z_$][\w$]*)\s*:\s*(?:[^;\n]{0,80}?location\.origin\s*\+\s*([A-Za-z_$][\w$]*)\b|new\s+URL\(\s*([A-Za-z_$][\w$]*)\s*,\s*[^)]*location\.origin)`,
                'g'
            );
            const earlyReturn = new RegExp(
                String.raw`if\s*\(\s*` +
                    NAIVE_ABSOLUTE_PREDICATE +
                    String.raw`[^{]{0,80}\)\s*\{\s*return\s+([A-Za-z_$][\w$]*)\s*;\s*\}[\s\S]{0,400}?location\.origin\s*\+\s*([A-Za-z_$][\w$]*)\b`,
                'g'
            );

            const push = (index: number, subject: string, returned: string, appended: string): void => {
                // 같은 값의 두 갈래여야 「절대/상대 판정을 흉내 낸」 것이다.
                if (subject !== returned || returned !== appended) return;
                const line = lineNumberAt(body, index);
                if (seen.has(line)) return;
                seen.add(line);
                found.push({ line, arg: subject });
            };

            let m: RegExpExecArray | null;
            while ((m = ternary.exec(body)) !== null) {
                push(m.index, m[1] ?? m[2], m[3], m[4] ?? m[5]);
            }
            while ((m = earlyReturn.exec(body)) !== null) {
                push(m.index, m[1] ?? m[2], m[3], m[4]);
            }

            return found;
        };

        it('코어·확장 JS 에 절대/상대 판정을 흉내 내는 오리진 조립이 없다 (서버 룰의 프론트 짝)', async () => {
            const fs = await import('node:fs');
            const path = await import('node:path');

            const repoRoot = path.resolve(__dirname, '..', '..', '..', '..', '..');
            const offenders: string[] = [];

            const walk = (dir: string): void => {
                for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
                    const full = path.join(dir, entry.name);
                    if (entry.isDirectory()) {
                        if (SKIP_DIRS.includes(entry.name)) continue;
                        walk(full);
                        continue;
                    }
                    if (!SOURCE_FILE_RE.test(entry.name)) continue;

                    const raw = fs.readFileSync(full, 'utf8');
                    const lines = raw.split('\n');

                    for (const { line, arg } of collectNaiveAbsoluteJudgments(stripComments(raw))) {
                        if (hasBasePathAllowMarker(lines, line)) continue;
                        offenders.push(`${path.relative(repoRoot, full)}:${line} → ${arg}`);
                    }
                }
            };

            walk(path.resolve(__dirname, ...CORE_SCAN_ROOT));

            for (const { base, sub } of EXTENSION_SOURCE_CONTAINERS) {
                const container = path.join(repoRoot, base);
                if (!fs.existsSync(container)) continue;
                for (const extension of fs.readdirSync(container, { withFileTypes: true })) {
                    if (!extension.isDirectory()) continue;
                    const dir = path.join(container, extension.name, sub);
                    if (fs.existsSync(dir)) walk(dir);
                }
            }

            expect(
                offenders,
                `절대/상대 판정을 호출부가 직접 하고 오리진을 접두사 없이 붙이는 지점:\n${offenders.join('\n')}`
            ).toEqual([]);
        });

        it('그 검사 자신이 오리진 조립 3항식·조기 return 을 잡고 접두사 경유는 통과시킨다 (음성·오탐 대조)', () => {
            const sample = [
                "const a = url.startsWith('http') ? url : window.location.origin + url;",
                'const b = /^https?:/i.test(href) ? href : new URL(href, location.origin);',
                "function abs(u: string) { if (u.startsWith('https://')) { return u; } return location.origin + u; }",
                // 접두사 헬퍼를 거친 값은 대상이 아니다.
                "const c = url.startsWith('http') ? url : window.location.origin + sitePath(url);",
                // 다른 값의 두 갈래는 판정 흉내가 아니다.
                "const d = url.startsWith('http') ? url : window.location.origin + other;",
            ].join(String.fromCharCode(10));

            const found = collectNaiveAbsoluteJudgments(stripComments(sample));

            expect(found.map((f) => `${f.line}:${f.arg}`)).toEqual(['1:url', '2:href', '3:u']);
        });
    });

    describe('코어 헬퍼와 확장 사본이 **같은 답을 낸다** (거동 계약)', () => {
        // 형제 축(아래)은 사본**끼리** 같은지만 잰다. 그래서 사본 전부가 한꺼번에
        // 코어와 갈리는 방향은 열려 있었다 — 22벌이 사이좋게 같은 오답을 내면 그 축은
        // 초록이다. 붙이는 쪽(`withBasePrefix` ↔ `sitePath`)과 벗기는 쪽
        // (`stripBasePrefix` ↔ `routePath`)은 방향이 반대라, 한쪽만 재는 검사로는
        // 다른 쪽이 통째로 빠져도 드러나지 않는다.
        const CASES = [
            '/',
            '/admin',
            '/admin/dashboard',
            '/board/notice/1',
            '/g7',
            '/g7/',
            '/g7/admin',
            '/g7/admin/dashboard',
            '//evil.example/p',
            '/g7//evil.example/p',
            '/공지/1',
            '/a b/c',
            // 접두사 바로 뒤가 `?`·`#` 이면 접두사 보유 — 회차 13 이중 접두 결함의 입력
            '/g7?tab=1',
            '/g7#top',
            '/g7?',
            '/g7x/foo',
            '/g7/admin?x=1#f',
            '',
            // 앞뒤 공백 — 코어 `withBasePrefix` 와 서버 `AssetUrl::sitePath()` 는 판정 전에
            // 걷어내는데 확장 사본 13벌은 걷어내지 않아, 같은 입력이 코어에서는 접두사를
            // 얻고 확장에서는 접두사 없이 나갔다 (회차 14 W1).
            ' /api/x',
            '/api/x ',
            '\t/admin',
            ' /g7?x',
            // 회귀 고정 — 인코딩된 `?`·대소문자·상위 이동·경계 없는 이웃은 접두사 보유가 아니다
            '/g7%3Fx',
            '/G7/',
            '/g7/../.env',
            '/g7x',
        ];

        const loadCopy = async (): Promise<Record<string, (p: string) => string>> => {
            const fs = await import('node:fs');
            const path = await import('node:path');
            const repoRoot = path.resolve(__dirname, '..', '..', '..', '..', '..');

            for (const { base, helper } of EXTENSION_SOURCE_CONTAINERS) {
                const container = path.join(repoRoot, base);
                if (!fs.existsSync(container)) continue;

                for (const extension of fs.readdirSync(container, { withFileTypes: true })) {
                    if (!extension.isDirectory()) continue;
                    const full = path.join(container, extension.name, path.join(...helper));
                    if (fs.existsSync(full)) return (await import(/* @vite-ignore */ full)) as any;
                }
            }

            throw new Error('확장 접두사 헬퍼 사본을 하나도 찾지 못했다 — 이 검사가 아무것도 재지 않는다');
        };

        for (const basePath of ['', '/g7', '/my/public/site']) {
            it(`접두사 \`${basePath || '(없음)'}\` 에서 붙이는 쪽·벗기는 쪽이 코어와 일치한다`, async () => {
                (globalThis as any).G7Config = { ...(globalThis as any).G7Config, basePath };

                const copy = await loadCopy();

                for (const input of CASES) {
                    expect(copy.sitePath(input), `sitePath('${input}')`).toBe(withBasePrefix(input));
                    expect(copy.routePath(input), `routePath('${input}')`).toBe(stripBasePrefix(input));
                }

                delete (globalThis as any).G7Config.basePath;
            });
        }
    });

    describe('확장이 가진 접두사 헬퍼 사본은 서로 같다 (소스 계약)', () => {
        // 확장 번들은 코어와 별도 IIFE 라 `import` 를 못 한다 — 그래서 같은 헬퍼가
        // 확장마다 **사본**으로 들어가 있다. 사본은 갈라도 아무 것도 울지 않는다 — 그
        // 확장만 다른 규칙을 따르게 되고, 증상은 그 화면의 버튼만 무반응인 것이라
        // 다른 확장이 정상인 만큼 원인이 더 숨는다. 저장소 선례(패리티 계약 테스트)를
        // 따라 동형성 자체를 재다 (13차 G3 라운드).
        type HelperCopy = { path: string; container: string; content: string };

        /**
         * 컨테이너별 사본 경로를 훑어 `{ path, container, content }` 를 모은다.
         *
         * `apiUrl.ts` 만이 아니다 — 템플릿의 `Img.tsx`·`Form.tsx` 도 같은 규칙의 사본이다
         * (`src`/`action` 에 `sitePath()` 를 붙이는 자리). 한 템플릿의 사본만 갈리면 그
         * 템플릿의 이미지·폼 제출만 하위 폴더 배치에서 사이트 밖으로 나가고, 다른 템플릿이
         * 정상인 만큼 원인이 더 숨는다 (16차 G3 라운드 A10).
         */
        const collectCopiesOf = async (
            spec: ReadonlyArray<{ base: string; helper: readonly string[] }>,
        ): Promise<HelperCopy[]> => {
            const fs = await import('node:fs');
            const path = await import('node:path');
            const repoRoot = path.resolve(__dirname, '..', '..', '..', '..', '..');
            const copies: HelperCopy[] = [];

            for (const { base, helper } of spec) {
                const sub = path.join(...helper);
                const container = path.join(repoRoot, base);
                if (!fs.existsSync(container)) continue;

                for (const extension of fs.readdirSync(container, { withFileTypes: true })) {
                    if (!extension.isDirectory()) continue;
                    const full = path.join(container, extension.name, sub);
                    if (!fs.existsSync(full)) continue;
                    copies.push({
                        path: path.relative(repoRoot, full),
                        container: base,
                        content: fs.readFileSync(full, 'utf8'),
                    });
                }
            }

            return copies;
        };

        const collectHelperCopies = () => collectCopiesOf(EXTENSION_SOURCE_CONTAINERS);

        /** 사본 군(群) — 군마다 따로 동형이어야 한다 (apiUrl 과 Img 가 같을 이유는 없다). */
        const COPY_FAMILIES = [
            { name: 'apiUrl.ts', spec: EXTENSION_SOURCE_CONTAINERS },
            {
                name: 'Img.tsx',
                spec: [{ base: 'templates/_bundled', sub: 'src', helper: ['src', 'components', 'basic', 'Img.tsx'] }],
            },
            {
                name: 'Form.tsx',
                spec: [{ base: 'templates/_bundled', sub: 'src', helper: ['src', 'components', 'basic', 'Form.tsx'] }],
            },
            // 링크의 소유자. 이 군만 **논리 동형**(주석 제외)으로 잰다 — 위 `withoutBlockComments`
            // 의 주석이 그 근거다.
            {
                name: 'A.tsx',
                normalize: true,
                spec: [{ base: 'templates/_bundled', sub: 'src', helper: ['src', 'components', 'basic', 'A.tsx'] }],
            },
        ] as const;

        /**
         * 블록 주석을 걷어낸 본문 — **논리만** 비교하는 군에 쓴다.
         *
         * `A.tsx` 는 네 벌의 코드가 완전히 같은데 머리말 산문만 다르다(샘플 템플릿 둘은
         * 짧은 판본, 실사용 템플릿 둘은 회귀 경위를 담은 긴 판본). 그 군까지 바이트 동일을
         * 요구하면 샘플 확장에 다른 템플릿의 레이아웃 사정을 적어 넣게 되므로, 이 군의
         * 계약은 「논리가 같다」로 둔다 — 종전에는 그래서 이 군이 **동형성 검사 밖**에
         * 있었고, 접두사를 붙이는 그 한 줄이 한 템플릿에서만 사라져도 아무것도 울지
         * 않았다 (22차 G3 라운드 C13).
         */
        const withoutBlockComments = (source: string): string =>
            source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/[ \t]+$/gm, '').replace(/\n{2,}/g, '\n').trim();

        const distinctOffenders = (copies: HelperCopy[]): string[] =>
            [...new Set(copies.map((c) => c.content))].length <= 1
                ? []
                : copies
                      .filter((c) => c.content !== copies[0].content)
                      .map((c) => `${c.path} 가 ${copies[0].path} 와 다르다`);

        // @scenario base_path=subpath, fetch_origin=extension_bundle, navigation_direction=request
        // @effects extension_base_path_helper_copies_stay_identical
        it('모든 사본이 바이트 동일하다', async () => {
            const copies = await collectHelperCopies();

            expect(distinctOffenders(copies)).toEqual([]);
        });

        // @scenario base_path=subpath, fetch_origin=extension_bundle, navigation_direction=request
        // @effects extension_installed_copies_match_their_bundled_source
        it('설치본(활성 디렉토리) 사본이 `_bundled` 원본과 같다', async () => {
            // 브라우저가 실제로 읽는 것은 `_bundled` 가 아니라 **설치본**이다. 그 디렉토리는
            // `{plugin,module,template}:update` 의 산출물이고 gitignore 대상이라, 저장소만
            // 보는 검사는 그것이 몇 판본 뒤처져 있어도 초록이다 — 실제로 두 플러그인
            // (`gdpr`·`tosspayments`)이 회차 14 이전 판본에 머물러 있었고(`?`·`#` 접두사
            // 경계를 모르고 `trim` 이 없는 판본), 그 사이트에서는 그 둘만 하위 폴더 배치에서
            // 주소가 어긋난다 (22차 G3 라운드 C1·C2).
            //
            // 설치본이 **없는** 것은 정상이다(신선 클론·CI 체크아웃). 있는데 다른 것만 잡는다.
            const fs = await import('node:fs');
            const path = await import('node:path');
            const repoRoot = path.resolve(__dirname, '..', '..', '..', '..', '..');

            // 24차 G3 라운드 R24-A — 이 계약은 **비교해서 green 이 아니라 비교 대상에서 빠져서
            // green** 이었다. 실측: compared 9 쌍은 전부 번들 == 설치본 버전이고, 실제로 갈린
            // 확장 6개(board·ecommerce·page·daum_postcode·marketing·basic)는 **전원 skip** 이었다.
            // 모집단과 실제 발산 집합이 서로소라, 22차가 막으려던 상황을 한 건도 재지 않았다.
            //
            // 빠지는 메커니즘이 정확히 위험한 축을 고른다: 헬퍼 파일 **부재**를 「정상(신선
            // 클론)」으로 삼켰는데, 공개 릴리즈 페이로드가 그 헬퍼를 아직 담고 있지 않아
            // GitHub 로 앞서 나간 확장일수록 파일이 없어 **더 확실하게** 빠졌다.
            //
            // 그래서 둘을 나눈다.
            //   ① 설치본 디렉토리 자체가 없다  → 신선 클론. 정상, 조용히 건너뛴다.
            //   ② 설치본은 있는데 헬퍼가 없다  → 다른 사실이다. 진단으로 남긴다(실패 아님 —
            //      공개 릴리즈가 그 파일을 담기 전까지는 정상 경로다).
            //   ③ 둘 다 있는데 다르다          → 방향을 본다. 설치본이 `_bundled` 보다 새로우면
            //      문서화된 `GitHub > _bundled` 우선순위상 정상 상태이고, 그때 `--force` 처방은
            //      성립하지 않는다(소스 선택을 바꾸지 않는다 — 23차 sirsoft-page 실측).
            const offenders: string[] = [];
            const ahead: string[] = [];
            const missingHelper: string[] = [];
            let compared = 0;
            let installedExtensions = 0;
            let withBundledHelper = 0;

            /**
             * manifest 의 version 을 읽는다 (없거나 못 읽으면 null).
             */
            const readVersion = (dir: string, manifest: string): string | null => {
                try {
                    const raw = JSON.parse(fs.readFileSync(path.join(dir, manifest), 'utf8'));

                    return typeof raw.version === 'string' ? raw.version : null;
                } catch {
                    return null;
                }
            };

            for (const { base, helper } of EXTENSION_SOURCE_CONTAINERS) {
                const sub = path.join(...helper);
                const bundled = path.join(repoRoot, base);
                const activeRoot = path.join(repoRoot, base.replace('/_bundled', ''));
                if (!fs.existsSync(bundled) || !fs.existsSync(activeRoot)) continue;

                const kind = base.includes('plugins') ? 'plugin' : base.includes('modules') ? 'module' : 'template';
                const manifest = `${kind}.json`;

                for (const extension of fs.readdirSync(bundled, { withFileTypes: true })) {
                    if (!extension.isDirectory()) continue;

                    const bundledDir = path.join(bundled, extension.name);
                    const installedDir = path.join(activeRoot, extension.name);
                    // ① 설치되어 있지 않다 → 검사 대상 아님.
                    if (!fs.existsSync(installedDir)) continue;
                    installedExtensions++;

                    const source = path.join(bundledDir, sub);
                    const installed = path.join(installedDir, sub);
                    if (!fs.existsSync(source)) continue;
                    withBundledHelper++;

                    // ② 설치본은 있는데 그 헬퍼만 없다 — 「부재는 정상」으로 삼키면 발산한
                    // 확장이 통째로 사각이 된다. 사실로 남긴다.
                    if (!fs.existsSync(installed)) {
                        missingHelper.push(
                            `${path.relative(repoRoot, installedDir)} 는 설치되어 있으나 ${sub} 가 없다`
                            + ' — 공개 릴리즈가 아직 그 헬퍼를 담지 않은 상태일 수 있다',
                        );
                        continue;
                    }

                    compared++;
                    if (fs.readFileSync(source, 'utf8') === fs.readFileSync(installed, 'utf8')) continue;

                    // ③ 방향 판정.
                    const bundledVersion = readVersion(bundledDir, manifest);
                    const installedVersion = readVersion(installedDir, manifest);
                    if (bundledVersion && installedVersion && bundledVersion !== installedVersion
                        && installedIsNewer(installedVersion, bundledVersion)) {
                        ahead.push(
                            `${path.relative(repoRoot, installedDir)} 설치본(v${installedVersion})이`
                            + ` _bundled(v${bundledVersion})보다 새롭다 — GitHub 릴리즈 경유로 앞선 정상 상태일 수 있다.`
                            + ` \`${kind}:update ${extension.name} --force\` 는 이 상태를 해소하지 못한다`,
                        );
                        continue;
                    }

                    offenders.push(
                        `${path.relative(repoRoot, installed)} 가 ${path.relative(repoRoot, source)} 와 다르다`
                        + ` — \`${kind}:update ${extension.name} --force\` 로 반영할 것`,
                    );
                }
            }

            expect(offenders).toEqual([]);

            // **모집단 회계** — 이 계약이 22차에 무력했던 이유는 위반이 없어서가 아니라
            // 대상이 조용히 빠져서였다. 그러니 「빠진 것이 없다」를 숫자로 잠근다.
            // `_bundled` 에 헬퍼가 있는 설치 확장은 반드시 세 갈래 중 하나로 계상된다:
            // 대조했거나(compared) · 설치본에 그 헬퍼가 없거나(missingHelper). 새 `continue`
            // 를 끼워 넣으면 이 등식이 깨져 red 가 된다.
            expect(compared + missingHelper.length).toBe(withBundledHelper);

            // 설치본이 하나도 없으면 이 검사는 아무것도 재지 않는다 — 그 사실을 삼키지 않고
            // 진단으로 남긴다(설치본 부재 자체는 실패가 아니다).
            if (installedExtensions === 0) {
                // eslint-disable-next-line no-console
                console.warn('[assetUrl] 설치본이 없어 대조하지 않았다 — 신선 클론에서는 정상이다');
            } else {
                // 비교쌍이 0 인데 설치본은 있다 = 모집단이 통째로 빠진 상태다. 그 사실이
                // 이 라운드가 연 결함 자체이므로 숫자로 드러낸다.
                // eslint-disable-next-line no-console
                console.warn(
                    `[assetUrl] 설치 확장 ${installedExtensions} · 대조 ${compared} · 헬퍼 부재 ${missingHelper.length} · 앞섬 ${ahead.length}`
                    + (missingHelper.length ? `\n  ${missingHelper.join('\n  ')}` : '')
                    + (ahead.length ? `\n  ${ahead.join('\n  ')}` : ''),
                );
            }
        });

        // @scenario base_path=subpath, fetch_origin=extension_bundle, navigation_direction=request
        // @effects extension_base_path_helper_copies_stay_identical
        it('템플릿의 Img.tsx · Form.tsx 사본도 군마다 바이트 동일하다', async () => {
            const offenders: string[] = [];
            for (const family of COPY_FAMILIES) {
                const raw = await collectCopiesOf(family.spec);
                const copies = (family as { normalize?: boolean }).normalize
                    ? raw.map((c) => ({ ...c, content: withoutBlockComments(c.content) }))
                    : raw;
                expect(copies.length, `[${family.name}] 사본을 하나도 찾지 못했다 — 모집단이 비면 이 검사는 공허하다`).toBeGreaterThan(1);
                offenders.push(...distinctOffenders(copies).map((o) => `[${family.name}] ${o}`));
            }

            expect(offenders).toEqual([]);
        });

        it('사본 모집단이 비어 있지 않다 (빈 모집단 대조 — 컨테이너별)', async () => {
            // 위 검사는 사본이 0벌이어도 초록이다. 경로 규칙을 바꿔 모집단이 통째로
            // 빠지는 것을 막는다. 하한은 리터럴 8 이 아니라 **도출**한다 — 컨테이너마다
            // 최소 1벌(한 컨테이너가 통째로 빠지면 합계로는 안 보인다) + 합계는 실측
            // (4/1/8 = 13)의 80% (16차 G3 라운드 F14).
            const copies = await collectHelperCopies();

            for (const { base } of EXTENSION_SOURCE_CONTAINERS) {
                expect(copies.filter((c) => c.container === base).length, `사본이 없다: ${base}`).toBeGreaterThan(0);
            }
            expect(copies.length).toBeGreaterThanOrEqual(Math.floor(13 * 0.8));

            // 템플릿 사본 군도 모집단을 잰다 — Img 4벌 · Form 2벌 (hello 템플릿은 Form 이 없다).
            expect((await collectCopiesOf(COPY_FAMILIES[1].spec)).length).toBeGreaterThanOrEqual(Math.floor(4 * 0.8));
            expect((await collectCopiesOf(COPY_FAMILIES[2].spec)).length).toBeGreaterThanOrEqual(Math.floor(2 * 0.8));
        });

        it('사본은 셋 다 내보낸다 — 서버 호출용·화면 이동용·라우트 대조용', async () => {
            // 게이트가 인정하는 이름이 사본에 실제로 있는지를 재다 — 이름만 바뀌어도
            // 위 동형성 검사는 그대로 초록이다.
            const copies = await collectHelperCopies();

            for (const copy of copies) {
                expect(copy.content).toContain('export function apiUrl(');
                expect(copy.content).toContain('export function sitePath(');
                // 붙이는 짝만 재면 벗기는 쪽이 통째로 빠져도 초록이다 — 실제로 확장
                // 30지점이 그 상태였다 (14차 G3 라운드).
                expect(copy.content).toContain('export function routePath(');
            }
        });

        // @scenario base_path=subpath, fetch_origin=extension_bundle, navigation_direction=request
        // @effects extension_base_path_helper_copies_stay_identical
        it('사본이 **필요한데 없는** 확장이 없다', async () => {
            // 위 세 검사는 전부 「사본을 가진 확장」만 모집단으로 삼는다. 그래서 사본이
            // 아예 없는 확장은 어느 검사에도 걸리지 않았다 — `sirsoft-ckeditor5` 가 그
            // 상태였고, 업로드 주소(`/api/plugins/sirsoft-ckeditor5/upload`)를 접두사 없이
            // CKEditor 에 넘겨 하위 폴더 배치에서 에디터 이미지 업로드만 사이트 밖으로
            // 나갔다. 그 주소는 `simpleUpload` 가 직접 보내므로 `fetch(` 수집기에도
            // 잡히지 않는다 (14차 G3 라운드).
            //
            // 「필요하다」의 판정: 그 확장의 TS 소스에 **루트 상대 사이트 주소 리터럴**이나
            // `location.pathname` 이 있으면 접두사를 다룰 일이 있는 확장이다.
            const fs = await import('node:fs');
            const path = await import('node:path');
            const repoRoot = path.resolve(__dirname, '..', '..', '..', '..', '..');

            const NEEDS_HELPER = /['"`]\/(?:api|admin|shop|mypage|board|page)\//;
            const missing: string[] = [];

            for (const { base, sub, helper: helperParts } of EXTENSION_SOURCE_CONTAINERS) {
                const helper = path.join(...helperParts);
                const container = path.join(repoRoot, base);
                if (!fs.existsSync(container)) continue;

                for (const extension of fs.readdirSync(container, { withFileTypes: true })) {
                    if (!extension.isDirectory()) continue;

                    const sourceDir = path.join(container, extension.name, sub);
                    if (!fs.existsSync(sourceDir)) continue;

                    let needs = false;
                    const scan = (dir: string): void => {
                        if (needs) return;
                        for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
                            if (needs) return;
                            const full = path.join(dir, entry.name);
                            if (entry.isDirectory()) {
                                if (['__tests__', 'tests', 'node_modules', 'dist'].includes(entry.name)) {
                                    continue;
                                }
                                scan(full);
                                continue;
                            }
                            if (!/\.(ts|tsx)$/.test(entry.name)) continue;
                            if (entry.name === 'apiUrl.ts') continue;

                            const body = fs.readFileSync(full, 'utf8');
                            if (NEEDS_HELPER.test(body) || body.includes('location.pathname')) {
                                needs = true;
                                return;
                            }
                        }
                    };
                    scan(sourceDir);

                    if (!needs) continue;

                    if (!fs.existsSync(path.join(container, extension.name, helper))) {
                        missing.push(
                            `${base}/${extension.name} 은 사이트 주소를 다루는데 접두사 헬퍼 사본이 없다`
                        );
                    }
                }
            }

            expect(missing).toEqual([]);
        });

        // @scenario base_path=subpath, navigation_direction=request
        // @effects prefixing_helper_allowlist_only_contains_prefixing_names
        it('허용목록의 모든 이름이 실제로 접두사를 붙인다', async () => {
            // 허용목록은 「이 이름을 쓰면 위반이 아니다」라는 **면제 선언**인데, 그 이름이
            // 정말 접두사를 붙이는지 재는 것이 없었다. 그래서 같은 구멍이 세 번 났다:
            // 13차가 `G7Core.url.route(`(벗기는 함수)를 넣었고, 14차가 그것을 빼면서
            // 세 줄 위의 `G7Core.url.base(`(인자를 받지 않는 함수)와 `publicStorage(`
            // (구현이 없는 이름)를 남겼다.
            //
            // 판정: 목록의 각 이름이 ① 코어 SSoT(`assetUrl.ts`) 또는 확장 헬퍼 사본에
            // **인자를 받는 함수**로 실재하거나 ② 그 자리를 설명하는 허용 사유가 명시된
            // 예외여야 한다.
            const fs = await import('node:fs');
            const path = await import('node:path');
            const repoRoot = path.resolve(__dirname, '..', '..', '..', '..', '..');

            const sources = [
                'resources/js/core/support/assetUrl.ts',
                'resources/js/core/template-engine/G7CoreGlobals.ts',
                'templates/_bundled/sirsoft-basic/src/support/apiUrl.ts',
                'resources/js/core/template-engine/layout-editor/hooks/useEditorMode.ts',
            ]
                .map((rel) => path.join(repoRoot, rel))
                .filter((full) => fs.existsSync(full))
                .map((full) => fs.readFileSync(full, 'utf8'))
                .join('\n');

            expect(sources).not.toBe('');

            const offenders: string[] = [];

            for (const helper of PREFIXING_HELPERS) {
                const name = helper.replace(/\($/, '').split('.').pop() as string;

                // 선언 형태 셋 — `export function n(`, `n: (arg` (객체 메서드), `const n = (arg`
                const declaresWithArgument = new RegExp(
                    `(?:function\\s+${name}\\s*\\(\\s*[A-Za-z_$])` +
                        `|(?:\\b${name}\\s*:\\s*\\(\\s*[A-Za-z_$])` +
                        `|(?:\\b${name}\\s*=\\s*\\(\\s*[A-Za-z_$])`
                ).test(sources);

                if (!declaresWithArgument) {
                    offenders.push(
                        `${helper} 는 인자를 받는 함수로 실재하지 않는다 — 접두사를 붙이지 못하거나 구현이 없다`
                    );
                }
            }

            // 「인자를 받는 함수인가」만으로는 **방향**을 재지 못한다. 13차가 넣은
            // `G7Core.url.route(` 는 벗기는 함수인데 그 형태를 완벽히 만족한다 — 즉 이
            // 검사가 막으려고 만들어진 바로 그 이름을 다시 넣어도 통과했다.
            //
            // 그래서 벗기는 이름을 명시 목록으로 두고, 붙이는 허용목록과 **교집합이 없음**을
            // 잰다. 목록이 죽지 않도록 각 이름이 실제로 소스에 있다는 것도 함께 확인한다 —
            // 이름이 바뀌면 이 검사가 조용히 통과하는 것이 아니라 red 가 된다.
            const STRIPPING_NAMES = ['stripBasePrefix', 'routePath', 'url.route'];

            for (const stripping of STRIPPING_NAMES) {
                expect(
                    sources.includes(stripping),
                    `벗기는 이름 \`${stripping}\` 이 소스에 없다 — 이 목록이 죽어 교집합 검사가 아무것도 재지 않는다`
                ).toBe(true);

                const collisions = PREFIXING_HELPERS.filter((helper) =>
                    helper.replace(/\($/, '').endsWith(stripping.split('.').pop() as string)
                );

                expect(
                    collisions,
                    `붙이는 이름의 허용목록에 **벗기는** 이름이 들어 있다 — 그 자리는 면제가 아니라 위반이다`
                ).toEqual([]);
            }

            expect(offenders).toEqual([]);
            expect(PREFIXING_HELPERS.length).toBeGreaterThanOrEqual(10);
        });

        // @scenario base_path=subpath, navigation_direction=request
        // @effects template_anchor_component_applies_base_prefix
        it('모든 번들 템플릿의 `A` 컴포넌트가 접두사를 붙인다', async () => {
            // 앵커 축의 게이트는 **날 소문자 `<a href=`** 만 본다. 대문자 `A` 를 통과시키는
            // 근거는 「그 컴포넌트가 `sitePath()` 를 쓴다」인데, **그 전제 자체를 재는
            // 검사가 없었다.** 그래서 그 전제가 거짓인 템플릿이 두 개 있어도 게이트는
            // 초록이었다 — `gnuboard7-hello_*` 2종의 `A.tsx` 는 `href` 를 `{...props}` 로
            // 그대로 흘려보냈고, 그 파일에는 리터럴 `href=` 가 없어 수집기에도 걸리지
            // 않았다. 레이아웃 JSON 에 루트 상대 `href` 3건이 실재한다 (15차 G3 라운드).
            //
            // 「전제를 재지 않는 허용」은 게이트의 사각 중 가장 조용한 형태다 — 통과
            // 근거가 코드가 아니라 주석에만 있기 때문이다.
            const fs = await import('node:fs');
            const path = await import('node:path');
            const repoRoot = path.resolve(__dirname, '..', '..', '..', '..', '..');
            const container = path.join(repoRoot, 'templates/_bundled');

            const offenders: string[] = [];
            let checked = 0;

            for (const template of fs.readdirSync(container, { withFileTypes: true })) {
                if (!template.isDirectory()) continue;

                const anchor = path.join(container, template.name, 'src/components/basic/A.tsx');
                if (!fs.existsSync(anchor)) continue;

                checked += 1;
                // **주석을 걷어낸 본문**에서 찾는다. 종전에는 파일 전체를 봐서, 그 컴포넌트의
                // 머리말이 `sitePath()` 를 설명으로 언급하는 것만으로 이 검사가 초록이었다 —
                // 실제 배선(`sitePath(href)`)을 지워도 통과했다(변이 실측, 22차 G3 라운드 C13).
                const body = fs
                    .readFileSync(anchor, 'utf8')
                    .replace(/\/\*[\s\S]*?\*\//g, '')
                    .replace(/^[ \t]*\/\/.*$/gm, '');

                if (!body.includes('sitePath(')) {
                    offenders.push(`templates/_bundled/${template.name} 의 A 컴포넌트가 접두사를 붙이지 않는다`);
                }
            }

            expect(offenders).toEqual([]);
            // 빈 모집단 대조 — 경로 규칙이 바뀌어 한 곳도 열리지 않으면 위 검사는 초록이다.
            expect(checked).toBeGreaterThanOrEqual(4);
        });

        // @scenario base_path=subpath, navigation_direction=route_match
        // @effects core_route_match_strips_base_prefix
        it('코어가 `location.pathname` 을 라우트와 대조할 때 접두사를 벗긴다 (트리에서 도출)', async () => {
            // **벗기는 방향**의 코어 축은 종전에 동작 계약 14케이스 **열거**로만 잠겨
            // 있었다. 열거는 새 지점이 생겨도 늘지 않는다 — 실측(회차 6)에서
            // `TemplateApp.ts` 의 `stripBasePrefix(` 를 지워도 주소 게이트 60건이 전부
            // 초록이었다. 그 상태의 결과는 하위 폴더 배치에서 라우트 대조가 언제나
            // 불일치가 되는 것이고(관리자 세션 만료가 사용자 로그인으로 흘러간다),
            // 확장 축은 정적 룰이 이미 도출형으로 덮고 있는데 코어만 빠져 있었다.
            //
            // `location.pathname` 을 **읽는** 곳 중 라우트 패턴과 대조하는 자리를 트리에서
            // 도출해 잰다. 복귀 주소로 쓰는 자리(원본 유지가 옳다)는 표식으로 가른다.
            const fs = await import('node:fs');
            const path = await import('node:path');
            const coreRoot = path.resolve(__dirname, '..', '..');

            /** 인자로 받은 주소를 **자기 본문에서** 벗기는 함수들. */
            const STRIPPING_WRAPPERS = ['checkLayoutEditorMode'];

            // 그 전제를 여기서 잰다 — 이름만 믿으면 그 함수가 벗기기를 그만두는 날
            // 이 축이 조용히 사문이 된다(「전제를 재지 않는 허용」).
            const editorMode = fs.readFileSync(
                path.join(coreRoot, 'template-engine/layout-editor/hooks/useEditorMode.ts'),
                'utf8'
            );
            expect(
                editorMode.includes('stripBasePrefix('),
                'checkLayoutEditorMode 가 더 이상 접두사를 벗기지 않는다 — 그 이름을 허용 목록에서 빼야 한다'
            ).toBe(true);

            // 브라우저 주소를 **읽는** 네 형태. 표기가 다를 뿐 같은 행위다 —
            //  ① `location.pathname` / 줄바꿈으로 갈린 접근
            //  ② `location['pathname']`
            //  ③ `const { pathname } = window.location`
            //  ④ `new URL(location.href).pathname`
            //
            // 확장 축은 같은 목록을 정적 룰이 갖는다. 이 파일은 배포 산출물이라 그쪽을
            // `import` 하지 않는다(공개물이 내부 도구를 참조하면 안 된다) — 대신 **그쪽
            // 픽스처가 이 리터럴을 읽어 동일성을 잰다.** 의존 방향은 내부 → 공개다.
            // 종전 코어 검사는 ① 한 형태만 봐서 나머지 셋이 무판정이었다
            // (22차 G3 라운드 C7).
            //
            // G7-SHARED-REGEX: pathname-access
            const pathnameAccessRe = new RegExp(
                [
                    String.raw`new\s+URL\s*\(\s*(?:window\s*\.\s*)?location(?:\s*\.\s*href)?\s*(?:\)|,[^()]*\))\s*\.\s*pathname`,
                    String.raw`(?:window\s*\.\s*)?location\s*\.\s*pathname`,
                    String.raw`(?:window\s*\.\s*)?location\s*\[\s*['"]pathname['"]\s*\]`,
                    String.raw`\{[^{}]*\bpathname\b[^{}]*\}\s*=\s*(?:window\s*\.\s*)?location\b(?!\s*\.)`,
                ].join('|'),
                'g'
            );

            const offenders: string[] = [];
            let readSites = 0;

            const walk = (dir: string): void => {
                for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
                    if (entry.name === '__tests__' || entry.name === 'node_modules') continue;
                    const full = path.join(dir, entry.name);
                    if (entry.isDirectory()) {
                        walk(full);
                        continue;
                    }
                    if (!/\.(ts|tsx)$/.test(entry.name)) continue;

                    const raw = fs.readFileSync(full, 'utf8');
                    const lines = raw.split(String.fromCharCode(10));

                    lines.forEach((line, index) => {
                        const trimmed = line.trim();
                        // 주석에 그 이름을 설명으로 적는 것은 정상이다 — 구분하지 않으면
                        // 검사가 오탐으로 가득 차고, 그러면 사람이 그 검사를 믿지 않게 된다.
                        if (trimmed.startsWith('//') || trimmed.startsWith('*') || trimmed.startsWith('/*')) return;

                        // 읽는 **형태 목록**은 확장 축의 정적 룰이 소유한다. 종전 코어
                        // 검사는 `location.pathname` 한 형태만 봐서, 줄바꿈으로 갈라진
                        // 접근·구조분해·대괄호·`new URL(location.href).pathname` 이 전부
                        // 무판정이었다 — 같은 결함군의 두 검사가 다른 폭을 가지면 좁은
                        // 쪽이 그대로 우회로다 (22차 G3 라운드 C7).
                        pathnameAccessRe.lastIndex = 0;
                        if (!pathnameAccessRe.test(line)) return;
                        // 대입 대상(주소를 쓰는 쪽)은 이 축이 아니다.
                        if (/location\s*\.\s*pathname\s*(?<![=!<>])=(?!=)/.test(line)) return;

                        readSites += 1;

                        if (line.includes('stripBasePrefix(')) return;

                        // **안에서 벗기는** 함수에 넘기는 것도 벗긴 것이다. 이 이름들은
                        // 자기 본문에서 `stripBasePrefix` 를 부르며, 그 사실은 바로 아래
                        // 단언이 잰다 — 이름만 적어 두면 그 함수가 벗기기를 그만두는 날
                        // 이 축이 조용히 사문이 된다.
                        if (STRIPPING_WRAPPERS.some((w) => line.includes(`${w}(`))) return;

                        // 복귀 주소·로깅처럼 **벗기면 안 되는** 자리는 표식으로 밝힌다.
                        if (hasBasePathAllowMarker(lines, index + 1)) return;

                        offenders.push(
                            `${path.relative(coreRoot, full)}:${index + 1} → ${line.trim().slice(0, 90)}`
                        );
                    });
                }
            };

            walk(coreRoot);

            expect(offenders).toEqual([]);
            // 모집단 대조 — 한 곳도 읽지 못하면 위 비교가 공허하다.
            expect(readSites).toBeGreaterThan(3);
        });

        // @scenario base_path=subpath, navigation_direction=request
        // @effects template_anchor_component_applies_base_prefix
        it('레이아웃 JSON 의 루트 상대 `href` 는 전부 `A` 컴포넌트 위에 있다', async () => {
            // 위 검사는 「`A` 가 접두사를 붙인다」를 잰다. 그런데 그 전제가 성립해도
            // **`href` 가 `A` 가 아닌 컴포넌트에 실리면** 아무 소용이 없다 — 그 축을 재는
            // 것이 없어, 실제로 `Button` 에 루트 상대 `href` 를 준 레이아웃이 한 건
            // 있었다. `Button` 은 `{...props}` 를 `<button>` 에 흘리므로 `sitePath` 를
            // 타지 않고, `<button href>` 는 애초에 네비게이트하지 않아 **그 버튼이 아무
            // 데도 가지 않는다**(오류 없음). 붙이는 자리를 한 곳으로 모았다는 선언은
            // 「모든 href 가 그 자리에 있다」를 함께 재야 성립한다.
            const fs = await import('node:fs');
            const path = await import('node:path');
            const repoRoot = path.resolve(__dirname, '..', '..', '..', '..', '..');

            // 루트 목록에 존재하지 않는 경로(`resources/layouts`)를 두지 않는다 — `walk` 이
            // 조용히 건너뛰어 「걸었다」와 「없었다」가 구분되지 않았다. 루트마다 최소 1건을
            // 따로 잰다 (16차 G3 라운드 B-9).
            const roots = ['templates/_bundled', 'modules/_bundled', 'plugins/_bundled'] as const;

            const offenders: string[] = [];
            let anchors = 0;
            let extensionAnchors = 0;
            const anchorsPerRoot: Record<string, number> = {};
            let currentRoot = '';
            let rootRelativeSrcAction = 0;

            /** 레이아웃 노드를 훑어 루트 상대 `href` 를 가진 컴포넌트 이름을 모은다. */
            const visit = (node: unknown, file: string): void => {
                if (Array.isArray(node)) {
                    for (const child of node) visit(child, file);

                    return;
                }
                if (!node || typeof node !== 'object') return;

                const record = node as Record<string, unknown>;
                const props = record.props as Record<string, unknown> | undefined;
                const href = props?.href;

                if (typeof href === 'string' && href.startsWith('/') && !href.startsWith('//')) {
                    anchors += 1;
                    anchorsPerRoot[currentRoot] = (anchorsPerRoot[currentRoot] ?? 0) + 1;
                    if (file.split(/[\\/]/).includes('extensions')) extensionAnchors += 1;
                    if (record.name !== 'A') {
                        offenders.push(`${file} → ${String(record.name)}.props.href = ${href}`);
                    }
                }

                // `src`·`action` 도 같은 축이다 — 브라우저가 라우터 없이 직접 여는 주소라
                // 접두사를 붙일 주체가 컴포넌트뿐이고, 그 자리는 `Img`·`Form` 이다. 종전
                // 게이트는 `href` 만 봐서 `Div.props.src = "/images/x.png"` 같은 형태를
                // 아무도 재지 않았다 (15차 G3 라운드 W4-2). 판정은 `href` 와 같다 — 정상
                // 형태(`Img.props.src`·`Form.props.action`)는 허용하고 다른 컴포넌트 위의
                // 것만 offenders 에 싣는다. 종전의 「실물 0 고정」은 정상 형태가 하나만 생겨도
                // red 라 소유자 분기가 도달 불가였다 (16차 G3 라운드 F12).
                // `backPath`·`backLink` 도 같은 축이다 — 화면이 「목록으로」로 여는 주소이고,
                // 값이 루트 상대라 접두사를 붙일 주체가 그 컴포넌트뿐이다. 소유자는
                // `PageHeader` 이며 실물 12건이 전부 그 위에 있다. 종전 게이트는 세 prop
                // (`href`·`src`·`action`)만 봐서 이 축이 통째로 사각이었다 — 지금은 그
                // 컴포넌트가 아직 없어 값이 무해하지만, 구현되는 순간 그 자리가 접두사를
                // 붙이는 책임을 진다는 사실을 아무도 재지 않는 상태였다 (22차 G3 라운드 C5).
                for (const [prop, owner] of [
                    ['src', 'Img'],
                    ['action', 'Form'],
                    ['backPath', 'PageHeader'],
                    ['backLink', 'PageHeader'],
                ] as const) {
                    const value = props?.[prop];
                    if (typeof value === 'string' && value.startsWith('/') && !value.startsWith('//')) {
                        rootRelativeSrcAction += 1;
                        if (record.name !== owner) {
                            offenders.push(`${file} → ${String(record.name)}.props.${prop} = ${value}`);
                        }
                    }
                }

                for (const value of Object.values(record)) visit(value, file);
            };

            const walk = (dir: string): void => {
                if (!fs.existsSync(dir)) return;
                for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
                    if (['node_modules', 'dist', 'vendor'].includes(entry.name)) continue;
                    const full = path.join(dir, entry.name);
                    if (entry.isDirectory()) {
                        walk(full);
                        continue;
                    }
                    if (!entry.name.endsWith('.json')) continue;
                    // `layouts` 뿐 아니라 **레이아웃 확장**(`resources/extensions/**`)도 본다.
                    // 그 JSON 은 코어 레이아웃에 노드를 끼워 넣는 것이라 화면에서는 레이아웃과
                    // 구별되지 않는데, 종전 조건은 경로에 `layouts` 세그먼트를 요구해 통째로
                    // 모집단 밖이었다 — 이 게이트를 만든 근거(`Button` 에 루트 상대 href)를
                    // 확장 JSON 에 그대로 넣으면 초록이었다. 형제 룰
                    // (`layout-binding-response-prefix`)은 이미 그 경로를 레이아웃으로
                    // 취급하고 있어, 같은 저장소 안에서 「레이아웃」의 정의가 갈려 있었다
                    // (7차 G3 라운드 B-4).
                    const segments = full.split(path.sep);
                    if (!segments.includes('layouts') && !segments.includes('extensions')) continue;

                    try {
                        visit(JSON.parse(fs.readFileSync(full, 'utf8')), path.relative(repoRoot, full));
                    } catch {
                        // 레이아웃이 아닌 JSON(설정·매니페스트)은 이 축의 대상이 아니다.
                    }
                }
            };

            for (const root of roots) {
                currentRoot = root;
                walk(path.join(repoRoot, root));
            }

            expect(offenders).toEqual([]);
            // 모집단 대조 — 루트 상대 `href` 를 한 건도 찾지 못하면 위 비교는 공허하다.
            // 하한은 실측(49)의 80% 이고, 루트마다 최소 1건을 따로 잰다 — 합계만 보면 한
            // 루트가 통째로 빠져도 나머지가 하한을 채운다.
            expect(anchors).toBeGreaterThanOrEqual(Math.floor(49 * 0.8));
            for (const root of roots) {
                expect(anchorsPerRoot[root] ?? 0, `루트 상대 href 모집단이 비었다: ${root}`).toBeGreaterThan(0);
            }
            // 레이아웃 확장 축도 **따로** 잰다. 합계만 보면 그 경로가 다시 빠져도
            // 나머지 레이아웃이 하한을 채워 초록이다 (B-4 가 그 형태였다).
            expect(extensionAnchors).toBeGreaterThan(0);
            // `src`·`action` 축은 위 offenders 가 판정한다 — 정상 형태(`Img`/`Form` 위)는
            // 허용되고, 그 수는 하한이 아니라 참고값이다(현재 실물 0).
            expect(rootRelativeSrcAction).toBeGreaterThanOrEqual(0);
        });
    });
});

/**
 * `?file=` 인코더 동형성 — 서버(`App\Support\AssetUrl`)와 프론트가 같은 문자열을 낸다.
 *
 * 두 인코더가 갈리면 같은 파일의 URL 문자열이 둘로 나뉜다. 디코딩하면 같은 경로라 404 도
 * 오류도 나지 않고, HTTP 캐시 키가 갈려 **같은 자산을 두 번 받는 것**이 유일한 증상이다.
 * 실제로 갈려 있었다 — `extensionAsset()` 만 `encodeURIComponent` 를 썼고, 그 함수는
 * `! ' ( ) *` 를 인코딩하지 않는 반면 PHP `rawurlencode()` 는 전부 인코딩한다. 같은 파일의
 * 형제 경로(`convertToCurrentMode`)는 이미 교정 인코더를 쓰고 있어, 한 파일 안에서 두 규칙이
 * 공존했다 (14차 G3 라운드).
 *
 * 기대표는 `tests/Fixtures/AssetUrl/file-query-parity.json` 하나다. 양쪽 테스트에 각각 적으면
 * 그 사본이 다시 갈리므로, PHP 쪽 `AssetUrlTest` 도 같은 파일을 읽는다.
 */
/**
 * 면제 표식 판정 자신을 잰다.
 *
 * 위의 여섯 통로 축은 전부 이 함수의 판정을 믿고 `continue` 한다 — 그 판정이
 * 느슨해지면 통로 축 전부가 동시에 열리는데, 저장소의 현재 표식 39건이 모두
 * 정상이라 그 완화는 red 를 내지 않는다. 그래서 판정 자신에 양성·음성 대조를 둔다
 * (7차 G3 라운드 E11).
 */
describe('base-path:allow 면제 표식은 사유를 갖춘 주석만 인정한다', () => {
    it('사유가 있는 주석 표식을 인정한다', () => {
        expect(hasBasePathAllowMarker(['// base-path:allow 외부 PG 가 준 절대 주소', 'fetch(url);'], 2)).toBe(true);
        expect(hasBasePathAllowMarker(['fetch(url); // base-path:allow 외부 PG 절대 주소'], 1)).toBe(true);
    });

    it('사유 없는 맨 표식은 인정하지 않는다', () => {
        expect(hasBasePathAllowMarker(['// base-path:allow', 'fetch(url);'], 2)).toBe(false);
        expect(hasBasePathAllowMarker(['// base-path:allow   ', 'fetch(url);'], 2)).toBe(false);
    });

    it('문자열 리터럴 안의 같은 글자는 면제가 아니다', () => {
        expect(hasBasePathAllowMarker(["const s = 'base-path:allow 사유';", 'fetch(url);'], 2)).toBe(false);
        expect(hasBasePathAllowMarker(["log('// base-path:allow 사유');", 'fetch(url);'], 2)).toBe(false);
    });

    it('두 줄을 이어 붙여 성립하는 형태는 인정하지 않는다', () => {
        // 종전 판정은 앞줄의 `//` 와 뒷줄의 글자가 이어 붙어 면제가 됐다.
        expect(hasBasePathAllowMarker(['// 설명만 있는 주석', "const s = 'base-path:allow 사유';"], 2)).toBe(false);
    });

    it('세 줄 이상 떨어진 표식은 덮지 않는다', () => {
        expect(hasBasePathAllowMarker(['// base-path:allow 사유', '', '', 'fetch(url);'], 4)).toBe(false);
    });
});

// e2e:allow 순수 URL 문자열 생성 유닛. 브라우저 거동은 단위 D(자가 복구) spec 이 커버한다.
describe('assetUrl — `?file=` 인코더는 서버 rawurlencode 와 동형이다', () => {
    beforeEach(() => {
        delete (globalThis as any).__g7AssetUrlMode;
        (globalThis as any).G7Config = { cache_version: 7, assetUrlMode: MODE_EXTENSIONLESS };
    });

    afterEach(() => {
        delete (globalThis as any).G7Config;
        delete (globalThis as any).__g7AssetUrlMode;
    });

    it('공유 기대표의 모든 경계 문자에서 서버와 같은 문자열을 만든다', async () => {
        const fs = await import('node:fs');
        const path = await import('node:path');

        const repoRoot = path.resolve(__dirname, '..', '..', '..', '..', '..');
        const fixture = JSON.parse(
            fs.readFileSync(path.join(repoRoot, 'tests/Fixtures/AssetUrl/file-query-parity.json'), 'utf8'),
        ) as { type: string; identifier: string; cases: Array<{ path: string; encoded: string; why: string }> };

        // 도출이 비면 이 검사는 아무것도 재지 않는다.
        expect(fixture.cases.length).toBeGreaterThan(4);

        for (const testCase of fixture.cases) {
            expect(
                extensionAsset(fixture.type, fixture.identifier, testCase.path),
                `${testCase.why} — ${testCase.path}`,
            ).toBe(`/api/${fixture.type}/assets/${fixture.identifier}?file=${testCase.encoded}`);
        }
    });

    it('확장 식별자도 같은 인코더를 지난다 — `! \' ( ) *` 가 서버와 같은 문자열이 된다', async () => {
        // 서버 `AssetUrl::asset()` 독블록은 「프론트 `extensionAsset()` 과 같이 rawurlencode」
        // 라 적었는데, 프론트는 `encodeURIComponent` 였다 — 그 다섯 글자에서 두 URL 이
        // 갈린다. 경로(`?file=`)는 이미 `rawEncode` 로 맞춰 두고 식별자만 옛 인코더가 남아
        // 한 함수 안에서 두 규칙이 공존했다 (회차 13 C-5).
        const fs = await import('node:fs');
        const path = await import('node:path');

        const repoRoot = path.resolve(__dirname, '..', '..', '..', '..', '..');
        const fixture = JSON.parse(
            fs.readFileSync(path.join(repoRoot, 'tests/Fixtures/AssetUrl/file-query-parity.json'), 'utf8'),
        ) as { identifier_cases: Array<{ identifier: string; encoded: string; why: string }> };

        expect(fixture.identifier_cases.length).toBeGreaterThan(1);

        for (const testCase of fixture.identifier_cases) {
            (globalThis as any).G7Config = { cache_version: 7, assetUrlMode: MODE_EXTENSIONLESS };
            delete (globalThis as any).__g7AssetUrlMode;
            expect(
                extensionAsset('modules', testCase.identifier, 'dist/x.js'),
                `${testCase.why} — ${testCase.identifier}`,
            ).toBe(`/api/modules/assets/${testCase.encoded}?file=dist%2Fx.js`);

            (globalThis as any).G7Config = { cache_version: 7, assetUrlMode: MODE_EXTENSION };
            delete (globalThis as any).__g7AssetUrlMode;
            expect(
                extensionAsset('modules', testCase.identifier, 'dist/x.js'),
                `${testCase.why} — ${testCase.identifier} (확장자 모드)`,
            ).toBe(`/api/modules/assets/${testCase.encoded}/dist/x.js`);
        }
    });

    it('버전이 붙어도 인코딩 규칙은 같다', () => {
        expect(extensionAsset('modules', 'sirsoft-board', "dist/js/a(1)'s.js", 7)).toBe(
            '/api/modules/assets/sirsoft-board?file=dist%2Fjs%2Fa%281%29%27s.js&v=7',
        );
    });

    it('확장자 모드에서는 경로를 인코딩하지 않는다 (서버와 동형 — 정적 서빙도 그렇다)', () => {
        (globalThis as any).G7Config = { cache_version: 7, assetUrlMode: MODE_EXTENSION };

        expect(extensionAsset('templates', 'sirsoft-basic', 'js/components.iife.js', 7)).toBe(
            '/api/templates/assets/sirsoft-basic/js/components.iife.js?v=7',
        );
    });
});

describe('코어 3번들이 assetUrl.ts 의 현재 판정을 싣고 있다 (dist 최신성 게이트)', () => {
    // 확장의 stale dist 는 정적 검사(`extension-dist-source-literal-sync`)가 잡는데, 그
    // 룰은 코어(`resources/js/core` ↔ `public/build/core`)를 3번들 다대다 매핑을 이유로
    // 제외했다. 그래서 `assetUrl.ts` 가 `?`·`#` 경계와 `trim` 을 얻은 뒤에도 배포되는
    // 세 번들은 옛 판정을 실은 채 남았고(경계 리터럴 0건), 단위 테스트는 소스를 읽으므로
    // 전부 초록이었다 — 사용자가 받는 파일은 소스가 아니다 (회차 14 W1).
    //
    // 지표는 두 가지다. ① 모듈 도달성: `__g7AssetUrlMode` 는 assetUrl.ts 만 쓰는 전역
    // 이름이라 번들이 이 모듈을 포함하면 원형으로 남는다. ② 판정 본체: 경계 판정의
    // 비교 사슬(`==="/"||x==="?"||x==="#"`)은 최소화를 지나도 같은 모양이다. ①이 있는
    // 번들에 ②가 없으면 그 번들은 옛 소스로 빌드된 것이다.
    // 번들 목록은 디렉토리에서 **도출**한다 — 리터럴 3벌이면 네 번째 번들이 생겨도 이 게이트
    // 밖이고, 하나가 사라져도 「읽기 실패」로만 드러난다. 하한 3 은 현재 실물이다 (16차 G3 라운드 A16).
    const REACH_MARKER = '__g7AssetUrlMode';
    const BOUNDARY_RE = /===\s*"\/"\s*\|\|\s*[\w$]+\s*===\s*"\?"\s*\|\|\s*[\w$]+\s*===\s*"#"/;
    // ③ 전처리 본체: `typeof p == "string" ? p.trim() : p` (회차 14 W1-2). 최소화를 지나면
    // `===` 가 `==` 로 줄고 식별자만 바뀐다.
    const TRIM_RE = /typeof\s+[\w$]+\s*===?\s*"string"\s*\?\s*[\w$]+\s*\.\s*trim\(\)\s*:\s*[\w$]+/;

    const readBundles = async () => {
        const fs = await import('node:fs');
        const path = await import('node:path');
        // 게이트 자신을 재기 위한 우회 — 변조한 사본 디렉토리를 가리키면 red 가 나야 한다.
        // 배포 산출물(`public/build/core`)은 건드리지 않는다.
        const dir =
            process.env.G7_CORE_BUNDLE_DIR ??
            path.resolve(__dirname, '..', '..', '..', '..', '..', 'public', 'build', 'core');
        const names = fs
            .readdirSync(dir)
            .filter((name) => name.endsWith('.min.js'))
            .sort();

        expect(names.length, `코어 번들 모집단이 비었다: ${dir}`).toBeGreaterThanOrEqual(3);

        return names.map((name) => ({ name, body: fs.readFileSync(path.join(dir, name), 'utf8') }));
    };

    it('소스가 지표 판정을 아직 갖고 있다 (지표가 소스에서 사라지면 이 게이트가 공허해진다)', async () => {
        const fs = await import('node:fs');
        const path = await import('node:path');
        const source = fs.readFileSync(path.resolve(__dirname, '..', 'assetUrl.ts'), 'utf8');

        expect(source).toContain(REACH_MARKER);
        expect(source).toMatch(/===\s*'\/'\s*\|\|\s*next\s*===\s*'\?'\s*\|\|\s*next\s*===\s*'#'/);
        expect(source).toMatch(/typeof path === 'string' \? path\.trim\(\) : path/);
    });

    it('assetUrl.ts 를 포함하는 번들마다 경계 판정과 trim 전처리가 실려 있다 (3벌 전부 측정)', async () => {
        const bundles = await readBundles();
        // 도달 판정을 마커 **하나**에 두면 그 마커를 트리셰이킹으로 잃은 번들이 단언 밖으로
        // 조용히 빠진다 — devtools 번들이 정확히 그 상태였다: `withBasePrefix` 의 경계
        // 사슬은 실려 있는데 `__g7AssetUrlMode` 가 없어 게이트가 그 번들을 재지 않았고,
        // 그 번들을 옛 소스로 되돌려도 초록이었다 (15차 G3 라운드 W4-8). 그래서 도달은
        // 「assetUrl.ts 의 어느 함수든 포함하면」이고, 세 벌 전부가 측정됐음을 따로 단언한다.
        const reaching = bundles.filter(
            (b) => b.body.includes(REACH_MARKER) || BOUNDARY_RE.test(b.body) || TRIM_RE.test(b.body),
        );

        expect(reaching.map((b) => b.name).sort()).toEqual(bundles.map((b) => b.name).sort());
        expect(reaching.length).toBeGreaterThanOrEqual(3);

        for (const bundle of reaching) {
            expect(
                BOUNDARY_RE.test(bundle.body),
                `${bundle.name} 이 옛 assetUrl.ts 로 빌드됐다 (경계 판정 없음) — php artisan core:build --production 으로 재빌드`,
            ).toBe(true);
            expect(
                TRIM_RE.test(bundle.body),
                `${bundle.name} 이 옛 assetUrl.ts 로 빌드됐다 (trim 전처리 없음) — php artisan core:build --production 으로 재빌드`,
            ).toBe(true);
        }
    });

    it('배포 번들에 소스맵 참조가 남아 있지 않다 (--production 빌드 지표)', async () => {
        for (const bundle of await readBundles()) {
            expect(bundle.body.includes('//# sourceMappingURL='), `${bundle.name} 에 sourceMappingURL 잔존`).toBe(false);
        }
    });
});
