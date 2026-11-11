// e2e:allow 편집 화면 변환기의 속성 쓰기 한 곳을 가짜 변환기로 잠근다 — 정책을 강제한 브라우저에서 정렬·색·표·이미지 크기가 실제로 보이는지는 tests/Playwright/specs/smoke/strict-csp.spec.ts 의 「게시판 글 수정 (CKEditor 5 편집기)」와 출시 전 화면 재실측이 덮는다
/**
 * 편집 화면의 서식(style)은 속성 문자열이 아니라 CSSOM 으로 입힌다 — 엄격한 스타일 정책 호환
 *
 * 회귀 배경: 운영자가 `style-src 'self'; style-src-attr 'none'` 을 걸면 `setAttribute('style', …)` 가 막힌다.
 * CKEditor 는 편집 화면 요소의 서식(가운데 정렬·글자색·표 테두리·이미지 크기)을 그 방식으로 써서, 정책을 건
 * 사이트에서는 가운데 정렬을 눌러도 편집 화면이 왼쪽 그대로였다(저장되는 내용은 맞다). 편집 화면 변환기의
 * 속성 쓰기를 인스턴스 단위로 덮어써 `style` 만 `el.style.cssText` 로 입힌다. 저장용 변환기는 건드리지 않는다.
 *
 * @scenario-manifest plugins/_bundled/sirsoft-ckeditor5/tests/scenarios/editor-editing-view-style.yaml
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { EditingViewCssomStyle } from '../handlers/editingViewStyle';
import { initEditorHandler } from '../handlers/initEditor';
import { destroyEditorHandler } from '../handlers/destroyEditor';

/** CKEditor 가 안전하지 않은 속성을 돌려 쓸 때 붙이는 접두어 */
const UNSAFE_PREFIX = 'data-ck-unsafe-attribute-';

/**
 * `setAttribute('style', …)` 호출을 센다 — jsdom 은 `style.cssText` 대입 뒤에도 style 속성을 보여 주므로,
 * 속성 유무가 아니라 「속성 문자열로 실었는가」를 호출로 잰다.
 *
 * @return 호출 기록을 돌려주는 함수
 */
function spyStyleAttributeWrites(): () => string[] {
    const original = Element.prototype.setAttribute;
    const writes: string[] = [];
    vi.spyOn(Element.prototype, 'setAttribute').mockImplementation(function (this: Element, name: string, value: string) {
        if (name.toLowerCase() === 'style') {
            writes.push(value);
        }

        return original.call(this, name, value);
    });

    return () => writes;
}

/**
 * CKEditor 편집 화면 변환기의 대역 — 동봉 판(43.3.1)의 `setDomElementAttribute` 와 같은 판정·같은 쓰기를 한다.
 *
 * @param safe `shouldRenderAttribute` 가 돌려줄 값
 * @return 변환기 대역과 원본 호출 기록
 */
function makeDomConverter(safe = true): { converter: any; originalCalls: Array<[string, string]> } {
    const originalCalls: Array<[string, string]> = [];
    const converter: any = {
        renderingMode: 'editing',
        shouldRenderAttribute: vi.fn(() => safe),
        setDomElementAttribute(el: Element, key: string, value: string, related?: any): void {
            originalCalls.push([key, value]);
            const render = this.shouldRenderAttribute(key, value, el.tagName.toLowerCase())
                || (related && related.shouldRenderUnsafeAttribute(key));
            el.setAttribute(render ? key : UNSAFE_PREFIX + key, value);
        },
    };

    return { converter, originalCalls };
}

/**
 * 변환기를 가진 편집기 대역에 플러그인을 건다.
 *
 * @param converter 변환기 대역
 * @return 편집기 대역
 */
function install(converter: any): any {
    const editor = { editing: { view: { domConverter: converter } } };
    EditingViewCssomStyle(editor);

    return editor;
}

afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

