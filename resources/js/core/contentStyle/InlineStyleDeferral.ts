/**
 * 본문 서식 지연 장치 — HTML 문자열의 `style` 속성을 「화면에 붙인 뒤 CSSOM 대입」으로 바꿔 입힌다 (#163).
 *
 * 엄격한 스타일 정책(`style-src 'self'; style-src-attr 'none'`)에서는 HTML 문자열에 든 `style="…"` 이
 * 적용되지 않는다. 문자열을 `DOMParser`·`<template>` 로 **파싱만 해도** 위반이 보고된다(Chrome 실측).
 * 반면 `el.style.cssText = …` 는 막히지 않는다. 그래서 다음 순서로 처리한다.
 *
 *  1. `defer(html)` — 정화 **전에** 태그 안의 `style=` 을 1회용 이름 `data-g7-style-{token}` 으로 바꾼다(파싱 위반 방지).
 *  2. `installHook(purify, config, token)` — DOMPurify 훅이 그 이름만 `data-g7-style` 로 올린다.
 *     정화 뒤에도 남은 `style` 속성(바꾸지 못한 경우)도 같은 속성으로 옮긴다.
 *  3. `apply(root)` — DOM 에 붙인 뒤 `[data-g7-style]` 마다 `el.style.cssText = 값` 을 대입하고 속성을 지운다.
 *
 * 보안 규칙
 *  - 원문에 이미 적혀 있던 `data-g7-style` 은 버린다(서식을 금지한 화면을 우회하지 못하게).
 *  - 정화 설정이 `style` 을 금지하면(`FORBID_ATTR` 에 포함, 또는 `ALLOWED_ATTR` 이 있는데 미포함) 올리지 않고 버린다.
 *  - 대입하는 문자열은 원문의 `style` 값 그대로다. 저장 데이터·서버 정화 로직은 바뀌지 않는다.
 *
 * 훅은 **전용 DOMPurify 인스턴스**에만 건다(전역 인스턴스에 걸면 다른 정화 호출로 샌다).
 *
 * @since engine-v1.91.0
 */

/** 지연된 서식을 싣는 속성 이름 */
export const ATTR = 'data-g7-style';

/** installHook 옵션 */
export interface InlineStyleHookOptions {
    /** 요소별 추가 허용 판정 — false 를 돌려주면 그 요소의 서식을 버린다 */
    allowStyle?: (node: Element) => boolean;
}

/**
 * DOMPurify 가 속성값에서 걸러 내는 모양(주석·RCDATA 요소를 닫는 글자열). 지연한 값은 DOMPurify 의 속성값 검사를
 * 거치지 않으므로(1회용 이름을 훅이 먼저 떼어 둔다) 같은 기준을 여기서 직접 적용한다.
 */
const UNSAFE_ATTRIBUTE_VALUE = /((--!?|])>)|<\/(style|title|textarea)/i;

const isSpace = (ch: string): boolean => ch === ' ' || ch === '\n' || ch === '\t' || ch === '\r' || ch === '\f';
const isAsciiLetter = (ch: string): boolean => (ch >= 'a' && ch <= 'z') || (ch >= 'A' && ch <= 'Z');

/**
 * 시작 태그 하나를 훑어 `style` 속성 이름의 위치를 찾습니다(따옴표 안의 `>` 는 태그 끝이 아니다).
 *
 * 정규식 대신 한 글자씩 읽는 스캐너다 — 닫히지 않은 `<` 가 이어지는 입력에서 정규식 역추적이 입력 길이의 제곱으로
 * 늘어 화면이 멈추는 것을 막는다. 입력 전체를 한 번만 지나간다.
 *
 * @param html 원문
 * @param start `<` 의 위치
 * @returns 태그 끝 다음 위치와 style 속성 이름 위치들. 태그가 닫히지 않으면 null
 */
