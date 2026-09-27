/**
 * 표현식 컨텍스트 DTO 변환기 (KVE-2026-2350)
 *
 * 액션 표현식 컨텍스트의 `$event`·`error`·`$args` 에는 원본 DOM 이벤트·Error·콜백 인자가 그대로
 * 들어갔다. 원본 이벤트는 `target.ownerDocument.defaultView` 로 Window 에 닿는 객체 그래프를
 * 품고 있어, 레이아웃 표현식이 `localStorage`·`fetch` 를 쓸 수 있었다.
 *
 * 여기서는 표현식이 실제로 읽는 값만 **plain 데이터**로 복사한다(스냅샷).
 * - DOM 이벤트 → 이벤트 스칼라 필드 + target/currentTarget 스냅샷
 * - DOM 요소 → value·checked·name·type·dataset·files·selectedOptions 등 스냅샷
 *   (form 이면 이름 있는 컨트롤을 `target.<name>.value` 로 읽을 수 있게 싣는다)
 * - Error → own 필드(response·status·errors·code …) + message·name (stack 제외)
 * - 그 외 객체 → 재귀 복사, 호스트 capability 객체는 null, 허용목록 밖 네이티브 함수는 null
 * - File/Blob 은 업로드 값으로 그대로 둔다(스크립트 실행 capability 가 없다)
 *
 * 호스트 판정·함수 허용목록은 평가기(SafeExpressionEvaluator)의 것을 그대로 쓴다 —
 * 두 층이 다른 판정을 쓰면 그 차집합이 우회로가 된다. 이 DTO 와 평가기 타입 경계는 이중 방어다.
 *
 * 필드 목록은 번들 레이아웃 전수 집계(2026-09-27, 2836 파일)에서 쓰이는 `$event.*` 경로를 모두 포함한다.
 *
 * @since engine-v1.73.0
 */

import { isUnsafeHostValue, isExpressionSafeFunction } from '../SafeExpressionEvaluator';

/** 재귀 복사 최대 깊이 (순환은 별도로 끊는다) */
const MAX_DEPTH = 32;

/** 복사하지 않는 키 (프로토타입 조작 키) */
const SKIPPED_KEYS = new Set(['__proto__', 'constructor', 'prototype']);

/** 이벤트에서 싣는 스칼라 필드 */
const EVENT_SCALAR_KEYS = [
    'key',
    'code',
    'keyCode',
    'which',
    'ctrlKey',
    'shiftKey',
    'altKey',
    'metaKey',
    'repeat',
    'isComposing',
    'button',
    'buttons',
    'clientX',
    'clientY',
    'pageX',
    'pageY',
    'offsetX',
    'offsetY',
    'screenX',
    'screenY',
    'deltaX',
    'deltaY',
    'deltaMode',
    'inputType',
    'data',
    'timeStamp',
    'defaultPrevented',
] as const;

/** 요소에서 싣는 스칼라 필드 */
const ELEMENT_SCALAR_KEYS = [
    'value',
    'name',
    'checked',
    'type',
    'tagName',
    'id',
    'disabled',
    'readOnly',
    'required',
    'multiple',
    'selectedIndex',
    'valueAsNumber',
    'indeterminate',
    'placeholder',
] as const;

/** scroll 이벤트에서만 싣는 레이아웃 필드 (읽기가 레이아웃 재계산을 유발하므로 한정) */
const SCROLL_KEYS = ['scrollHeight', 'scrollTop', 'clientHeight', 'scrollLeft', 'clientWidth', 'scrollWidth'] as const;

/**
 * 값이 DOM/React 이벤트인지 판정합니다.
 *
 * @param value 검사할 값
 * @returns 이벤트면 true
 */
function isEventObject(value: unknown): value is Event {
    if (value === null || typeof value !== 'object' || typeof Event === 'undefined') {
        return false;
    }

    if (value instanceof Event) {
        return true;
    }

    // React SyntheticEvent — nativeEvent 가 실제 DOM 이벤트
    const native = (value as { nativeEvent?: unknown }).nativeEvent;
    return native instanceof Event;
}

/**
 * 값이 DOM 요소인지 판정합니다.
 *
 * @param value 검사할 값
 * @returns 요소면 true
 */
function isElement(value: unknown): value is Element {
    return typeof Element !== 'undefined' && value instanceof Element;
}

/**
 * 원시 값인지 판정합니다 (함수·객체 제외).
 *
 * @param value 검사할 값
 * @returns 원시 값이면 true
 */
function isPrimitive(value: unknown): boolean {
    return value === null || (typeof value !== 'object' && typeof value !== 'function');
}