describe('편집 화면 서식 — CSSOM 으로 입힌다', () => {
    /**
     * @scenario case=style_attribute
     * @effects style_applied_via_cssom, no_style_attribute_string_write
     */
    it('style 은 cssText 로 입히고 setAttribute("style") 를 부르지 않는다', () => {
        const { converter, originalCalls } = makeDomConverter();
        install(converter);
        const writes = spyStyleAttributeWrites();
        const p = document.createElement('p');

        converter.setDomElementAttribute(p, 'style', 'text-align:center;', {});

        expect(writes()).toEqual([]);
        expect(originalCalls).toEqual([]);
        expect(p.style.textAlign).toBe('center');
        expect(p.style.length).toBe(1);
    });

    /**
     * @scenario case=style_attribute
     * @effects style_applied_via_cssom
     */
    it('값이 바뀌면 앞 값을 남기지 않고 새 값으로 바꾼다', () => {
        const { converter } = makeDomConverter();
        install(converter);
        const p = document.createElement('p');

        converter.setDomElementAttribute(p, 'style', 'text-align:center;margin-left:40px;', {});
        converter.setDomElementAttribute(p, 'style', 'text-align:right;', {});

        expect(p.style.textAlign).toBe('right');
        expect(p.style.marginLeft).toBe('');
        expect(p.style.length).toBe(1);
    });

    /**
     * @scenario case=other_attribute
     * @effects other_attributes_delegated
     */
    it('style 이 아닌 속성은 원본이 그대로 쓴다', () => {
        const { converter, originalCalls } = makeDomConverter();
        install(converter);
        const a = document.createElement('a');
        const related = { shouldRenderUnsafeAttribute: () => false };

        converter.setDomElementAttribute(a, 'href', 'https://example.com', related);
        converter.setDomElementAttribute(a, 'class', 'ck-link', related);

        expect(originalCalls).toEqual([['href', 'https://example.com'], ['class', 'ck-link']]);
        expect(a.getAttribute('href')).toBe('https://example.com');
        expect(a.getAttribute('class')).toBe('ck-link');
    });

    /**
     * @scenario case=unsafe_style
     * @effects unsafe_value_delegated
     */
    it('안전 판정이 거짓이면 원본으로 넘겨 CKEditor 의 우회 속성 경로를 그대로 탄다', () => {
        const { converter, originalCalls } = makeDomConverter(false);
        install(converter);
        const writes = spyStyleAttributeWrites();
        const p = document.createElement('p');

        converter.setDomElementAttribute(p, 'style', 'color:red;', { shouldRenderUnsafeAttribute: () => false });

        expect(originalCalls).toEqual([['style', 'color:red;']]);
        expect(writes()).toEqual([]);
        expect(p.style.length).toBe(0);
        expect(p.getAttribute(`${UNSAFE_PREFIX}style`)).toBe('color:red;');
    });

    /**
     * @scenario case=unsafe_style
     * @effects style_applied_via_cssom
     */
    it('변환기는 거부해도 뷰 요소가 허용하면 입히고, 남아 있던 우회 속성은 걷는다', () => {
        const { converter, originalCalls } = makeDomConverter(false);
        install(converter);
        const p = document.createElement('p');
        p.setAttribute(`${UNSAFE_PREFIX}style`, 'color:red;');

        converter.setDomElementAttribute(p, 'style', 'color:blue;', { shouldRenderUnsafeAttribute: (key: string) => key === 'style' });

        expect(originalCalls).toEqual([]);
        expect(p.style.color).toBe('blue');
        expect(p.hasAttribute(`${UNSAFE_PREFIX}style`)).toBe(false);
    });

    /**
     * @scenario case=empty_value
     * @effects empty_value_removes_attribute
     */
    it('빈 값이면 style 속성을 지운다', () => {
        const { converter } = makeDomConverter();
        install(converter);
        const p = document.createElement('p');
        p.style.cssText = 'text-align: center;';

        converter.setDomElementAttribute(p, 'style', '', {});

        expect(p.hasAttribute('style')).toBe(false);
        expect(p.style.length).toBe(0);
    });

    /**
     * @scenario case=related_element_missing
     * @effects style_applied_via_cssom
     */
    it('뷰 요소 없이 불려도(직접 그리는 HTML 조각) 예외 없이 입힌다', () => {
        const { converter } = makeDomConverter();
        install(converter);
        const div = document.createElement('div');

        expect(() => converter.setDomElementAttribute(div, 'style', 'width:50%;')).not.toThrow();
        expect(div.style.width).toBe('50%');
    });

    /**
     * @scenario case=converter_missing
     * @effects other_editor_build_untouched
     */
    it('변환기가 없거나 메서드가 없는 편집기에서는 아무것도 하지 않는다', () => {
        expect(() => EditingViewCssomStyle(undefined)).not.toThrow();
        expect(() => EditingViewCssomStyle({})).not.toThrow();
        expect(() => EditingViewCssomStyle({ editing: { view: {} } })).not.toThrow();

        const converter: any = { renderingMode: 'editing' };
        EditingViewCssomStyle({ editing: { view: { domConverter: converter } } });
        expect(converter.setDomElementAttribute).toBeUndefined();
    });

    /**
     * @scenario case=data_converter
     * @effects saved_html_converter_untouched
     */
    it('편집 화면 변환기만 덮어쓴다 — 같은 틀에서 나온 다른 변환기(저장용)는 그대로다', () => {
        class Converter {
            shouldRenderAttribute(): boolean {
                return true;
            }

            setDomElementAttribute(el: Element, key: string, value: string): void {
                el.setAttribute(key, value);
            }
        }
        const editing: any = new Converter();
        const data: any = new Converter();
        install(editing);
        const writes = spyStyleAttributeWrites();
        const p = document.createElement('p');

        data.setDomElementAttribute(p, 'style', 'text-align:center;');

        expect(Object.prototype.hasOwnProperty.call(editing, 'setDomElementAttribute')).toBe(true);
        expect(Object.prototype.hasOwnProperty.call(data, 'setDomElementAttribute')).toBe(false);
        expect(writes()).toEqual(['text-align:center;']);
        expect(p.getAttribute('style')).toBe('text-align:center;');
    });
});

