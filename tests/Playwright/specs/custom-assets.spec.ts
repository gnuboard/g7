/**
 * E2E: 사용자 추가 에셋(`custom/`) 적용 순서 (공개 이슈 #123)
 *
 * 운영자가 덧붙인 CSS 는 확장 스타일보다 **뒤**에 붙어야 재정의가 성립한다. 앞에 붙으면
 * "고쳤는데 안 먹는다" 가 되고, 그 원인은 화면 어디에도 드러나지 않는다.
 *
 * 이 스펙은 **실제 픽스처를 놓고 최종 적용값을 잰다.** 문서 순서만 보면 "뒤에 있다" 는
 * 확인일 뿐 "이긴다" 의 증명이 아니다 — specificity·!important·미디어쿼리 중 하나만
 * 어긋나도 순서가 맞는데 적용되지 않는 상태가 성립한다. `getComputedStyle` 이 유일한 증거다.
 *
 * 경쟁 상대가 **실재하는 속성**으로 잰다. 아무도 정의하지 않는 속성으로 재면 순서와
 * 무관하게 통과하므로 "적용됐다" 의 증명일 뿐 "이겼다" 의 증명이 아니다. 관리자 번들은
 * `body { color: … }` 를 정의하므로(`dist/css/components.css`) 그 속성을 다툰다.
 *
 * 픽스처는 **활성 확장 디렉토리**에 놓는다. 활성 확장 경로는 `.gitignore` 대상이라
 * 배포본에 섞이지 않으며, `_bundled` 에 놓는 것은 정적 검사가 차단한다.
 *
 * 시나리오 축·효과는 tests/scenarios/custom-extension-assets.yaml 참조.
 */
import { existsSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import type { Page } from '@playwright/test';

import { test, expect } from '../fixtures/auth';
import { acquireCustomAssetLock, releaseCustomAssetLock } from '../fixtures/custom-asset-lock';

const REPO_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');

/**
 * 픽스처를 놓을 활성 확장 3종.
 *
 * 세 타입을 동시에 놓아야 **확장 간 순서**(모듈 → 플러그인 → 템플릿)를 다툴 수 있다.
 * 하나만 놓으면 그 하나가 언제나 이기므로 순서가 검증되지 않는다.
 */
const FIXTURES = {
    module: 'modules/sirsoft-page',
    plugin: 'plugins/sirsoft-gdpr',
    template: 'templates/sirsoft-admin_basic',
} as const;

/**
 * 확장별·파일별 색.
 *
 * 템플릿·번들 어디에서도 쓰지 않는 값이라 우연히 같아져 거짓 통과할 수 없다.
 * 마지막에 오는 `template20` 이 최종 승자여야 한다.
 */
const COLOR = {
    module: 'rgb(11, 11, 11)',
    plugin: 'rgb(22, 22, 22)',
    template10: 'rgb(33, 33, 33)',
    template20: 'rgb(44, 44, 44)',
} as const;

/** custom JS 가 실행 흔적을 남기는 전역 배열 이름. */
const TRACE = '__g7CustomOrder';

/** 선언 순서대로의 기대 실행 흔적. */
const EXPECTED_TRACE = ['module', 'plugin', 'template-10', 'template-20'];

/**
 * 다툴 속성을 덮는 규칙.
 *
 * `!important` 를 쓰지 않는다 — 그것을 쓰면 순서와 무관하게 이기므로 이 스펙이
 * 검증하려는 캐스케이드 순서를 우회해 버린다. 번들과 **같은 specificity**(`body`)로
 * 두고 «나중에 온 것이 이긴다» 는 성질만으로 판정한다.
 *
 * @param color 적용할 색
 * @return CSS 본문
 */
function css(color: string): string {
    return `body { color: ${color}; }\n`;
}

/**
 * 실행 흔적을 남기는 스크립트.
 *
 * 배열을 스스로 만들어 두므로 어느 것이 먼저 실행되든 흔적이 유실되지 않는다.
 *
 * @param mark 남길 표식
 * @return JS 본문
 */
function js(mark: string): string {
    return `(window.${TRACE} = window.${TRACE} || []).push(${JSON.stringify(mark)});\n`;
}

/**
 * 픽스처 파일 절대 경로.
 *
 * @param key 확장 종류
 * @param name 파일명
 * @return 절대 경로
 */
function fixturePath(key: keyof typeof FIXTURES, name: string): string {
    return resolve(REPO_ROOT, FIXTURES[key], 'custom', name);
}

/**
 * 파일 하나를 놓습니다.
 *
 * @param key 확장 종류
 * @param name 파일명
 * @param contents 내용
 * @return void
 */
function put(key: keyof typeof FIXTURES, name: string, contents: string): void {
    const target = fixturePath(key, name);

    mkdirSync(dirname(target), { recursive: true });
    writeFileSync(target, contents, 'utf8');
}

/**
 * 파일 하나를 치웁니다.
 *
 * @param key 확장 종류
 * @param name 파일명
 * @return void
 */
function drop(key: keyof typeof FIXTURES, name: string): void {
    const target = fixturePath(key, name);

    if (existsSync(target)) {
        rmSync(target, { force: true });
    }
}

/**
 * 세 확장에 픽스처를 모두 놓습니다.
 *
 * @return void
 */
function putAll(): void {
    put('module', '10-e2e-order.css', css(COLOR.module));
    put('module', '10-e2e-order.js', js('module'));

    put('plugin', '10-e2e-order.css', css(COLOR.plugin));
    put('plugin', '10-e2e-order.js', js('plugin'));

    put('template', '10-e2e-order.css', css(COLOR.template10));
    put('template', '20-e2e-order.css', css(COLOR.template20));
    put('template', '10-e2e-order.js', js('template-10'));
    put('template', '20-e2e-order.js', js('template-20'));
}

/**
 * 픽스처 디렉토리를 통째로 치웁니다.
 *
 * 운영자 파일 자리이므로 스펙이 만든 것 외에는 원래 비어 있다. 남겨 두면 다음 실행이
 * 지난 픽스처를 함께 재게 되어 순서 판정이 어긋난다.
 *
 * @return void
 */
function cleanAll(): void {
    for (const relative of Object.values(FIXTURES)) {
        const dir = resolve(REPO_ROOT, relative, 'custom');

        if (existsSync(dir)) {
            rmSync(dir, { recursive: true, force: true });
        }
    }
}

/** 서버가 내려준 custom 자산 목록을 읽는다. */
async function declaredCustomAssets(page: Page) {
    return page.evaluate(
        () =>
            ((window as any).G7Config?.customAssets ?? []) as Array<{
                id: string;
                type: string;
                url: string;
                source?: string;
            }>
    );
}

/**
 * 관리자 화면에 진입해 자산 로드가 끝나기를 기다립니다.
 *
 * custom 자산은 확장 번들 **뒤**에 붙으므로 첫 페인트 시점에는 아직 적용되지 않았다.
 * 최종 적용값을 재려면 붙을 때까지 기다려야 한다.
 *
 * @param page 대상 페이지
 * @return void
 */
async function enterAdmin(page: Page): Promise<void> {
    await page.goto('/admin/login');
    await page.waitForFunction(() => typeof (window as any).G7Config !== 'undefined', null, {
        timeout: 30_000,
    });
    await page.waitForLoadState('networkidle');
}

/**
 * `body` 의 최종 색이 기대값이 될 때까지 기다립니다.
 *
 * @param page 대상 페이지
 * @param expected 기대 색
 * @return void
 */
async function expectFinalColor(page: Page, expected: string): Promise<void> {
    await page.waitForFunction(
        (want) => getComputedStyle(document.body).color === want,
        expected,
        { timeout: 30_000 }
    );
}

test.describe('사용자 추가 에셋 — 적용 순서', () => {
    // 이 스펙은 **공유 파일 시스템 상태**(활성 확장의 custom 픽스처)를 놓고 잰다.
    // 병렬로 돌리면 한 테스트의 파일 수정과 다른 테스트의 단언이 겹치고, 워커별 정리가
    // 아직 쓰는 중인 픽스처를 지운다 — 제품 결함이 아닌데 실패하는 flaky 가 된다.
    test.describe.configure({ mode: 'serial' });

    // 파일 안의 순서만으로는 부족하다 — `custom-asset-management.spec.ts` 가 같은 서버의
    // 같은 `custom/` 디렉토리를 만지고, 그 쓰기가 확장 캐시 버전을 올려 자산 URL 을 통째로
    // 회전시킨다. 파일끼리는 병렬이므로 그 회전이 이쪽 측정 창에 끼어든다.
    test.beforeAll(async () => {
        // 훅 기본 상한(30초)은 상대 spec 이 잠금을 쥐고 있는 시간보다 짧다 — 올리지 않으면
        // 배제가 성립한 바로 그 순간에 훅이 시간 초과로 죽는다.
        test.setTimeout(5 * 60 * 1000);
        await acquireCustomAssetLock();
        cleanAll();
        putAll();
    });

    test.afterAll(() => {
        cleanAll();
        releaseCustomAssetLock();
    });

    // ── 배선 ────────────────────────────────────────────────────────────────

    test('서버가 세 확장의 custom 자산을 선언 순서대로 내려준다', async ({ page }) => {
        // @scenario custom_source=convention_scan, custom_asset=css
        // @effects custom_asset_loaded_after_extension_bundles
        await enterAdmin(page);

        const shape = await page.evaluate(() => {
            const config = (window as any).G7Config;

            return {
                hasKey: Object.prototype.hasOwnProperty.call(config, 'customAssets'),
                isArray: Array.isArray(config.customAssets),
            };
        });

        expect(shape.hasKey).toBe(true);
        expect(shape.isArray).toBe(true);

        const declared = await declaredCustomAssets(page);
        const mine = declared.filter((asset) => asset.url.includes('e2e-order'));

        // 픽스처를 8개 놓았으므로 목록이 비면 배선이 끊긴 것이다 —
        // 빈 목록을 그냥 통과시키면 이 스펙 전체가 공허해진다.
        expect(mine.length, '픽스처가 목록에 없습니다 — 수집/서빙 배선이 끊겼습니다.').toBe(8);

        // 확장 간 순서: 모듈 → 플러그인 → 템플릿.
        // 같은 확장 안에서는 CSS 가 JS 보다 앞이고, 파일명 오름차순이다.
        const order = mine.map((asset) => {
            const scope = asset.url.includes('/modules/')
                ? 'module'
                : asset.url.includes('/plugins/')
                  ? 'plugin'
                  : 'template';

            return `${scope}:${asset.url.match(/(\d\d-e2e-order\.\w+)/)?.[1] ?? '?'}`;
        });

        expect(order).toEqual([
            'module:10-e2e-order.css',
            'module:10-e2e-order.js',
            'plugin:10-e2e-order.css',
            'plugin:10-e2e-order.js',
            'template:10-e2e-order.css',
            'template:20-e2e-order.css',
            'template:10-e2e-order.js',
            'template:20-e2e-order.js',
        ]);
    });

    test('사용자 추가 에셋은 same-origin 으로 서빙된다', async ({ page }) => {
        // @scenario custom_source=convention_scan, custom_asset=static_file
        // @effects runtime_asset_served_same_origin
        await enterAdmin(page);

        const declared = await declaredCustomAssets(page);
        const origin = await page.evaluate(() => window.location.origin);

        expect(declared.length).toBeGreaterThan(0);

        for (const asset of declared.filter((item) => item.source !== 'url')) {
            expect(
                asset.url.startsWith('/') || asset.url.startsWith(origin),
                `외부 origin 으로 나갔습니다: ${asset.url}`
            ).toBe(true);
        }
    });

    // ── A. 캐스케이드 경쟁 ──────────────────────────────────────────────────

    test('A1·A2·A3 — 경쟁이 성립한 속성에서 템플릿의 마지막 파일이 이긴다', async ({ page }) => {
        // 관리자 번들이 `body { color }` 를 정의하므로 이 속성은 실제 경쟁 상태다.
        // 최종 승자가 template20 이라는 것은 세 가지를 동시에 증명한다:
        //   A1 운영자 CSS 가 번들 CSS 를 이긴다 (번들 값이 아니다)
        //   A2 확장 간 순서가 모듈 → 플러그인 → 템플릿이다 (앞 둘의 값이 아니다)
        //   A3 한 확장 안에서 파일명 오름차순이다 (template10 의 값이 아니다)
        //
        // A1 과 A2·A3 는 **이기는 근거가 다르다**. A1 은 번들이 `@layer` 안에 있어
        // 레이어 밖(custom)이 순서와 무관하게 이기는 것이고, A2·A3 는 custom 끼리
        // 모두 레이어 밖·같은 specificity 라 **순수하게 소스 순서**로 갈리는 것이다.
        // 아래에서 그 구도 자체를 단언한다 — 근거가 바뀌면 알아채야 한다.
        // @scenario custom_source=convention_scan, custom_asset=css
        // @effects custom_asset_cascade_order_module_plugin_template
        await enterAdmin(page);

        // 경쟁 구도를 먼저 실측한다. «최종값이 내 값이더라» 만으로는 왜 이겼는지 모르고,
        // 이유가 달라지면 보장의 성질도 달라진다.
        //
        //   번들 규칙이 `@layer` 안 → 레이어 밖(custom)이 **순서와 무관하게** 이긴다
        //   번들 규칙이 레이어 밖   → **소스 순서**가 승패를 정한다
        //
        // 둘 중 어느 쪽인지 단언해 두지 않으면, 나중에 번들이 레이어를 걷어냈을 때
        // 보장의 근거가 바뀌었는데도 이 테스트는 계속 통과한다.
        const shape = await page.evaluate(() => {
            const found: Array<{ fromCustom: boolean; layer: string }> = [];

            function walk(rules: CSSRuleList, href: string, layer: string): void {
                for (const rule of Array.from(rules)) {
                    const anyRule = rule as any;

                    if (anyRule.selectorText === 'body' && anyRule.style?.color) {
                        found.push({ fromCustom: href.includes('e2e-order'), layer });
                    }

                    if (anyRule.cssRules) {
                        const next =
                            rule.constructor.name === 'CSSLayerBlockRule'
                                ? `${layer}>${anyRule.name ?? '?'}`
                                : layer;

                        walk(anyRule.cssRules, href, next);
                    }
                }
            }

            for (const sheet of Array.from(document.styleSheets)) {
                try {
                    walk(sheet.cssRules, String(sheet.href ?? 'inline'), '(none)');
                } catch {
                    continue; // 교차 origin 시트는 읽을 수 없다 — same-origin 만 본다
                }
            }

            return {
                bundle: found.filter((entry) => !entry.fromCustom).map((entry) => entry.layer),
                custom: found.filter((entry) => entry.fromCustom).map((entry) => entry.layer),
            };
        });

        expect(
            shape.bundle.length,
            '번들이 body{color} 를 정의하지 않습니다 — 경쟁 상대가 없어 이 단언이 공허해집니다.'
        ).toBeGreaterThan(0);

        expect(
            shape.custom.length,
            'custom 규칙이 문서에 붙지 않았습니다 — 로드 배선이 끊겼습니다.'
        ).toBe(4);

        // 현재 구도: 번들은 레이어 안, custom 은 레이어 밖.
        expect(
            shape.bundle.every((layer) => layer !== '(none)'),
            '번들 규칙이 레이어 밖으로 나왔습니다 — 이제 순서가 승패를 정하므로 이 절의 근거가 바뀌었습니다.'
        ).toBe(true);

        expect(
            shape.custom.every((layer) => layer === '(none)'),
            'custom 규칙이 레이어 안에 들어갔습니다 — 번들 레이어와의 우열이 선언 순서에 좌우됩니다.'
        ).toBe(true);

        await expectFinalColor(page, COLOR.template20);

        const applied = await page.evaluate(() => getComputedStyle(document.body).color);

        expect(applied, '최종 승자가 템플릿의 마지막 파일이 아닙니다.').toBe(COLOR.template20);
        expect(applied).not.toBe(COLOR.template10);
        expect(applied).not.toBe(COLOR.plugin);
        expect(applied).not.toBe(COLOR.module);
    });

    test('A4 — 템플릿 픽스처를 치우면 플러그인이 승자가 된다 (순서가 우연이 아님)', async ({ page }) => {
        // A2 가 «템플릿이 마지막이라» 이긴 것인지, «템플릿만 적용된» 것인지 가른다.
        // 뒤를 치웠을 때 그 앞이 올라와야 순서가 실재하는 것이다.
        // @scenario custom_source=convention_scan, custom_asset=css
        // @effects custom_asset_cascade_order_module_plugin_template
        drop('template', '10-e2e-order.css');
        drop('template', '20-e2e-order.css');

        await enterAdmin(page);
        await expectFinalColor(page, COLOR.plugin);

        const applied = await page.evaluate(() => getComputedStyle(document.body).color);

        expect(applied, '템플릿을 치웠는데 플러그인이 올라오지 않았습니다.').toBe(COLOR.plugin);

        // 다음 테스트를 위해 되돌린다.
        put('template', '10-e2e-order.css', css(COLOR.template10));
        put('template', '20-e2e-order.css', css(COLOR.template20));
    });

    // ── B. JS 실행 순서 ────────────────────────────────────────────────────

    test('B1·B2 — custom JS 는 선언 순서대로 실행된다 (CSS 와 섞여 있어도)', async ({ page }) => {
        // 선언 목록에서 CSS 와 JS 가 번갈아 오지만, JS 끼리의 상대 순서는 유지되어야 한다.
        // 순서가 뒤집히면 뒤 스크립트가 앞 스크립트의 정의에 기대는 구성이 조용히 깨진다.
        // @scenario custom_source=convention_scan, custom_asset=js
        // @effects custom_script_execution_order_matches_declaration
        await enterAdmin(page);

        await page.waitForFunction(
            (args) => ((window as any)[args.trace] ?? []).length === args.count,
            { trace: TRACE, count: EXPECTED_TRACE.length },
            { timeout: 30_000 }
        );

        const trace = await page.evaluate((name) => (window as any)[name], TRACE);

        expect(trace, 'custom JS 실행 순서가 선언 순서와 다릅니다.').toEqual(EXPECTED_TRACE);
    });

    test('B3 — 하나가 실패해도 나머지는 실행되고 안내가 뜬다', async ({ page }) => {
        // 운영자가 넣은 파일 하나가 실패했다고 나머지까지 멈추면, 고친 것과 무관한
        // 기능이 함께 죽는다. 실패는 격리하고 사실은 알린다.
        // @scenario custom_source=convention_scan, custom_asset=js
        // @effects custom_script_execution_order_matches_declaration
        // 가운데 것 하나만 막는다 — 앞뒤가 모두 살아야 «격리» 가 증명된다.
        // 맨 앞이나 맨 뒤를 막으면 "뒤가 안 돌았다"/"앞이 안 돌았다" 와 구분되지 않는다.
        const blocked = '**/plugins/**e2e-order.js*';

        await page.route(blocked, (route) => route.abort());

        await enterAdmin(page);

        // 막힌 플러그인만 빠지고 나머지는 순서 그대로 실행되어야 한다.
        await page.waitForFunction(
            (args) => ((window as any)[args.trace] ?? []).length === args.count,
            { trace: TRACE, count: 3 },
            { timeout: 30_000 }
        );

        const trace = await page.evaluate((name) => (window as any)[name], TRACE);

        expect(trace, '한 파일의 실패가 나머지 실행을 막거나 순서를 흔들었습니다.').toEqual([
            'module',
            'template-10',
            'template-20',
        ]);

        // 실패를 조용히 삼키지 않는다 — 안내가 떠야 운영자가 사실을 안다.
        await expect(page.locator('#g7-asset-failure-notice')).toBeVisible({ timeout: 20_000 });

        await page.unroute(blocked);
    });

    // ── C. 캐시 서명 ───────────────────────────────────────────────────────

    test('C1·C2 — 파일을 고치면 세 타입 모두 URL 이 바뀌고, 확장 번들과 같은 축을 쓴다', async ({ page }) => {
        // 무효화 축은 세 타입이 같다. 템플릿·모듈·플러그인 `custom/` 이 모두 확장 자산과
        // **같은 메커니즘**으로 정적 게시되므로 URL 도 같은 축(확장 캐시 버전)을 쓴다.
        //
        // 파일 서명(mtime)을 URL 에 실으면 안 된다 — 정적 경로는 언제나 현재 게시 버전이라
        // 버전 일치 게이트에 걸려 정적 분기가 영영 선택되지 않는다. 대신 파일 변경을 감지해
        // 캐시 버전을 올리므로, 고치면 URL 도 바뀐다.
        //
        // 종전 이 테스트는 "custom 서명과 번들 URL 은 언제나 독립" 을 단언했다. custom 을
        // 정적 게시로 옮기면서 그 전제가 깨졌으므로 실제 계약으로 갈아 끼운다.
        //
        // @scenario custom_source=convention_scan, custom_asset=css
        // @effects custom_asset_url_busts_on_file_change
        // @scenario custom_source=convention_scan, custom_asset=static_file
        // @effects custom_asset_published_with_extension_assets
        await enterAdmin(page);

        const pick = (list: Array<{ url: string }>, needle: string) =>
            list.find((a) => a.url.includes(needle))?.url;

        const before = await declaredCustomAssets(page);
        const beforeTemplate = pick(before, '20-e2e-order.css');
        const beforeModule = pick(before, '/modules/');
        const beforePlugin = pick(before, '/plugins/');

        expect(beforeTemplate, '템플릿 픽스처 URL 을 찾지 못했습니다.').toBeTruthy();
        expect(beforeModule, '모듈 픽스처 URL 을 찾지 못했습니다.').toBeTruthy();
        expect(beforePlugin, '플러그인 픽스처 URL 을 찾지 못했습니다.').toBeTruthy();

        // 세 타입 모두 내용을 바꾼다 — 어느 타입이든 URL 은 반드시 달라져야 한다.
        put('template', '20-e2e-order.css', css('rgb(55, 55, 55)'));
        put('module', '10-e2e-order.css', css('rgb(66, 66, 66)'));
        put('plugin', '10-e2e-order.css', css('rgb(77, 77, 77)'));

        await enterAdmin(page);

        const after = await declaredCustomAssets(page);

        expect(
            pick(after, '20-e2e-order.css'),
            '템플릿 custom 을 고쳤는데 URL 이 그대로입니다 — 브라우저가 옛 CSS 를 계속 씁니다.'
        ).not.toBe(beforeTemplate);
        expect(
            pick(after, '/modules/'),
            '모듈 custom 을 고쳤는데 URL 이 그대로입니다.'
        ).not.toBe(beforeModule);
        expect(
            pick(after, '/plugins/'),
            '플러그인 custom 을 고쳤는데 URL 이 그대로입니다.'
        ).not.toBe(beforePlugin);

        // 바뀐 내용이 실제로 적용되는지까지 본다 — URL 만 바뀌고 내용이 안 바뀌면 의미가 없다.
        await expectFinalColor(page, 'rgb(55, 55, 55)');

        // 다음 테스트를 위해 원래 값으로 되돌린다.
        put('template', '20-e2e-order.css', css(COLOR.template20));
        put('module', '10-e2e-order.css', css(COLOR.module));
        put('plugin', '10-e2e-order.css', css(COLOR.plugin));
    });
});
