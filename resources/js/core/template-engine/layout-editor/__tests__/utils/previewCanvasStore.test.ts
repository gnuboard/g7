/**
 * previewCanvasStore.test.ts — 캔버스 ↔ 호스트 격리 façade swap 회귀 방지
 *
 *  (S4'') / 위험요소 #30
 *
 * 본 테스트의 목적: PreviewCanvas 가 마운트되어 있는 동안 `window.__templateApp`
 * 과 `G7Core.dispatch`/`modal`/`toast` 가 격리 façade 로 swap 되어 캔버스 안
 * 인터랙션이 호스트 admin TemplateApp 의 globalState 를 건드리지 않는지 검증.
 * 언마운트 시 원본 복원 보장도 함께 검증.
 *
 * 검증 매트릭스:
 *  1. install — __templateApp swap, G7Core.dispatch/modal/toast wrap
 *  2. 격리 store 의 getGlobalState/setGlobalState 가 in-memory 에서 동작
 *  3. listener 가 setGlobalState 시 호출됨
 *  4. onChange 콜백이 setGlobalState 시 호출됨
 *  5. ActionDispatcher 위임 — G7Core.dispatch 가 격리 dispatcher 의 executeAction 호출
 *  6. modal/toast no-op — 호스트 modal/toast push 없음
 *  7. restore — install 전 원본으로 복원
 *  8. 데이터소스 격리 — getDataSource/setDataSource 인메모리
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { installPreviewCanvasStore } from '../../utils/previewCanvasStore';

// ActionDispatcher 의 최소 시뮬레이션 — 본 테스트는 인스턴스 메서드 호출
// 검증만 필요하므로 partial mock 사용
interface MockDispatcher {
    executeAction: ReturnType<typeof vi.fn>;
}

function makeDispatcher(): MockDispatcher {
    return {
        executeAction: vi.fn().mockResolvedValue({ success: true }),
    };
}

describe('installPreviewCanvasStore — install/restore 페어', () => {
    let originalTemplateApp: any;
    let originalDispatch: any;
    let originalModal: any;
    let originalToast: any;

    beforeEach(() => {
        // 호스트 admin 의 가짜 TemplateApp + G7Core 진입점 세팅
        const hostGlobalState: Record<string, any> = { host: true, _local: { hostLocal: 'A' } };
        originalTemplateApp = {
            getGlobalState: () => hostGlobalState,
            setGlobalState: vi.fn((updates: any) => {
                Object.assign(hostGlobalState, updates);
            }),
            getActionDispatcher: () => ({ executeAction: vi.fn() }),
            getLocale: () => 'ko',
        };
        originalDispatch = vi.fn();
        originalModal = { open: vi.fn(), close: vi.fn(), closeAll: vi.fn() };
        originalToast = { push: vi.fn(), dismiss: vi.fn() };

        (window as any).__templateApp = originalTemplateApp;
        (window as any).G7Core = {
            dispatch: originalDispatch,
            modal: originalModal,
            toast: originalToast,
        };
    });

    afterEach(() => {
        // 테스트 격리 — 호스트 전역 정리
        delete (window as any).__templateApp;
        delete (window as any).G7Core;
    });

    it('install 직후 __templateApp 이 격리 façade 로 swap 됨', () => {
        const dispatcher = makeDispatcher() as any;
        const handle = installPreviewCanvasStore({ actionDispatcher: dispatcher, locale: 'ko' });

        expect((window as any).__templateApp).not.toBe(originalTemplateApp);
        expect(typeof (window as any).__templateApp.getGlobalState).toBe('function');
        expect(typeof (window as any).__templateApp.setGlobalState).toBe('function');

        handle.restore();
    });

    it('install 직후 G7Core.dispatch/modal/toast 가 wrap 됨', () => {
        const dispatcher = makeDispatcher() as any;
        const handle = installPreviewCanvasStore({ actionDispatcher: dispatcher, locale: 'ko' });

        const G7Core = (window as any).G7Core;
        expect(G7Core.dispatch).not.toBe(originalDispatch);
        expect(G7Core.modal.open).not.toBe(originalModal.open);
        expect(G7Core.toast.push).not.toBe(originalToast.push);

        handle.restore();
    });

    it('restore 시 __templateApp 과 G7Core 진입점이 원본으로 복원됨', () => {
        const dispatcher = makeDispatcher() as any;
        const handle = installPreviewCanvasStore({ actionDispatcher: dispatcher, locale: 'ko' });

        handle.restore();

        expect((window as any).__templateApp).toBe(originalTemplateApp);
        const G7Core = (window as any).G7Core;
        expect(G7Core.dispatch).toBe(originalDispatch);
        expect(G7Core.modal).toBe(originalModal);
        expect(G7Core.toast).toBe(originalToast);
    });

    it('restore 후 호스트 setGlobalState 는 영향 받지 않음 (이전 swap 격리)', () => {
        const dispatcher = makeDispatcher() as any;
        const handle = installPreviewCanvasStore({ actionDispatcher: dispatcher, locale: 'ko' });
        handle.restore();

        // 원본 호스트 TemplateApp 으로 다시 setGlobalState 호출 — 정상 동작 보장
        (window as any).__templateApp.setGlobalState({ afterRestore: true });
        expect(originalTemplateApp.setGlobalState).toHaveBeenCalledWith({ afterRestore: true });
    });
});

describe('installPreviewCanvasStore — 격리 store 동작', () => {
    beforeEach(() => {
        (window as any).__templateApp = { getGlobalState: () => ({}), setGlobalState: vi.fn() };
        (window as any).G7Core = { dispatch: vi.fn(), modal: { open: vi.fn() }, toast: { push: vi.fn() } };
    });

    afterEach(() => {
        delete (window as any).__templateApp;
        delete (window as any).G7Core;
    });

    it('initialGlobalState 가 격리 store 의 초기 globalState 로 주입됨', () => {
        const dispatcher = makeDispatcher() as any;
        const handle = installPreviewCanvasStore({
            actionDispatcher: dispatcher,
            locale: 'ko',
            initialGlobalState: { foo: 'bar', _local: { lk: 1 } },
        });

        const isolated = (window as any).__templateApp.getGlobalState();
        expect(isolated.foo).toBe('bar');
        expect(isolated._local).toEqual({ lk: 1 });

        handle.restore();
    });

    it('_local 미주입 시 빈 객체로 보강', () => {
        const dispatcher = makeDispatcher() as any;
        const handle = installPreviewCanvasStore({ actionDispatcher: dispatcher, locale: 'ko' });

        const isolated = (window as any).__templateApp.getGlobalState();
        expect(isolated._local).toEqual({});

        handle.restore();
    });

    it('격리 setGlobalState 객체 업데이트가 in-memory globalState 에 반영됨', () => {
        const dispatcher = makeDispatcher() as any;
        const handle = installPreviewCanvasStore({ actionDispatcher: dispatcher, locale: 'ko' });

        const isolated = (window as any).__templateApp;
        isolated.setGlobalState({ counter: 1 });
        expect(isolated.getGlobalState().counter).toBe(1);

        isolated.setGlobalState({ counter: 2, other: 'X' });
        expect(isolated.getGlobalState().counter).toBe(2);
        expect(isolated.getGlobalState().other).toBe('X');

        handle.restore();
    });

    it('격리 setGlobalState 함수형 업데이트가 prev 상태를 받아 적용됨', () => {
        const dispatcher = makeDispatcher() as any;
        const handle = installPreviewCanvasStore({
            actionDispatcher: dispatcher,
            locale: 'ko',
            initialGlobalState: { n: 10 },
        });

        const isolated = (window as any).__templateApp;
        isolated.setGlobalState((prev: any) => ({ ...prev, n: prev.n + 5 }));
        expect(isolated.getGlobalState().n).toBe(15);

        handle.restore();
    });

    it('onGlobalStateChange listener 가 setGlobalState 시 호출됨', () => {
        const dispatcher = makeDispatcher() as any;
        const handle = installPreviewCanvasStore({ actionDispatcher: dispatcher, locale: 'ko' });

        const isolated = (window as any).__templateApp;
        const listener = vi.fn();
        const unsubscribe = isolated.onGlobalStateChange(listener);

        isolated.setGlobalState({ a: 1 });
        expect(listener).toHaveBeenCalled();

        // unsubscribe 후 추가 호출 없음
        listener.mockClear();
        unsubscribe();
        isolated.setGlobalState({ b: 2 });
        expect(listener).not.toHaveBeenCalled();

        handle.restore();
    });

    it('onChange 콜백이 setGlobalState 시 snapshot 과 함께 호출됨', () => {
        const dispatcher = makeDispatcher() as any;
        const onChange = vi.fn();
        const handle = installPreviewCanvasStore({
            actionDispatcher: dispatcher,
            locale: 'ko',
            initialGlobalState: { x: 1 },
            onChange,
        });

        (window as any).__templateApp.setGlobalState({ x: 2 });
        expect(onChange).toHaveBeenCalledTimes(1);
        const arg = onChange.mock.calls[0][0];
        expect(arg.globalState.x).toBe(2);
        expect(typeof arg.localState).toBe('object');

        handle.restore();
    });

    it('getActionDispatcher 는 install 옵션의 dispatcher 인스턴스를 반환', () => {
        const dispatcher = makeDispatcher() as any;
        const handle = installPreviewCanvasStore({ actionDispatcher: dispatcher, locale: 'ko' });

        const isolated = (window as any).__templateApp;
        expect(isolated.getActionDispatcher()).toBe(dispatcher);

        handle.restore();
    });

    it('getLocale 은 install 옵션의 locale 반환, changeLocale 은 no-op', () => {
        const dispatcher = makeDispatcher() as any;
        const handle = installPreviewCanvasStore({ actionDispatcher: dispatcher, locale: 'en' });

        const isolated = (window as any).__templateApp;
        expect(isolated.getLocale()).toBe('en');

        // changeLocale 호출 시 효과 없음 — 격리 locale 미변경
        isolated.changeLocale('ja');
        expect(isolated.getLocale()).toBe('en');

        handle.restore();
    });

    it('getRouter 의 navigate/replace/push 는 모두 no-op (호출해도 throw 없음)', () => {
        const dispatcher = makeDispatcher() as any;
        const handle = installPreviewCanvasStore({ actionDispatcher: dispatcher, locale: 'ko' });

        const router = (window as any).__templateApp.getRouter();
        expect(() => router.navigate('/somewhere')).not.toThrow();
        expect(() => router.replace('/x')).not.toThrow();
        expect(() => router.push('/y')).not.toThrow();

        handle.restore();
    });

    it('getDataSource/setDataSource — 격리 in-memory 저장', () => {
        const dispatcher = makeDispatcher() as any;
        const handle = installPreviewCanvasStore({ actionDispatcher: dispatcher, locale: 'ko' });

        const isolated = (window as any).__templateApp;
        expect(isolated.getDataSource('posts')).toBeNull();

        isolated.setDataSource('posts', { data: [1, 2, 3] });
        expect(isolated.getDataSource('posts')).toEqual({ data: [1, 2, 3] });

        handle.restore();
    });

    it('refetchDataSource 는 no-op (null 반환)', async () => {
        const dispatcher = makeDispatcher() as any;
        const handle = installPreviewCanvasStore({ actionDispatcher: dispatcher, locale: 'ko' });

        const result = await (window as any).__templateApp.refetchDataSource('posts');
        expect(result).toBeNull();

        handle.restore();
    });
});

describe('installPreviewCanvasStore — G7Core.dispatch 라우팅', () => {
    beforeEach(() => {
        (window as any).__templateApp = { getGlobalState: () => ({}), setGlobalState: vi.fn() };
        (window as any).G7Core = { dispatch: vi.fn(), modal: { open: vi.fn() }, toast: { push: vi.fn() } };
    });

    afterEach(() => {
        delete (window as any).__templateApp;
        delete (window as any).G7Core;
    });

    it('G7Core.dispatch 호출 시 격리 dispatcher 의 executeAction 호출됨', () => {
        const dispatcher = makeDispatcher() as any;
        const handle = installPreviewCanvasStore({ actionDispatcher: dispatcher, locale: 'ko' });

        const action = { handler: 'setState', target: '_local', params: { foo: 'bar' } };
        const context = { someCtx: true };

        (window as any).G7Core.dispatch(action, context);

        expect(dispatcher.executeAction).toHaveBeenCalledWith(action, context);

        handle.restore();
    });

    it('dispatcher 가 throw 해도 swap 된 dispatch 는 silent 처리 (undefined 반환)', () => {
        const dispatcher: any = {
            executeAction: vi.fn(() => {
                throw new Error('boom');
            }),
        };
        const handle = installPreviewCanvasStore({ actionDispatcher: dispatcher, locale: 'ko' });

        const result = (window as any).G7Core.dispatch({ handler: 'x' });
        expect(result).toBeUndefined();

        handle.restore();
    });
});

describe('installPreviewCanvasStore — modal/toast no-op', () => {
    let originalModalOpen: any;
    let originalToastPush: any;

    beforeEach(() => {
        originalModalOpen = vi.fn();
        originalToastPush = vi.fn();
        (window as any).__templateApp = { getGlobalState: () => ({}), setGlobalState: vi.fn() };
        (window as any).G7Core = {
            dispatch: vi.fn(),
            modal: { open: originalModalOpen, close: vi.fn(), closeAll: vi.fn() },
            toast: { push: originalToastPush, dismiss: vi.fn() },
        };
    });

    afterEach(() => {
        delete (window as any).__templateApp;
        delete (window as any).G7Core;
    });

    it('swap 된 modal.open 은 호스트 modal.open 호출하지 않음', () => {
        const dispatcher = makeDispatcher() as any;
        const handle = installPreviewCanvasStore({ actionDispatcher: dispatcher, locale: 'ko' });

        (window as any).G7Core.modal.open({ id: 'm1' });
        expect(originalModalOpen).not.toHaveBeenCalled();

        handle.restore();
    });

    it('swap 된 toast.push 는 호스트 toast.push 호출하지 않음', () => {
        const dispatcher = makeDispatcher() as any;
        const handle = installPreviewCanvasStore({ actionDispatcher: dispatcher, locale: 'ko' });

        (window as any).G7Core.toast.push({ type: 'info', message: 'x' });
        expect(originalToastPush).not.toHaveBeenCalled();

        handle.restore();
    });
});

describe('installPreviewCanvasStore — window 미존재 환경 안전', () => {
    it('window 가 정의되어 있어도 G7Core 부재 시 안전 동작', () => {
        const savedG7Core = (window as any).G7Core;
        delete (window as any).G7Core;
        (window as any).__templateApp = { getGlobalState: () => ({}), setGlobalState: vi.fn() };

        const dispatcher = makeDispatcher() as any;
        // G7Core 가 없어도 install 자체는 성공해야 한다 (throw 없음)
        expect(() =>
            installPreviewCanvasStore({ actionDispatcher: dispatcher, locale: 'ko' }),
        ).not.toThrow();

        // cleanup
        delete (window as any).__templateApp;
        if (savedG7Core !== undefined) (window as any).G7Core = savedG7Core;
    });
});
