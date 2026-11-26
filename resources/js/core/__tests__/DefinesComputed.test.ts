/**
 * Defines/Computed 기능 테스트
 *
 * 레이아웃 JSON 의 defines 와 computed 가 라우트 진입 때 화면 데이터 컨텍스트에 실리는지 본다.
 * 실제 TemplateApp 의 라우트 변경 처리(handleRouteChange)와 계산 메서드(calculateComputed)를 거친다.
 *
 * 종전에는 계산 로직의 사본을 시험 안에 두고, 「정적 상수」 「_defines 주입」 「LayoutData 인터페이스」 시험은
 * 시험이 만든 객체 리터럴이나 시험 안에서 다시 구현한 주입 로직만 단언했다. 원본이 깨져도 통과했으므로 실제 경로로 옮겼다.
 * (매니페스트 core-defines-computed-nu2.yaml)
 *
 * @since engine-v1.9.0
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

const { sharedActionDispatcher, renderTemplateMock } = vi.hoisted(() => ({
    sharedActionDispatcher: {
        setNavigate: vi.fn(),
        setGlobalState: vi.fn(),
        setDefaultContext: vi.fn(),
        setGlobalStateUpdater: vi.fn(),
        registerHandler: vi.fn(),
        setNamedActions: vi.fn(),
        setGlobalHeaders: vi.fn(),
        isPreviewMode: vi.fn(() => false),
        setPreviewMode: vi.fn(),
        customHandlers: new Map(),
    },
    renderTemplateMock: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('../template-engine', async () => {
    const actual = await vi.importActual<any>('../template-engine/DataSourceManager');
    return {
        initTemplateEngine: vi.fn().mockResolvedValue(undefined),
        renderTemplate: renderTemplateMock,
        destroyTemplate: vi.fn(),
        // TemplateApp 이 오류 화면·언어 전환 경로에서 부른다 — 대역에 없으면 import 단계에서 시험 전체가 깨진다
        releaseTemplateRoot: vi.fn(),
        updateTemplateData: vi.fn(),
        DataSourceManager: actual.DataSourceManager,
        getActionDispatcher: vi.fn().mockReturnValue(sharedActionDispatcher),
        getState: vi.fn().mockReturnValue({
            actionDispatcher: sharedActionDispatcher,
            reactRoot: null,
            currentLayoutJson: null,
            currentDataContext: { _global: {} },
            // startCacheEpoch: 화면 전환이 바인딩 캐시 새 세대를 연다 (engine-v1.95.0, BINDING_CACHE_EPOCH)
            bindingEngine: { invalidateCacheByKeys: vi.fn(), clearCache: vi.fn(), startCacheEpoch: vi.fn(() => 1), startRenderCycle: vi.fn() },
        }),
    };
});

vi.mock('../template-engine/TransitionManager', () => ({
    transitionManager: {
        setPending: vi.fn(),
        getIsPending: vi.fn(() => false),
        subscribe: vi.fn(() => vi.fn()),
        clearSubscribers: vi.fn(),
    },
}));

vi.mock('../routing/Router', () => ({
    Router: vi.fn(function (this: any) {
        this.loadRoutes = vi.fn().mockResolvedValue(undefined);
        this.on = vi.fn();
        this.navigateToCurrentPath = vi.fn();
        this.getRoutes = vi.fn().mockReturnValue([]);
    }),
}));

vi.mock('../template-engine/ComponentRegistry', () => {
    const mockInstance: any = {
        loadComponents: vi.fn().mockResolvedValue(undefined),
        getComponent: vi.fn().mockReturnValue(() => null),
        hasComponent: vi.fn().mockReturnValue(true),
        getMissingExtensionComponents: vi.fn().mockReturnValue([]),
        getInstance: vi.fn(),
    };
    mockInstance.getInstance.mockReturnValue(mockInstance);
    return { ComponentRegistry: { getInstance: vi.fn(() => mockInstance) } };
});

import { TemplateApp } from '../TemplateApp';
import { DataSourceManager } from '../template-engine/DataSourceManager';
import type { LayoutData } from '../template-engine/LayoutLoader';

/** 레이아웃의 defines·computed 만 시험마다 바꾼다 */
type DefinesComputedLayout = Pick<LayoutData, 'defines' | 'computed'>;

