/**
 * 카테고리 선택 — 경로 표시가 categories 데이터소스 트리를 읽는다
 *
 * @description
 * 7.1.0 출시 점검 D3-2: `addCategoryToSelection` 이 코어에 없는 `G7Core.state.getDataSources()` 로 카테고리 트리를
 * 읽어 늘 빈 트리였다 — 수정 화면에서 기존 카테고리(경로 정보 없는 form.categories)를 이어 받을 때 「상위 > 하위」
 * 경로 대신 이름만 남았다. 코어 공개 API `G7Core.dataSource.get('categories')`(응답 본문) 로 읽는다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { addCategoryToSelectionHandler } from '../../handlers/categoryHandlers';

let mockLocalState: Record<string, any> = {};

const tree = [
    { id: 1, name: { ko: '의류' }, children: [{ id: 2, name: { ko: '상의' }, children: [] }] },
    { id: 3, name: { ko: '신발' }, children: [] },
];

const mockG7Core = {
    state: {
        getLocal: () => mockLocalState,
        setLocal: vi.fn((updates: Record<string, any>) => {
            mockLocalState = { ...mockLocalState, ...updates };
        }),
    },
    // 코어 dataSource.get 은 응답 본문을 돌려준다 — 레이아웃 `categories?.data?.data` 와 같은 모양
    dataSource: { get: vi.fn((id: string) => (id === 'categories' ? { data: { data: tree } } : undefined)) },
    locale: { current: () => 'ko', supported: () => ['ko', 'en'] },
    t: (key: string) => key,
    toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn() },
    createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
};

describe('카테고리 선택 — 데이터소스 트리로 기존 카테고리 경로 복원', () => {
    beforeEach(() => {
        (window as any).G7Core = mockG7Core;
        vi.clearAllMocks();
    });

    afterEach(() => {
        delete (window as any).G7Core;
    });

    /**
     * @scenario case=c01
     * @effects category_breadcrumb_reads_core_datasource
     */
    it('경로 정보 없는 기존 카테고리를 트리에서 「의류 > 상의」로 복원한다', () => {
        mockLocalState = {
            form: { category_ids: [2], categories: [{ id: 2, name: { ko: '상의' } }] },
            selectedCategoryInfos: [],
            ui: { selectedCategories: [{ id: 3, name: { ko: '신발' } }] },
        };

        addCategoryToSelectionHandler({ handler: 'addCategoryToSelection' } as any, {} as any);

        expect(mockG7Core.dataSource.get).toHaveBeenCalledWith('categories');
        expect(mockLocalState.selectedCategoryInfos).toEqual([
            { id: 2, breadcrumb: '의류 > 상의' },
            { id: 3, breadcrumb: '신발' },
        ]);
        expect(mockLocalState.form.category_ids).toEqual([2, 3]);
    });
});