describe('편집기 생성 — 편집 화면 서식 플러그인을 항상 싣는다', () => {
    let create: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        vi.spyOn(console, 'log').mockImplementation(() => {});
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
            asset: {
                plugin: (_id: string, p: string) => `/assets?file=${encodeURIComponent(p)}`,
                loadStylesheet: vi.fn(async () => {}),
                loadScript: vi.fn(async () => {
                    (window as any).CKEDITOR_VERSION = '43.3.1';
                    (window as any).CKEDITOR = { ClassicEditor: Object.assign(class {}, { create }) };
                }),
            },
        };
    });

    afterEach(async () => {
        await destroyEditorHandler({ params: { name: 'content' } }, {});
        delete (window as any).G7Core;
        delete (window as any).CKEDITOR;
        delete (window as any).CKEDITOR_VERSION;
        delete (window as any).__SirsoftCkeditor5;
        document.querySelectorAll('script, link, style').forEach((el) => el.remove());
    });

    /**
     * @scenario case=editor_create
     * @effects plugin_registered_on_every_editor
     */
    it('이미지 업로드를 끈 단일 편집기에도 extraPlugins 로 들어간다', async () => {
        const container = document.createElement('div');
        container.id = 'ckeditor5-content';
        document.body.appendChild(container);

        await initEditorHandler({ params: { name: 'content', imageUpload: false } }, {});

        expect(create).toHaveBeenCalledTimes(1);
        expect(create.mock.calls[0][1].extraPlugins).toContain(EditingViewCssomStyle);
    });

    /**
     * @scenario case=editor_create
     * @effects plugin_registered_on_every_editor
     */
    it('다국어 편집기도 만드는 편집기마다 들어간다', async () => {
        const container = document.createElement('div');
        container.id = 'ckeditor5-content';
        document.body.appendChild(container);

        await initEditorHandler({ params: { name: 'content', multilingual: true } }, {});

        expect(create).toHaveBeenCalled();
        for (const call of create.mock.calls) {
            expect(call[1].extraPlugins).toContain(EditingViewCssomStyle);
        }
    });
});