function scanStartTag(html: string, start: number): { end: number; styleAt: number[] } | null {
    const length = html.length;
    let i = start + 1;
    // 태그 이름
    while (i < length && !isSpace(html[i]) && html[i] !== '/' && html[i] !== '>') i++;
    const styleAt: number[] = [];
    while (i < length) {
        const ch = html[i];
        if (ch === '>') return { end: i + 1, styleAt };
        if (isSpace(ch) || ch === '/') {
            i++;
            continue;
        }
        // 속성 이름
        const nameStart = i;
        while (i < length && !isSpace(html[i]) && html[i] !== '/' && html[i] !== '>' && html[i] !== '=') i++;
        if (i - nameStart === 5 && html.slice(nameStart, i).toLowerCase() === 'style') {
            styleAt.push(nameStart);
        }
        // 값
        let j = i;
        while (j < length && isSpace(html[j])) j++;
        if (j < length && html[j] === '=') {
            j++;
            while (j < length && isSpace(html[j])) j++;
            if (j < length && (html[j] === '"' || html[j] === "'")) {
                const close = html.indexOf(html[j], j + 1);
                if (close === -1) return null;
                i = close + 1;
            } else {
                while (j < length && !isSpace(html[j]) && html[j] !== '>') j++;
                i = j;
            }
        }
    }

    return null;
}

let tokenSeq = 0;

/**
 * 1회용 토큰을 만듭니다(소문자·숫자만 — 속성 이름에 쓴다).
 *
 * @returns 토큰
 */
function createToken(): string {
    tokenSeq = (tokenSeq + 1) % 1_000_000;
    let random = '';
    try {
        const bytes = new Uint8Array(8);
        crypto.getRandomValues(bytes);
        random = Array.from(bytes, (b) => b.toString(36)).join('');
    } catch {
        random = Math.random().toString(36).slice(2);
    }

    return `${random}${tokenSeq.toString(36)}`.toLowerCase().replace(/[^a-z0-9]/g, '');
}

/**
 * 정화 전에 태그 안의 `style=` 속성 이름을 1회용 이름으로 바꿉니다.
 *
 * @param html 원문 HTML
 * @returns 바꾼 HTML 과 토큰
 */
export function defer(html: string): { html: string; token: string } {
    const token = createToken();
    if (typeof html !== 'string' || html === '' || !/style/i.test(html)) {
        return { html, token };
    }
    const replacement = `${ATTR}-${token}`;
    const parts: string[] = [];
    let copied = 0;
    let at = html.indexOf('<');
    while (at !== -1) {
        if (at + 1 >= html.length || !isAsciiLetter(html[at + 1])) {
            at = html.indexOf('<', at + 1);
            continue;
        }
        const tag = scanStartTag(html, at);
        // 닫히지 않은 태그 — 브라우저도 나머지를 태그 안으로 읽는다. 더 훑지 않는다(남은 style 은 훅이 정화 뒤에 옮긴다)
        if (!tag) break;
        for (const nameAt of tag.styleAt) {
            parts.push(html.slice(copied, nameAt), replacement);
            copied = nameAt + 5;
        }
        at = html.indexOf('<', tag.end);
    }
    if (copied === 0) {
        return { html, token };
    }
    parts.push(html.slice(copied));

    return { html: parts.join(''), token };
}

/**
 * 정화 설정이 `style` 속성을 허용하는지 판정합니다.
 *
 * @param config DOMPurify 설정
 * @returns 허용하면 true
 */
function styleAllowed(config: Record<string, any> | undefined): boolean {
    const lower = (list: unknown): string[] => (Array.isArray(list) ? list.map((item) => String(item).toLowerCase()) : []);
    if (lower(config?.FORBID_ATTR).includes('style')) return false;
    if (Array.isArray(config?.ALLOWED_ATTR)) {
        return lower(config?.ALLOWED_ATTR).includes('style') || lower(config?.ADD_ATTR).includes('style');
    }

    return true;
}

/**
 * 전용 DOMPurify 인스턴스에 서식 지연 훅을 겁니다.
 *
 * @param purifyInstance 전용 DOMPurify 인스턴스
 * @param config 이 정화 호출에 쓸 설정(style 허용 여부 판정용)
 * @param token `defer()` 가 돌려준 토큰(없으면 정화 뒤 남은 `style` 속성만 옮긴다)
 * @param opts 옵션
 * @returns 반환값 없음
 */
