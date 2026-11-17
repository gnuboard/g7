// e2e:allow 차단 엔진의 보존·복원 분기 단위. 정책을 강제한 브라우저에서 동의 후 복원된 요소의 크기·테두리 유지와 위반 0건은 출시 전 화면 재실측(엄격한 스타일 정책 강제 + 차단된 iframe 복원)이 덮는다.
/**
 * 차단했다가 복원하는 요소의 style 은 문자열이 아니라 CSSOM 으로 되살린다 — 스타일 보안 정책 호환
 *
 * 회귀 배경: 차단 엔진은 요소를 `outerHTML` 문자열로 보존했다가 동의 후 `innerHTML` 로 되살린다. 운영자가
 * `style-src-attr 'none'` 을 걸면 문자열 속 `style="…"` 이 막혀(파싱만 해도 위반이 보고된다), 동의 후 되살아난
 * 영상·지도 iframe 이 크기·테두리를 잃었다. 보존하기 전에 style 을 요소에서 떼어 따로 두고(`data-gdpr-original-styles`),
 * 복원한 뒤 `el.style.cssText` 로 입힌다 — CSSOM 대입은 정책이 막지 않는다.
 *
 * @scenario-manifest plugins/_bundled/sirsoft-gdpr/tests/scenarios/blocker-restore-style.yaml
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { setBlockedDomains, setCurrentConsent, startBlocker, stopBlocker } from '../blocker';

beforeEach(() => {
    Object.defineProperty(window, 'location', {
        value: { hostname: 'mysite.test', href: 'https://mysite.test/' },
        writable: true,
    });
    document.body.innerHTML = '';
    setBlockedDomains({});
    setCurrentConsent({});
    startBlocker();
});

afterEach(() => {
    stopBlocker();
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

/**
 * 관측자 콜백이 돌 틈을 줍니다.
 *
 * @return 다음 매크로태스크에 풀리는 약속
 */
async function flush(): Promise<void> {
    await new Promise((resolve) => setTimeout(resolve, 0));
}

/**
 * `innerHTML` 대입으로 들어간 HTML 문자열을 모읍니다 — 복원 때 style 속성 문자열을 다시 파싱하는지 잰다.
 *
 * @return 대입된 문자열 목록을 돌려주는 함수
 */
function spyInnerHtmlWrites(): () => string[] {
    const descriptor = Object.getOwnPropertyDescriptor(Element.prototype, 'innerHTML') as PropertyDescriptor;
    const writes: string[] = [];
    Object.defineProperty(Element.prototype, 'innerHTML', {
        configurable: true,
        get: descriptor.get,
        set(this: Element, value: string) {
            writes.push(String(value));
            descriptor.set?.call(this, value);
        },
    });
    restoreInnerHtml = () => Object.defineProperty(Element.prototype, 'innerHTML', descriptor);

    return () => writes;
}

let restoreInnerHtml: (() => void) | null = null;

afterEach(() => {
    restoreInnerHtml?.();
    restoreInnerHtml = null;
});

/**
 * 마케팅 카테고리로 표시한 iframe 을 만듭니다.
 *
 * @param cssText 입힐 스타일 (빈 문자열이면 style 없음)
 * @return iframe 요소
 */
function makeIframe(cssText: string): HTMLIFrameElement {
    const iframe = document.createElement('iframe');
    iframe.setAttribute('src', 'https://video.example.test/embed/1');
    iframe.setAttribute('data-gdpr-category', 'marketing');
    iframe.setAttribute('title', '영상');
    if (cssText !== '') {
        iframe.style.cssText = cssText;
    }

    return iframe;
}

