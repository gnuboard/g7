// @vitest-environment jsdom
import '@testing-library/jest-dom';

/**
 * @file admin-ecommerce-inquiry-settings-board-list-forbidden.test.tsx
 * @description 쇼핑몰 환경설정 「문의 게시판」 — 게시판 목록을 볼 권한이 없는 운영자가 열었을 때
 *
 * 쇼핑몰 설정 권한만 있고 게시판 조회 권한은 없는 운영자(쇼핑몰 관리자 역할)가 환경설정을 열면 이 확장의
 * 게시판 목록(`board_list_for_inquiry`)이 403 이 된다. 종전에는
 *  - 데이터소스에 오류 처리가 없어 엔진 기본 처리(오류 알림)로 「해당 권한이 없습니다.」 가 떴고,
 *  - 선택지가 안내 한 줄뿐이라 저장된 게시판(`inquiry.board_slug`) 대신 「게시판을 선택하세요」 가 보였으며,
 *  - 칸이 열려 있어 그 안내(빈 값)를 고르고 저장하면 저장된 문의 게시판이 지워졌다.
 *
 * 이 시험은 손으로 식을 따라가지 않는다 — 실제 DataSourceManager·ErrorHandlingResolver 로 403 의 처리기를 정하고,
 * 실제 렌더 도구(createLayoutTest)에 실물 확장 조각과 실물 쇼핑몰 설정 데이터소스·「저장」 요청 본문을 올려 그린다.
 *
 * @scenario-manifest tests/scenarios/admin-ecommerce-inquiry-settings-board-list-forbidden.yaml
 */
import React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, screen, waitFor } from '@testing-library/react';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { DataSourceManager } from '@core/template-engine/DataSourceManager';
import { getErrorHandlingResolver } from '@core/error';

import extension from '../../../extensions/admin-ecommerce-inquiry-settings.json';
import ecommerceSettings from '../../../../../sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_settings.json';

// ─── 실제 DataSourceManager 를 돌리기 위한 인증·API 클라이언트 모의 ───

const { apiGet } = vi.hoisted(() => ({ apiGet: vi.fn() }));
vi.mock('@core/auth/AuthManager', () => ({
    AuthManager: {
        getInstance: vi.fn(() => ({
            isAuthenticated: () => true,
            getToken: () => 'test-token',
        })),
    },
}));
vi.mock('@core/api/ApiClient', () => ({
    getApiClient: vi.fn(() => ({ get: apiGet, post: vi.fn(), getToken: () => 'test-token' })),
}));

type Node = Record<string, any>;

/**
 * 트리에서 조건에 맞는 첫 노드를 찾는다.
 *
 * @param x 탐색 대상
 * @param pred 조건
 * @returns 노드 또는 null
 */
function findNode(x: any, pred: (n: Node) => boolean): Node | null {
    if (Array.isArray(x)) {
        for (const y of x) {
            const r = findNode(y, pred);
            if (r) return r;
        }

        return null;
    }
    if (!x || typeof x !== 'object') return null;
    if (pred(x)) return x;
    for (const v of Object.values(x)) {
        const r = findNode(v, pred);
        if (r) return r;
    }

    return null;
}

const boardListSource = (extension as any).data_sources.find((s: any) => s.id === 'board_list_for_inquiry');
const settingsSource = (ecommerceSettings as any).data_sources.find((s: any) => s.id === 'settings');
const saveButton = findNode(ecommerceSettings, (n) => n.id === 'save_button')!;
/** 실물 「저장」 단추가 보내는 요청 — 본문 식은 레이아웃 그대로 쓴다(후속 처리만 뺀다) */
const saveApiCall = findNode(saveButton.actions, (n) => n.handler === 'apiCall')!;

const BOARDS = [
    { id: 7, slug: 'inquiry', name: '상품 문의', abilities: { can_update: true } },
    { id: 8, slug: 'qna', name: '묻고 답하기', abilities: { can_update: true } },
];

const SAVED_SETTINGS = {
    data: {
        basic_info: { shop_name: '테스트 상점' },
        inquiry: { board_slug: 'inquiry' },
    },
};

// ─── 렌더 시험용 컴포넌트 ───

const Passthrough = (tag: keyof React.JSX.IntrinsicElements) => {
    const Comp: React.FC<any> = ({ className, children, text, id, title, disabled, type, onClick }) =>
        React.createElement(tag, { className, id, title, disabled, type, onClick }, children ?? text);

    return Comp;
};

/** 실제 Select 처럼 options 를 받아 그린다 — 지금 값에 맞는 선택지가 없으면 화면에는 첫 선택지(안내)가 보인다 */
const TestSelect: React.FC<any> = ({ name, value, options, disabled, onChange }) => (
    <select data-testid="inquiry-board-select" name={name} value={value ?? ''} disabled={disabled} onChange={onChange}>
        {(options ?? []).map((o: any) => (
            <option key={String(o.value)} value={o.value}>{o.label}</option>
        ))}
    </select>
);

