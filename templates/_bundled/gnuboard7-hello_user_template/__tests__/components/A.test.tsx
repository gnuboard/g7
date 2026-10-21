/**
 * @file A.test.tsx
 * @description A(링크) — 새 탭 열기·보통 클릭 SPA 이동·비활성·쿼리 병합 계약 (#757 WP-L L3)
 *
 * 보통 클릭 판정은 엔진 `G7Core.navigation.isPlainLeftClick` 하나만 쓴다(W9). 엔진이 아직 그 함수를
 * 싣지 않은 상태에서도 계약을 검증하려고 같은 이름의 함수를 주입한다.
 *
 * @scenario-manifest tests/scenarios/link-component.yaml
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { A } from '../../src/components/basic/A';

/** E1 계약(handoff E1-p2-api §1)과 같은 규칙 — 현재 요소가 a[href] 면 target·download 도 본다 */
const isPlainLeftClick = (e: any) => {
    if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return false;
    const el = e.currentTarget as HTMLAnchorElement | null;
    if (el && el.tagName === 'A') {
        const target = el.getAttribute('target');
        if ((target && target !== '_self') || el.hasAttribute('download')) return false;
    }
    return true;
};

describe('A (gnuboard7-hello_user_template) — 링크 계약', () => {
    let dispatch: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        dispatch = vi.fn();
        (window as any).G7Core = {
            dispatch,
            navigation: {
                isPlainLeftClick,
                mergeQueryIntoHref: (href: string) => `${href}?page=2&q=memo`,
            },
        };
    });

    afterEach(() => {
        delete (window as any).G7Core;
    });

    /**
     * @scenario click=plain
     * @effects plain_click_spa_navigate
     */
    it('액션 없는 내부 링크를 보통 클릭하면 기본 이동을 막고 SPA navigate 를 한 번 부른다', () => {
        render(<A href="/admin/memos">목록</A>);
        const link = screen.getByRole('link', { name: '목록' });
        const notPrevented = fireEvent.click(link, { button: 0 });
        expect(notPrevented).toBe(false);
        expect(dispatch).toHaveBeenCalledTimes(1);
        expect(dispatch).toHaveBeenCalledWith({ handler: 'navigate', params: { path: '/admin/memos' } });
    });

    /**
     * @scenario click=modifier
     * @effects modifier_click_left_to_browser
     */
    it.each([
        ['Ctrl', { ctrlKey: true }],
        ['Cmd', { metaKey: true }],
        ['Shift', { shiftKey: true }],
    ])('%s+클릭은 브라우저에 맡긴다(기본 동작 유지·SPA 이동 0)', (_name, mods) => {
        render(<A href="/admin/memos">목록</A>);
        const notPrevented = fireEvent.click(screen.getByRole('link', { name: '목록' }), { button: 0, ...mods });
        expect(notPrevented).toBe(true);
        expect(dispatch).not.toHaveBeenCalled();
    });

    /**
     * @scenario click=modifier
     * @effects modifier_click_left_to_browser
     */
    it('target="_blank" 링크는 보통 클릭도 브라우저에 맡긴다(새 창)', () => {
        render(<A href="/admin/memos" target="_blank">새 창</A>);
        const notPrevented = fireEvent.click(screen.getByRole('link', { name: '새 창' }), { button: 0 });
        expect(notPrevented).toBe(true);
        expect(dispatch).not.toHaveBeenCalled();
    });

    /**
     * @scenario click=external
     * @effects modifier_click_left_to_browser
     */
    it('외부 주소는 보통 클릭도 브라우저 기본 이동', () => {
        render(<A href="https://example.com/">외부</A>);
        const notPrevented = fireEvent.click(screen.getByRole('link', { name: '외부' }), { button: 0 });
        expect(notPrevented).toBe(true);
        expect(dispatch).not.toHaveBeenCalled();
    });

    /**
     * @scenario click=external
     * @effects modifier_click_left_to_browser
     */
    it('`/\\호스트` 주소는 브라우저가 다른 호스트로 읽으므로 내부 SPA 이동으로 다루지 않는다', () => {
        render(<A href={'/\\example.com/x'}>역슬래시</A>);
        fireEvent.click(screen.getByText('역슬래시'), { button: 0 });
        expect(dispatch).not.toHaveBeenCalled();
    });

    /**
     * @scenario click=layout_action
     * @effects layout_action_owns_click
     */
    it('레이아웃 클릭 액션(onClick)이 있으면 그것이 클릭을 맡고 A 는 SPA 이동을 하지 않는다', () => {
        const onClick = vi.fn();
        render(<A href="/admin/memos/create" onClick={onClick}>등록</A>);
        fireEvent.click(screen.getByRole('link', { name: '등록' }), { button: 0 });
        expect(onClick).toHaveBeenCalledTimes(1);
        expect(dispatch).not.toHaveBeenCalled();
    });

    /**
     * @scenario click=disabled
     * @effects disabled_link_has_no_href
     */
    it('disabled 면 href 가 없고 aria-disabled·tabIndex -1 이며 클릭이 막힌다', () => {
        render(<A href="/admin/memos/create" disabled>등록</A>);
        const el = screen.getByText('등록').closest('a') as HTMLAnchorElement;
        expect(el.hasAttribute('href')).toBe(false);
        expect(el.getAttribute('aria-disabled')).toBe('true');
        expect(el.getAttribute('tabindex')).toBe('-1');
        expect(fireEvent.click(el, { button: 0 })).toBe(false);
        expect(dispatch).not.toHaveBeenCalled();
    });

    /**
     * @scenario click=merge_query
     * @effects merge_query_href
     */
    it('mergeQuery 면 현재 쿼리를 합친 주소를 href 로 그린다(새 탭도 같은 주소)', () => {
        render(<A href="/admin/memos" mergeQuery>목록</A>);
        expect(screen.getByRole('link', { name: '목록' }).getAttribute('href')).toBe('/admin/memos?page=2&q=memo');
    });

    /**
     * @scenario click=no_engine
     * @effects modifier_click_left_to_browser
     */
    it('엔진 전역이 없으면 보통 클릭도 브라우저 기본 이동(오류 없음)', () => {
        delete (window as any).G7Core;
        render(<A href="/admin/memos">목록</A>);
        expect(fireEvent.click(screen.getByRole('link', { name: '목록' }), { button: 0 })).toBe(true);
    });
});

