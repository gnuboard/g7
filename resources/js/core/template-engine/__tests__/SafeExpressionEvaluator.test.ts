import { describe, it, expect } from 'vitest';
import { evaluateSafeExpression } from '../SafeExpressionEvaluator';

/**
 * SafeExpressionEvaluator 테스트.
 *
 * `new Function` / `with(ctx)` 를 대체하는 안전한 표현식 인터프리터 검증.
 * 실제 레이아웃 표현식 호환성 + 샌드박스 탈출 차단(보안)을 모두 다룬다.
 *
 * 시나리오 축(case)은 배포 번들 E2E(layout-expression-sandbox.spec.ts)가 커버하고,
 * 이 파일은 소스 레벨에서 같은 효과를 떠받친다.
 *
 * 효과 요약(마커 아님 — 평문): sandbox_escape_blocked,
 * same_expression_same_value_across_paths. 실제 마커는 그 효과를 단언하는 개별
 * 테스트에만 둔다 — 파일 레벨에 몰아 적으면 테스트를 전부 지워도 커버리지가 green 으로 남는다.
 */

const evalx = (expr: string, ctx: Record<string, unknown> = {}): unknown =>
    evaluateSafeExpression(expr, ctx);

describe('SafeExpressionEvaluator', () => {
    describe('literals', () => {
        it('정수/실수', () => {
            expect(evalx('42')).toBe(42);
            expect(evalx('3.14')).toBe(3.14);
            expect(evalx('.5')).toBe(0.5);
            expect(evalx('1e3')).toBe(1000);
        });

        it('문자열 (따옴표/이스케이프)', () => {
            expect(evalx("'hello'")).toBe('hello');
            expect(evalx('"world"')).toBe('world');
            expect(evalx("'a\\'b'")).toBe("a'b");
            expect(evalx('"a\\"b"')).toBe('a"b');
            expect(evalx("'a\\\\b'")).toBe('a\\b');
            expect(evalx("'a\\nb'")).toBe('a\nb');
            expect(evalx("'a\\tb'")).toBe('a\tb');
        });

        it('boolean/null/undefined', () => {
            expect(evalx('true')).toBe(true);
            expect(evalx('false')).toBe(false);
            expect(evalx('null')).toBe(null);
            expect(evalx('undefined')).toBe(undefined);
        });

        it('배열/객체 리터럴', () => {
            expect(evalx('[1, 2, 3]')).toEqual([1, 2, 3]);
            expect(evalx("{ a: 1, 'b-c': 2 }")).toEqual({ a: 1, 'b-c': 2 });
        });
    });

    describe('member & optional chaining', () => {
        it('점 접근', () => {
            expect(evalx('a.b.c', { a: { b: { c: 5 } } })).toBe(5);
        });

        it('옵셔널 체이닝 단락', () => {
            expect(evalx('a?.b', { a: null })).toBe(undefined);
            expect(evalx('a?.b?.c', { a: undefined })).toBe(undefined);
            expect(evalx("user?.name ?? 'Guest'", { user: null })).toBe('Guest');
            expect(evalx("user?.name ?? 'Guest'", { user: { name: 'Kim' } })).toBe('Kim');
        });

        it('없는 식별자는 undefined (throw 하지 않음)', () => {
            expect(evalx('missing')).toBe(undefined);
            expect(evalx('missing?.x')).toBe(undefined);
            expect(evalx("missing ?? 'fallback'")).toBe('fallback');
        });

        it('nullish 비-optional 접근은 undefined 반환', () => {
            expect(evalx('a.b', { a: undefined })).toBe(undefined);
        });
    });

    describe('computed access', () => {
        it('문자열/변수 키', () => {
            expect(evalx("obj['key']", { obj: { key: 9 } })).toBe(9);
            expect(evalx('obj[k]', { obj: { key: 9 }, k: 'key' })).toBe(9);
            expect(evalx("query['status[]']", { query: { 'status[]': ['a'] } })).toEqual(['a']);
        });

        it('옵셔널 computed', () => {
            expect(evalx('a?.[0]', { a: null })).toBe(undefined);
            expect(evalx('a?.[0]', { a: [7] })).toBe(7);
        });
    });

    describe('calls & methods', () => {
        it('컨텍스트 함수 호출', () => {
            expect(evalx("$t('some.key')", { $t: (k: string) => k })).toBe('some.key');
        });

        it('$get 헬퍼', () => {
            const $get = (obj: unknown, path: string[], def: unknown): unknown => {
                let cur: unknown = obj;
                for (const p of path) {
                    if (cur == null) return def;
                    cur = (cur as Record<string, unknown>)[p];
                }
                return cur ?? def;
            };
            expect(evalx("$get(product, ['prices', 'KRW'], 'n/a')", { product: { prices: { KRW: 1000 } }, $get })).toBe(
                1000,
            );
            expect(evalx("$get(product, ['prices', 'USD'], 'n/a')", { product: { prices: { KRW: 1000 } }, $get })).toBe(
                'n/a',
            );
        });

        it('인스턴스 메서드 체인', () => {
            expect(evalx('String(value).toLocaleString()', { value: 1234 })).toBe('1234');
        });

        it('옵셔널 호출', () => {
            expect(evalx('obj?.method?.()', { obj: {} })).toBe(undefined);
            expect(evalx('obj?.method?.()', { obj: { method: () => 3 } })).toBe(3);
        });

        it('Math.max 등 화이트리스트 전역', () => {
            expect(evalx('Math.max(0, (products?.data?.length ?? 0) - 1)', { products: { data: [1, 2, 3] } })).toBe(2);
            expect(evalx('Math.max(0, (products?.data?.length ?? 0) - 1)', {})).toBe(0);
        });
    });

    describe('arrow-function array methods', () => {
        it('filter', () => {
            expect(evalx("['a', 'b', 'c'].filter(v => v !== 'b')")).toEqual(['a', 'c']);
        });

        it('map returning object', () => {
            expect(
                evalx('items.map(i => ({ id: i.id, label: i.name })).length', {
                    items: [
                        { id: 1, name: 'x' },
                        { id: 2, name: 'y' },
                    ],
                }),
            ).toBe(2);
        });

        it('findIndex (u, i, a) 3-params dedupe', () => {
            const ctx = {
                list: [{ uuid: 'a' }, { uuid: 'b' }, { uuid: 'a' }],
            };
            expect(evalx('list.filter((u, i, a) => a.findIndex(x => x.uuid === u.uuid) === i).length', ctx)).toBe(2);
        });
    });

    describe('arrow param 배열 구조분해 (engine-v1.60.5 회귀 — 장바구니/바로구매 불능)', () => {
        // 구 평가기(new Function)가 허용하던 형태 전수: 저장소 레이아웃 54파일 104곳 사용
        // (`([code])` 35 · `([field, messages])` 34 · `([k, v])` 등 — _purchase_card.json 이 대표)
        it('단일 요소 ([k])', () => {
            expect(evalx('Object.entries({ a: 1, b: 2 }).map(([k]) => k)')).toEqual(['a', 'b']);
        });

        it('두 요소 ([k, v])', () => {
            expect(evalx('Object.entries({ a: 1, b: 2 }).map(([k, v]) => k + v)')).toEqual(['a1', 'b2']);
        });

        it('선행 홀 ([, v])', () => {
            expect(evalx('Object.entries({ a: 1, b: null }).filter(([, v]) => v != null).length')).toBe(1);
        });

        it('배열 인자 직접 분해', () => {
            expect(evalx('[[1, 2], [3, 4]].map(([a, b]) => a * b)')).toEqual([2, 12]);
        });

        it('파라미터 기본값 조합 (([a, b] = []) =>)', () => {
            expect(evalx('[undefined].map(([a, b] = [7, 8]) => (a ?? 0) + (b ?? 0))')).toEqual([15]);
        });

        it('null/undefined 인자 분해는 JS 와 동일하게 예외', () => {
            expect(() => evalx('[null].map(([a]) => a)')).toThrow();
        });

        it('실전 회귀: _purchase_card.json 장바구니 담기 본문 표현식', () => {
            const ctx = {
                product: { data: { id: 98, has_options: true, options: [{ id: 1 }, { id: 2 }] } },
                _local: {
                    selectedOptionItems: [
                        {
                            optionId: 11,
                            quantity: 2,
                            additionalOptionSelections: { 5: 50, 6: null },
                            additionalOptionCustomTexts: { 5: '각인 문구' },
                        },
                    ],
                    noOptionQuantity: 1,
                },
            };
            const expr =
                'product.data?.has_options && (product.data?.options?.length ?? 0) > 1 ? ' +
                '{ product_id: product.data?.id, items: (_local.selectedOptionItems ?? []).map(item => ({ ' +
                'product_option_id: item.optionId, quantity: item.quantity, ' +
                'additional_option_selections: Object.entries(item.additionalOptionSelections ?? {})' +
                '.filter(([, vid]) => vid != null)' +
                '.map(([gid, vid]) => ({ additional_option_id: Number(gid), value_id: Number(vid), custom_text: item.additionalOptionCustomTexts?.[Number(gid)] })) ' +
                '})) } : { product_id: product.data?.id, items: [{ quantity: _local.noOptionQuantity ?? 1 }] }';
            expect(evalx(expr, ctx)).toEqual({
                product_id: 98,
                items: [
                    {
                        product_option_id: 11,
                        quantity: 2,
                        additional_option_selections: [{ additional_option_id: 5, value_id: 50, custom_text: '각인 문구' }],
                    },
                ],
            });
        });

        it('실전 회귀: 게시판 환경설정 권한 computed (filter([key]) + startsWith)', () => {
            const ctx = {
                settings: {
                    data: {
                        basic_defaults: {
                            default_board_permissions: { 'admin.manage': true, read: true, write: false },
                        },
                    },
                },
            };
            expect(
                evalx(
                    "Object.entries(settings?.data?.basic_defaults?.default_board_permissions ?? {}).filter(([key]) => !key.startsWith('admin.')).map(([key]) => key)",
                    ctx,
                ),
            ).toEqual(['read', 'write']);
        });

        it('실전 회귀: 검증 오류 표시 ([field, messages]) — 플러그인 설정 onError 형태', () => {
            const ctx = { errors: { name: ['이름은 필수입니다'], email: ['형식 오류'] } };
            expect(evalx("Object.entries(errors ?? {}).map(([field, messages]) => field + ': ' + messages[0])", ctx)).toEqual([
                'name: 이름은 필수입니다',
                'email: 형식 오류',
            ]);
        });

        it('function 선언 파라미터의 배열 구조분해', () => {
            expect(evalx('(function f([a, b]) { return a + b; })([3, 4])')).toBe(7);
        });
    });

    describe('spread (array / object / call)', () => {
        it('배열 스프레드', () => {
            expect(evalx('[...a, ...b, 3]', { a: [1], b: [2] })).toEqual([1, 2, 3]);
        });

        it('객체 스프레드', () => {
            expect(evalx('{ ...a, c: 3 }', { a: { a: 1, b: 2 } })).toEqual({ a: 1, b: 2, c: 3 });
        });

        it('call 스프레드', () => {
            expect(evalx('Math.max(...nums)', { nums: [4, 9, 2] })).toBe(9);
        });
    });

    describe('operators & precedence', () => {
        it('산술 우선순위', () => {
            expect(evalx('1 + 2 * 3')).toBe(7);
            expect(evalx('(1 + 2) * 3')).toBe(9);
            expect(evalx('10 % 3')).toBe(1);
            expect(evalx('7 / 2')).toBe(3.5);
        });

        it('단항', () => {
            expect(evalx('!true')).toBe(false);
            expect(evalx('-5')).toBe(-5);
            expect(evalx('+"3"')).toBe(3);
            expect(evalx("typeof x", { x: 'str' })).toBe('string');
            expect(evalx('typeof missing')).toBe('undefined');
        });

        it('비교/동등', () => {
            expect(evalx('1 === 1')).toBe(true);
            expect(evalx("1 === '1'")).toBe(false);
            expect(evalx("1 == '1'")).toBe(true);
            expect(evalx('2 !== 3')).toBe(true);
            expect(evalx('2 < 3 && 3 <= 3')).toBe(true);
        });

        it('(count ?? 0) + 1', () => {
            expect(evalx('(count ?? 0) + 1', {})).toBe(1);
            expect(evalx('(count ?? 0) + 1', { count: 5 })).toBe(6);
        });
    });

    describe('ternary / nullish / logical short-circuit', () => {
        it('삼항 (중첩)', () => {
            expect(evalx("a ? 'x' : b ? 'y' : 'z'", { a: false, b: true })).toBe('y');
            expect(evalx("a ? 'x' : b ? 'y' : 'z'", { a: false, b: false })).toBe('z');
        });

        it('|| 은 첫 truthy 피연산자 반환 (boolean 아님)', () => {
            expect(evalx("'' || 'fallback'")).toBe('fallback');
            expect(evalx("'first' || 'second'")).toBe('first');
            expect(evalx('0 || 42')).toBe(42);
        });

        it('&& 는 첫 falsy 또는 마지막 값', () => {
            expect(evalx("'a' && 'b'")).toBe('b');
            expect(evalx("0 && 'b'")).toBe(0);
        });

        it('?? 는 nullish 만 폴백', () => {
            expect(evalx("0 ?? 'x'")).toBe(0);
            expect(evalx("null ?? 'x'")).toBe('x');
            expect(evalx("'' ?? 'x'")).toBe('');
        });

        it('&& 단락으로 오른쪽 미평가', () => {
            let called = false;
            const ctx = {
                cond: false,
                boom: () => {
                    called = true;
                    return 1;
                },
            };
            expect(evalx('cond && boom()', ctx)).toBe(false);
            expect(called).toBe(false);
        });
    });

    describe('whitelisted globals', () => {
        it('JSON / Number / Boolean / parse*', () => {
            expect(evalx('JSON.stringify(a)', { a: { x: 1 } })).toBe('{"x":1}');
            expect(evalx("Number('42')")).toBe(42);
            expect(evalx("parseInt('10px', 10)")).toBe(10);
            expect(evalx("parseFloat('3.5rem')")).toBe(3.5);
            expect(evalx('isNaN(x)', { x: NaN })).toBe(true);
            expect(evalx('isFinite(1)')).toBe(true);
            expect(evalx('Boolean(0)')).toBe(false);
            expect(evalx('Array.isArray(a)', { a: [] })).toBe(true);
        });
    });

    describe('context shadowing', () => {
        it('컨텍스트 값이 전역보다 우선', () => {
            expect(evalx('String', { String: 'shadowed' })).toBe('shadowed');
            expect(evalx('Math', { Math: 123 })).toBe(123);
        });
    });

    describe('template literals', () => {
        it('보간 없는 템플릿', () => {
            expect(evalx('`no interpolation`')).toBe('no interpolation');
        });

        it('단일 보간', () => {
            expect(evalx('`/mypage/${$args[0]}`', { $args: ['x'] })).toBe('/mypage/x');
        });

        it('표현식 보간', () => {
            expect(evalx('`total: ${(count ?? 0) + 1}`', {})).toBe('total: 1');
            expect(evalx('`total: ${(count ?? 0) + 1}`', { count: 9 })).toBe('total: 10');
        });

        it('다중 보간 + 리터럴 조각', () => {
            expect(evalx('`${a}-${b}!`', { a: 'x', b: 'y' })).toBe('x-y!');
        });

        it('템플릿 내 이스케이프', () => {
            expect(evalx('`a\\nb`')).toBe('a\nb');
            expect(evalx('`price \\${x}`')).toBe('price ${x}');
        });
    });

    describe('new operator (whitelisted constructors)', () => {
        it('new Date(str).getTime() → number', () => {
            expect(evalx("new Date('2020-01-01').getTime()")).toBe(new Date('2020-01-01').getTime());
            expect(typeof evalx("new Date('2020-01-01').getTime()")).toBe('number');
        });

        it('new Date().toISOString().slice(0,10) → 10-char string', () => {
            const r = evalx('new Date().toISOString().slice(0, 10)');
            expect(typeof r).toBe('string');
            expect((r as string).length).toBe(10);
        });

        it('Array.from(new Set([...])) dedupe', () => {
            expect(evalx('Array.from(new Set([1, 1, 2]))')).toEqual([1, 2]);
        });

        it('new Map([...]).get(key)', () => {
            expect(evalx("new Map([['a', 1]]).get('a')")).toBe(1);
        });

        it('new Date(query.expires_at).getTime()', () => {
            const ctx = { query: { expires_at: '2021-06-15T00:00:00Z' } };
            expect(evalx('new Date(query.expires_at).getTime()', ctx)).toBe(
                new Date('2021-06-15T00:00:00Z').getTime(),
            );
        });

        it('Array.from(new Set(x.flatMap(...)))', () => {
            const ctx = { x: [{ tags: ['a', 'b'] }, { tags: ['b', 'c'] }] };
            expect(evalx('Array.from(new Set((x ?? []).flatMap(p => p.tags)))', ctx)).toEqual(['a', 'b', 'c']);
        });
    });

    describe('real-world compatibility', () => {
        /** @effects same_expression_same_value_across_paths */
        it('상태 필터 토글 (statusFilter)', () => {
            const expr =
                "((_global.statusFilter ?? query['status[]']) || []).includes('pending') ? ((_global.statusFilter ?? query['status[]']) || []).filter(v => v !== 'pending') : [...((_global.statusFilter ?? query['status[]']) || []), 'pending']";
            // 현재 pending 없음 → 추가
            expect(evalx(expr, { _global: { statusFilter: ['active'] }, query: {} })).toEqual(['active', 'pending']);
            // 현재 pending 있음 → 제거
            expect(evalx(expr, { _global: { statusFilter: ['pending', 'active'] }, query: {} })).toEqual(['active']);
            // 둘 다 없음 → 추가
            expect(evalx(expr, { _global: {}, query: {} })).toEqual(['pending']);
        });

        /** @effects same_expression_same_value_across_paths */
        it('board_managers 병합 + dedupe', () => {
            const expr =
                '[...(_local.form?.board_managers ?? []), ...(_local.managerSearchResults ?? [])].filter(u => ($event.target.value ?? []).includes(u.uuid)).filter((u, i, a) => a.findIndex(x => x.uuid === u.uuid) === i)';
            const ctx = {
                _local: {
                    form: { board_managers: [{ uuid: 'a' }, { uuid: 'b' }] },
                    managerSearchResults: [{ uuid: 'b' }, { uuid: 'c' }],
                },
                $event: { target: { value: ['a', 'b'] } },
            };
            expect(evalx(expr, ctx)).toEqual([{ uuid: 'a' }, { uuid: 'b' }]);
        });

        /** @effects same_expression_same_value_across_paths */
        it('comment blind 로그 판정', () => {
            const expr =
                "comment?.abilities?.can_manage && (comment?.action_logs ?? []).filter(log => log.action === 'blind').length > 0";
            expect(
                evalx(expr, {
                    comment: { abilities: { can_manage: true }, action_logs: [{ action: 'blind' }] },
                }),
            ).toBe(true);
            expect(
                evalx(expr, {
                    comment: { abilities: { can_manage: true }, action_logs: [] },
                }),
            ).toBe(false);
        });

        /** @effects same_expression_same_value_across_paths */
        it('마지막 blind 로그 reason 추출', () => {
            const expr = "(post?.data?.action_logs ?? []).filter(log => log.action === 'blind').slice(-1)[0]?.reason ?? '-'";
            expect(
                evalx(expr, {
                    post: { data: { action_logs: [{ action: 'blind', reason: 'spam' }] } },
                }),
            ).toBe('spam');
            expect(evalx(expr, { post: { data: { action_logs: [] } } })).toBe('-');
        });
    });

    // engine-v1.60.0 — statement 본문(function/arrow 블록 IIFE) 회귀 복원.
    // KVE-2026-1915 로 new Function → AST 인터프리터 교체 시, 기존 30개 레이아웃이 쓰던
    // `(function(){ const …; if(…) return …; })()` 형태가 조용히 거부돼 저장이 미해석
    // 원문 문자열로 전송되던 회귀(문의 게시판 지정 실패 등)를 고정한다.
    describe('statement 본문 IIFE (engine-v1.60.0 회귀 복원)', () => {
        it('const 선언 + return (arrow 블록)', () => {
            expect(evalx('(() => { const x = 1; return x + 1; })()')).toBe(2);
        });

        it('const 선언 + return (function 식)', () => {
            expect(evalx('(function() { const a = 2; const b = 3; return a * b; })()')).toBe(6);
        });

        it('if 분기 다중 return — 탭별 payload 조립 (이커머스 설정 저장 본문)', () => {
            const expr =
                "(function() { const tab = _global.activeEcommerceSettingsTab || query.tab || 'basic_info'; const form = _local.form ?? {}; if (tab === 'notification_definitions') { return { _tab: 'notifications', notifications: { channels: form.notifications?.channels || [] } }; } if (tab === 'mileage') { return { _tab: 'mileage', mileage: form.mileage ?? {} }; } return { _tab: tab, [tab]: form[tab] ?? {}, inquiry: form.inquiry ?? {} }; })()";
            // 문의 게시판 지정: basic_info 탭에서 inquiry.board_slug 가 payload 에 실려야 한다
            const result = evalx(expr, {
                _global: {},
                query: { tab: 'basic_info' },
                _local: { form: { basic_info: { shop_name: 'S' }, inquiry: { board_slug: 'inquiry' } } },
            }) as Record<string, unknown>;
            expect(result._tab).toBe('basic_info');
            expect((result.inquiry as Record<string, unknown>).board_slug).toBe('inquiry');
            expect((result.basic_info as Record<string, unknown>).shop_name).toBe('S');
        });

        it('new Date() + 변형 메서드 (게시판 신고 기간 프리셋)', () => {
            const expr = '(() => { const d = new Date(2026, 0, 10); d.setDate(d.getDate() - 2); return d.getDate(); })()';
            expect(evalx(expr)).toBe(8);
        });

        it('for-of + continue + 재귀 arrow + 기본 파라미터 (카테고리 flatten)', () => {
            const expr =
                "(() => { const result = []; const flatten = (items, path = [], depth = 0) => { if (!items || !Array.isArray(items) || items.length === 0) return; for (const item of items) { if (!item || !item.id) continue; const currentPath = [...path, item.name]; result.push({ value: item.id, label: currentPath.join(' > ') }); if (item.children && Array.isArray(item.children) && item.children.length > 0 && depth < 2) { flatten(item.children, currentPath, depth + 1); } } }; flatten(categories); return result; })()";
            const out = evalx(expr, {
                categories: [
                    { id: 1, name: 'A', children: [{ id: 2, name: 'A1', children: [] }] },
                    { id: 3, name: 'B', children: [] },
                    { id: null, name: 'skip' },
                ],
            }) as Array<{ value: number; label: string }>;
            expect(out).toEqual([
                { value: 1, label: 'A' },
                { value: 2, label: 'A > A1' },
                { value: 3, label: 'B' },
            ]);
        });

        it('delete 로 로컬 객체 사본 프로퍼티 제거 (edit 모드 slug 제외)', () => {
            const expr = '(() => { const data = {...form}; if (mode === "edit") { delete data.slug; } return data; })()';
            expect(evalx(expr, { form: { name: 'N', slug: 's' }, mode: 'edit' })).toEqual({ name: 'N' });
            expect(evalx(expr, { form: { name: 'N', slug: 's' }, mode: 'create' })).toEqual({ name: 'N', slug: 's' });
        });

        it('function 콜백 + concat + map (게시판 카테고리 옵션)', () => {
            const expr =
                "[{value:'all', label:'전체'}].concat((categories ?? []).map(function(c){return{value:c,label:c};}))";
            expect(evalx(expr, { categories: ['notice', 'qna'] })).toEqual([
                { value: 'all', label: '전체' },
                { value: 'notice', label: 'notice' },
                { value: 'qna', label: 'qna' },
            ]);
        });

        it('try/catch 블록 본문 (실제 throw 를 잡음)', () => {
            // 이 평가기는 null 멤버 접근을 관대하게 undefined 로 돌려주므로(with 시맨틱),
            // 실제로 throw 하는 것은 비함수 호출·JSON.parse 오류 등이다.
            expect(evalx('(() => { try { return notFn(); } catch (e) { return "fallback"; } })()', { notFn: 123 })).toBe('fallback');
            expect(evalx("(() => { try { return JSON.parse('{bad'); } catch (e) { return 'invalid'; } })()")).toBe('invalid');
            expect(evalx('(() => { try { return safe; } catch (e) { return "x"; } })()', { safe: 42 })).toBe(42);
        });

        it('IIFE 반환 타입 보존 — 객체/배열/빈문자열/undefined (if 판정 계약)', () => {
            expect(evalx('(function(){ return {}; })()')).toEqual({});
            expect(evalx('(function(){ return []; })()')).toEqual([]);
            expect(evalx('(function(){ return ""; })()')).toBe('');
            expect(evalx('(function(){ return; })()')).toBe(undefined);
        });
    });

    describe('SECURITY — sandbox escape blocked', () => {
        /** @effects sandbox_escape_blocked */
        it("''.constructor.constructor('return 1')()", () => {
            expect(() => evalx("''.constructor.constructor('return 1')()")).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it("''['constructor']['constructor']('return 1')()", () => {
            expect(() => evalx("''['constructor']['constructor']('return 1')()")).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('({}).__proto__', () => {
            expect(() => evalx('({}).__proto__')).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('[].constructor', () => {
            expect(() => evalx('[].constructor')).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('x.prototype', () => {
            expect(() => evalx('x.prototype', { x: {} })).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('Function(...)', () => {
            expect(() => evalx("Function('return 1')")).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('window', () => {
            expect(() => evalx('window')).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('globalThis', () => {
            expect(() => evalx('globalThis')).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('eval', () => {
            expect(() => evalx("eval('1')")).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('computed constructor via variable — eval-time block', () => {
            expect(() => evalx("''[c]", { c: 'constructor' })).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('computed __proto__ via variable — eval-time block', () => {
            expect(() => evalx('o[k]', { o: {}, k: '__proto__' })).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('constructor access inside arrow callback still blocked', () => {
            expect(() => evalx('[1].map(x => x.constructor)')).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('constructor access inside template interpolation still blocked', () => {
            expect(() => evalx("`${''.constructor.constructor('x')()}`")).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('new on non-whitelisted constructor rejected', () => {
            expect(() => evalx('new evil()', { evil: function () {} })).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('new Function still rejected', () => {
            expect(() => evalx("new Function('return 1')")).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('new x() with context function rejected (not whitelisted)', () => {
            expect(() => evalx('new x()', { x: function () {} })).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it("new ''.constructor() rejected", () => {
            expect(() => evalx("new ''.constructor()")).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('assignment rejected', () => {
            expect(() => evalx('x = 1', { x: 0 })).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('increment rejected', () => {
            expect(() => evalx('x++', { x: 0 })).toThrow();
        });

        // engine-v1.60.0: 함수 표현식/블록 본문은 허용된다(해석기 클로저로 실행).
        // 탈출 벡터는 "function 키워드" 가 아니라 `.constructor`/`Function`/`eval` 접근이며,
        // 그 차단은 statement 본문 안에서도 그대로 유지된다.
        /** @effects sandbox_escape_blocked */
        it('함수 표현식 본문에서도 constructor 탈출은 여전히 차단', () => {
            expect(() => evalx('(function(){ return "".constructor; })()')).toThrow();
            expect(() => evalx('(function(){ return [].constructor.constructor("return 1")(); })()')).toThrow();
            expect(() => evalx('(() => { const c = "".constructor; return c; })()')).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('함수 본문에서도 Function/eval 전역 참조는 차단', () => {
            expect(() => evalx('(function(){ return Function("return 1"); })()')).toThrow();
            expect(() => evalx('(() => { return eval("1"); })()')).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('블록 본문 안의 대입식은 여전히 거부', () => {
            expect(() => evalx('(function(){ x = 1; return x; })()', { x: 0 })).toThrow();
            expect(() => evalx('(() => { let y = 0; y = 2; return y; })()')).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('블록 본문 안에서도 비화이트리스트 new 는 차단', () => {
            expect(() => evalx('(function(){ return new evil(); })()', { evil: function () {} })).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('delete 로 화이트리스트 전역 프로퍼티 삭제 차단', () => {
            expect(() => evalx('(function(){ delete Math.floor; return 1; })()')).toThrow();
            expect(() => evalx('(function(){ const o = {}; return delete o.constructor; })()')).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('sequence operator rejected', () => {
            expect(() => evalx('1, 2')).toThrow();
        });
    });

    // KVE-2026-1915 재발 — 비-문자열 computed 키 + Object 리플렉션 static 을 통한
    // 샌드박스 탈출. 기존 SECURITY 스위트는 문자열 키(''[c], c='constructor')만 검증해
    // 아래 벡터들이 81건 green 상태에서도 그대로 실행됐다.
    describe('SECURITY — non-string computed key escape blocked (KVE-2026-1915)', () => {
        /** @effects sandbox_escape_blocked */
        it("''[['constructor']] — 배열 키가 'constructor' 로 강제변환되어 접근되던 결함", () => {
            expect(() => evalx("''[['constructor']]")).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it("''[['constructor']][['constructor']]('return 1')() — 배열 키 RCE 전체 체인", () => {
            expect(() => evalx("''[['constructor']][['constructor']]('return 1')()")).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it("''[['const' + 'ructor']] — 배열 안 문자열 조립 우회", () => {
            expect(() => evalx("''[['const' + 'ructor']]")).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it("''[[['constructor']]] — 중첩 배열 키 우회", () => {
            expect(() => evalx("''[[['constructor']]]")).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('o[[k]] — 컨텍스트 값을 담은 배열 키 우회', () => {
            expect(() => evalx('o[[k]]', { o: {}, k: '__proto__' })).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it("''[{ toString: () => 'constructor' }] — 객체 toString 강제변환 우회 (TOCTOU 없음: 1회 정규화)", () => {
            expect(() => evalx("''[{ toString: () => 'constructor' }]")).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('delete o[[key]] — 배열 키 삭제 경로도 차단', () => {
            expect(() => evalx('(function(){ const o = {}; return delete o[["constructor"]]; })()')).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('정상 computed 접근은 유지 — 배열/숫자/문자열 키', () => {
            expect(evalx("o['a']", { o: { a: 1 } })).toBe(1);
            expect(evalx('arr[0]', { arr: [42] })).toBe(42);
            expect(evalx("o[k]", { o: { name: 'x' }, k: 'name' })).toBe('x');
        });
    });

    describe('SECURITY — Object reflection statics removed (KVE-2026-1915)', () => {
        /** @effects sandbox_escape_blocked */
        it('Object.getPrototypeOf 는 노출되지 않는다', () => {
            expect(() => evalx('Object.getPrototypeOf(String)')).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('Object.getOwnPropertyDescriptor(...).value 리플렉션 RCE 전체 체인', () => {
            expect(() =>
                evalx("Object.getOwnPropertyDescriptor(Object.getPrototypeOf(String), 'constructor').value('return 1')()"),
            ).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('Object.setPrototypeOf 는 노출되지 않는다 (프로토타입 오염 차단)', () => {
            expect(() => evalx('Object.setPrototypeOf({}, {})')).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('Object.defineProperty 는 노출되지 않는다', () => {
            expect(() => evalx("Object.defineProperty({}, 'x', { value: 1 })")).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('Object.getOwnPropertyDescriptors 는 노출되지 않는다', () => {
            expect(() => evalx('Object.getOwnPropertyDescriptors(String)')).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('안전한 Object 데이터 메서드는 유지 — keys/values/entries/assign/fromEntries', () => {
            expect(evalx('Object.keys({ a: 1, b: 2 })')).toEqual(['a', 'b']);
            expect(evalx('Object.values({ a: 1, b: 2 })')).toEqual([1, 2]);
            expect(evalx('Object.entries({ a: 1 })')).toEqual([['a', 1]]);
            expect(evalx('Object.assign({ a: 1 }, { b: 2 })')).toEqual({ a: 1, b: 2 });
            expect(evalx("Object.fromEntries([['a', 1]])")).toEqual({ a: 1 });
        });

        // 회귀 방지: create 는 프로토타입/디스크립터를 읽지도 쓰지도 않으므로 유지된다.
        // 실제 레이아웃(_tab_reviews.json)의 리뷰 옵션 필터 맵 생성 패턴을 고정한다.
        /** @effects sandbox_escape_blocked */
        it('Object.create(null) + assign 은 유지 — 실제 레이아웃 필터 맵 패턴', () => {
            expect(evalx("Object.assign(Object.create(null), { a: '1' }, { b: '2' })")).toEqual({
                a: '1',
                b: '2',
            });
            expect(
                evalx('Object.assign(Object.create(null), base, { [k]: v })', {
                    base: { x: 1 },
                    k: 'y',
                    v: 2,
                }),
            ).toEqual({ x: 1, y: 2 });
        });
    });

    // ==========================================
    // 공유 전역 변조 차단 (delete 가드와 대칭)
    // ==========================================
    //
    // WHITELIST_GLOBALS 는 Math/JSON/Date 등 **실제 전역 참조**를 노출한다. delete 는
    // identity 검사로 이미 차단하지만, facade 에 남긴 assign/freeze 는 대상 객체를
    // 검사하지 않으면 같은 공유 전역을 변조할 수 있다 — 페이지 전체(엔진·모듈·플러그인)에
    // 지속되는 오염이므로 delete 와 동일 강도로 막아야 한다.
    describe('공유 전역 변조 차단', () => {
        /** @effects sandbox_escape_blocked */
        it('Object.assign 으로 화이트리스트 전역을 변조할 수 없다', () => {
            expect(() => evalx('Object.assign(Math, { floor: 1 })')).toThrow();
            expect(() => evalx('Object.assign(JSON, { parse: 1 })')).toThrow();
            expect(Math.floor(1.5)).toBe(1);
        });

        /** @effects sandbox_escape_blocked */
        it('Object.freeze 로 화이트리스트 전역을 동결할 수 없다', () => {
            expect(() => evalx('Object.freeze(Math)')).toThrow();
            expect(Object.isFrozen(Math)).toBe(false);
        });

        /** @effects sandbox_escape_blocked */
        it('화이트리스트 생성자도 동일하게 보호된다', () => {
            expect(() => evalx('Object.assign(Date, { now: 1 })')).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('일반 객체에 대한 assign·freeze 는 정상 동작한다 (과차단 회귀 방지)', () => {
            expect(evalx('Object.assign({ a: 1 }, { b: 2 })')).toEqual({ a: 1, b: 2 });
            expect(evalx("Object.assign(Object.create(null), { a: '1' })")).toEqual({ a: '1' });
            expect(evalx('Object.isFrozen(Object.freeze({ a: 1 }))')).toBe(true);
            expect(evalx('Object.assign(target, { b: 2 })', { target: { a: 1 } })).toEqual({
                a: 1,
                b: 2,
            });
        });
    });

    describe('SECURITY — legacy 접근자를 통한 프로토타입 도달 차단 (KVE-2026-1915)', () => {
        // Object.prototype 위의 legacy 접근자 4종은 프로퍼티를 **키가 아니라 문자열 인자**로
        // 지목하므로 normalizeKey 의 키 검사를 원리상 거치지 않는다. 이는 Object facade 에서
        // getPrototypeOf/setPrototypeOf/defineProperty 를 제거한 것과 같은 능력을 우회 복원한다.
        // 배포 레이아웃 599개에서 이 4개 이름 사용은 0건이라 차단해도 회귀가 없다.

        afterEach(() => {
            // 오염이 실제로 일어났다면 다른 테스트로 번지지 않게 정리한다.
            delete (Object.prototype as Record<string, unknown>).pwned;
            delete (Array.prototype as Record<string, unknown>).pwned;
        });

        it.each([
            ['__lookupGetter__', "({}).__lookupGetter__('__proto__')"],
            ['__lookupSetter__', "({}).__lookupSetter__('__proto__')"],
            ['__defineGetter__', "({}).__defineGetter__('x', function () { return 1; })"],
            ['__defineSetter__', "({}).__defineSetter__('x', function (v) { return v; })"],
        ])('%s 접근이 거부된다', (_name, expr) => {
            expect(() => evalx(expr)).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('문자열 조립으로도 우회할 수 없다 (정적 검사가 못 잡는 형태)', () => {
            expect(() => evalx("({})['__lookup' + 'Getter__']('__pro' + 'to__')")).toThrow();
            expect(() => evalx("({})[['__lookupGetter__']]('__proto__')")).toThrow();
        });

        /** @effects sandbox_escape_blocked */
        it('프로토타입을 손에 넣어 전역을 오염시킬 수 없다', () => {
            // .call 없이 method-position 으로도 thisArg 가 공급되므로 두 형태를 모두 고정한다.
            expect(() =>
                evalx("({}).__lookupGetter__('__pro' + 'to__').call({}).__defineGetter__('pwned', function () { return 1; })")
            ).toThrow();
            expect(() =>
                evalx("({ g: ({}).__lookupGetter__('__pro' + 'to__') }).g()")
            ).toThrow();

            expect((Object.prototype as Record<string, unknown>).pwned).toBeUndefined();
            expect(({} as Record<string, unknown>).pwned).toBeUndefined();
        });

        /** @effects sandbox_escape_blocked */
        it('배열 프로토타입도 동일하게 보호된다', () => {
            expect(() => evalx("[].__lookupGetter__('__pro' + 'to__').call([])")).toThrow();
            expect((Array.prototype as Record<string, unknown>).pwned).toBeUndefined();
            expect([].map).toBeTypeOf('function');
        });

        /** @effects sandbox_escape_blocked */
        it('함수 객체의 프로토타입에도 도달할 수 없다', () => {
            expect(() =>
                evalx("Math.floor.__lookupGetter__('__pro' + 'to__').call(Math.floor)")
            ).toThrow();
            expect(Math.floor(1.5)).toBe(1);
        });

        /** @effects sandbox_escape_blocked */
        it('Object.assign 이 source 의 __proto__ 키로 프로토타입을 바꾸지 못한다', () => {
            // JSON.parse 는 __proto__ 를 own enumerable 데이터 프로퍼티로 만든다.
            // 네이티브 Object.assign 은 [[Set]] 으로 복사해 target 의 __proto__ setter 를 깨운다.
            const state: Record<string, unknown> = { a: 1 };

            // 금지 키는 조용히 건너뛰지 않고 거부한다 — 평가기의 다른 금지 키 처리(normalizeKey)와
            // 같은 강도. 조용한 skip 은 공격 시도를 정상 렌더로 위장한다.
            expect(() =>
                evalx('Object.assign(state, JSON.parse(raw))', {
                    state,
                    raw: '{"__proto__":{"isAdmin":true}}',
                })
            ).toThrow();

            expect(Object.getPrototypeOf(state)).toBe(Object.prototype);
            expect((state as { isAdmin?: boolean }).isAdmin).toBeUndefined();
        });

        /** @effects sandbox_escape_blocked */
        it('정상 표현식은 그대로 통과한다 (과차단 회귀 방지)', () => {
            // 배포 레이아웃이 실제로 쓰는 형태들 — toLocaleString 19곳, Object.assign 34곳, Object.create 1곳
            expect(evalx('(1234.5).toLocaleString()')).toBeTypeOf('string');
            expect(evalx("Object.assign(Object.create(null), { a: '1' })")).toEqual({ a: '1' });
            expect(evalx('Object.assign({ a: 1 }, { b: 2 })')).toEqual({ a: 1, b: 2 });
            expect(evalx('({ a: 1 }).hasOwnProperty("a")')).toBe(true);
        });
    });
});
