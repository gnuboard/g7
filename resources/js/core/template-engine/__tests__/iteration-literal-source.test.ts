import { describe, it, expect, beforeEach } from 'vitest';
import { DataBindingEngine } from '../DataBindingEngine';
import { resolveIterationSource } from '../helpers/RenderHelpers';

/**
 * 회귀 테스트 — iteration source / evaluateExpression 의 리터럴·내장객체 처리
 *
 * 배경: 본인인증 이력 화면(admin_identity_logs)의 상태/발생위치 필터가
 *   `{{['requested','sent',...]}}` 형태의 배열 리터럴을 iteration source 로
 *   사용했으나 체크박스가 전혀 렌더되지 않았다. 채널 필터는
 *   `{{Array.from(new Set((...).flatMap(...)))}}` 가 빈 배열로 평가되었다.
 *
 * 근본 원인 2종:
 *   ① isComplexExpression 정규식이 `[`/`]`/`{`/`}` 를 인식하지 못해
 *      배열/객체 리터럴이 "단순 경로" 로 오판 → resolve() 경로탐색 → undefined
 *   ② extractVariablesFromExpression 의 reserved 화이트리스트에 Set/Map 누락
 *      → Set 이 컨텍스트 변수로 오인되어 undefined 로 전역 가림 → new undefined() 에러
 */