const ROUTE = { path: '/admin/currency', layout: 'currency_view', params: {}, query: {} as Record<string, string> };

let app: TemplateApp;

/**
 * 레이아웃 하나로 라우트에 진입하고, 화면에 넘긴 데이터 컨텍스트를 돌려준다.
 *
 * @param layout 레이아웃의 defines·computed
 * @param query 라우트 쿼리
 * @returns renderTemplate 에 넘긴 dataContext
 */
const enter = async (layout: DefinesComputedLayout, query: Record<string, string> = {}): Promise<Record<string, any>> => {
    const a = app as any;
    a.layoutLoader = {
        loadLayout: vi.fn().mockResolvedValue({
            layout_name: 'currency_view',
            components: [{ id: 'root', type: 'basic', name: 'Div' }],
            ...layout,
        }),
        clear: vi.fn(),
    };
    await a.handleRouteChange({ ...ROUTE, query });
    const calls = renderTemplateMock.mock.calls;
    return calls.length ? calls[calls.length - 1][0].dataContext : {};
};

/**
 * 라우트 진입 computed 계산(TemplateApp.calculateComputed)을 실제로 부른다.
 *
 * @param computed computed 정의
 * @param ctx 평가 컨텍스트
 * @returns 계산된 _computed
 */
const calc = (computed: Record<string, string>, ctx: Record<string, any> = {}): Record<string, any> =>
    (app as any).calculateComputed(computed, ctx);

/** {{}} 안의 식 하나를 라우트 진입 computed 경로로 평가한다 */
const evaluate = (expression: string, ctx: Record<string, any> = {}): any => calc({ value: `{{${expression}}}` }, ctx).value;

beforeEach(() => {
    document.body.innerHTML = '<div id="app"></div>';
    window.history.replaceState(null, '', ROUTE.path);
    (window as any).G7Core = {};
    renderTemplateMock.mockClear();
    vi.spyOn(DataSourceManager.prototype, 'fetchDataSourcesWithResults').mockResolvedValue([] as any);
    app = new TemplateApp({ templateId: 'sirsoft-admin_basic', templateType: 'admin', locale: 'ko' } as any);
});

afterEach(() => {
    vi.restoreAllMocks();
    delete (window as any).G7Core;
});

describe('Defines 기능', () => {
    describe('정적 상수 정의', () => {
        // @scenario area=defines_constants
        // @effects layout_defines_reach_screen_context
        it('간단한 문자열 매핑을 정의할 수 있어야 함', async () => {
            const ctx = await enter({ defines: { currencyToFlag: { KRW: 'kr', USD: 'us', JPY: 'jp', CNY: 'cn', EUR: 'eu' } } });

            expect(ctx._defines.currencyToFlag.KRW).toBe('kr');
            expect(ctx._defines.currencyToFlag.USD).toBe('us');
            expect(ctx._defines.currencyToFlag.JPY).toBe('jp');
        });

        // @scenario area=defines_constants
        // @effects layout_defines_reach_screen_context
        it('중첩된 객체를 정의할 수 있어야 함', async () => {
            const ctx = await enter({
                defines: {
                    statusColors: {
                        active: { bg: 'green', text: 'white' },
                        inactive: { bg: 'gray', text: 'black' },
                        pending: { bg: 'yellow', text: 'black' },
                    },
                },
            });

            expect(ctx._defines.statusColors.active.bg).toBe('green');
            expect(ctx._defines.statusColors.pending.text).toBe('black');
        });

        // @scenario area=defines_constants
        // @effects layout_defines_reach_screen_context
        it('배열을 정의할 수 있어야 함', async () => {
            const ctx = await enter({ defines: { supportedCurrencies: ['KRW', 'USD', 'JPY', 'CNY', 'EUR'] } });

            expect(ctx._defines.supportedCurrencies).toContain('KRW');
            expect(ctx._defines.supportedCurrencies.length).toBe(5);
        });
    });

    describe('dataContext에 _defines 주입', () => {
        // @scenario area=defines_injection
        // @effects layout_defines_reach_screen_context
        it('defines가 있으면 _defines로 주입되어야 함', async () => {
            const ctx = await enter({ defines: { currencyToFlag: { KRW: 'kr', USD: 'us' } } });

            expect(ctx._defines).toBeDefined();
            expect(ctx._defines.currencyToFlag.KRW).toBe('kr');
        });

        // @scenario area=defines_injection
        // @effects empty_defines_not_injected
        it('defines가 없으면 _defines는 undefined이어야 함', async () => {
            expect((await enter({ defines: {} }))._defines).toBeUndefined();
            expect((await enter({}))._defines).toBeUndefined();
        });
    });
});

