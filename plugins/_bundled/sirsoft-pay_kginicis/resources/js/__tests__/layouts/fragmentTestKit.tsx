/**
 * KG이니시스 화면 조각 렌더 테스트 공용 도구
 *
 * 설치되는 조각 JSON(resources/extensions/*.json)의 컴포넌트를 엔진(createLayoutTest)에 그대로 태운다.
 * 기본 컴포넌트만 HTML 태그로 등록하고, 번역은 ko 사전을 그대로 넣는다.
 */

import React from 'react';
// 코어 엔진은 저장소 루트 기준 상대 경로로 부른다(설정의 `@core` 별칭은 한 단계 모자라 풀리지 않는다).
import { createLayoutTest } from '../../../../../../../resources/js/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '../../../../../../../resources/js/core/template-engine/ComponentRegistry';
import ko from '../../../lang/ko.json';
import en from '../../../lang/en.json';

export type LayoutTest = ReturnType<typeof createLayoutTest>;

/** 조각이 쓰는 기본 컴포넌트 이름 → HTML 태그 */
const TAGS: Record<string, string> = {
    Div: 'div',
    Span: 'span',
    P: 'p',
    A: 'a',
    Button: 'button',
    Icon: 'i',
    H3: 'h3',
};

/**
 * 기본 컴포넌트를 HTML 태그로 등록한 레지스트리를 만든다.
 *
 * @returns 컴포넌트 레지스트리
 */
export function setupRegistry(): ComponentRegistry {
    const registry = ComponentRegistry.getInstance();
    const entries: Record<string, unknown> = {};
    for (const [name, tag] of Object.entries(TAGS)) {
        entries[name] = {
            component: ({ text, children, className, href, target, rel, type, disabled, onClick, id, 'data-testid': testId }: any) =>
                React.createElement(tag, { className, href, target, rel, type, disabled, onClick, id, 'data-testid': testId }, children ?? text),
            metadata: { name, type: 'basic' },
        };
    }
    entries.Fragment = { component: ({ children }: any) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } };
    (registry as any).registry = entries;

    return registry;
}

export interface RenderFragmentOptions {
    /** 초기 _local·_global */
    state?: { _local?: Record<string, unknown>; _global?: Record<string, unknown> };
    /** 영수증 조회 API(`/api/plugins/sirsoft-pay_kginicis/…`)가 돌려줄 data. 주면 그 응답을 등록한다 */
    receipt?: Record<string, unknown>;
    /** 화면 언어 (기본 ko) */
    locale?: 'ko' | 'en';
}

/**
 * 조각 컴포넌트를 렌더한다.
 *
 * @param components 조각 컴포넌트 목록
 * @param data 데이터 소스 값(order·orderData·kginicis_status 등)
 * @param options 초기 상태·API 응답·언어
 * @returns 렌더된 테스트 도구
 */
export async function renderFragment(
    components: unknown[],
    data: Record<string, unknown>,
    options: RenderFragmentOptions = {},
): Promise<LayoutTest> {
    const locale = options.locale ?? 'ko';
    // 표현식 안 `$t()` 는 앱 설정의 템플릿 ID·언어로 사전을 찾는다 — 테스트 유틸이 사전을 넣는 템플릿 ID 를 알려 준다.
    (window as any).__templateApp = { getConfig: () => ({ templateId: 'test-template', locale }) };
    const utils = createLayoutTest(
        { version: '1.0.0', layout_name: 'kginicis_fragment_test', components: components as any },
        {
            initialState: options.state ?? {},
            initialData: data,
            translations: { 'sirsoft-pay_kginicis': locale === 'en' ? en : ko },
            locale,
            componentRegistry: setupRegistry(),
        },
    );
    if (options.receipt !== undefined) {
        // 엔진 테스트 fetch 대역은 주소의 세 번째 조각으로 응답을 고른다 — 플러그인 API 는 플러그인 식별자다.
        utils.mockApi('sirsoft_pay_kginicis', { response: options.receipt });
    }
    await utils.render();

    return utils;
}

/**
 * 렌더 중 나간 영수증 조회 요청을 돌려준다.
 *
 * @returns [주소, 요청 옵션] 목록
 */
export function receiptRequests(): Array<[string, RequestInit | undefined]> {
    const calls = ((globalThis.fetch as any)?.mock?.calls ?? []) as Array<[unknown, RequestInit | undefined]>;

    return calls
        .map(([url, init]) => [String(url), init] as [string, RequestInit | undefined])
        .filter(([url]) => url.includes('/api/plugins/sirsoft-pay_kginicis/') && url.endsWith('/receipt'));
}

/**
 * 레이아웃 트리에서 조건에 맞는 노드를 모두 찾는다.
 *
 * @param node 시작 노드
 * @param match 판정 함수
 * @param out 결과 누적
 * @returns 찾은 노드 목록
 */
export function findAll(node: unknown, match: (n: Record<string, any>) => boolean, out: Record<string, any>[] = []): Record<string, any>[] {
    if (!node || typeof node !== 'object') {
        return out;
    }
    const value = node as Record<string, any>;
    if (!Array.isArray(value) && match(value)) {
        out.push(value);
    }
    for (const child of Object.values(value)) {
        findAll(child, match, out);
    }

    return out;
}

/**
 * 레이아웃 트리에서 id 로 노드를 찾는다.
 *
 * @param node 시작 노드
 * @param id 노드 id
 * @returns 노드(없으면 undefined)
 */
export function findById(node: unknown, id: string): Record<string, any> | undefined {
    return findAll(node, (n) => n.id === id)[0];
}