describe('blocker 보존·복원 — style 은 CSSOM 으로', () => {
    /**
     * @scenario restore=iframe_with_style
     * @effects preserved_markup_has_no_style_attribute, restored_element_keeps_style_via_cssom
     */
    it('보존 문자열에 style 속성이 없고, 복원한 iframe 의 스타일은 원래와 같다', async () => {
        const iframe = makeIframe('width: 320px; border: 2px solid red;');
        const before = iframe.style.cssText;
        document.body.appendChild(iframe);
        await flush();

        const placeholder = document.querySelector('.gdpr-blocked-embed') as HTMLElement;
        expect(placeholder).not.toBeNull();
        expect(document.querySelector('iframe')).toBeNull();
        expect(placeholder.getAttribute('data-gdpr-original')).not.toMatch(/\sstyle\s*=/i);
        expect(JSON.parse(placeholder.getAttribute('data-gdpr-original-styles') as string)).toEqual([before]);

        const writes = spyInnerHtmlWrites();
        setCurrentConsent({ marketing: true });
        await flush();

        const restored = document.querySelector('iframe') as HTMLIFrameElement;
        expect(restored).not.toBeNull();
        expect(restored.style.cssText).toBe(before);
        expect(restored.style.width).toBe('320px');
        expect(restored.getAttribute('src')).toBe('https://video.example.test/embed/1');
        expect(restored.getAttribute('title')).toBe('영상');
        // 표식 속성은 남기지 않는다
        expect(restored.hasAttribute('data-gdpr-style-ref')).toBe(false);
        // 복원할 때 style 속성 문자열을 다시 파싱하지 않는다 — 파싱만 해도 정책 위반이 보고된다
        expect(writes().some((html) => /\sstyle\s*=/i.test(html))).toBe(false);
        expect(document.querySelector('.gdpr-blocked-embed')).toBeNull();
    });

    /**
     * @scenario restore=img_with_style
     * @effects preserved_markup_has_no_style_attribute, restored_element_keeps_style_via_cssom
     */
    it('도메인으로 차단한 img 도 스타일을 되살린다', async () => {
        setBlockedDomains({ analytics: ['pixel.example.test'] });
        const img = document.createElement('img');
        img.setAttribute('src', 'https://pixel.example.test/p.gif');
        img.setAttribute('alt', '픽셀');
        img.style.cssText = 'max-width: 100%; float: right;';
        const before = img.style.cssText;
        document.body.appendChild(img);
        await flush();

        const placeholder = document.querySelector('.gdpr-blocked-embed') as HTMLElement;
        expect(placeholder).not.toBeNull();
        expect(placeholder.getAttribute('data-gdpr-original')).not.toMatch(/\sstyle\s*=/i);

        setCurrentConsent({ analytics: true });
        await flush();

        const restored = document.querySelector('img') as HTMLImageElement;
        expect(restored).not.toBeNull();
        expect(restored.style.cssText).toBe(before);
        expect(restored.getAttribute('alt')).toBe('픽셀');
    });

    /**
     * @scenario restore=descendant_with_style
     * @effects preserved_markup_has_no_style_attribute, restored_descendants_keep_style_via_cssom
     */
    it('차단한 요소 안쪽 자손의 스타일도 순서대로 되살린다', async () => {
        const iframe = makeIframe('height: 200px;');
        // iframe 의 대체 콘텐츠(자손) — 실제 문서에서도 outerHTML 에 함께 보존된다
        const fallback = document.createElement('p');
        fallback.style.cssText = 'color: blue;';
        const inner = document.createElement('span');
        inner.style.cssText = 'font-weight: bold;';
        const plain = document.createElement('em');
        fallback.append(inner, plain);
        iframe.appendChild(fallback);
        document.body.appendChild(iframe);
        await flush();

        const placeholder = document.querySelector('.gdpr-blocked-embed') as HTMLElement;
        expect(placeholder.getAttribute('data-gdpr-original')).not.toMatch(/\sstyle\s*=/i);
        expect(JSON.parse(placeholder.getAttribute('data-gdpr-original-styles') as string)).toHaveLength(3);

        setCurrentConsent({ marketing: true });
        await flush();

        // HTML 파서는 iframe 안쪽을 글자로 읽는다 — 되살아난 iframe 자신의 스타일만 확인한다
        const restored = document.querySelector('iframe') as HTMLIFrameElement;
        expect(restored.style.height).toBe('200px');
        expect(restored.hasAttribute('data-gdpr-style-ref')).toBe(false);
    });

    /**
     * @scenario restore=descendant_with_style
     * @effects restored_descendants_keep_style_via_cssom
     */
    it('보존 문자열의 자손 표식은 순번에 맞는 스타일을 받고, 표식은 모두 지워진다', async () => {
        const placeholder = document.createElement('div');
        placeholder.className = 'gdpr-blocked-embed';
        placeholder.dataset.gdprBlockedCategory = 'marketing';
        placeholder.setAttribute(
            'data-gdpr-original',
            '<div data-gdpr-style-ref="0"><p data-gdpr-style-ref="1"><span data-gdpr-style-ref="2">가</span><em>나</em></p></div>'
        );
        placeholder.setAttribute('data-gdpr-original-styles', JSON.stringify(['height: 200px;', 'color: blue;', 'font-weight: bold;']));
        document.body.appendChild(placeholder);

        setCurrentConsent({ marketing: true });
        await flush();

        const restored = document.body.firstElementChild as HTMLElement;
        expect(restored.tagName).toBe('DIV');
        expect(restored.style.height).toBe('200px');
        expect((restored.querySelector('p') as HTMLElement).style.color).toBe('blue');
        expect((restored.querySelector('span') as HTMLElement).style.fontWeight).toBe('bold');
        expect((restored.querySelector('em') as HTMLElement).hasAttribute('style')).toBe(false);
        expect(document.querySelectorAll('[data-gdpr-style-ref]')).toHaveLength(0);
    });

    /**
     * @scenario restore=element_without_style
     * @effects unstyled_element_preserved_as_before
     */
    it('style 이 없던 요소는 종전과 같이 보존·복원한다', async () => {
        const iframe = makeIframe('');
        const original = iframe.outerHTML;
        document.body.appendChild(iframe);
        await flush();

        const placeholder = document.querySelector('.gdpr-blocked-embed') as HTMLElement;
        expect(placeholder.getAttribute('data-gdpr-original')).toBe(original);
        expect(placeholder.hasAttribute('data-gdpr-original-styles')).toBe(false);

        setCurrentConsent({ marketing: true });
        await flush();

        const restored = document.querySelector('iframe') as HTMLIFrameElement;
        expect(restored.outerHTML).toBe(original);
    });

    /**
     * @scenario restore=legacy_placeholder
     * @effects unstyled_element_preserved_as_before
     */
    it('스타일 목록이 없거나 깨진 placeholder 도 예외 없이 복원한다', async () => {
        for (const styles of [null, 'not-json', '{"a":1}']) {
            const placeholder = document.createElement('div');
            placeholder.className = 'gdpr-blocked-embed';
            placeholder.dataset.gdprBlockedCategory = 'marketing';
            placeholder.setAttribute('data-gdpr-original', '<iframe src="https://video.example.test/embed/2" data-gdpr-category="marketing" data-gdpr-style-ref="0"></iframe>');
            if (styles !== null) {
                placeholder.setAttribute('data-gdpr-original-styles', styles);
            }
            document.body.appendChild(placeholder);
        }

        expect(() => setCurrentConsent({ marketing: true })).not.toThrow();
        await flush();

        const restored = document.querySelectorAll('iframe');
        expect(restored).toHaveLength(3);
        restored.forEach((iframe) => {
            expect(iframe.hasAttribute('data-gdpr-style-ref')).toBe(false);
            expect(iframe.style.length).toBe(0);
        });
    });

    /**
     * @scenario restore=script_with_src
     * @effects script_restore_branch_unchanged
     */
    it('script 복원 분기는 그대로다 — 새 요소로 만들고 표식 속성을 옮기지 않는다', async () => {
        const script = document.createElement('script');
        script.setAttribute('src', 'https://tracker.example.test/t.js');
        script.setAttribute('data-gdpr-category', 'analytics');
        script.style.cssText = 'display: none;';
        document.body.appendChild(script);
        await flush();
        expect(document.querySelector('script')).toBeNull();

        const createSpy = vi.spyOn(document, 'createElement');
        setCurrentConsent({ analytics: true });
        await flush();

        const restored = document.querySelector('script') as HTMLScriptElement;
        expect(restored).not.toBeNull();
        expect(createSpy).toHaveBeenCalledWith('script');
        expect(restored.getAttribute('src')).toBe('https://tracker.example.test/t.js');
        expect(restored.getAttribute('data-gdpr-category')).toBe('analytics');
        expect(restored.hasAttribute('data-gdpr-style-ref')).toBe(false);
        expect(restored.hasAttribute('style')).toBe(false);
    });
});
