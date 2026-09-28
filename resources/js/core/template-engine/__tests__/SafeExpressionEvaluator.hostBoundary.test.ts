/**
 * SafeExpressionEvaluator 호스트 capability 타입 경계 테스트 (KVE-2026-2350)
 *
 * 금지 프로퍼티 이름(constructor/__proto__)만 막던 평가기는 DOM 노드에서 출발한
 * `.ownerDocument.defaultView.localStorage`·`.fetch(...)` 를 정상 읽기·호출로 허용했다.
 * 이제 값의 종류로 막는다 — 호스트 객체는 쥐는 순간 거부, 함수는 허용목록만 호출.
 * 거부축과 정상 통과축을 쌍으로 둔다(허용목록 누락은 대량 회귀로 이어진다).
 *
 * @since engine-v1.73.0
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import {
    evaluateSafeExpression,
    isUnsafeHostValue,
    isExpressionSafeFunction,
} from '../SafeExpressionEvaluator';
import { DataBindingEngine } from '../DataBindingEngine';

const evalx = (expr: string, ctx: Record<string, unknown> = {}) => evaluateSafeExpression(expr, ctx);

describe('SafeExpressionEvaluator — 호스트 capability 타입 경계', () => {
    let img: HTMLImageElement;

    beforeEach(() => {
        img = document.createElement('img');
        document.body.appendChild(img);
        delete (window as any).__g7_escape_canary;
    });

    afterEach(() => {
        img.remove();
        delete (window as any).__g7_escape_canary;
        localStorage.removeItem('g7_expr_escape_marker');
    });

    describe('거부 — 호스트 객체 그래프 탈출', () => {
        /**
         * @scenario case=host_graph_escape
         *
         * @effects sandbox_escape_blocked, host_object_access_blocked
         */
        it('원본 이벤트 target 에서 ownerDocument.defaultView 로 Window 에 닿지 못한다 (신고 payload)', () => {
            const $event = { target: img };
            const payload =
                "$event.target.ownerDocument.defaultView.localStorage.setItem('g7_expr_escape_marker', 'WINDOW_REACHED')";

            expect(() => evalx(payload, { $event })).toThrow();
            expect(localStorage.getItem('g7_expr_escape_marker')).toBeNull();
        });

        /**
         * @effects sandbox_escape_blocked, host_object_access_blocked
         */
        it('DOM 노드·Document·Window·Storage·Location 을 쥐는 순간 거부한다', () => {
            expect(() => evalx('el', { el: img })).toThrow(/host object/);
            expect(() => evalx('holder.el', { holder: { el: img } })).toThrow(/host object/);
            expect(() => evalx('x.doc', { x: { doc: document } })).toThrow(/host object/);
            expect(() => evalx('x.w', { x: { w: window } })).toThrow(/host object/);
            expect(() => evalx('x.s', { x: { s: window.localStorage } })).toThrow(/host object/);
            expect(() => evalx('x.l', { x: { l: window.location } })).toThrow(/host object/);
        });

        /**
         * @effects sandbox_escape_blocked, native_host_function_call_blocked
         */
        it('허용목록 밖 네이티브 함수(call/apply/bind·리플렉션·bound)는 호출하지 않고, Storage·fetch 는 홀더에서 끊긴다', () => {
            // 허용목록 밖 네이티브 — 이름이 아니라 함수 참조로 거부한다
            expect(() => evalx('items.map.call(items, x => x)', { items: [1] })).toThrow();
            expect(() => evalx('items.map.bind(items)', { items: [1] })).toThrow();
            expect(() => evalx('d(o, "a")', { d: Object.getOwnPropertyDescriptor, o: { a: 1 } })).toThrow(
                /native host function/
            );
            expect(() => evalx('b()', { b: (() => 1).bind(null) })).toThrow(/native host function/);
            // Storage·fetch 는 함수에 닿기 전에 홀더(Storage·Window)에서 거부된다
            // (jsdom 은 DOM API 를 JS 로 구현해 메서드 자체는 네이티브로 보이지 않는다 — 실 브라우저는 둘 다 막힌다)
            expect(() => evalx("s.getItem('auth_token')", { s: window.localStorage })).toThrow(/host object/);
            expect(() => evalx("x.w.fetch('/api/x')", { x: { w: window } })).toThrow(/host object/);
            expect(() => evalx('x.el.querySelector("body")', { x: { el: document.body } })).toThrow(/host object/);
        });

        /**
         * @effects sandbox_escape_blocked, dangerous_payload_does_not_set_global
         */
        it('헬퍼 반환값 경유 우회도 막힌다 — $get 류가 호스트 객체를 돌려주면 거부', () => {
            const $get = (obj: any, path: string[]) => path.reduce((o, k) => (o == null ? o : o[k]), obj);

            expect(() => evalx("$get($event, ['target', 'ownerDocument'])", { $get, $event: { target: img } })).toThrow(
                /host object/
            );
            expect((window as any).__g7_escape_canary).toBeUndefined();
        });

        /**
         * @effects host_object_access_blocked
         */
        it('최종 결과로 호스트 객체를 돌려주지 않는다 (배열 원소 경유)', () => {
            expect(() => evalx('list.find(x => x.nodeType === 1)', { list: [img] })).toThrow();
        });
    });

    describe('정상 통과 — 순수 계산 표현식 회귀 없음', () => {
        /**
         * @scenario case=safe_expression
         *
         * @effects same_expression_same_value_across_paths
         */
        it('번들 레이아웃이 쓰는 배열·문자열·Object·Math·JSON·Date 메서드는 그대로 동작한다', () => {
            const ctx = {
                items: [{ id: 1, name: 'a', tags: ['x'] }, { id: 2, name: 'b', tags: [] }],
                text: '  Hello World  ',
                form: { a: 1, b: 2 },
            };

            expect(evalx('items.filter(i => i.id > 1).map(i => i.name)', ctx)).toEqual(['b']);
            expect(evalx('items.some(i => i.tags.includes("x"))', ctx)).toBe(true);
            expect(evalx('items.find(i => i.id === 2)?.name', ctx)).toBe('b');
            expect(evalx('items.reduce((s, i) => s + i.id, 0)', ctx)).toBe(3);
            expect(evalx('items.flatMap(i => i.tags).join(",")', ctx)).toBe('x');
            expect(evalx('text.trim().toUpperCase().split(" ")', ctx)).toEqual(['HELLO', 'WORLD']);
            expect(evalx('text.trim().startsWith("He") && text.indexOf("W") > 0', ctx)).toBe(true);
            expect(evalx('Object.keys(form).concat(Object.values(form).map(String))', ctx)).toEqual(['a', 'b', '1', '2']);
            expect(evalx('Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v * 2]))', ctx)).toEqual({
                a: 2,
                b: 4,
            });
            expect(evalx('Object.assign({}, form, { c: 3 })', ctx)).toEqual({ a: 1, b: 2, c: 3 });
            expect(evalx('Array.isArray(items) && Array.from(items).length', ctx)).toBe(2);
            expect(evalx('Math.max(1, 5) + Math.floor(2.7) + Math.round(1.4)', ctx)).toBe(8);
            expect(evalx('JSON.parse(JSON.stringify(form)).a', ctx)).toBe(1);
            expect(evalx('(1234.5).toLocaleString("en-US")', ctx)).toBe('1,234.5');
            expect(evalx('new Date(0).toISOString().slice(0, 4)', ctx)).toBe('1970');
            expect(typeof evalx('Date.now()', ctx)).toBe('number');
            expect(evalx('[3, 1, 2].sort((a, b) => a - b)', ctx)).toEqual([1, 2, 3]);
            expect(evalx('[1, 0, 2].filter(Boolean).map(Number)', ctx)).toEqual([1, 2]);
            expect(evalx('String(12).padStart(4, "0")', ctx)).toBe('0012');
            expect(evalx('form.hasOwnProperty("a")', ctx)).toBe(true);
            expect(evalx('new Map([["k", 1]]).get("k")', ctx)).toBe(1);
            expect(evalx('new Set([1, 1, 2]).size', ctx)).toBe(2);
        });

        /**
         * @effects same_expression_same_value_across_paths
         */
        it('엔진이 컨텍스트에 넣는 JS 헬퍼($t·$get 류)와 평가기 클로저는 호출된다', () => {
            const $t = (key: string) => `T:${key}`;
            const $get = (obj: any, path: string[], fb?: unknown) =>
                path.reduce((o, k) => (o == null ? o : o[k]), obj) ?? fb;

            expect(evalx("$t('a.b')", { $t })).toBe('T:a.b');
            expect(evalx("$get(data, ['x', 'y'], 'fb')", { $get, data: { x: { y: 1 } } })).toBe(1);
            expect(evalx('((x) => x * 2)(3)', {})).toBe(6);
            expect(evalx('(function f(n) { return n <= 1 ? 1 : n * f(n - 1); })(4)', {})).toBe(24);
        });

        /**
         * @effects same_expression_same_value_across_paths
         */
        it('업로드 File·Date·Error 값은 호스트 capability 가 아니라 읽힌다', () => {
            const file = new File(['x'], 'a.png', { type: 'image/png' });

            expect(evalx('files[0].name + ":" + files.length', { files: [file] })).toBe('a.png:1');
            expect(evalx('e.message', { e: new Error('boom') })).toBe('boom');
        });

        /**
         * @effects same_expression_same_value_across_paths
         */
        it('gdpr 설정의 localStorage/sessionStorage 는 데이터 키라 그대로 읽힌다 (이름이 아니라 종류로 막는다)', () => {
            const ctx = { settings: { necessary_storage_locked: { localStorage: ['a'], sessionStorage: [] } } };

            expect(evalx('(settings?.necessary_storage_locked?.localStorage ?? []).length', ctx)).toBe(1);
            expect(evalx('({ localStorage: [1] }).localStorage[0]', ctx)).toBe(1);
        });
    });

    describe('단순 경로 바인딩 (DataBindingEngine.resolve)', () => {
        /**
         * @effects host_object_access_blocked, sandbox_escape_blocked
         */
        it('호출 없는 경로도 호스트 객체를 지나 값을 읽지 못한다 (…defaultView.localStorage.auth_token)', () => {
            localStorage.setItem('auth_token', 'secret-token');
            const engine = new DataBindingEngine();

            const value = engine.resolve('$event.target.ownerDocument.defaultView.localStorage.auth_token', {
                $event: { target: img },
            });

            expect(value).not.toBe('secret-token');
            expect(value).toBeUndefined();
            localStorage.removeItem('auth_token');
        });

        /**
         * @effects same_expression_same_value_across_paths
         */
        it('데이터 경로는 종전대로 해석된다 (정상 회귀)', () => {
            const engine = new DataBindingEngine();

            expect(engine.resolve('user.profile.name', { user: { profile: { name: '홍길동' } } })).toBe('홍길동');
            expect(engine.resolve('items[1].id', { items: [{ id: 1 }, { id: 2 }] })).toBe(2);
        });
    });

    describe('엔진 $get 헬퍼 (DataBindingEngine)', () => {
        /**
         * @effects get_helper_host_path_blocked, sandbox_escape_blocked
         */
        it('$get 이 중간의 호스트 객체를 건너뛰어 문자열을 꺼내지 못한다 (…defaultView.localStorage.auth_token)', () => {
            localStorage.setItem('auth_token', 'secret-token');
            const engine = new DataBindingEngine();
            // DTO 가 복사하지 않는 비-plain 객체 안에 DOM 참조가 있는 경우 (서드파티 컴포넌트 콜백 인자 등)
            class Holder {
                el = img;
            }

            const value = engine.resolveBindings(
                "{{$get(holder, ['el', 'ownerDocument', 'defaultView', 'localStorage', 'auth_token'], 'fb')}}",
                { holder: new Holder() }
            );

            expect(value).not.toBe('secret-token');
            expect(value).toBe('fb');
            localStorage.removeItem('auth_token');
        });

        /**
         * @effects same_expression_same_value_across_paths
         */
        it('$get 의 데이터 경로·폴백은 종전대로 동작한다 (정상 회귀)', () => {
            const engine = new DataBindingEngine();
            const ctx = { product: { prices: { KRW: { formatted: '1,000원' } } }, currency: 'KRW' };

            expect(engine.resolveBindings("{{$get(product.prices, [currency, 'formatted'], '-')}}", ctx)).toBe('1,000원');
            expect(engine.resolveBindings("{{$get(product.prices, ['USD', 'formatted'], '-')}}", ctx)).toBe('-');
            expect(engine.resolveBindings("{{$get(product, 'prices').KRW.formatted}}", ctx)).toBe('1,000원');
        });
    });

    describe('공개 판정 함수', () => {
        /**
         * @effects host_object_access_blocked
         */
        it('isUnsafeHostValue / isExpressionSafeFunction 이 평가기와 같은 판정을 돌려준다', () => {
            expect(isUnsafeHostValue(img)).toBe(true);
            expect(isUnsafeHostValue(document)).toBe(true);
            expect(isUnsafeHostValue(window)).toBe(true);
            expect(isUnsafeHostValue(localStorage)).toBe(true);
            expect(isUnsafeHostValue(Promise.resolve())).toBe(true);
            expect(isUnsafeHostValue({ a: 1 })).toBe(false);
            expect(isUnsafeHostValue([img])).toBe(false);
            expect(isUnsafeHostValue(new Date())).toBe(false);

            expect(isExpressionSafeFunction(Array.prototype.map)).toBe(true);
            expect(isExpressionSafeFunction((x: number) => x)).toBe(true);
            expect(isExpressionSafeFunction(Object.getOwnPropertyDescriptor)).toBe(false);
            expect(isExpressionSafeFunction(Function.prototype.call)).toBe(false);
            expect(isExpressionSafeFunction((() => 1).bind(null))).toBe(false);
        });
    });
});
