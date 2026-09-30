// e2e:allow 느린 외부 PG 응답·라우트 전환 타이밍 의존으로 브라우저 E2E 재현 불가, Vitest 회귀(__tests__/orderPaymentDisplayInjectors.test.ts)로 검증
/**
 * 주문 화면 주입 폴링·조회 도우미
 *
 * 주문 상세 화면에 결제 정보를 덧붙이는 인젝터는 대상 DOM 이 그려질 때까지 짧은 간격으로
 * 재시도한다. 예전 setInterval 폴러는 틱이 앞선 비동기 주입(서버 조회)을 기다리지 않아, 첫 응답이
 * 올 때까지 400ms 마다 같은 조회가 새로 나갔고(서버는 매번 외부 PG API 까지 간다) 라우트가 바뀔
 * 때마다 폴러가 겹겹이 쌓였다. 여기서는
 *  - 한 틱이 끝난 뒤에야 다음 틱을 예약하고(직렬화),
 *  - 인젝터마다 폴러를 하나만 두어 같은 키면 새로 시작하지 않고 다른 키면 이전 폴러를 멈추며,
 *  - 서버 조회는 한 화면 진입(같은 경로)당 키별 1회만 보내고 결과(실패 포함)를 재사용한다.
 *  - 레이아웃 확장이 같은 주소를 데이터 소스로 받으면 그 값을 재사용해 조회를 보내지 않는다.
 */
import { getAuthToken, getGuestOrderToken } from './guestOrderToken';

/** 폴러 한 틱. true 면 종료, false 면 다음 틱. isActive() 가 false 면 이미 멈춘 폴러다. */
export type OrderScreenPollTick = (isActive: () => boolean) => Promise<boolean>;

export interface OrderScreenPoller {
    /** 같은 key 가 돌고 있으면 무시하고, 다른 key 가 돌고 있으면 멈춘 뒤 시작한다. */
    start(key: string, tick: OrderScreenPollTick): void;
    stop(): void;
}

interface PollRun {
    key: string;
    timer: ReturnType<typeof setTimeout> | null;
    stopped: boolean;
}

const pollers = new Set<OrderScreenPoller>();
const screenRequests = new Map<string, Promise<unknown>>();
let screenPath: string | null = null;

export function createOrderScreenPoller(intervalMs = 400, maxAttempts = 30): OrderScreenPoller {
    let current: PollRun | null = null;

    const poller: OrderScreenPoller = {
        start(key, tick) {
            if (current && current.key === key) return;
            poller.stop();

            const run: PollRun = { key, timer: null, stopped: false };
            current = run;
            let attempts = 0;
            const isActive = () => !run.stopped;

            const step = async (): Promise<void> => {
                run.timer = null;
                attempts++;

                let done: boolean;
                try {
                    done = await tick(isActive);
                } catch {
                    done = true;
                }

                if (run.stopped) return;
                if (done || attempts >= maxAttempts) {
                    run.stopped = true;
                    if (current === run) current = null;
                    return;
                }
                run.timer = setTimeout(() => void step(), intervalMs);
            };

            run.timer = setTimeout(() => void step(), intervalMs);
        },
        stop() {
            if (!current) return;
            current.stopped = true;
            if (current.timer !== null) clearTimeout(current.timer);
            current = null;
        },
    };

    pollers.add(poller);
    return poller;
}

/**
 * 화면 진입을 기록한다. 경로가 바뀌었으면 이전 화면의 조회 결과를 버린다.
 * 인젝터의 라우트 변경 처리 첫머리에서 부른다 — 주문을 떠났다 돌아오면 새로 조회해야 한다.
 */
export function enterOrderScreen(path: string = location.pathname): void {
    if (screenPath === path) return;
    screenPath = path;
    screenRequests.clear();
}

