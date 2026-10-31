/**
 * 레이아웃 조각 렌더 테스트 공용 도구
 *
 * 설치되는 조각 원본(JSON)의 노드를 그대로 엔진(createLayoutTest)에 태워 화면에 무엇이 보이는지 확인한다.
 * 기본 태그만 쓰는 조각이라 HTML 태그 그대로 그리는 최소 컴포넌트만 등록한다.
 */

import React from 'react';
// 코어 엔진은 저장소 루트 기준 상대 경로로 부른다(설정의 `@core` 별칭은 한 단계 모자라 풀리지 않는다).
import { ComponentRegistry } from '../../../../../../../resources/js/core/template-engine/ComponentRegistry';
import ko from '../../../lang/ko.json';

/** 조각이 쓰는 기본 컴포넌트 → 그릴 HTML 태그 */
const TAGS: Record<string, string> = {
    Div: 'div',
    Span: 'span',
    P: 'p',
    H3: 'h3',
    A: 'a',
    Button: 'button',
    Icon: 'i',
};

/**
 * 조각이 쓰는 기본 컴포넌트를 HTML 태그로 그리는 레지스트리를 준비합니다.
 *
 * @return 컴포넌트 레지스트리
 */
export function setupFragmentRegistry(): ComponentRegistry {
    const registry = ComponentRegistry.getInstance();
    const entries: Record<string, unknown> = {};
    for (const [name, tag] of Object.entries(TAGS)) {
        entries[name] = {
            component: ({ text, children, ...props }: Record<string, any>) => React.createElement(tag, props, children || text),
            metadata: { name, type: 'basic' },
        };
    }
    entries.Fragment = {
        component: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
        metadata: { name: 'Fragment', type: 'layout' },
    };
    (registry as any).registry = entries;

    return registry;
}

/**
 * 레지스트리를 비웁니다.
 */
export function resetFragmentRegistry(): void {
    (ComponentRegistry.getInstance() as any).registry = {};
}

/**
 * 표현식 안 `$t()` 는 앱 설정의 템플릿 ID 로 사전을 찾는다 — 테스트 유틸이 사전을 넣는 템플릿 ID 를 알려 준다.
 */
export function stubTemplateConfig(): void {
    (window as any).__templateApp = { getConfig: () => ({ templateId: 'test-template', locale: 'ko' }) };
}

/**
 * 템플릿 설정 흉내를 걷어냅니다.
 */
export function clearTemplateConfig(): void {
    delete (window as any).__templateApp;
}

/** 이 플러그인의 한국어 사전 (플러그인 이름공간 아래) */
export const PLUGIN_TRANSLATIONS = { 'sirsoft-pay_nicepayments': ko };

type Node = Record<string, any>;

/**
 * 레이아웃 트리에서 id 로 노드를 찾습니다.
 *
 * @param node 시작 노드
 * @param id 찾을 id
 * @return 찾은 노드(없으면 undefined)
 */
export function findNodeById(node: unknown, id: string): Node | undefined {
    if (!node || typeof node !== 'object') return undefined;
    if (Array.isArray(node)) {
        for (const item of node) {
            const found = findNodeById(item, id);
            if (found) return found;
        }

        return undefined;
    }
    const value = node as Node;
    if (value.id === id) return value;
    for (const child of Object.values(value)) {
        if (child && typeof child === 'object') {
            const found = findNodeById(child, id);
            if (found) return found;
        }
    }

    return undefined;
}

/**
 * fetch 로 나간 요청 가운데 주소에 needle 을 품은 것만 돌려줍니다.
 *
 * @param fetchMock 전역 fetch 목
 * @param needle 주소 조각
 * @return [주소, 요청 옵션] 목록
 */
export function callsTo(fetchMock: unknown, needle: string): Array<[string, RequestInit | undefined]> {
    const calls = ((fetchMock as { mock?: { calls?: unknown[][] } })?.mock?.calls ?? []) as Array<[unknown, RequestInit | undefined]>;

    return calls
        .map(([url, init]) => [String(url), init] as [string, RequestInit | undefined])
        .filter(([url]) => url.includes(needle));
}

/**
 * 요청 옵션의 헤더를 평범한 객체로 읽습니다.
 *
 * @param init 요청 옵션
 * @return 헤더 이름 → 값
 */
export function headersOf(init: RequestInit | undefined): Record<string, string> {
    const headers = init?.headers;
    if (!headers) return {};
    if (headers instanceof Headers) {
        const out: Record<string, string> = {};
        headers.forEach((value, key) => { out[key] = value; });

        return out;
    }
    if (Array.isArray(headers)) return Object.fromEntries(headers);

    return headers as Record<string, string>;
}