describe('Computed 기능', () => {
    describe('표현식 평가', () => {
        // @scenario area=computed_expression
        // @effects route_entry_computed_evaluates_expressions
        it('단순 속성 접근을 평가할 수 있어야 함', () => {
            expect(evaluate('user.name', { user: { name: '홍길동', age: 30 } })).toBe('홍길동');
        });

        // @scenario area=computed_expression
        // @effects route_entry_computed_evaluates_expressions
        it('산술 연산을 평가할 수 있어야 함', () => {
            expect(evaluate('price * quantity', { price: 1000, quantity: 5 })).toBe(5000);
        });

        // @scenario area=computed_expression
        // @effects route_entry_computed_evaluates_expressions
        it('비교 연산을 평가할 수 있어야 함', () => {
            expect(evaluate("user.role === 'admin'", { user: { role: 'admin' } })).toBe(true);
        });

        // @scenario area=computed_expression
        // @effects route_entry_computed_evaluates_expressions
        it('삼항 연산자를 평가할 수 있어야 함', () => {
            expect(evaluate("isActive ? '활성' : '비활성'", { isActive: true })).toBe('활성');
        });

        // @scenario area=computed_expression
        // @effects route_entry_computed_evaluates_expressions
        it('nullish 연산자를 평가할 수 있어야 함', () => {
            expect(evaluate('value ?? defaultValue', { value: null, defaultValue: '기본값' })).toBe('기본값');
        });

        // @scenario area=computed_expression
        // @effects route_entry_computed_evaluates_expressions
        it('옵셔널 체이닝을 평가할 수 있어야 함', () => {
            expect(evaluate("user?.profile?.name ?? '없음'", { user: { profile: null } })).toBe('없음');
        });

        // @scenario area=computed_expression
        // @effects route_entry_computed_reads_defines
        it('_defines 값을 참조할 수 있어야 함', () => {
            const ctx = { _defines: { currencyToFlag: { KRW: 'kr', USD: 'us' } }, currency: 'KRW' };

            expect(evaluate('_defines.currencyToFlag[currency]', ctx)).toBe('kr');
        });
    });

    describe('calculateComputed 함수', () => {
        // @scenario area=computed_route_entry
        // @effects route_entry_computed_evaluates_expressions
        it('여러 computed 값을 계산할 수 있어야 함', () => {
            const result = calc(
                { fullName: "{{user.firstName + ' ' + user.lastName}}", isAdult: '{{user.age >= 18}}' },
                { user: { firstName: '길동', lastName: '홍', age: 25 } },
            );

            expect(result.fullName).toBe('길동 홍');
            expect(result.isAdult).toBe(true);
        });

        // @scenario area=computed_route_entry
        // @effects route_entry_computed_plain_string_kept
        it('{{}} 없는 순수 문자열은 그대로 사용해야 함', () => {
            expect(calc({ staticValue: '고정 문자열' }).staticValue).toBe('고정 문자열');
        });

        // @scenario area=computed_route_entry
        // @effects route_entry_computed_failure_is_undefined
        it('평가 실패 시 undefined를 반환해야 함', () => {
            const result = calc({ invalid: '{{nonExistent.property.deep}}', ok: '{{1 + 1}}' });

            expect(result.invalid).toBeUndefined();
            expect(result.ok).toBe(2);
        });

        // @scenario area=computed_route_entry
        // @effects route_entry_computed_reads_defines
        it('_defines를 참조하는 computed를 계산할 수 있어야 함', () => {
            const result = calc(
                { flagCode: '{{_defines.currencyToFlag[currentCurrency]}}' },
                { _defines: { currencyToFlag: { KRW: 'kr', USD: 'us' } }, currentCurrency: 'USD' },
            );

            expect(result.flagCode).toBe('us');
        });
    });

    describe('실제 사용 사례', () => {
        const currencyLayout: DefinesComputedLayout = {
            defines: { currencyToFlag: { KRW: 'kr', USD: 'us', JPY: 'jp', CNY: 'cn', EUR: 'eu' } },
            computed: { currentFlag: '{{_defines.currencyToFlag[query.code] ?? "un"}}' },
        };

        // @scenario area=real_cases
        // @effects route_entry_computed_reads_defines
        it('통화-국기 매핑을 처리할 수 있어야 함', async () => {
            const ctx = await enter(currencyLayout, { code: 'JPY' });

            expect(ctx._computed.currentFlag).toBe('jp');
        });

        // @scenario area=real_cases
        // @effects route_entry_computed_reads_defines
        it('알 수 없는 통화에 대해 기본값을 반환해야 함', async () => {
            const ctx = await enter(currencyLayout, { code: 'GBP' });

            expect(ctx._computed.currentFlag).toBe('un');
        });

        // @scenario area=real_cases
        // @effects route_entry_computed_reads_defines
        it('복잡한 조건부 computed를 처리할 수 있어야 함', async () => {
            const ctx = await enter(
                {
                    defines: {
                        statusLabels: { active: '활성', inactive: '비활성', pending: '대기중' },
                        statusColors: { active: 'text-green-600', inactive: 'text-gray-600', pending: 'text-yellow-600' },
                    },
                    computed: {
                        statusLabel: "{{_defines.statusLabels[query.status] ?? '알 수 없음'}}",
                        statusClass: "{{_defines.statusColors[query.status] ?? 'text-gray-400'}}",
                    },
                },
                { status: 'active' },
            );

            expect(ctx._computed.statusLabel).toBe('활성');
            expect(ctx._computed.statusClass).toBe('text-green-600');
        });
    });
});

