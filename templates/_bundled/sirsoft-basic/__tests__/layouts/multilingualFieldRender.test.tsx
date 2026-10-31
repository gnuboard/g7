/**
 * 다국어 객체 필드는 화면에 원문 객체로 나가면 안 되고, 로케일도 실제로 골라야 한다.
 *
 * 리소스가 `{ko: …, en: …}` 배열로 내려보내는 필드(`LocalizesCountryName::getCountryLocalizedName()`
 * 의 리턴 타입이 `?array`)를 레이아웃이 그대로 출력하면 구매자 화면에
 * `{"ko":"한국","en":"South Korea"}` 라는 JSON 문자열이 뜬다 — 오류도 경고도 남지 않고,
 * 값이 채워져 있으므로 빈 화면으로도 보이지 않는다.
 * (2026-09-01 S13 3차 계획 검증 브라우저 실측이 교환 재발송지 카드에서 발견)
 *
 * 정답 형태는 **엔진이 정한 `$localized(…)` 하나뿐**이다 (확정 2026-09-01 4차).
 * 손으로 조립한 `?.[_global.locale] ?? ?.ko` 형태는 정답이 아니다 — `_global` 에 `locale`
 * 키 자체가 없어서(브라우저 실측: sidebarOpen·settings·appConfig·shopBase·currentUser … 뿐)
 * 첫 분기가 언제나 undefined 로 떨어지고 결국 늘 한국어가 나간다. 영어 화면에서
 * `Exchanges can only be redelivered within the original country (한국)` 이 뜨던 것이 그 증상이다.
 * `$localized` 는 `context.$locale` → `window.__templateApp.getConfig().locale` 로 활성
 * 로케일을 회수한다(`DataBindingEngine.ts` engine-v1.52.1).
 *
 * 모집단은 **양쪽 다 저장소에서 파생한다** (§2.2 ⓐ) —
 *   ⓐ 검사 대상 파일: 이 저장소의 레이아웃 JSON 전수 (템플릿 2종 + 모듈 + 플러그인 + 코어).
 *      한 템플릿만 훑으면 다른 템플릿의 같은 결함이 통째로 사각이 된다.
 *   ⓑ 검사 대상 필드: PHP 리소스에서 다국어 **객체**로 내보내는 키를 직접 뽑는다.
 *      손으로 적으면 리소스가 늘어도 따라오지 않는다.
 *
 * 키 이름이 겹치는 축을 파생으로 가른다 — `country_name` 은 이커머스
 * `UserAddressResource` 가 **객체**로 내보내지만 코어 `UserResource` 는 같은 이름을
 * `HasCountryAttributes::getCountryName(): string` 으로 **문자열**로 내보낸다. 레이아웃 글자만
 * 봐서는 둘을 구분할 수 없으므로, 문자열로도 나가는 키는 강제 대상에서 제외한다.
 * 이 제외도 하드코딩이 아니라 PHP 파생이다.
 */
import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const REPO_ROOT = path.resolve(__dirname, '../../../../..');

/**
 * 레이아웃 JSON 이 사는 곳 — **디렉토리 목록도 파생한다.**
 *
 * 손으로 적으면 확장이 늘 때 따라오지 않고, 더 나쁘게는 지금도 틀린다:
 * 처음 적었던 목록은 존재하지 않는 `resources/layouts` 를 넣고 있었고 플러그인의
 * `resources/extensions`(레이아웃 확장 JSON — 같은 렌더링 경로를 탄다) 를 통째로 빠뜨려,
 * 그 필드를 실제로 그리는 결제 플러그인 3종이 모집단 밖이었다.
 */
function deriveLayoutRoots(): string[] {
    const roots: string[] = [];
    const push = (p: string) => {
        if (fs.existsSync(path.join(REPO_ROOT, p))) roots.push(p);
    };
    push('resources/layouts');
    for (const kind of ['templates', 'modules', 'plugins']) {
        const base = path.join(REPO_ROOT, kind, '_bundled');
        if (!fs.existsSync(base)) continue;
        for (const ext of fs.readdirSync(base, { withFileTypes: true })) {
            if (!ext.isDirectory()) continue;
            // 템플릿은 `layouts/`, 모듈·플러그인은 `resources/{layouts,extensions}/`
            push(path.join(kind, '_bundled', ext.name, 'layouts'));
            push(path.join(kind, '_bundled', ext.name, 'resources', 'layouts'));
            push(path.join(kind, '_bundled', ext.name, 'resources', 'extensions'));
        }
    }
    return roots;
}