describe('iteration source / evaluateExpression - 리터럴 및 내장객체 회귀', () => {
  let engine: DataBindingEngine;

  beforeEach(() => {
    engine = new DataBindingEngine();
  });

  describe('resolveIterationSource - 배열/객체 리터럴 (결함 #1/#2)', () => {
    it('문자열 배열 리터럴을 그대로 배열로 반환해야 함', () => {
      const source = "{{['requested','sent','verified','failed','expired','cancelled','policy_violation_logged']}}";
      const result = resolveIterationSource(source, {}, engine);
      expect(Array.isArray(result)).toBe(true);
      expect(result).toEqual([
        'requested',
        'sent',
        'verified',
        'failed',
        'expired',
        'cancelled',
        'policy_violation_logged',
      ]);
    });

    it('숫자 배열 리터럴을 그대로 배열로 반환해야 함', () => {
      const result = resolveIterationSource('{{[1, 2, 3, 4, 5]}}', {}, engine);
      expect(result).toEqual([1, 2, 3, 4, 5]);
    });

    it('발생위치(origin_type) 문자열 배열 리터럴을 반환해야 함', () => {
      const source = "{{['route','hook','policy','middleware','api','custom','system']}}";
      const result = resolveIterationSource(source, {}, engine);
      expect(Array.isArray(result)).toBe(true);
      expect(result).toHaveLength(7);
      expect(result[0]).toBe('route');
    });

    it('객체 리터럴 배열도 그대로 반환해야 함', () => {
      const source = "{{[{ id: 'a', label: 'A' }, { id: 'b', label: 'B' }]}}";
      const result = resolveIterationSource(source, {}, engine);
      expect(result).toEqual([
        { id: 'a', label: 'A' },
        { id: 'b', label: 'B' },
      ]);
    });

    it('기존 동작 회귀 방지 - 단순 경로는 그대로 resolve', () => {
      const context = { items: { data: [{ id: 1 }, { id: 2 }] } };
      const result = resolveIterationSource('{{items.data}}', context, engine);
      expect(result).toEqual([{ id: 1 }, { id: 2 }]);
    });

    it('기존 동작 회귀 방지 - 복잡한 표현식(?? [])은 그대로 평가', () => {
      const context = { roles: { data: [{ id: 1 }] } };
      expect(resolveIterationSource('{{roles?.data ?? []}}', context, engine)).toEqual([{ id: 1 }]);
      expect(resolveIterationSource('{{missing?.data ?? []}}', {}, engine)).toEqual([]);
    });
  });

  describe('evaluateExpression - Set/Map 내장객체 (결함 #3)', () => {
    it('Array.from(new Set([...])) 로 중복 제거 배열을 반환해야 함', () => {
      const result = engine.evaluateExpression(
        "Array.from(new Set(['sms', 'email', 'sms', 'app']))",
        {},
      );
      expect(result).toEqual(['sms', 'email', 'app']);
    });

    it('컨텍스트 데이터에서 flatMap + Set 으로 채널 후보를 도출해야 함', () => {
      const context = {
        identityProviders: {
          data: [
            { id: 'mail', channels: ['email'] },
            { id: 'kginicis', channels: ['sms', 'app'] },
            { id: 'dup', channels: ['email', 'sms'] },
          ],
        },
      };
      const result = engine.evaluateExpression(
        'Array.from(new Set((identityProviders?.data ?? []).flatMap(p => Array.isArray(p.channels) ? p.channels : [])))',
        context,
      );
      expect(result).toEqual(['email', 'sms', 'app']);
    });

    it('new Map() 도 평가 가능해야 함', () => {
      const result = engine.evaluateExpression(
        "new Map([['a', 1], ['b', 2]]).get('b')",
        {},
      );
      expect(result).toBe(2);
    });

    it('기존 내장객체(Array/Object/Math) 동작 회귀 방지', () => {
      expect(engine.evaluateExpression('Array.isArray([1,2])', {})).toBe(true);
      expect(engine.evaluateExpression('Object.keys({ a: 1, b: 2 }).length', {})).toBe(2);
      expect(engine.evaluateExpression('Math.max(3, 7, 1)', {})).toBe(7);
    });
  });

  /**
   * 전수조사 회귀 방지 — 순수 숫자 대괄호 인덱싱 (`items[0]`, `entry[1]`)
   *
   * 정규식에 `[` 를 추가하면 `?.`/`&&` 등 다른 연산자 없이 대괄호만 가진
   * 순수 인덱싱 표현식이 resolve() 경로에서 evaluateExpression 경로로 이동한다.
   * 코드베이스 전수조사에서 발견한 실제 사용 패턴(_isolated.selectedCategories[0],
   * nameEntry[1], $args[0].url)이 변경 전후 동일 결과를 내야 한다.
   *
   * 따옴표 키 인덱싱(`obj['key']`)은 현재 resolve() 의 PATH_SEGMENT_PATTERN
   * (`\[(\d+)\]`, 숫자 인덱스만 매칭)으로 처리 불가하므로 if/source 에
   * 순수 단독으로는 존재하지 않는다(존재 시 이미 깨져 있음). 따라서 본
   * 회귀 테스트는 실제로 동작 중이던 숫자 인덱싱을 보호한다.
   */
  describe('전수조사 회귀 - 순수 대괄호 인덱싱 경로 이동 무영향', () => {
    it('변수[숫자] - resolve 경로와 evaluateExpression 경로 결과 일치', () => {
      const context = {
        rows: [{ id: 'a' }, { id: 'b' }, { id: 'c' }],
      };
      // resolve 경로 (변경 전 동작)
      const viaResolve = engine.resolve('rows[1]', context, { skipCache: true });
      // evaluateExpression 경로 (변경 후 동작)
      const viaEval = engine.evaluateExpression('rows[1]', context);
      expect(viaResolve).toEqual({ id: 'b' });
      expect(viaEval).toEqual({ id: 'b' });
      expect(viaEval).toEqual(viaResolve);
    });

    it('중첩 대괄호 인덱싱 (selectedCategories[0]) 결과 일치', () => {
      const context = {
        _isolated: { selectedCategories: [{ children: [1, 2] }, { children: [] }] },
      };
      expect(engine.resolve('_isolated.selectedCategories[0]', context, { skipCache: true })).toEqual({
        children: [1, 2],
      });
      expect(engine.evaluateExpression('_isolated.selectedCategories[0]', context)).toEqual({
        children: [1, 2],
      });
    });

    it('대괄호 인덱싱 후 속성 접근 ($args[0].url) 결과 일치', () => {
      const context = { $args: [{ url: '/admin/home' }] };
      expect(engine.resolve('$args[0].url', context, { skipCache: true })).toBe('/admin/home');
      expect(engine.evaluateExpression('$args[0].url', context)).toBe('/admin/home');
    });

    it('Object.entries 결과 항목 인덱싱 (nameEntry[1]) if 조건 평가', () => {
      // _panel_view.json 의 "if": "{{nameEntry[1]}}" 실제 패턴
      const context = { nameEntry: ['ko', '한국어'] };
      // if 조건은 Boolean 캐스팅 — 값이 truthy 면 렌더
      expect(Boolean(engine.evaluateExpression('nameEntry[1]', context))).toBe(true);
      const emptyContext = { nameEntry: ['ko', ''] };
      expect(Boolean(engine.evaluateExpression('nameEntry[1]', emptyContext))).toBe(false);
    });

    it('범위 밖 인덱스는 양 경로 모두 undefined', () => {
      const context = { rows: [{ id: 'a' }] };
      expect(engine.resolve('rows[5]', context, { skipCache: true })).toBeUndefined();
      expect(engine.evaluateExpression('rows[5]', context)).toBeUndefined();
    });

    it('스프레드 + 배열 리터럴 결합 (의존성 병합) iteration source', () => {
      // _tab_user.json 의 "source": "{{[...(row.dependencies?.modules || []), ...(row.dependencies?.plugins || [])]}}"
      const context = {
        row: { dependencies: { modules: ['m1', 'm2'], plugins: ['p1'] } },
      };
      const source =
        '{{[...(row.dependencies?.modules || []), ...(row.dependencies?.plugins || [])]}}';
      const result = resolveIterationSource(source, context, engine);
      expect(result).toEqual(['m1', 'm2', 'p1']);
    });
  });

  describe('resolveIterationSource + Set 결합 (채널 필터 실제 경로)', () => {
    it('Set 기반 채널 후보 배열을 iteration source 로 반환해야 함', () => {
      const context = {
        identityProviders: {
          data: [
            { id: 'mail', channels: ['email'] },
            { id: 'kginicis', channels: ['sms', 'app', 'email'] },
          ],
        },
      };
      const source =
        '{{Array.from(new Set((identityProviders?.data ?? []).flatMap(p => Array.isArray(p.channels) ? p.channels : [])))}}';
      const result = resolveIterationSource(source, context, engine);
      expect(Array.isArray(result)).toBe(true);
      expect(result).toEqual(['email', 'sms', 'app']);
    });
  });
});