describe('보안 회귀 — computed 표현식 샌드박스 탈출 차단(KVE-2026-1915)', () => {
    // @scenario area=security
    // @effects route_entry_computed_sandbox_escape_blocked
    it("''.constructor.constructor 로 함수를 생성하려 하면 평가가 막혀 undefined 여야 함", () => {
        expect(evaluate("''.constructor.constructor('return 1')()")).toBeUndefined();
    });

    // @scenario area=security
    // @effects route_entry_computed_sandbox_escape_blocked
    it('__proto__ 접근은 차단되어 undefined 여야 함', () => {
        expect(evaluate('({}).__proto__')).toBeUndefined();
    });

    // @scenario area=security
    // @effects route_entry_computed_sandbox_escape_blocked
    it('prototype 접근은 차단되어 undefined 여야 함', () => {
        expect(evaluate('[].constructor.prototype')).toBeUndefined();
    });

    // @scenario area=security
    // @effects route_entry_computed_sandbox_escape_blocked
    it('전역 Function/eval 참조는 차단되어 undefined 여야 함', () => {
        expect(evaluate("Function('return 1')()")).toBeUndefined();
        expect(evaluate("eval('1')")).toBeUndefined();
    });

    // @scenario area=security
    // @effects route_entry_computed_sandbox_escape_blocked
    it('calculateComputed 경로에서도 위험 토큰은 undefined 로 폴백되어야 함', () => {
        expect(calc({ pwn: "{{''.constructor.constructor('return 2')()}}" }).pwn).toBeUndefined();
    });
});

describe('LayoutLoader defines/computed 인터페이스', () => {
    // @scenario area=layout_contract
    // @effects layout_defines_reach_screen_context
    it('LayoutData 인터페이스에 defines 필드가 포함되어야 함', async () => {
        // 타입은 LayoutLoader 의 실제 LayoutData 를 쓴다 — 필드가 사라지면 tsc 가 이 시험을 막는다
        const layout: DefinesComputedLayout = {
            defines: { mapping: { a: 1, b: 2 } },
            computed: { result: '{{_defines.mapping.a + _defines.mapping.b}}' },
        };

        const ctx = await enter(layout);

        expect(ctx._defines).toEqual({ mapping: { a: 1, b: 2 } });
        expect(ctx._computed.result).toBe(3);
        expect(ctx._computedDefinitions).toEqual(layout.computed);
    });
});
