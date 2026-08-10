import { describe, it, expect } from 'vitest';
import { DataBindingEngine } from '../DataBindingEngine';

/**
 * 단일 바인딩 식 안에 `}` 가 든 경우(빈 객체 fallback 등)의 파싱 회귀.
 *
 * 종전 `^\{\{([^}]+)\}\}$` 정규식은 `{{error.errors ?? {}}}` 같은 표현식을 단일 바인딩으로
 * 인식하지 못해 원본 문자열이 그대로 컴포넌트로 새어 나갔다. extractSingleBinding 으로
 * 중괄호 균형을 추적해 정확히 단일 식을 추출하도록 수정.
 */
describe('DataBindingEngine — {}}} 닫기 모호성 (단일 바인딩 내부 중괄호)', () => {
  it('{{error.errors ?? {}}} 는 빈 객체 fallback 으로 평가된다 (raw 문자열 누출 금지)', () => {
    const eng = new DataBindingEngine();
    const ctx: any = { error: {} }; // error.errors === undefined → ?? {} fallback
    const out: any = eng.resolveObject({ addressErrors: '{{error.errors ?? {}}}' }, ctx, {});
    expect(out.addressErrors).toEqual({});
    expect(out.addressErrors).not.toBe('{{error.errors ?? {}}}');
  });

  it('{{error.errors ?? { _general: error.message }}} 객체 리터럴 fallback 평가', () => {
    const eng = new DataBindingEngine();
    // 에러 컨텍스트 키는 `error` 다 (ActionDispatcher 가 onError/errorHandling 양쪽에서 이 키로 주입).
    // `$error` 는 엔진이 채우지 않으므로 항상 undefined 가 된다.
    const ctx: any = { error: { errors: undefined, message: '오류' } };
    const out: any = eng.resolveObject(
      { optionErrors: '{{error.errors ?? { _general: error.message }}}' },
      ctx,
      {},
    );
    expect(out.optionErrors).toEqual({ _general: '오류' });
  });

  it('값이 있으면 그대로 객체 반환 (fallback 미발동)', () => {
    const eng = new DataBindingEngine();
    const ctx: any = { error: { errors: { name: 'required' } } };
    const out: any = eng.resolveObject({ e: '{{error.errors ?? {}}}' }, ctx, {});
    expect(out.e).toEqual({ name: 'required' });
  });

  it('단순 바인딩 {{error.errors}} 회귀 없음', () => {
    const eng = new DataBindingEngine();
    const ctx: any = { error: { errors: { x: 1 } } };
    const out: any = eng.resolveObject({ e: '{{error.errors}}' }, ctx, {});
    expect(out.e).toEqual({ x: 1 });
  });

  it('다중 바인딩 "{{a}} {{b}}" 는 단일 바인딩으로 오인하지 않고 문자열 보간', () => {
    const eng = new DataBindingEngine();
    const ctx: any = { a: 'X', b: 'Y' };
    const out: any = eng.resolveObject({ s: '{{a}} {{b}}' }, ctx, {});
    expect(out.s).toBe('X Y');
  });

  /**
   * 파이프 식 안에 중괄호가 든 경우.
   *
   * 종전에는 파이프 분기가 `resolveBindings(`{{...}}`)` 로 위임했는데, 그 안의
   * `BINDING_PATTERN`(`/\{\{([^}]+)\}\}/g`)이 `}` 를 포함한 식을 매칭하지 못해
   * `String.replace` 가 입력을 그대로 돌려주었다. 결과적으로 원본 `{{...}}` 문자열이
   * 화면에 노출됐다. evaluatePipeExpression 직접 호출로 문자열 조립 자체를 없앴다.
   * @since engine-v1.54.9
   */
  describe('파이프 식 내부 중괄호', () => {
    it('{{(row.meta ?? {}) | json}} 가 원본 문자열로 새지 않는다', () => {
      const eng = new DataBindingEngine();
      const ctx: any = { row: {} }; // row.meta === undefined → ?? {} fallback
      const out: any = eng.resolveObject({ meta: '{{(row.meta ?? {}) | json}}' }, ctx, {});
      expect(out.meta).toBe('{}');
      expect(out.meta).not.toContain('{{');
    });

    it('중괄호를 포함한 파이프 식이 값이 있을 때도 정상 평가된다', () => {
      const eng = new DataBindingEngine();
      const ctx: any = { row: { meta: { a: 1 } } };
      const out: any = eng.resolveObject({ meta: '{{(row.meta ?? {}) | json}}' }, ctx, {});
      expect(out.meta).toBe('{"a":1}');
    });

    it('evaluatePipeExpression 직접 호출도 동일하게 평가된다', () => {
      const eng = new DataBindingEngine();
      const ctx: any = { row: { meta: { a: 1 } } };
      expect(eng.evaluatePipeExpression('(row.meta ?? {}) | json', ctx)).toBe('{"a":1}');
    });
  });
});
