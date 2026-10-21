/**
 * @file A.locationSync.test.tsx
 * @description A(링크) — 쿼리만 바뀐 같은 화면에서 `mergeQuery` href 가 새 쿼리를 따라가는지 (#757 링크 단계 후속, 7.1.1 회귀)
 *
 * `mergeQuery` 링크는 렌더할 때 현재 주소의 쿼리를 href 에 합친다. 같은 화면에서 쿼리만 바꾸는 `navigate replace`
 * (`history.replaceState` 뒤 다시 그리기)에서는 링크 props 가 그대로라 엔진이 링크를 다시 그리지 않아, 가운데 클릭·
 * Ctrl+클릭·새 탭·주소 복사가 옛 쿼리로 갔다. 링크는 엔진 `G7Core.navigation.subscribeLocationSearch` 로 주소 교체
 * 알림(`notifyLocationReplaced`)을 구독해 다시 그린다.
 *
 * 엔진 함수는 실제 것을 주입한다(주소 합치기·구독·알림). 엔진이 링크를 다시 그리지 않는 조건은 비교 함수가 늘 같다고
 * 답하는 memo 부모(`Frozen`)로 세운다.
 *
 * @scenario-manifest tests/scenarios/link-component.yaml
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, screen, act, cleanup } from '@testing-library/react';
import { A } from '../../src/components/basic/A';
import {
    isPlainLeftClick as engineIsPlainLeftClick,
    mergeQueryIntoHref as engineMergeQueryIntoHref,
    notifyLocationReplaced,
    subscribeLocationSearch as engineSubscribeLocationSearch,
} from '@core/template-engine/navigationLinks';

/** props 가 같으면 다시 그리지 않는 부모 — 엔진 DynamicRenderer memo 와 같은 조건 */
const Frozen = React.memo(({ children }: { children: React.ReactNode }) => <>{children}</>, () => true);

/** 엔진 updateQueryParams 와 같은 순서 — replaceState 로 주소를 바꾸고 엔진이 알린다 */
const replaceQuery = (path: string) => {
    act(() => {
        window.history.replaceState(null, '', path);
        notifyLocationReplaced();
    });
};

describe('A (gnuboard7-hello_admin_template) — 같은 화면 쿼리 교체 뒤 mergeQuery href', () => {
    let subscribeSpy: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        subscribeSpy = vi.fn((cb: () => void) => engineSubscribeLocationSearch(cb));
        (window as any).G7Core = {
            dispatch: vi.fn(),
            navigation: {
                isPlainLeftClick: engineIsPlainLeftClick,
                mergeQueryIntoHref: engineMergeQueryIntoHref,
                subscribeLocationSearch: subscribeSpy,
            },
        };
        window.history.replaceState(null, '', '/admin/memos?page=2');
    });

    afterEach(() => {
        cleanup();
        delete (window as any).G7Core;
        window.history.replaceState(null, '', '/');
    });

    /**
     * @scenario click=query_replace
     * @effects merge_query_href_follows_query_replace
     */
    it('부모가 다시 그리지 않아도 주소 교체 알림·뒤로/앞으로(popstate) 뒤 href 가 새 쿼리다', () => {
        render(<Frozen><A href="/admin/memos/5" mergeQuery>메모</A></Frozen>);
        const link = () => screen.getByRole('link', { name: '메모' });
        expect(link().getAttribute('href')).toBe('/admin/memos/5?page=2');

        replaceQuery('/admin/memos?q=hello&page=3');
        expect(link().getAttribute('href')).toBe('/admin/memos/5?q=hello&page=3');

        act(() => {
            window.history.replaceState(null, '', '/admin/memos?page=1');
            window.dispatchEvent(new PopStateEvent('popstate'));
        });
        expect(link().getAttribute('href')).toBe('/admin/memos/5?page=1');
    });

    /**
     * @scenario click=query_replace
     * @effects merge_query_href_follows_query_replace
     */
    it('mergeQuery 가 없는 링크·끈 링크는 주소를 구독하지 않는다(일반 렌더 비용 그대로)', () => {
        render(
            <>
                <A href="/admin/memos">목록</A>
                <A href="/admin/memos/5" mergeQuery disabled>끈 링크</A>
            </>,
        );
        expect(subscribeSpy).not.toHaveBeenCalled();
    });

    /**
     * @scenario click=query_replace
     * @effects merge_query_href_follows_query_replace
     */
    it('구독 API 가 없는 옛 엔진에서도 그려지고, 뒤로/앞으로(popstate)에는 새 쿼리를 합친다', () => {
        delete (window as any).G7Core.navigation.subscribeLocationSearch;
        render(<Frozen><A href="/admin/memos/5" mergeQuery>메모</A></Frozen>);
        const link = () => screen.getByRole('link', { name: '메모' });
        expect(link().getAttribute('href')).toBe('/admin/memos/5?page=2');

        act(() => {
            window.history.replaceState(null, '', '/admin/memos?page=9');
            window.dispatchEvent(new PopStateEvent('popstate'));
        });
        expect(link().getAttribute('href')).toBe('/admin/memos/5?page=9');
    });
});
