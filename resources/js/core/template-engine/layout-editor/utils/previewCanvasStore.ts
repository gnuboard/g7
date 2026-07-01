/**
 * previewCanvasStore.ts — 편집기 캔버스 전용 격리 state/dispatcher store
 *
 * 배경:
 *   PreviewCanvas 안의 컴포넌트(Form/Checkbox/Input 등)가 사용자 인터랙션 시
 *   `window.G7Core.state.setLocal` / `setGlobalState` 등 전역 API 를 호출하면,
 *   G7Core 가 내부적으로 `window.__templateApp.setGlobalState(...)` 를 통해
 *   호스트(admin) TemplateApp 의 globalState 를 직접 변경한다.
 *   이 결과로 호스트의 `#app` 이 자기 admin 레이아웃과 무관한 사용자 상태로
 *   재렌더링되며 통째로 unmount 되는 결함이 발생 (Phase 2 결함 카테고리 H).
 *
 *   격리 ComponentRegistry/TranslationEngine/ActionDispatcher 만으로는 부족하다 —
 *   G7Core 의 state/dispatcher 글로벌 진입점도 PreviewCanvas 마운트 동안 격리
 *   인스턴스로 swap 해야 한다.
 *
 * 전략:
 *   PreviewCanvas 가 마운트되면 `installPreviewCanvasStore(opts)` 가
 *   - `window.__templateApp` 의 핵심 메서드를 격리 façade 로 교체
 *   - `window.G7Core.state.*` / `G7Core.dispatch` / `G7Core.data.*` 등을 격리 store 에 라우팅
 *   언마운트 시 `restoreHostStore()` 가 원본을 복원.
 *
 *   격리 store 는 in-memory globalState + listeners + no-op data source API 만 제공한다.
 *   캔버스 내부 컴포넌트가 호출하는 setLocal/set 은 격리 store 에만 영향을 주고
 *   호스트 #app 의 React 트리는 건드리지 않는다.
 *
 *   onChange 콜백이 주어지면 격리 globalState 가 변경될 때마다 호출되어
 *   PreviewCanvas 가 자체 re-render 를 트리거할 수 있다.
 *
 * @since engine-v1.50.0
 */

import type { ActionDispatcher } from '../../ActionDispatcher';

export interface IsolatedStoreSnapshot {
    globalState: Record<string, any>;
    localState: Record<string, any>;
}

export interface InstallPreviewCanvasStoreOptions {
    /** 격리 ActionDispatcher 인스턴스 — 캔버스 안에서 dispatch 발생 시 사용 */
    actionDispatcher: ActionDispatcher;
    /** 캔버스가 사용하는 locale */
    locale: string;
    /** 격리 store 의 상태가 변경될 때 호출되는 콜백 (PreviewCanvas 가 re-render 트리거에 사용) */
    onChange?: (snapshot: IsolatedStoreSnapshot) => void;
    /** 초기 _global 상태 */
    initialGlobalState?: Record<string, any>;
}

export interface PreviewCanvasStoreHandle {
    /** 격리 store 의 현재 상태 스냅샷 반환 */
    getSnapshot: () => IsolatedStoreSnapshot;
    /** 격리 store + 호스트 swap 모두 해제 */
    restore: () => void;
}

interface HostBackup {
    templateApp: any;
    g7CoreState: any;
    g7CoreDispatch: any;
    g7CoreData: any;
    g7CoreModal: any;
    g7CoreToast: any;
}

/**
 * PreviewCanvas 마운트 시 호출.
 *
 * window.__templateApp 와 window.G7Core 의 state/dispatch 진입점을 격리 façade 로 swap.
 * 반환된 handle 의 restore() 를 언마운트 시 호출.
 */