describe('A — 새 창 링크의 rel (#757)', () => {
    /**
     * @scenario click=new_window
     * @effects new_window_link_has_noopener
     */
    it('target="_blank" 에 rel 이 없으면 noopener noreferrer 를 붙인다', () => {
        render(<A href="/memos" target="_blank">새 창 링크</A>);
        const rel = (screen.getByText('새 창 링크').closest('a')?.getAttribute('rel') ?? '').split(/\s+/);
        expect(rel).toEqual(expect.arrayContaining(['noopener', 'noreferrer']));
    });

    /**
     * @scenario click=new_window
     * @effects new_window_link_has_noopener
     */
    it('이미 준 rel 값은 지키고 noopener noreferrer 를 합친다(중복 없이)', () => {
        render(<A href="https://example.com/" target="_blank" rel="nofollow noopener">외부 새 창</A>);
        const rel = (screen.getByText('외부 새 창').closest('a')?.getAttribute('rel') ?? '').split(/\s+/);
        expect(rel).toEqual(expect.arrayContaining(['nofollow', 'noopener', 'noreferrer']));
        expect(rel.filter((t) => t === 'noopener')).toHaveLength(1);
    });

    /**
     * @scenario click=new_window
     * @effects new_window_link_has_noopener
     */
    it('target 이 _blank 가 아니면 rel 을 붙이지 않는다', () => {
        render(<A href="/memos">같은 창 링크</A>);
        expect(screen.getByText('같은 창 링크').closest('a')?.getAttribute('rel')).toBeNull();
    });
});