/** 덮어쓰기 전 레지스트리 원본 (afterEach 에서 되돌린다) */
let savedRegistry: unknown = null;

/**
 * 이 조각이 쓰는 컴포넌트만 등록한 레지스트리를 만든다.
 *
 * @returns 테스트 레지스트리
 */
function setupTestRegistry(): ComponentRegistry {
    const registry = ComponentRegistry.getInstance();
    savedRegistry ??= (registry as any).registry;
    (registry as any).registry = {
        Div: { component: Passthrough('div'), metadata: { name: 'Div', type: 'basic' } },
        P: { component: Passthrough('p'), metadata: { name: 'P', type: 'basic' } },
        Span: { component: Passthrough('span'), metadata: { name: 'Span', type: 'basic' } },
        Label: { component: Passthrough('label'), metadata: { name: 'Label', type: 'basic' } },
        A: { component: Passthrough('a'), metadata: { name: 'A', type: 'basic' } },
        Button: { component: Passthrough('button'), metadata: { name: 'Button', type: 'basic' } },
        Icon: { component: () => null, metadata: { name: 'Icon', type: 'basic' } },
        Select: { component: TestSelect, metadata: { name: 'Select', type: 'basic' } },
        Fragment: { component: Passthrough('div'), metadata: { name: 'Fragment', type: 'layout' } },
    };

    return registry;
}

const TRANSLATIONS = {
    'sirsoft-board': {
        admin: {
            ecommerce_settings: {
                inquiry: {
                    description: '상품 문의게시판으로 사용할 게시판을 지정할 수 있습니다.',
                    board_label: '문의 게시판',
                    board_placeholder: '게시판을 선택하세요',
                    board_list_unavailable: '게시판 목록을 볼 수 없어 문의 게시판을 바꿀 수 없습니다.',
                },
            },
        },
    },
};

/** 실물 쇼핑몰 설정 데이터소스 + 실물 확장 조각 + 실물 「저장」 요청을 한 화면에 올린 감싸개 */
const wrapperLayout = {
    version: '1.0.0',
    layout_name: 'test_ecommerce_settings_inquiry_board',
    data_sources: [settingsSource, ...(extension as any).data_sources],
    components: [
        {
            id: 'settings_content',
            type: 'basic',
            name: 'Div',
            dataKey: 'form',
            children: [
                ...(extension as any).components,
                {
                    id: 'test_save_button',
                    type: 'basic',
                    name: 'Button',
                    text: 'save',
                    actions: [
                        {
                            type: 'click',
                            handler: 'apiCall',
                            target: saveApiCall.target,
                            auth_required: true,
                            params: { method: saveApiCall.params.method, body: saveApiCall.params.body },
                        },
                    ],
                },
            ],
        },
    ],
};

/**
 * 「저장」 이 보낸 요청 본문을 모은다.
 *
 * @returns 저장 요청 본문 목록
 */
function savedBodies(): Array<Record<string, any>> {
    const calls = ((globalThis.fetch as any)?.mock?.calls ?? []) as Array<[string, RequestInit?]>;

    return calls
        .filter(([url, init]) => String(url).includes('/admin/settings') && String(init?.method ?? 'GET').toUpperCase() === 'PUT')
        .map(([, init]) => JSON.parse(String(init?.body ?? '{}')));
}

describe('문의 게시판 — 게시판 목록 403 의 처리기 (실제 DataSourceManager·ErrorHandlingResolver)', () => {
    const executor = vi.fn(async () => undefined);

    beforeEach(() => {
        vi.clearAllMocks();
        getErrorHandlingResolver().setTemplateConfig(null);
        getErrorHandlingResolver().setLayoutConfig(null);
        getErrorHandlingResolver().setActionExecutor(executor);
    });

    /**
     * @scenario board_list_response=403
     * @effects board_list_403_is_silent, board_list_failure_marks_list_unavailable
     */
    it('403 은 알림 없이 넘기고(suppress), 목록 자리는 「못 받음」 표시가 있는 빈 목록이 된다', async () => {
        apiGet.mockRejectedValueOnce({
            response: { status: 403, data: { message: '해당 권한이 없습니다.' }, statusText: 'Forbidden' },
            status: 403,
            message: 'Request failed with status code 403',
        });

        const manager = new DataSourceManager();
        const results = await manager.fetchDataSources([boardListSource], {}, new URLSearchParams('tab=basic_info'));

        const handlers = executor.mock.calls.map((c: any[]) => c[0]?.handler);
        expect(handlers).toEqual(['suppress']);
        expect(handlers).not.toContain('toast');
        expect(results.board_list_for_inquiry?.data?.load_failed).toBe(true);
        expect(results.board_list_for_inquiry?.data?.data).toEqual([]);
    });

    /**
     * @scenario board_list_response=500
     * @effects board_list_server_error_still_alerts, board_list_failure_marks_list_unavailable
     */
    it('권한 외 실패(500)는 종전대로 알린다 — 목록 자리는 같은 「못 받음」 표시', async () => {
        apiGet.mockRejectedValueOnce({
            response: { status: 500, data: { message: '서버 오류' }, statusText: 'Server Error' },
            status: 500,
            message: 'Request failed with status code 500',
        });

        const manager = new DataSourceManager();
        const results = await manager.fetchDataSources([boardListSource], {}, new URLSearchParams('tab=basic_info'));

        expect(executor.mock.calls.map((c: any[]) => c[0]?.handler)).toEqual(['toast']);
        expect(results.board_list_for_inquiry?.data?.load_failed).toBe(true);
    });
});