/**
 * DOM 요소를 표현식이 읽는 필드만 담은 스냅샷으로 복사합니다.
 *
 * @param element DOM 요소
 * @param eventType 이벤트 타입 (scroll 이면 스크롤 필드 포함)
 * @param includeFormControls form 이면 이름 있는 컨트롤을 싣는다 (컨트롤 자신은 false 로 재귀 차단)
 * @returns 요소 스냅샷
 */
function toElementData(element: Element, eventType?: string, includeFormControls = true): Record<string, unknown> {
    const el = element as unknown as Record<string, unknown>;
    const out: Record<string, unknown> = {};

    for (const key of ELEMENT_SCALAR_KEYS) {
        const value = el[key];
        if (value !== undefined && isPrimitive(value)) {
            out[key] = value;
        }
    }

    // 기존 계약: name/checked/type 은 없어도 기본값으로 싣는다 (extractEventData 와 동일)
    out.name = out.name ?? '';
    out.checked = out.checked ?? false;
    out.type = out.type ?? '';

    // data-* 속성
    const dataset = (element as HTMLElement).dataset;
    if (dataset) {
        out.dataset = { ...dataset };
    }

    // <input type=file> 선택 파일 (File 은 업로드 값으로 그대로 둔다)
    const files = (element as HTMLInputElement).files;
    if (files && typeof files.length === 'number') {
        out.files = Array.from(files);
    }

    // <select> 선택 항목 — `$event.target.selectedOptions[0]?.dataset?.url`
    const selectedOptions = (element as HTMLSelectElement).selectedOptions;
    if (selectedOptions && typeof selectedOptions.length === 'number') {
        out.selectedOptions = Array.from(selectedOptions).map(option => ({
            value: option.value,
            text: option.text,
            label: option.label,
            index: option.index,
            dataset: { ...option.dataset },
        }));
    }

    // scroll 이벤트: 스크롤 위치 (기존 extractEventData 계약)
    if (eventType === 'scroll') {
        for (const key of SCROLL_KEYS) {
            out[key] = (el[key] as number | undefined) ?? 0;
        }
    }

    // <form> submit: 이름 있는 컨트롤 — `$event.target.search.value`
    if (includeFormControls && typeof HTMLFormElement !== 'undefined' && element instanceof HTMLFormElement) {
        for (const control of Array.from(element.elements)) {
            const name = (control as HTMLInputElement).name;
            if (!name || SKIPPED_KEYS.has(name) || Object.prototype.hasOwnProperty.call(out, name)) {
                continue;
            }
            out[name] = toElementData(control, eventType, false);
        }
    }

    return out;
}

/**
 * 이벤트 target 값을 스냅샷으로 복사합니다.
 *
 * DOM 요소면 요소 스냅샷, 합성 컴포넌트가 만든 `{ name, value, checked }` 같은 plain 객체면
 * 재귀 복사한다(값이 다국어 객체일 수 있다).
 *
 * @param target 이벤트 target
 * @param eventType 이벤트 타입
 * @param seen 순환 추적
 * @param depth 현재 깊이
 * @returns target 스냅샷
 */
function toTargetData(
    target: unknown,
    eventType: string | undefined,
    seen: WeakMap<object, unknown>,
    depth: number
): unknown {
    if (target === null || target === undefined) {
        return null;
    }

    if (isElement(target)) {
        return toElementData(target, eventType);
    }

    const copied = copyValue(target, seen, depth + 1);
    if (copied && typeof copied === 'object' && !Array.isArray(copied)) {
        const record = copied as Record<string, unknown>;
        record.name = record.name ?? '';
    }

    return copied;
}

/**
 * DOM/React 이벤트를 표현식용 DTO 로 복사합니다.
 *
 * @param event 원본 이벤트
 * @param seen 순환 추적
 * @param depth 현재 깊이
 * @returns 이벤트 DTO
 */
function toEventDto(event: Event, seen: WeakMap<object, unknown>, depth: number): Record<string, unknown> {
    const raw = event as unknown as Record<string, unknown>;
    const out: Record<string, unknown> = { type: event.type };

    for (const key of EVENT_SCALAR_KEYS) {
        const value = raw[key];
        if (value !== undefined && isPrimitive(value)) {
            out[key] = value;
        }
    }

    // CustomEvent.detail
    if (raw.detail !== undefined) {
        out.detail = copyValue(raw.detail, seen, depth + 1);
    }

    out.target = toTargetData(event.target, event.type, seen, depth);

    if (event.currentTarget && event.currentTarget !== event.target) {
        out.currentTarget = toTargetData(event.currentTarget, event.type, seen, depth);
    } else {
        out.currentTarget = out.target;
    }

    // 디바운스 병합 메타데이터 (MultilingualInput 등)
    if (Array.isArray(raw._changedKeys)) {
        out._changedKeys = [...(raw._changedKeys as unknown[])];
    }

    return out;
}

