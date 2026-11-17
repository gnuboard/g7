// e2e:allow 핸들러가 <style> 요소·style 속성 문자열을 만들지 않고 값은 CSS 변수로 싣는지 잠근다 — 정책을 강제한 브라우저에서의 위반 0건과 다크·높이·미리보기 표시는 출시 전 화면 재실측(엄격한 스타일 정책 강제 + 글쓰기·글 보기)이 덮는다
/**
 * 편집기·본문 표시가 인라인 스타일을 쓰지 않는다 — 엄격한 스타일 정책 호환
 *
 * 회귀 배경: 운영자가 `style-src 'self'; style-src-attr 'none'` 을 걸면 (A) `<style>` 요소와 (B) HTML 문자열의
 * `style="…"` 속성이 막힌다. 종전 핸들러는 본문 보정·다국어 탭·다크 모드·편집기 높이 규칙을 `<style>` 로, 미디어
 * 미리보기 모양을 `style="…"` 문자열로 실어, 정책을 켠 사이트에서 편집기가 다크 모드·높이·탭 모양을 잃었다.
 * 이 플러그인이 이름을 정하는 클래스(다국어 탭·미리보기, `ckeditor-*`)의 규칙은 플러그인 CSS 파일
 * (`resources/css/main.css` → `dist/css/plugin.css`)로, CKEditor 라이브러리 DOM(`.ck-*`) 보정·다크·높이 규칙은
 * 확장 CSS 가 남의 클래스를 실을 수 없어(감사 룰 `extension-css-namespace`) `initEditor.ts` 의 `LIBRARY_CSS` 를 코어
 * `G7Core.style.setRules`(생성형 스타일시트 — 정책에 막히지 않는다)로 싣는다. 편집기 높이 값만 컨테이너의 CSS 변수로
 * 싣고, 다크 규칙은 중립 표식 `html[data-g7-color-scheme="dark"]` 로 스코프한다.
 *
 * @scenario-manifest plugins/_bundled/sirsoft-ckeditor5/tests/scenarios/editor-no-inline-style.yaml
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    EMBED_PREVIEW_CLASS,
    EMBED_PREVIEW_FRAME_CLASS,
    LIBRARY_CSS,
    LIBRARY_STYLE_KEY,
    MIN_HEIGHT_ATTR,
    MIN_HEIGHT_VAR,
    initEditorHandler,
    mediaEmbedProviders,
} from '../handlers/initEditor';
import { destroyEditorHandler } from '../handlers/destroyEditor';

/** vitest 는 플러그인 디렉터리에서 실행한다 */
const PLUGIN_ROOT = process.cwd();
const read = (path: string): string => readFileSync(resolve(PLUGIN_ROOT, path), 'utf8');

const MAIN_CSS = read('resources/css/main.css');
const MANIFEST = JSON.parse(read('plugin.json'));
const SOURCES: Record<string, string> = {
    'index.ts': read('resources/js/index.ts'),
    'initEditor.ts': read('resources/js/handlers/initEditor.ts'),
    'destroyEditor.ts': read('resources/js/handlers/destroyEditor.ts'),
    'editingViewStyle.ts': read('resources/js/handlers/editingViewStyle.ts'),
};

/** 주석을 걷어 낸 소스 — 주석 속 설명 문구(`<style>` 등)를 코드로 오인하지 않게 */
const stripComments = (source: string): string => source
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/(^|[^:])\/\/.*$/gm, '$1');

/** 정규식 특수문자를 글자 그대로 맞추도록 이스케이프 */
const escapeRegExp = (text: string): string => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/** 다크 모드 중립 표식 — 라이브러리 규칙(`LIBRARY_CSS`)의 스코프 */
const DARK_ROOT = 'html[data-g7-color-scheme="dark"]';

/** 플러그인 CSS 파일의 다크 짝 접미 — 템플릿 다크 클래스(`.dark`)가 아니라 중립 표식 규격(「다크 안의 라이트」 제외) */
const DARK_SUFFIX = ':is([data-g7-color-scheme="dark"] *, [data-g7-color-scheme="dark"])'
    + ':not(:where([data-g7-color-scheme="dark"] [data-g7-color-scheme="light"] *, [data-g7-color-scheme="dark"] [data-g7-color-scheme="light"]))';