/**
 * 한 화면 진입당 key 별로 한 번만 요청하고, 진행 중이거나 끝난 결과(실패 포함)를 재사용한다.
 * 인증 토큰이 바뀌면(비회원 토큰이 늦게 준비되는 경우 등) 다른 요청으로 보고 한 번 더 보낸다.
 */
export function requestOncePerOrderScreen<T>(key: string, request: () => Promise<T>): Promise<T> {
    enterOrderScreen();

    const cacheKey = `${key}|${getAuthToken() ?? ''}|${getGuestOrderToken() ?? ''}`;
    const cached = screenRequests.get(cacheKey) as Promise<T> | undefined;
    if (cached) return cached;

    const pending = request();
    screenRequests.set(cacheKey, pending);
    return pending;
}

/**
 * 화면을 떠나기 직전의 레이아웃 데이터 소스 값. 라우트 전환 직후에는 엔진이 새 화면 값을 받기 전이라
 * 이전 화면 값이 그대로 남아 있으므로, 이 값과 같은 객체는 새 화면 값으로 쓰지 않는다.
 */
const leftDataSources = new Map<string, unknown>();

function readLayoutDataSource(dataSourceId: string): { available: boolean; value: unknown } {
    const dataSource = (window as unknown as { G7Core?: { dataSource?: { get?: (id: string) => unknown } } })
        .G7Core?.dataSource;
    if (typeof dataSource?.get !== 'function') return { available: false, value: undefined };
    try {
        return { available: true, value: dataSource.get(dataSourceId) };
    } catch {
        return { available: false, value: undefined };
    }
}

/**
 * 라우트가 바뀌는 순간(history.pushState·popstate 직후, 엔진이 새 화면을 불러오기 전) 동기로 부른다.
 */
export function rememberLeavingDataSources(dataSourceIds: string[]): void {
    for (const id of dataSourceIds) {
        leftDataSources.set(id, readLayoutDataSource(id).value);
    }
}

export type SharedDataSourceRead =
    | { state: 'ready'; value: unknown }
    | { state: 'waiting' }
    | { state: 'unavailable' };

/**
 * 같은 화면의 레이아웃 확장이 이미 같은 주소를 데이터 소스로 받고 있으면 그 값을 쓴다 — 인젝터가 한 번 더
 * 부르면 서버가 외부 PG API 를 두 번 조회한다. 엔진이 없으면 unavailable, 아직 새 값이 없으면 waiting.
 * 데이터 소스 조회가 실패해도 값이 비어 waiting 으로 보이므로, 호출부는 기다리는 한도를 둔다.
 */
export function readSharedDataSource(dataSourceId: string): SharedDataSourceRead {
    const { available, value } = readLayoutDataSource(dataSourceId);
    if (!available) return { state: 'unavailable' };
    if (value === undefined || value === null) return { state: 'waiting' };
    if (leftDataSources.has(dataSourceId) && leftDataSources.get(dataSourceId) === value) {
        return { state: 'waiting' };
    }
    return { state: 'ready', value };
}

/**
 * 화면의 주문 데이터 소스(`order`)가 아직 불러오는 중인지. 엔진은 불러오는 동안 키를 undefined 로 두고,
 * 실패하면 null 로 채운다. 불러오는 중에 「주문 정보 없음」으로 보고 조회를 보내면 다른 결제대행사 주문이나
 * 비회원 화면에도 회원 전용 주소를 부르게 된다(401·404).
 */
export function isOrderDataLoading(): boolean {
    try {
        const core = (window as unknown as { G7Core?: { getState?: () => { currentDataContext?: Record<string, unknown> } } })
            .G7Core;
        const ctx = core?.getState?.()?.currentDataContext;
        return !!ctx && 'order' in ctx && ctx.order === undefined;
    } catch {
        return false;
    }
}

export function resetOrderScreenPollingForTests(): void {
    pollers.forEach(poller => poller.stop());
    screenRequests.clear();
    leftDataSources.clear();
    screenPath = null;
}
