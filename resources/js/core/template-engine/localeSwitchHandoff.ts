/**
 * 언어 전환 인계 저장소
 *
 * 언어 전환은 엔진을 다시 띄우고 같은 화면을 새로 마운트한다(번역·CKEditor 처럼 생성 시점에 언어가
 * 정해지는 컴포넌트까지 새 언어로 다시 만들기 위해서다). 이때 `_local` 은 엔진이 이어 주지만,
 * 컴포넌트 안에만 있는 저장 전 상태(업로더가 고른 대기 파일 등 직렬화할 수 없는 값)는 사라진다.
 *
 * 이 저장소는 언어 전환이 진행되는 동안만 열린다.
 * - 전환 중 언마운트되는 컴포넌트가 `stash(key, value)` 로 값을 맡긴다 (전환 중이 아니면 무시)
 * - 새로 마운트되는 같은 컴포넌트가 `take(key)` 로 되찾는다 (한 번 꺼내면 지워진다)
 * - 다음 화면 이동이 시작되면 남은 값은 버린다 (다른 화면에 새지 않게)
 *
 * 키는 컴포넌트가 정한다. 같은 화면에서 인스턴스를 구분할 수 있게 경로와 컴포넌트 식별값을 함께 쓴다.
 *
 * @since engine-v1.79.0
 */

const STORE_KEY = '__g7LocaleSwitchHandoff';

interface HandoffStore {
    active: boolean;
    entries: Map<string, unknown>;
}

/**
 * 저장소를 반환합니다 (window 전역 — 코어 번들과 템플릿 번들이 같은 저장소를 본다).
 *
 * @returns 인계 저장소
 */
function getStore(): HandoffStore {
    const w = typeof window !== 'undefined' ? (window as any) : (globalThis as any);
    if (!w[STORE_KEY] || !(w[STORE_KEY].entries instanceof Map)) {
        w[STORE_KEY] = { active: false, entries: new Map<string, unknown>() };
    }

    return w[STORE_KEY];
}

/**
 * 언어 전환을 시작합니다 — 이후 언마운트되는 컴포넌트의 `stash` 를 받는다.
 */
export function beginLocaleSwitchHandoff(): void {
    const store = getStore();
    store.active = true;
    store.entries.clear();
}

/**
 * 맡기기를 닫습니다 — 이미 맡긴 값은 새 화면이 `take` 할 때까지 남는다.
 */
export function closeLocaleSwitchHandoff(): void {
    getStore().active = false;
}

/**
 * 남은 값을 모두 버립니다 (다음 화면 이동이 시작될 때).
 */
export function clearLocaleSwitchHandoff(): void {
    const store = getStore();
    store.active = false;
    store.entries.clear();
}

/**
 * 언어 전환으로 언마운트되는 중인지 반환합니다.
 *
 * @returns 전환 중이면 true
 */
export function isLocaleSwitching(): boolean {
    return getStore().active;
}

/**
 * 전환 중에 값을 맡깁니다. 전환 중이 아니면 아무것도 하지 않는다.
 *
 * @param key 컴포넌트가 정한 키
 * @param value 맡길 값
 * @returns 맡겼으면 true
 */
export function stashForLocaleSwitch(key: string, value: unknown): boolean {
    const store = getStore();
    if (!store.active || !key) {
        return false;
    }
    store.entries.set(key, value);

    return true;
}

/**
 * 맡긴 값을 되찾습니다. 한 번 꺼내면 지워진다.
 *
 * @param key 컴포넌트가 정한 키
 * @returns 맡긴 값 (없으면 undefined)
 */
export function takeLocaleSwitchHandoff<T = unknown>(key: string): T | undefined {
    const store = getStore();
    if (!key || !store.entries.has(key)) {
        return undefined;
    }
    const value = store.entries.get(key) as T;
    store.entries.delete(key);

    return value;
}