/**
 * plain 객체(Object/null 프로토타입)인지 판정합니다.
 *
 * @param value 검사할 값
 * @returns plain 객체면 true
 */
function isPlainObject(value: object): boolean {
    const proto = Object.getPrototypeOf(value);
    return proto === Object.prototype || proto === null;
}

/**
 * 값을 표현식에 실어도 되는 plain 데이터로 재귀 복사합니다.
 *
 * @param value 원본 값
 * @param seen 순환·공유 참조 추적 (같은 원본은 같은 사본)
 * @param depth 현재 깊이
 * @returns 복사된 값
 */
function copyValue(value: unknown, seen: WeakMap<object, unknown>, depth: number): unknown {
    if (value === null || value === undefined) {
        return value;
    }

    if (typeof value === 'function') {
        return isExpressionSafeFunction(value) ? value : null;
    }

    if (typeof value !== 'object') {
        return value;
    }

    if (depth > MAX_DEPTH) {
        return null;
    }

    if (seen.has(value)) {
        return seen.get(value);
    }

    if (isEventObject(value)) {
        const dto = toEventDto(value, seen, depth);
        seen.set(value, dto);
        return dto;
    }

    if (isElement(value)) {
        const dto = toElementData(value);
        seen.set(value, dto);
        return dto;
    }

    // 업로드 값 — 스크립트 실행 capability 가 없다 (평가기도 허용)
    if (typeof Blob !== 'undefined' && value instanceof Blob) {
        return value;
    }
    if (typeof FileList !== 'undefined' && value instanceof FileList) {
        return Array.from(value);
    }

    if (isUnsafeHostValue(value)) {
        return null;
    }

    if (Array.isArray(value)) {
        const out: unknown[] = [];
        seen.set(value, out);
        for (const item of value) {
            out.push(copyValue(item, seen, depth + 1));
        }
        return out;
    }

    if (isPlainObject(value)) {
        const out: Record<string, unknown> = {};
        seen.set(value, out);
        for (const key of Object.keys(value)) {
            if (SKIPPED_KEYS.has(key)) continue;
            Object.defineProperty(out, key, {
                value: copyValue((value as Record<string, unknown>)[key], seen, depth + 1),
                enumerable: true,
                writable: true,
                configurable: true,
            });
        }
        return out;
    }

    // Date·Map·Set·RegExp·사용자 클래스 인스턴스 등 — 호스트가 아니면 그대로 둔다
    return value;
}

/**
 * 이벤트(또는 합성 컴포넌트가 넘긴 값)를 `$event` 용 DTO 로 변환합니다.
 *
 * @param event 원본 이벤트 또는 콜백 데이터
 * @returns 표현식용 `$event` 값
 */
export function toExpressionEvent(event: unknown): unknown {
    return copyValue(event, new WeakMap(), 0);
}

/**
 * 콜백 인자 배열을 `$args` 용으로 복사합니다.
 *
 * @param args 원본 인자 배열
 * @returns 표현식용 `$args` 값 (배열이 아니면 그대로 복사)
 */
export function toExpressionArgs(args: unknown): unknown {
    return copyValue(args, new WeakMap(), 0);
}

/**
 * 임의 값을 표현식에 실어도 되는 plain 데이터로 복사합니다.
 *
 * @param value 원본 값
 * @returns 복사된 값
 */
export function toExpressionData(value: unknown): unknown {
    return copyValue(value, new WeakMap(), 0);
}

/**
 * 오류 객체를 `error` 용 DTO 로 복사합니다.
 *
 * Error 의 `message`·`name` 은 비열거 프로퍼티라 명시로 싣고, own 열거 필드(`response`·`status`·
 * `errors`·`code`·`data` …)는 plain 데이터로 복사한다. `stack` 은 싣지 않는다.
 *
 * @param error 원본 오류
 * @returns 표현식용 `error` 값 (객체면 DTO, 원시 값이면 그대로)
 */
export function toExpressionError(error: unknown): unknown {
    // 원시 값(문자열 throw 등)은 capability 가 없으므로 그대로 둔다 (종전 바인딩 결과 유지)
    if (error === null || typeof error !== 'object') {
        return error;
    }

    const seen = new WeakMap<object, unknown>();
    const out: Record<string, unknown> = {};

    for (const key of Object.keys(error)) {
        if (key === 'stack' || SKIPPED_KEYS.has(key)) continue;
        Object.defineProperty(out, key, {
            value: copyValue((error as Record<string, unknown>)[key], seen, 1),
            enumerable: true,
            writable: true,
            configurable: true,
        });
    }

    // plain 객체 오류는 message 가 없으면 없는 그대로 둔다 — `error.message ?? '대체문구'` 가 살아 있어야 한다
    if (error instanceof Error) {
        out.message = error.message;
        out.name = error.name;
    }

    return out;
}
