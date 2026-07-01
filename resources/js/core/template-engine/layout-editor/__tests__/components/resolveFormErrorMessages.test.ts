/**
 * resolveFormErrorMessages.test.ts — formErrors `$t:` 값 해석 + 파이프 파라미터 분리 회귀 가드
 *
 *
 * 결함 배경: states 의 formErrors 메시지 값 `$t:key|count=8` 을 resolveFormErrorMessages 가
 * `|count=8` 접미사를 분리하지 않고 통째로 키(`key|count=8`)로 translate 에 넘겨 해석 실패 →
 * 캔버스에 raw 키 노출. 본 테스트는 파이프 파라미터가 엔진 params 인자로
 * 올바로 전달되는지, 파라미터 없는 키/평문/배열/객체가 종전대로 처리되는지 가드한다.
 *
 * @since engine-v1.50.0
 */

import { describe, it, expect } from 'vitest';
import { resolveFormErrorMessages } from '../../components/PreviewCanvas';

/** translate 호출 인자를 기록하는 스텁 엔진. key|params 를 그대로 합성 반환. */
function makeEngine() {
  const calls: Array<{ key: string; params?: string }> = [];
  const engine = {
    translate(key: string, _ctx: { templateId: string; locale: string }, params?: string): string {
      calls.push({ key, params });
      // 엔진 동작 모사: params 의 count 값을 키에 끼워 해석 성공을 표현.
      if (params) {
        const m = /count=(\d+)/.exec(params);
        return m ? `해석:${key}(${m[1]})` : `해석:${key}`;
      }
      return `해석:${key}`;
    },
  };
  return { engine, calls };
}

const CTX = { templateId: 'sirsoft-basic', locale: 'ko' };

describe('resolveFormErrorMessages — 파이프 파라미터 분리', () => {
  it('`$t:key|count=8` 의 파이프 접미사를 분리해 params 로 전달한다(raw 키 노출 방지)', () => {
    const { engine, calls } = makeEngine();
    const out = resolveFormErrorMessages(
      { '_local.errors.password': ['$t:auth.register.error.password_min|count=8'] },
      engine,
      CTX,
    );
    expect(calls).toHaveLength(1);
    expect(calls[0].key).toBe('auth.register.error.password_min'); // 키에 파이프 없음
    expect(calls[0].params).toBe('|count=8'); // 선행 `|` 포함 엔진 형식
    expect((out!['_local.errors.password'] as string[])[0]).toBe('해석:auth.register.error.password_min(8)');
  });

  it('파라미터 없는 `$t:key` 는 params 없이 키만 넘긴다', () => {
    const { engine, calls } = makeEngine();
    const out = resolveFormErrorMessages({ '_local.errors.email': '$t:auth.register.error.email_exists' }, engine, CTX);
    expect(calls[0].key).toBe('auth.register.error.email_exists');
    expect(calls[0].params).toBeUndefined();
    expect(out!['_local.errors.email']).toBe('해석:auth.register.error.email_exists');
  });

  it('평문(비-$t:) 값은 그대로 둔다', () => {
    const { engine } = makeEngine();
    const out = resolveFormErrorMessages({ '_local.errors.name': '이름을 입력해 주세요' }, engine, CTX);
    expect(out!['_local.errors.name']).toBe('이름을 입력해 주세요');
  });

  it('배열 값의 각 원소를 해석한다', () => {
    const { engine } = makeEngine();
    const out = resolveFormErrorMessages(
      { '_local.errors.x': ['$t:a.b', '평문', '$t:c.d|count=2'] },
      engine,
      CTX,
    );
    expect(out!['_local.errors.x']).toEqual(['해석:a.b', '평문', '해석:c.d(2)']);
  });

  it('engine 부재 시 입력을 그대로 반환(디그레이드)', () => {
    const map = { '_local.errors.email': '$t:a.b|count=8' };
    expect(resolveFormErrorMessages(map, null, CTX)).toBe(map);
  });

  it('map 부재 시 undefined', () => {
    const { engine } = makeEngine();
    expect(resolveFormErrorMessages(undefined, engine, CTX)).toBeUndefined();
  });
});