const LAYOUT_ROOTS = deriveLayoutRoots();

/** PHP 리소스가 사는 곳 — 다국어 객체/문자열 키를 여기서 파생한다. */
const PHP_ROOTS = ['app', 'modules/_bundled', 'plugins/_bundled'];

function walk(dir: string, ext: string, out: string[] = []): string[] {
    if (!fs.existsSync(dir)) return out;
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        if (entry.name === 'node_modules' || entry.name === 'vendor') continue;
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) walk(full, ext, out);
        else if (entry.name.endsWith(ext)) out.push(full);
    }
    return out;
}

/**
 * `'키' => …getCountryLocalizedName(` 형태에서 키를 뽑는다 — 다국어 **객체**로 나가는 필드.
 * `'키' => …getCountryName(` 형태에서 키를 뽑는다 — **문자열**로 나가는 필드.
 */
function derivePhpEmittedKeys(): { objectKeys: Set<string>; stringKeys: Set<string> } {
    const objectKeys = new Set<string>();
    const stringKeys = new Set<string>();
    for (const root of PHP_ROOTS) {
        for (const file of walk(path.join(REPO_ROOT, root), '.php')) {
            const src = fs.readFileSync(file, 'utf-8');
            for (const m of src.matchAll(/'([a-z0-9_]+)'\s*=>[^\n]*?getCountryLocalizedName\s*\(/g)) {
                objectKeys.add(m[1]);
            }
            for (const m of src.matchAll(/'([a-z0-9_]+)'\s*=>[^\n]*?getCountryName\s*\(/g)) {
                stringKeys.add(m[1]);
            }
        }
    }
    return { objectKeys, stringKeys };
}

function layoutLinesMentioning(field: string): Array<{ file: string; line: number; text: string }> {
    const found: Array<{ file: string; line: number; text: string }> = [];
    for (const root of LAYOUT_ROOTS) {
        for (const file of walk(path.join(REPO_ROOT, root), '.json')) {
            const lines = fs.readFileSync(file, 'utf-8').split('\n');
            lines.forEach((text, i) => {
                if (text.includes(field)) {
                    found.push({ file: path.relative(REPO_ROOT, file), line: i + 1, text });
                }
            });
        }
    }
    return found;
}

describe('다국어 객체 필드 출력 — 엔진이 정한 로케일 선택만 인정', () => {
    const { objectKeys, stringKeys } = derivePhpEmittedKeys();
    // 문자열로도 나가는 키는 레이아웃 글자만으로 구분이 안 되므로 강제 대상에서 뺀다.
    const enforced = [...objectKeys].filter((k) => !stringKeys.has(k)).sort();

    /**
     * @effects multilingual_field_keys_derived_from_php_resources
     */
    it('PHP 파생이 살아 있다 — 다국어 객체 키와 문자열 키를 실제로 뽑아낸다', () => {
        // 하한 — 두 집합이 비면 아래 검사는 아무것도 재지 않는다.
        expect(objectKeys.size, '다국어 객체 키를 하나도 못 뽑았다 — PHP 파생이 깨졌다').toBeGreaterThan(0);
        expect(stringKeys.size, '문자열 키를 하나도 못 뽑았다 — 충돌 판정 파생이 깨졌다').toBeGreaterThan(0);
        expect(enforced.length, '강제 대상이 0개다 — 전부 충돌로 걸러졌다').toBeGreaterThan(0);
    });

    /**
     * @effects layout_population_spans_templates_modules_plugins
     */
    it('레이아웃 모집단이 살아 있다 — 세 종류의 확장을 모두 훑는다', () => {
        const total = LAYOUT_ROOTS.reduce((n, r) => n + walk(path.join(REPO_ROOT, r), '.json').length, 0);
        expect(total, '레이아웃 JSON 을 하나도 못 찾았다 — 경로 파생이 깨졌다').toBeGreaterThan(0);

        // 한 종류라도 통째로 빠지면 그 계열의 같은 결함이 영구히 사각이 된다.
        for (const kind of ['templates', 'modules', 'plugins']) {
            expect(
                LAYOUT_ROOTS.filter((r) => r.startsWith(kind)).length,
                `${kind} 계열의 레이아웃 디렉토리를 하나도 못 찾았다 — 경로 파생이 깨졌다`,
            ).toBeGreaterThan(0);
        }
    });

    /**
     * `_global.locale` 은 **어디서도 세워지지 않는다**(2026-09-01 실측).
     *
     * `setLocale` 핸들러는 `TemplateApp.changeLocale()` 을 부르고, 그 메서드는 config·
     * localStorage·DB·레이아웃 캐시·엔진 재초기화를 하지만 전역 상태에는 아무것도 쓰지 않는다.
     * 그래서 `x?.[_global.locale] ?? x?.ko` 는 언제나 첫 분기가 undefined 라 늘 한국어로
     * 떨어진다 — 예외도 경고도 없고, 한국어 화면에서는 정상으로 보인다.
     *
     * 활성 로케일을 든 것은 컨텍스트 변수 `$locale` 이다. `_global.locale ?? $locale` 형태는
     * 결과적으로 동작하므로 여기서 막는 것은 **`_global.locale` 이 유일한 로케일 출처인**
     * 경우뿐이다.
     *
     * **범위는 템플릿 레이아웃이다** — 템플릿 쪽에는 이 키를 쓰는 곳이 없어 같은 형태가
     * 조용히 `?.ko` 로 굳는다. (`sirsoft-ecommerce` 관리자 화면이 셸 초기화 액션으로 이 키를
     * 직접 채우던 방식은 걷어냈고, 그 화면들도 `$locale` 을 읽는다.)
     *
     * 고칠 때는 `$locale` 을 읽는다 — 엔진이 렌더마다 컨텍스트 최상위에 넣는다
     * (`template-engine.ts` `createGlobalVariables()`; `DynamicRenderer` 의 편집 모드 주입은
     * 편집기 미리보기용 보강이다). 표시용으로는 `$localized(…)` 도 된다 — 그 헬퍼는 컨텍스트에
     * `$locale` 이 없으면 `window.__templateApp.getConfig().locale` 로 회수한다
     * (`DataBindingEngine.ts` engine-v1.52.1).
     *
     * `_global.locale ?? $locale` 처럼 뒤에 다른 항을 둔 형태는 여기서 거르지 않는다 —
     * 이 축이 막는 것은 템플릿에서 `_global.locale` 이 **유일한 출처**인 경우뿐이다.
     *
     * @scenario output_form=global_locale_sole_source
     * @effects template_layouts_never_use_global_locale_as_sole_source
     */
    it('템플릿 레이아웃이 _global.locale 을 유일한 로케일 출처로 쓰지 않는다', () => {
        const templateRoots = LAYOUT_ROOTS.filter((r) => r.startsWith('templates'));

        // 하한 — 템플릿 레이아웃을 하나도 못 찾으면 이 축은 아무것도 재지 않는다.
        expect(templateRoots.length, '템플릿 레이아웃 디렉토리를 하나도 못 찾았다 — 경로 파생이 깨졌다').toBeGreaterThan(0);

        const offenders = layoutLinesMentioning('_global.locale')
            .filter((r) => r.file.startsWith('templates'))
            .filter((r) => !r.text.includes('$locale'))
            .map((r) => `${r.file}:${r.line}`);

        expect(
            offenders,
            '_global.locale 은 세워지지 않는 값이다 — 이 자리는 언제나 undefined 로 평가되어 로케일 선택이 죽고 한국어로 고착된다. $localized(…) 또는 $locale 을 쓴다',
        ).toEqual([]);
    });

    for (const field of enforced) {
        /**
         * @scenario output_form=localized_helper
         * @effects multilingual_field_output_uses_localized_helper
         */
        it(`${field} 를 출력하는 모든 지점이 $localized() 로 로케일을 고른다`, () => {
            const refs = layoutLinesMentioning(field);

            // 하한 — 그 필드를 쓰는 레이아웃이 없으면 이 축은 아무것도 재지 않는다.
            expect(refs.length, `${field} 를 쓰는 레이아웃이 하나도 없다 — 파생이 깨졌다`).toBeGreaterThan(0);

            // 엔진이 정한 형태: `$localized(` 안에 그 필드가 들어 있어야 한다.
            const localeAware = new RegExp('\\$localized\\([^)]*' + field);
            const offenders = refs.filter((r) => !localeAware.test(r.text));

            expect(
                offenders.map((o) => `${o.file}:${o.line}`),
                `다국어 객체를 $localized() 없이 출력한다 — 원문 객체가 뜨거나 로케일 선택이 죽는다`,
            ).toEqual([]);
        });
    }
});
