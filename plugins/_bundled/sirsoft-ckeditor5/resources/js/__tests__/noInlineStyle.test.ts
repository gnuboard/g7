// e2e:allow 핸들러가 <style> 요소·style 속성 문자열을 만들지 않고 값은 CSS 변수로 싣는지 잠근다 — 정책을 강제한 브라우저에서의 위반 0건과 다크·높이·미리보기 표시는 출시 전 화면 재실측(엄격한 스타일 정책 강제 + 글쓰기·글 보기)이 덮는다
/**
 * 편집기·본문 표시가 인라인 스타일을 쓰지 않는다 — 엄격한 스타일 정책 호환
 *
 * 회귀 배경: 운영자가 `style-src 'self'; style-src-attr 'none'` 을 걸면 (A) `<style>` 요소와 (B) HTML 문자열의
 * `style="…"` 속성이 막힌다. 종전 핸들러는 본문 보정·다국어 탭·다크 모드·편집기 높이 규칙을 `<style>` 로, 미디어
 * 미리보기 모양을 `style="…"` 문자열로 실어, 정책을 켠 사이트에서 편집기가 다크 모드·높이·탭 모양을 잃었다.
 * 규칙은 플러그인 CSS 파일(`resources/css/main.css` → `dist/css/plugin.css`)로 옮기고, 편집기 높이만 컨테이너의
 * CSS 변수로 싣는다.
 *
 * @scenario-manifest plugins/_bundled/sirsoft-ckeditor5/tests/scenarios/editor-no-inline-style.yaml
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    EMBED_PREVIEW_CLASS,
    EMBED_PREVIEW_FRAME_CLASS,
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

let create: ReturnType<typeof vi.fn>;
let registered: Record<string, Function>;

/**
 * 코어 대역을 깝니다 — CKEditor 본체는 생성 호출만 받는 가짜다.
 *
 * @return 없음
 */
function installCore(): void {
    registered = {};
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
    document.documentElement.classList.remove('dark');
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
    });

    /**
     * @scenario case=editor_dark
     * @effects no_style_element_injected, dark_rules_scoped_in_css_file
     */
    it('다크 모드에서 띄워도, 띄운 뒤 다크로 바꿔도 <style> 을 싣지 않는다', async () => {
        document.documentElement.classList.add('dark');
        await mount({});
        expect(document.querySelectorAll('style')).toHaveLength(0);

        document.documentElement.classList.remove('dark');
        document.documentElement.classList.add('dark');
        // 종전에는 <html> class 변화를 관측자가 받아 <style> 을 넣고 뺐다 — 관측자 콜백이 돌 틈을 준다
        await new Promise((done) => setTimeout(done, 0));

        expect(document.querySelectorAll('style')).toHaveLength(0);
        expect(MAIN_CSS).toMatch(/^html\.dark\s*\{[^}]*--ck-color-base-background:/m);
        expect(MAIN_CSS).toMatch(/^html\.dark \.ck\.ck-toolbar\s*\{/m);
    });

    /**
     * @scenario case=editor_multilingual
     * @effects no_style_element_injected, min_height_via_css_variable, tab_rules_in_css_file
     */
    it('다국어 편집기도 <style> 을 싣지 않고, 탭 규칙은 CSS 파일에 있다', async () => {
        const container = await mount({ multilingual: true, height: 250 });

        expect(container.querySelector('.ckeditor5-locale-tabs')).not.toBeNull();
        expect(document.querySelectorAll('style')).toHaveLength(0);
        expect(container.style.getPropertyValue(MIN_HEIGHT_VAR)).toBe('250px');
        expect(MAIN_CSS).toMatch(/^\.ckeditor5-locale-tabs button\.is-active\.is-default\s*\{/m);
        expect(MAIN_CSS).toMatch(/^html\.dark \.ckeditor5-locale-tabs button\.is-default\s*\{/m);
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
     * @effects no_style_element_injected, content_rules_in_css_file
     */
    it('읽기 화면 본문 스타일 주입 핸들러는 동봉 CSS 링크만 싣고 <style> 을 만들지 않는다', async () => {
        await import('../index');
        const inject = registered['sirsoft-ckeditor5.injectContentCss'];
        expect(inject).toBeTypeOf('function');

        inject();
        inject();

        expect(document.querySelectorAll('style')).toHaveLength(0);
        expect((window as any).G7Core.asset.loadStylesheet).toHaveBeenCalled();
        // 종전 <style> 의 보정 규칙이 CSS 파일에 있다 — 사용자 서식(style 속성) 선택자는 그대로다
        expect(MAIN_CSS).toContain(':root .ck-content table col { width: auto !important; }');
        expect(MAIN_CSS).toContain(':root .ck-content figure.table[style*="float: left"]');
        expect(MAIN_CSS).toContain('p:has(img[style*="float"]) { overflow: hidden; }');
        expect(MAIN_CSS).toMatch(/^html\.dark \.ck-content\S* figure\.table table th \{ background-color:/m);
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
     * @effects min_height_via_css_variable, dark_rules_scoped_in_css_file
     */
    it('CSS 파일: 높이 규칙은 속성 + 변수, CKEditor 변수 덮어쓰기는 html.dark 에만 있다', () => {
        expect(MAIN_CSS).toMatch(
            new RegExp(`\\[${MIN_HEIGHT_ATTR}\\] \\.ck-editor__editable\\s*\\{\\s*min-height: var\\(${MIN_HEIGHT_VAR}\\) !important;`)
        );

        // 다크 변수를 :root 에 두면 라이트 모드 편집기까지 어두워진다
        const rootBlocks = MAIN_CSS.match(/^:root\s*\{[^}]*\}/gm) ?? [];
        expect(rootBlocks).toEqual([]);

        // `.dark` 만으로는 동봉 CSS 의 :root 변수 선언을 이기지 못한다
        expect(MAIN_CSS).not.toMatch(/^\.dark\s*\{/m);
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
        expect(MANIFEST.assets.css).toEqual({ entry: 'resources/css/main.css', output: 'dist/css/plugin.css' });
    });
});