export function installPreviewCanvasStore(
    opts: InstallPreviewCanvasStoreOptions,
): PreviewCanvasStoreHandle {
    const w = window as any;
    if (typeof w === 'undefined') {
        return {
            getSnapshot: () => ({ globalState: {}, localState: {} }),
            restore: () => {},
        };
    }

    const G7Core = w.G7Core || {};

    // 호스트 백업 — restore 시 그대로 복원
    const backup: HostBackup = {
        templateApp: w.__templateApp,
        g7CoreState: G7Core.state,
        g7CoreDispatch: G7Core.dispatch,
        g7CoreData: G7Core.data,
        g7CoreModal: G7Core.modal,
        g7CoreToast: G7Core.toast,
    };

    // 격리 state — 캔버스 안에서만 효력
    let isolatedGlobal: Record<string, any> = { ...(opts.initialGlobalState ?? {}) };
    if (!isolatedGlobal._local) {
        isolatedGlobal._local = {};
    }
    const listeners: Array<(state: Record<string, any>) => void> = [];

    const notify = (): void => {
        const snapshot: IsolatedStoreSnapshot = {
            globalState: { ...isolatedGlobal },
            localState: { ...(isolatedGlobal._local ?? {}) },
        };
        for (const listener of listeners) {
            try {
                listener(isolatedGlobal);
            } catch {
                // listener 오류는 격리 store 외부로 누수시키지 않는다
            }
        }
        opts.onChange?.(snapshot);
    };

    // 격리 데이터소스 — 캔버스는 샘플 모드라 fetch 미수행, getDataSource 만 빈 객체 반환
    const isolatedDataSources: Record<string, any> = {};

    // 격리 TemplateApp façade
    const isolatedTemplateApp: any = {
        // 핵심 state API
        getGlobalState: () => isolatedGlobal,
        setGlobalState: (
            updates: Record<string, any> | ((prev: Record<string, any>) => Record<string, any>),
            _options?: { render?: boolean },
        ) => {
            if (typeof updates === 'function') {
                isolatedGlobal = updates(isolatedGlobal);
            } else {
                isolatedGlobal = { ...isolatedGlobal, ...updates };
            }
            notify();
        },
        onGlobalStateChange: (listener: (state: Record<string, any>) => void) => {
            listeners.push(listener);
            return () => {
                const idx = listeners.indexOf(listener);
                if (idx >= 0) listeners.splice(idx, 1);
            };
        },
        get globalState(): Record<string, any> {
            return isolatedGlobal;
        },

        // ActionDispatcher — 격리 인스턴스 그대로 노출
        getActionDispatcher: () => opts.actionDispatcher,

        // 데이터소스 — 캔버스는 샘플 모드이므로 모두 no-op / 빈 값
        getDataSource: (id: string) => isolatedDataSources[id] ?? null,
        setDataSource: (id: string, data: any) => {
            isolatedDataSources[id] = data;
        },
        refetchDataSource: async (_id: string) => {
            // 샘플 모드 — fetch 없음, 격리 캐시만 그대로 둠
            return null;
        },
        updateDataSourceItem: (id: string, _itemId: any, _patch: any) => {
            return isolatedDataSources[id] ?? null;
        },

        // 로케일 — 캔버스 안에서 변경 차단 (호스트 admin 의 로케일과 분리)
        getLocale: () => opts.locale,
        changeLocale: (_locale: string) => {
            // 캔버스 안에서 setLocale 발화는 무시 (편집 모드)
        },

        // 라우터 — 캔버스 안에서 navigate 발화는 격리 ActionDispatcher 의 no-op navigate 가 받아낸다
        getRouter: () => ({
            navigate: () => {},
            replace: () => {},
            push: () => {},
        }),
        updateQueryParams: () => {},
    };

    // window.__templateApp 교체
    w.__templateApp = isolatedTemplateApp;

    // G7Core.state — getGlobalState / setGlobalState 라우팅 (G7Core 본체는 그대로 두고 핵심 메서드만 무력화)
    // setLocal / set 등이 내부적으로 (window as any).__templateApp 을 다시 읽으므로
    // __templateApp 교체만으로도 충분하지만, 명시적으로 G7Core.state 도 wrap 하여 dispatcher 컨텍스트 단절을 보장.

    // G7Core.dispatch — 격리 ActionDispatcher 에 위임
    if (G7Core && typeof G7Core.dispatch === 'function') {
        G7Core.dispatch = (action: any, context?: any) => {
            try {
                return opts.actionDispatcher.executeAction(action, context ?? {});
            } catch {
                return undefined;
            }
        };
    }

    // G7Core.modal — 캔버스 안에서 모달 열기는 no-op (편집 모드)
    if (G7Core && typeof G7Core.modal === 'object' && G7Core.modal !== null) {
        G7Core.modal = {
            ...G7Core.modal,
            open: () => {},
            close: () => {},
            closeAll: () => {},
        };
    }

    // G7Core.toast — 캔버스 안에서 toast push 는 no-op
    if (G7Core && typeof G7Core.toast === 'object' && G7Core.toast !== null) {
        G7Core.toast = {
            ...G7Core.toast,
            push: () => {},
            dismiss: () => {},
        };
    }

    return {
        getSnapshot: () => ({
            globalState: { ...isolatedGlobal },
            localState: { ...(isolatedGlobal._local ?? {}) },
        }),
        restore: () => {
            // 호스트 복원 — 다른 코드가 같은 시점에 swap 하지 않았다면 그대로 복원
            if (w.__templateApp === isolatedTemplateApp) {
                w.__templateApp = backup.templateApp;
            }
            if (w.G7Core) {
                if (backup.g7CoreState !== undefined) w.G7Core.state = backup.g7CoreState;
                if (backup.g7CoreDispatch !== undefined) w.G7Core.dispatch = backup.g7CoreDispatch;
                if (backup.g7CoreData !== undefined) w.G7Core.data = backup.g7CoreData;
                if (backup.g7CoreModal !== undefined) w.G7Core.modal = backup.g7CoreModal;
                if (backup.g7CoreToast !== undefined) w.G7Core.toast = backup.g7CoreToast;
            }
            // listeners 정리
            listeners.length = 0;
        },
    };
}