describe('문의 게시판 — 화면과 「저장」 본문 (실제 렌더 도구)', () => {
    let testUtils: ReturnType<typeof createLayoutTest>;

    beforeEach(() => {
        testUtils = createLayoutTest(wrapperLayout as any, {
            auth: { isAuthenticated: true, user: { id: 5, name: '쇼핑몰 관리자' }, authType: 'admin' },
            locale: 'ko',
            translations: TRANSLATIONS,
            initialState: { _global: { activeEcommerceSettingsTab: 'basic_info' } },
            componentRegistry: setupTestRegistry(),
        });
        testUtils.mockApi('settings', { response: SAVED_SETTINGS });
    });

    afterEach(() => {
        testUtils.cleanup();
        if (savedRegistry !== null) {
            (ComponentRegistry.getInstance() as any).registry = savedRegistry;
            savedRegistry = null;
        }
    });

    /**
     * @scenario board_list_response=403
     * @effects inquiry_select_locked_showing_saved_board, inquiry_list_unavailable_notice_shown
     */
    it('게시판 목록 403 — 칸은 잠기고 저장된 게시판을 보이며, 바꿀 수 없다는 안내가 나온다', async () => {
        testUtils.mockApiError('board_list_for_inquiry', 403, '해당 권한이 없습니다.');
        await testUtils.render();

        const select = screen.getByTestId('inquiry-board-select') as HTMLSelectElement;
        // 종전: 선택지가 안내 하나뿐이라 저장값 'inquiry' 에 맞는 선택지가 없어 「게시판을 선택하세요」 가 보이고 칸이 열려 있었다.
        expect(select).toBeDisabled();
        expect(Array.from(select.options).map((o) => o.value)).toContain('inquiry');
        expect(select.value).toBe('inquiry');
        expect(select.options[select.selectedIndex]?.textContent).not.toBe('게시판을 선택하세요');
        expect(screen.getByText('게시판 목록을 볼 수 없어 문의 게시판을 바꿀 수 없습니다.')).toBeInTheDocument();
        // 데이터소스 403 처리기는 알림을 내지 않는다
        expect(testUtils.getToasts()).toEqual([]);
    });

    /**
     * @scenario board_list_response=403
     * @effects inquiry_board_slug_kept_on_save
     */
    it('게시판 목록 403 — 다른 칸을 고쳐 저장해도 요청 본문의 문의 게시판은 저장값 그대로다', async () => {
        testUtils.mockApiError('board_list_for_inquiry', 403, '해당 권한이 없습니다.');
        await testUtils.render();

        testUtils.setState('form.basic_info.shop_name', '바뀐 상점', 'local');
        await testUtils.rerender();
        fireEvent.click(screen.getByText('save'));

        await waitFor(() => expect(savedBodies().length).toBe(1));
        const body = savedBodies()[0];
        expect(body._tab).toBe('basic_info');
        expect(body.basic_info.shop_name).toBe('바뀐 상점');
        expect(body.inquiry).toEqual({ board_slug: 'inquiry' });
    });

    /**
     * @scenario board_list_response=200
     * @effects inquiry_select_editable_with_board_list, inquiry_board_change_saved
     */
    it('게시판 목록을 받은 운영자 — 칸이 열려 있고 목록에서 고른 게시판이 저장 본문에 실린다', async () => {
        testUtils.mockApi('board_list_for_inquiry', { response: { data: { data: BOARDS } } });
        await testUtils.render();

        const select = screen.getByTestId('inquiry-board-select') as HTMLSelectElement;
        expect(select).not.toBeDisabled();
        expect(Array.from(select.options).map((o) => o.value)).toEqual(['', 'inquiry', 'qna']);
        expect(select.value).toBe('inquiry');
        expect(screen.queryByText('게시판 목록을 볼 수 없어 문의 게시판을 바꿀 수 없습니다.')).not.toBeInTheDocument();

        testUtils.setState('form.inquiry.board_slug', 'qna', 'local');
        await testUtils.rerender();
        fireEvent.click(screen.getByText('save'));

        await waitFor(() => expect(savedBodies().length).toBe(1));
        expect(savedBodies()[0].inquiry).toEqual({ board_slug: 'qna' });
    });
});
