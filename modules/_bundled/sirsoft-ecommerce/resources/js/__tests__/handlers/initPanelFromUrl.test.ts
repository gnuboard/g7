/**
 * 목록-편집 패널 화면 — 주소(`?id=…&mode=…`)로 직접 들어오면 그 항목을 골라 패널을 연다
 *
 * @description
 * 브랜드·카테고리·상품 공통정보·상품정보고시 화면은 왼쪽 목록 항목 이름이 링크다(#757 결정 2026-10-15) —
 * 가운데 클릭으로 연 새 탭·주소 직접 진입은 마운트 때 `init*FromUrl` 처리기가 쿼리로 항목을 골라야 패널이 열린다.
 * 공통정보·고시 처리기는 목록을 데이터소스 응답 껍데기(`ds.data` — 목록과 쪽 정보를 담은 객체)에서 찾아 3초를
 * 기다리다 포기했다(M1 실측: 새 탭·직접 진입에서 빈 패널). 화면 목록이 읽는 경로(`<id>?.data?.data`)와 같은
 * 모양의 데이터소스로 네 처리기를 같은 기준에 둔다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { initBrandFromUrlHandler } from '../../handlers/initBrandFromUrl';
import { initCategoryFromUrlHandler } from '../../handlers/initCategoryFromUrl';
import { initCommonInfoFromUrlHandler } from '../../handlers/initCommonInfoFromUrl';
import { initNoticeFromUrlHandler } from '../../handlers/initNoticeFromUrl';

/** 데이터소스 응답 본문 — 레이아웃 목록이 `<id>?.data?.data` 로 읽는 모양 그대로 */
const body = (items: any[]) => ({ data: { data: items, meta: { current_page: 1, last_page: 1 } } });

const CASES = [
    {
        screen: 'brand',
        handler: initBrandFromUrlHandler,
        dataSourceId: 'brands',
        idKey: 'selectedBrandId',
        itemKey: 'selectedBrand',
    },
    {
        screen: 'category',
        handler: initCategoryFromUrlHandler,
        dataSourceId: 'categories',
        idKey: 'selectedCategoryId',
        itemKey: 'selectedCategory',
    },
    {
        screen: 'common_info',
        handler: initCommonInfoFromUrlHandler,
        dataSourceId: 'commonInfos',
        idKey: 'selectedId',
        itemKey: 'selectedItem',
    },
    {
        screen: 'notice',
        handler: initNoticeFromUrlHandler,
        dataSourceId: 'templates',
        idKey: 'selectedTemplateId',
        itemKey: 'selectedTemplate',
    },
];

describe('목록-편집 패널 화면 — 주소 직접 진입(새 탭)으로 항목 패널 열기', () => {
    let dataSources: Record<string, any> = {};
    const set = vi.fn();

    beforeEach(() => {
        vi.useFakeTimers();
        set.mockClear();
        (window as any).G7Core = {
            state: { set, getDataSource: (id: string) => dataSources[id] },
            createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
        };
    });

    afterEach(() => {
        vi.useRealTimers();
        window.history.replaceState({}, '', '/');
        delete (window as any).G7Core;
    });

    for (const c of CASES) {
        // @scenario surface=admin_layout
        // @effects panel_item_links_open_item_on_direct_entry
        it(`${c.screen} — ?id=3&mode=view 로 들어오면 그 항목을 고르고 보기 패널을 연다`, async () => {
            window.history.replaceState({}, '', `/admin/ecommerce/x?search=a&id=3&mode=view`);
            dataSources = { [c.dataSourceId]: body([{ id: 1 }, { id: 3, name: { ko: '셋' } }]) };

            const run = c.handler({});
            await vi.runAllTimersAsync();
            await run;

            expect(set).toHaveBeenCalledTimes(1);
            const state = set.mock.calls[0][0];
            expect(state[c.idKey]).toBe(3);
            expect(state[c.itemKey]).toEqual({ id: 3, name: { ko: '셋' } });
            expect(state.panelMode).toBe('view');
        });

        // @scenario surface=admin_layout
        // @effects panel_item_links_open_item_on_direct_entry
        it(`${c.screen} — 목록이 늦게 도착해도 기다렸다가 고른다(mode=edit 는 편집 패널)`, async () => {
            window.history.replaceState({}, '', `/admin/ecommerce/x?id=3&mode=edit`);
            dataSources = {};

            const run = c.handler({});
            await vi.advanceTimersByTimeAsync(500);
            dataSources = { [c.dataSourceId]: body([{ id: 3 }]) };
            await vi.runAllTimersAsync();
            await run;

            expect(set).toHaveBeenCalledTimes(1);
            expect(set.mock.calls[0][0][c.idKey]).toBe(3);
            expect(set.mock.calls[0][0].panelMode).toBe('edit');
        });
    }
});
