// audit:allow no-inline-style-in-html-output reason: 구형 브라우저·jsdom 폴백 한 곳(adoptedStyleSheets 미지원 환경)만 <style> 요소를 만든다
/**
 * 런타임 CSS 규칙 헬퍼 — `<style>` 요소 없이 동적 규칙을 문서에 붙인다 (#163).
 *
 * 엄격한 스타일 정책(`style-src 'self'; style-src-attr 'none'`, 또는 `style-src-elem 'self'`)에서는
 * 스크립트가 만든 `<style>` 요소가 적용되지 않는다. 생성형 스타일시트(`new CSSStyleSheet()` +
 * `document.adoptedStyleSheets`)는 CSSOM 대입이라 이 정책에 걸리지 않는다(Chrome 실측 위반 0건).
 *
 * - 1순위: 생성형 시트를 `document.adoptedStyleSheets` 에 더한다. 배열을 통째로 덮어쓰지 않는다(남이 넣은 시트 보존).
 * - 폴백: 기능이 없는 환경(구형 브라우저, jsdom)은 `<style id="{key}">`. 이 조합(구형 브라우저 + 강제 정책)에서는
 *   규칙이 적용되지 않는다(문서화된 한계).
 * - 표식 요소: adopted 모드에서도 `<meta id="{key}" data-g7-runtime-style="adopted">` 를 `<head>` 에 붙이고 제거 때 뗀다.
 *   기존 E2E·문서가 `<head>` 의 같은 id 요소 존재를 「덮개 생존 신호」로 쓰는 계약을 지킨다.
 * - 레지스트리는 `window.__g7RuntimeStyles`(Map) — 편집기·DevTools 번들에 이 모듈이 복제돼도 한 곳을 공유한다.
 *
 * @since engine-v1.91.0
 */

/** 규칙 묶음 핸들 */
export interface RuntimeStyleHandle {
    /** 규칙 묶음 키(표식 요소의 id) */
    key: string;
    /** 적용 방식 */
    mode: 'adopted' | 'element';
    /** 규칙 제거 */
    remove(): void;
}

/** setRules 옵션 */
export interface RuntimeStyleOptions {
    /** 표식 요소(adopted: meta, element: style)에 더할 속성 */
    markerAttrs?: Record<string, string>;
}

interface RuntimeStyleEntry {
    mode: 'adopted' | 'element';
    css: string;
    sheet: CSSStyleSheet | null;
    marker: HTMLElement | null;
}

const REGISTRY_KEY = '__g7RuntimeStyles';

/**
 * 공유 레지스트리를 돌려줍니다.
 *
 * @returns 키 → 항목 Map
 */
function registry(): Map<string, RuntimeStyleEntry> {
    const host = window as unknown as Record<string, unknown>;
    let map = host[REGISTRY_KEY] as Map<string, RuntimeStyleEntry> | undefined;
    if (!(map instanceof Map)) {
        map = new Map<string, RuntimeStyleEntry>();
        host[REGISTRY_KEY] = map;
    }

    return map;
}

/**
 * 생성형 스타일시트를 쓸 수 있는 환경인지 판정합니다.
 *
 * @returns 쓸 수 있으면 true
 */
function supportsAdopted(): boolean {
    try {
        return typeof CSSStyleSheet === 'function'
            && typeof (CSSStyleSheet.prototype as { replaceSync?: unknown }).replaceSync === 'function'
            && Array.isArray((document as { adoptedStyleSheets?: unknown }).adoptedStyleSheets);
    } catch {
        return false;
    }
}

/**
 * 항목 하나를 문서에서 떼어 냅니다.
 *
 * @param entry 레지스트리 항목
 * @returns 반환값 없음
 */
function detach(entry: RuntimeStyleEntry): void {
    if (entry.mode === 'adopted' && entry.sheet) {
        const sheet = entry.sheet;
        document.adoptedStyleSheets = document.adoptedStyleSheets.filter((item) => item !== sheet);
    }
    if (entry.marker?.parentNode) {
        entry.marker.parentNode.removeChild(entry.marker);
    }
}