/** 주석을 걷어 낸 플러그인 CSS — 머리 주석의 설명 문구(`.ck-*` 등)를 선택자로 오인하지 않게 */
const MAIN_CSS_CODE = MAIN_CSS.replace(/\/\*[\s\S]*?\*\//g, '');

let create: ReturnType<typeof vi.fn>;
let registered: Record<string, Function>;
/** 코어 `G7Core.style` 대역이 받은 규칙 묶음(key → CSS) */
let runtimeRules: Map<string, string>;
let setRules: ReturnType<typeof vi.fn>;

/**
 * 코어 대역을 깝니다 — CKEditor 본체는 생성 호출만 받는 가짜다.
 *
 * @return 없음
 */
function installCore(): void {
    registered = {};
    runtimeRules = new Map();
    setRules = vi.fn((key: string, css: string) => {
        runtimeRules.set(key, css);

        return { key, mode: 'adopted', remove: () => runtimeRules.delete(key) };
    });
    create = vi.fn(async () => ({
        destroy: async () => {},
        model: { document: { on: () => {} } },
        editing: { view: { document: { on: () => {} } } },
        ui: { view: { editable: { element: document.createElement('div') } } },
        plugins: { get: () => undefined },
        getData: () => '',
        setData: () => {},
        on: () => {},
        enableReadOnlyMode: () => {},
    }));
    (window as any).G7Core = {
        locale: { current: () => 'ko', supported: () => ['ko', 'en'] },
        toast: { error: vi.fn() },
        // 코어 생성형 스타일시트 API(engine-v1.91.0) — 실제 구현은 `<style>` 를 만들지 않는다(jsdom 폴백만 예외라 대역으로 잰다)
        style: {
            setRules,
            has: (key: string) => runtimeRules.has(key),
            remove: (key: string) => runtimeRules.delete(key),
        },
        AuthManager: { getInstance: () => ({ getUser: () => null }) },
        state: { get: () => ({}), getLocal: () => ({ form: {} }), setLocal: vi.fn() },
        getActionDispatcher: () => ({
            registerHandler: (name: string, handler: Function) => {
                registered[name] = handler;
            },
        }),
        asset: {
            plugin: (_id: string, p: string) => `/assets?file=${encodeURIComponent(p)}`,
            loadStylesheet: vi.fn(async () => {}),
            loadScript: vi.fn(async () => {
                (window as any).CKEDITOR_VERSION = '43.3.1';
                (window as any).CKEDITOR = { ClassicEditor: Object.assign(class {}, { create }) };
            }),
        },
    };
}

/**
 * 편집기 컨테이너를 세우고 편집기를 띄웁니다.
 *
 * @param params initEditor 파라미터
 * @return 컨테이너
 */
async function mount(params: Record<string, unknown>): Promise<HTMLElement> {
    const container = document.createElement('div');
    container.id = 'ckeditor5-content';
    document.body.appendChild(container);
    await initEditorHandler({ params: { name: 'content', ...params } }, {});
    expect(create).toHaveBeenCalled();

    return container;
}

beforeEach(() => {
    document.body.innerHTML = '';
    vi.spyOn(console, 'log').mockImplementation(() => {});
    installCore();
});

afterEach(async () => {
    await destroyEditorHandler({ params: { name: 'content' } }, {});
    document.documentElement.removeAttribute('data-g7-color-scheme');
    delete (window as any).G7Core;
    delete (window as any).CKEDITOR;
    delete (window as any).CKEDITOR_VERSION;
    delete (window as any).__SirsoftCkeditor5;
    document.querySelectorAll('script, link, style').forEach((el) => el.remove());
    vi.restoreAllMocks();
    vi.resetModules();
});

describe('편집기 — 인라인 스타일 없음', () => {
    /**
     * @scenario case=editor_single
     * @effects no_style_element_injected, min_height_via_css_variable
     */
    it('편집기를 띄워도 <style> 을 싣지 않고, 높이는 컨테이너의 CSS 변수로 싣는다', async () => {
        const container = await mount({ height: 320 });

        expect(document.querySelectorAll('style')).toHaveLength(0);
        expect(container.hasAttribute(MIN_HEIGHT_ATTR)).toBe(true);
        expect(container.style.getPropertyValue(MIN_HEIGHT_VAR)).toBe('320px');
        // 라이브러리 DOM 보정·다크·높이 규칙은 코어 생성형 스타일시트로 싣는다
        expect(setRules).toHaveBeenCalledWith(LIBRARY_STYLE_KEY, LIBRARY_CSS);
    });

    /**
     * @scenario case=editor_single
     * @effects no_style_element_injected
     */
    it('코어 생성형 스타일시트 API 가 없어도 <style> 로 되돌아가지 않는다', async () => {
        delete (window as any).G7Core.style;

        await mount({ height: 320 });

        expect(document.querySelectorAll('style')).toHaveLength(0);
    });

    /**
     * @scenario case=editor_dark
     * @effects no_style_element_injected, dark_rules_scoped_by_neutral_marker
     */
    it('다크 모드에서 띄워도, 띄운 뒤 다크로 바꿔도 <style> 을 싣지 않는다', async () => {
        document.documentElement.setAttribute('data-g7-color-scheme', 'dark');
        await mount({});
        expect(document.querySelectorAll('style')).toHaveLength(0);

        document.documentElement.setAttribute('data-g7-color-scheme', 'light');
        document.documentElement.setAttribute('data-g7-color-scheme', 'dark');
        // 종전에는 <html> 다크 표식 변화를 관측자가 받아 <style> 을 넣고 뺐다 — 관측자 콜백이 돌 틈을 준다
        await new Promise((done) => setTimeout(done, 0));

        expect(document.querySelectorAll('style')).toHaveLength(0);
        // 전환을 따라 규칙을 다시 싣지 않는다 — 한 벌을 실어 두고 선택자 스코프로 전환을 따른다
        expect(setRules).toHaveBeenCalledTimes(1);
        expect(runtimeRules.get(LIBRARY_STYLE_KEY)).toBe(LIBRARY_CSS);
        expect(LIBRARY_CSS).toMatch(new RegExp(`^${escapeRegExp(DARK_ROOT)}\\s*\\{[^}]*--ck-color-base-background:`, 'm'));
        expect(LIBRARY_CSS).toMatch(new RegExp(`^${escapeRegExp(DARK_ROOT)} \\.ck\\.ck-toolbar\\s*\\{`, 'm'));
    });

    /**
     * @scenario case=editor_multilingual
     * @effects no_style_element_injected, min_height_via_css_variable, tab_rules_in_css_file
     */
    it('다국어 편집기도 <style> 을 싣지 않고, 탭 규칙은 CSS 파일에 있다', async () => {
        const container = await mount({ multilingual: true, height: 250 });

        expect(container.querySelector('.ckeditor-locale-tabs')).not.toBeNull();
        // 핸들러가 다는 클래스와 CSS 파일의 선택자가 같은 이름이다(기본 언어 = 활성 탭)
        expect(container.querySelector('.ckeditor-locale-tabs .ckeditor-locale-tab.ckeditor-locale-tab--active.ckeditor-locale-tab--default')).not.toBeNull();
        expect(document.querySelectorAll('style')).toHaveLength(0);
        expect(container.style.getPropertyValue(MIN_HEIGHT_VAR)).toBe('250px');
        expect(MAIN_CSS).toMatch(/^\.ckeditor-locale-tabs \.ckeditor-locale-tab\.ckeditor-locale-tab--active\.ckeditor-locale-tab--default\s*\{/m);
        expect(MAIN_CSS).toMatch(new RegExp(`^\\.ckeditor-locale-tabs \\.ckeditor-locale-tab\\.ckeditor-locale-tab--default${escapeRegExp(DARK_SUFFIX)}\\s*\\{`, 'm'));
    });

    /**
     * @scenario case=editor_destroy
     * @effects min_height_marker_removed_on_destroy
     */
    it('편집기를 해제하면 컨테이너의 높이 속성과 CSS 변수를 걷는다', async () => {
        const container = await mount({ height: 320 });

        await destroyEditorHandler({ params: { name: 'content' } }, {});

        expect(container.hasAttribute(MIN_HEIGHT_ATTR)).toBe(false);
        expect(container.style.getPropertyValue(MIN_HEIGHT_VAR)).toBe('');
    });

    /**
     * @scenario case=content_view
     * @effects no_style_element_injected, content_rules_via_runtime_stylesheet
     */
    it('읽기 화면 본문 스타일 주입 핸들러는 동봉 CSS 링크와 생성형 스타일시트만 싣고 <style> 을 만들지 않는다', async () => {
        await import('../index');
        const inject = registered['sirsoft-ckeditor5.injectContentCss'];
        expect(inject).toBeTypeOf('function');

        inject();
        inject();

        expect(document.querySelectorAll('style')).toHaveLength(0);
        expect((window as any).G7Core.asset.loadStylesheet).toHaveBeenCalled();
        // 읽기 화면(편집기 없음)에서도 라이브러리 보정 규칙을 싣고, 두 번 불러도 한 벌만 싣는다
        expect(setRules).toHaveBeenCalledTimes(1);
        expect(setRules).toHaveBeenCalledWith(LIBRARY_STYLE_KEY, LIBRARY_CSS);
        // 종전 <style> 의 보정 규칙이 LIBRARY_CSS 에 있다 — 사용자 서식(style 속성) 선택자는 그대로다
        expect(LIBRARY_CSS).toContain(':root .ck-content table col { width: auto !important; }');
        expect(LIBRARY_CSS).toContain(':root .ck-content figure.table[style*="float: left"]');
        expect(LIBRARY_CSS).toContain('p:has(img[style*="float"]) { overflow: hidden; }');
        expect(LIBRARY_CSS).toMatch(new RegExp(`^${escapeRegExp(DARK_ROOT)} \\.ck-content\\S* figure\\.table table th \\{ background-color:`, 'm'));
    });

    /**
     * @scenario case=embed_preview
     * @effects preview_html_has_no_style_attribute
     */
    it('미디어 미리보기 HTML 에 style 속성이 없고 클래스로 꾸민다', () => {
        const [provider] = mediaEmbedProviders([
            { id: 'youtube', patterns: ['youtu\\.be/(?<id>[\\w-]{11})'], player_url: 'https://www.youtube-nocookie.com/embed/{id}' },
        ]);
        const html = provider.html('https://youtu.be/dQw4w9WgXcQ'.match(provider.url[0]) as RegExpMatchArray);

        expect(html).not.toMatch(/style\s*=/i);
        expect(html).toContain(`<div class="${EMBED_PREVIEW_CLASS}">`);
        expect(html).toContain(`class="${EMBED_PREVIEW_FRAME_CLASS}"`);
        expect(html).toContain('src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"');

        expect(MAIN_CSS).toMatch(new RegExp(`\\.${EMBED_PREVIEW_CLASS}\\s*\\{[^}]*position: relative[^}]*padding-bottom: 56\\.25%[^}]*height: 0`));
        expect(MAIN_CSS).toMatch(new RegExp(`\\.${EMBED_PREVIEW_FRAME_CLASS}\\s*\\{[^}]*position: absolute[^}]*inset: 0[^}]*width: 100%[^}]*height: 100%[^}]*border: 0`));
    });

    /**
     * @scenario case=css_contract
     * @effects min_height_via_css_variable, dark_rules_scoped_by_neutral_marker, library_rules_outside_css_file
     */
    it('규칙 계약: 높이 규칙은 속성 + 변수, CKEditor 변수 덮어쓰기는 html[data-g7-color-scheme="dark"] 에만 있다', () => {
        expect(LIBRARY_CSS).toMatch(
            new RegExp(`\\[${MIN_HEIGHT_ATTR}\\] \\.ck-editor__editable\\s*\\{\\s*min-height: var\\(${MIN_HEIGHT_VAR}\\) !important;`)
        );

        for (const [name, css] of [['LIBRARY_CSS', LIBRARY_CSS], ['main.css', MAIN_CSS]]) {
            // 다크 변수를 :root 에 두면 라이트 모드 편집기까지 어두워진다
            expect(css.match(/^:root\s*\{[^}]*\}/gm) ?? [], name).toEqual([]);
            // `.dark` 만으로는 동봉 CSS 의 :root 변수 선언을 이기지 못하고, 템플릿 다크 클래스는 전제하지 않는다
            expect(css, name).not.toMatch(/(^|[\s,>~+(])(html)?\.dark[\s{.,:)]/m);
        }

        // CKEditor 변수(--ck-color-*) 덮어쓰기는 중립 표식 블록 하나에만 있다
        const varBlocks = LIBRARY_CSS.match(/^[^{}\n]+\{[^}]*--ck-color-[^}]*\}/gm) ?? [];
        expect(varBlocks).toHaveLength(1);
        expect(varBlocks[0].slice(0, varBlocks[0].indexOf('{')).trim()).toBe(DARK_ROOT);
        expect(MAIN_CSS_CODE).not.toContain('--ck-color-');

        // 라이브러리 DOM(`.ck-*`) 규칙은 플러그인 CSS 파일(자기 이름공간만 — `extension-css-namespace`)에 두지 않는다
        expect(MAIN_CSS_CODE).not.toMatch(/\.ck[-.\s{]/);
    });

    /**
     * @scenario case=source_contract
     * @effects no_style_element_injected, preview_html_has_no_style_attribute, css_declared_as_plugin_asset
     */
    it('핸들러 소스에 <style> 생성·style 속성 문자열이 없고, CSS 는 플러그인 자산으로 선언돼 있다', () => {
        for (const [name, source] of Object.entries(SOURCES)) {
            const code = stripComments(source);

            expect(code, name).not.toMatch(/createElement\(\s*['"]style['"]\s*\)/);
            expect(code, name).not.toMatch(/setAttribute\(\s*['"]style['"]/);
            expect(code, name).not.toMatch(/style\s*=\s*\\?["']/);
        }

        expect(SOURCES['index.ts']).toContain("import '../css/main.css';");
        expect(MANIFEST.assets.css).toEqual({ entry: 'resources/css/main.css', output: 'dist/css/plugin.css', namespace: 'ckeditor' });
    });
});
