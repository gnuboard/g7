/**
 * noticeTemplate 저장 모달 핸들러 회귀 테스트 (§B-FAIL save_template)
 *
 * @description
 * 루트 modals 배열의 Modal 은 show prop 이 아니라 modalStack(openModal/closeModal) 으로
 * 여닫힌다. 또한 모달 입력은 _global.ui.saveTemplateData 를 SSoT 로 하며, 저장 핸들러는
 * 백엔드 계약(name/fields.*.{name,content}/is_active)에 맞춰 payload 를 구성한다.
 *
 * - saveAsNoticeTemplateHandler: 항목 존재만 검증 + openModal(modal_save_template)
 * - confirmSaveNoticeTemplateHandler: _global.ui.saveTemplateData 검증 → fields payload → closeModal
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';
import {
    saveAsNoticeTemplateHandler,
    confirmSaveNoticeTemplateHandler,
} from '../noticeHandlers';

interface MockOpts {
    localState?: Record<string, any>;
    globalState?: Record<string, any>;
    apiPost?: ReturnType<typeof vi.fn>;
}

const createMockSetup = (overrides?: MockOpts) => {
    const localState: Record<string, any> = overrides?.localState ? { ...overrides.localState } : {};
    const globalState: Record<string, any> = overrides?.globalState ? { ...overrides.globalState } : {};

    const apiPost = overrides?.apiPost ?? vi.fn(async () => ({ data: { id: 1 } }));

    const g7CoreMock: any = {
        state: {
            getLocal: vi.fn(() => localState),
            setLocal: vi.fn((updates: Record<string, any>) => {
                Object.assign(localState, updates);
            }),
            get: vi.fn(() => globalState),
            set: vi.fn((updates: Record<string, any>) => {
                // dot notation 단순 반영
                for (const [k, v] of Object.entries(updates)) {
                    if (k.includes('.')) {
                        const [a, b] = k.split('.');
                        globalState[a] = { ...(globalState[a] ?? {}), [b]: v };
                    } else {
                        globalState[k] = v;
                    }
                }
            }),
        },
        api: { post: apiPost },
        dispatch: vi.fn(),
        datasources: { refresh: vi.fn() },
        toast: { success: vi.fn(), warning: vi.fn(), error: vi.fn() },
        t: vi.fn((key: string) => key),
    };

    (window as any).G7Core = g7CoreMock;
    return { g7CoreMock, localState, globalState, apiPost };
};

afterEach(() => {
    delete (window as any).G7Core;
    vi.clearAllMocks();
});

describe('saveAsNoticeTemplateHandler — 모달 열기 (openModal)', () => {
    it('항목이 없으면 경고 토스트 + 모달을 열지 않는다', () => {
        const { g7CoreMock } = createMockSetup({
            localState: { form: { notice_items: [] } },
        });

        saveAsNoticeTemplateHandler({ handler: 'x', params: {} } as any, {} as any);

        expect(g7CoreMock.toast.warning).toHaveBeenCalled();
        expect(g7CoreMock.dispatch).not.toHaveBeenCalled();
    });

    it('항목이 있으면 이름 검증 없이 openModal(modal_save_template) 을 호출한다', () => {
        const { g7CoreMock } = createMockSetup({
            localState: {
                form: { notice_items: [{ key: 'f1', name: { ko: '항목' }, content: { ko: '내용' } }] },
            },
        });

        saveAsNoticeTemplateHandler({ handler: 'x', params: {} } as any, {} as any);

        expect(g7CoreMock.toast.warning).not.toHaveBeenCalled();
        expect(g7CoreMock.dispatch).toHaveBeenCalledWith(
            expect.objectContaining({ handler: 'openModal', target: 'modal_save_template' }),
        );
    });

    it('모달 입력 상태(_global.ui.saveTemplateData)를 초기화한다', () => {
        const { g7CoreMock, globalState } = createMockSetup({
            localState: {
                form: { notice_items: [{ key: 'f1', name: { ko: '항목' }, content: { ko: '내용' } }] },
            },
        });

        saveAsNoticeTemplateHandler({ handler: 'x', params: {} } as any, {} as any);

        expect(g7CoreMock.state.set).toHaveBeenCalled();
        expect(globalState.ui?.saveTemplateData).toBeDefined();
    });
});

describe('confirmSaveNoticeTemplateHandler — 저장 + closeModal', () => {
    const seedItems = [
        { key: 'f1', name: { ko: '제조사' }, content: { ko: 'ACME' } },
        { key: 'f2', name: { ko: '원산지' }, content: { ko: '국내' } },
    ];

    it('이름(_global.ui.saveTemplateData.name)이 비면 경고 + API 미호출', async () => {
        const { g7CoreMock } = createMockSetup({
            localState: { form: { notice_items: seedItems } },
            globalState: { ui: { saveTemplateData: { name: { ko: '' } } } },
        });

        await confirmSaveNoticeTemplateHandler({ handler: 'x', params: {} } as any, {} as any);

        expect(g7CoreMock.toast.warning).toHaveBeenCalled();
        expect(g7CoreMock.api.post).not.toHaveBeenCalled();
    });

    it('백엔드 계약(name/fields.*.{name,content}/is_active) 으로 POST 한다', async () => {
        const { apiPost } = createMockSetup({
            localState: { form: { notice_items: seedItems } },
            globalState: { ui: { saveTemplateData: { name: { ko: '기본고시' }, is_default: true } } },
        });

        await confirmSaveNoticeTemplateHandler({ handler: 'x', params: {} } as any, {} as any);

        expect(apiPost).toHaveBeenCalledTimes(1);
        const [, body] = apiPost.mock.calls[0];
        expect(body.name).toEqual({ ko: '기본고시' });
        expect(body.is_active).toBe(true);
        expect(Array.isArray(body.fields)).toBe(true);
        expect(body.fields).toHaveLength(2);
        expect(body.fields[0]).toEqual({ name: { ko: '제조사' }, content: { ko: 'ACME' } });
        // 백엔드 미지원 키는 보내지 않는다
        expect(body).not.toHaveProperty('items');
        expect(body).not.toHaveProperty('category_id');
        expect(body).not.toHaveProperty('is_default');
    });

    it('저장 성공 시 closeModal 을 호출하고 입력 상태를 초기화한다', async () => {
        const { g7CoreMock, globalState } = createMockSetup({
            localState: { form: { notice_items: seedItems } },
            globalState: { ui: { saveTemplateData: { name: { ko: '기본고시' } } } },
        });

        await confirmSaveNoticeTemplateHandler({ handler: 'x', params: {} } as any, {} as any);

        expect(g7CoreMock.dispatch).toHaveBeenCalledWith(
            expect.objectContaining({ handler: 'closeModal' }),
        );
        expect(g7CoreMock.toast.success).toHaveBeenCalled();
        // 입력 초기화 (빈 로케일 객체)
        expect(globalState.ui?.saveTemplateData?.name).toEqual({ ko: '', en: '' });
    });
});