/**
 * 표식 요소에 속성을 답니다.
 *
 * @param marker 표식 요소
 * @param key 규칙 묶음 키
 * @param mode 적용 방식
 * @param attrs 추가 속성
 * @returns 반환값 없음
 */
function mark(marker: HTMLElement, key: string, mode: 'adopted' | 'element', attrs?: Record<string, string>): void {
    marker.id = key;
    marker.setAttribute('data-g7-runtime-style', mode);
    for (const [name, value] of Object.entries(attrs ?? {})) {
        if (name.toLowerCase() === 'style') continue;
        marker.setAttribute(name, value);
    }
}

/**
 * 규칙 묶음을 문서에 붙입니다. 같은 key 는 교체합니다.
 *
 * @param key 규칙 묶음 키 — 표식 요소의 id 가 된다
 * @param css CSS 본문(문자열 또는 문자열 배열)
 * @param opts 옵션
 * @returns 핸들
 */
export function setRules(key: string, css: string | string[], opts?: RuntimeStyleOptions): RuntimeStyleHandle {
    const text = Array.isArray(css) ? css.join('') : css;
    const map = registry();
    const previous = map.get(key);
    if (previous) {
        detach(previous);
        map.delete(key);
    }

    let entry: RuntimeStyleEntry | null = null;
    if (supportsAdopted()) {
        try {
            const sheet = new CSSStyleSheet();
            sheet.replaceSync(text);
            document.adoptedStyleSheets = [...document.adoptedStyleSheets, sheet];
            const marker = document.createElement('meta');
            mark(marker, key, 'adopted', opts?.markerAttrs);
            document.head.appendChild(marker);
            entry = { mode: 'adopted', css: text, sheet, marker };
        } catch {
            entry = null;
        }
    }
    if (!entry) {
        const element = document.createElement('style');
        mark(element, key, 'element', opts?.markerAttrs);
        element.textContent = text;
        document.head.appendChild(element);
        entry = { mode: 'element', css: text, sheet: null, marker: element };
    }
    map.set(key, entry);

    return { key, mode: entry.mode, remove: () => remove(key) };
}

/**
 * 규칙 묶음을 제거합니다. 없는 key 는 조용히 지나갑니다.
 *
 * 레지스트리에 없는데 같은 id 의 요소가 `<head>` 에 남아 있으면(옛 번들이 넣은 `<style>` 등) 그것도 뗍니다.
 *
 * @param key 규칙 묶음 키
 * @returns 반환값 없음
 */
export function remove(key: string): void {
    const map = registry();
    const entry = map.get(key);
    if (entry) {
        detach(entry);
        map.delete(key);

        return;
    }
    const stray = document.getElementById(key);
    if (stray && (stray.tagName === 'STYLE' || stray.hasAttribute('data-g7-runtime-style'))) {
        stray.remove();
    }
}

/**
 * 규칙 묶음이 붙어 있는지 확인합니다.
 *
 * @param key 규칙 묶음 키
 * @returns 붙어 있으면 true
 */
export function has(key: string): boolean {
    return registry().has(key);
}

/**
 * 붙어 있는 규칙 묶음의 CSS 본문을 돌려줍니다.
 *
 * @param key 규칙 묶음 키
 * @returns CSS 본문 (없으면 null)
 */
export function getCssText(key: string): string | null {
    return registry().get(key)?.css ?? null;
}

/**
 * 규칙 묶음의 스타일시트 객체를 돌려줍니다(adopted 모드는 생성형 시트, element 모드는 `<style>` 의 시트).
 *
 * @param key 규칙 묶음 키
 * @returns 스타일시트 (없으면 null)
 */
export function getSheet(key: string): CSSStyleSheet | null {
    const entry = registry().get(key);
    if (!entry) return null;
    if (entry.sheet) return entry.sheet;

    return (entry.marker as HTMLStyleElement | null)?.sheet ?? null;
}

export const RuntimeStyleSheet = { setRules, remove, has, getCssText, getSheet };