export function installHook(purifyInstance: any, config?: Record<string, any>, token?: string, opts?: InlineStyleHookOptions): void {
    if (!purifyInstance || typeof purifyInstance.addHook !== 'function') return;

    const allowed = styleAllowed(config);
    const deferredName = token ? `${ATTR}-${token}` : null;
    const pending = new WeakMap<Element, string>();
    const permits = (node: Element): boolean => allowed && (opts?.allowStyle ? opts.allowStyle(node) !== false : true);

    purifyInstance.addHook('beforeSanitizeAttributes', (node: Element) => {
        if (!node || typeof node.hasAttribute !== 'function') return;
        // 원문에 이미 적혀 있던 지연 속성은 버린다 — 서식 금지 화면 우회 방지
        if (node.hasAttribute(ATTR)) node.removeAttribute(ATTR);
        if (deferredName && node.hasAttribute(deferredName)) {
            const value = node.getAttribute(deferredName) ?? '';
            node.removeAttribute(deferredName);
            // DOMPurify 가 속성값에서 걸러 내는 모양이면 버린다 — 지연한 값은 DOMPurify 의 속성값 검사를 거치지 않는다
            if (value.trim() !== '' && !UNSAFE_ATTRIBUTE_VALUE.test(value) && permits(node)) pending.set(node, value);
        }
    });

    purifyInstance.addHook('afterSanitizeAttributes', (node: Element) => {
        if (!node || typeof node.hasAttribute !== 'function') return;
        const parts: string[] = [];
        const deferred = pending.get(node);
        if (deferred !== undefined) {
            parts.push(deferred);
            pending.delete(node);
        }
        // 이름을 바꾸지 못하고 정화를 통과한 style 속성도 같은 속성으로 옮긴다
        if (node.hasAttribute('style')) {
            const value = node.getAttribute('style') ?? '';
            node.removeAttribute('style');
            if (value.trim() !== '' && permits(node)) parts.push(value);
        }
        if (parts.length > 0) node.setAttribute(ATTR, parts.join(';'));
    });
}

/**
 * 한 번에 처리 — 이름 바꾸기 → 훅 → 정화 → 훅 해제.
 *
 * @param purifyInstance 전용 DOMPurify 인스턴스(`DOMPurify(window)`)
 * @param html 원문 HTML
 * @param config DOMPurify 설정
 * @returns 정화 결과(`style` 이 `data-g7-style` 로 옮겨진 HTML). 문자열이 아닌 반환 설정이면 DOMPurify 반환값 그대로
 */
export function sanitize(purifyInstance: any, html: string, config?: Record<string, any>): any {
    if (!purifyInstance || typeof purifyInstance.sanitize !== 'function') return html;
    if (typeof purifyInstance.addHook !== 'function' || typeof purifyInstance.removeHook !== 'function') {
        return purifyInstance.sanitize(html, config);
    }
    const deferred = defer(html);
    installHook(purifyInstance, config, deferred.token);
    try {
        return purifyInstance.sanitize(deferred.html, config);
    } finally {
        purifyInstance.removeHook('beforeSanitizeAttributes');
        purifyInstance.removeHook('afterSanitizeAttributes');
    }
}

/**
 * DOM 에 붙은 뒤 지연된 서식을 CSSOM 대입으로 입힙니다.
 *
 * @param root 대상 루트(자신 포함). null 이면 아무것도 하지 않는다
 * @returns 적용한 요소 수
 */
export function apply(root: Element | DocumentFragment | null | undefined): number {
    if (!root || typeof (root as Element).querySelectorAll !== 'function') return 0;
    const targets: Element[] = [];
    if (typeof (root as Element).hasAttribute === 'function' && (root as Element).hasAttribute(ATTR)) {
        targets.push(root as Element);
    }
    targets.push(...Array.from((root as Element).querySelectorAll(`[${ATTR}]`)));

    let applied = 0;
    for (const el of targets) {
        const value = el.getAttribute(ATTR) ?? '';
        el.removeAttribute(ATTR);
        const style = (el as HTMLElement).style;
        if (value.trim() === '' || !style) continue;
        style.cssText = style.cssText ? `${style.cssText};${value}` : value;
        applied++;
    }

    return applied;
}

export const InlineStyleDeferral = { ATTR, defer, installHook, sanitize, apply };
