/**
 * 조건 표현식 샌드박스 단위 테스트 (KVE-2026-1915)
 *
 * `layout-expression-sandbox.spec.ts`(Playwright) 가 배포 번들의 공개 API
 * `G7Core.evaluateCondition` 로 잠그는 `window.__g7jsi` canary 축을, 그 API 가 실제로
 * 호출하는 함수 `evaluateStringCondition` 을 통해 단위 레벨에서 고정한다. E2E 미실행
 * 환경에서도 "위험 표현식은 코드를 실행하지 못한다(전역 canary 미설정)" 축이 검증된다.
 *
 * scripts[].if / if / classMap 등 레이아웃 조건은 전부 이 함수를 경유하므로, 이 canary 가
 * 설정되면 렌더 경로 어디서든 샌드박스가 뚫린 것이다.
 *
 * 효과 요약(마커 아님 — 평문): sandbox_escape_blocked, dangerous_payload_does_not_set_global.
 * 실제 마커는 그 효과를 단언하는 개별 테스트에만 둔다 — 파일 레벨에 몰아 적으면 테스트를
 * 전부 지워도 커버리지가 green 으로 남는다.
 */
import { describe, it, expect, afterEach } from 'vitest';
import { evaluateStringCondition } from '../helpers/ConditionEvaluator';
import { DataBindingEngine } from '../DataBindingEngine';

const CANARY = '__g7jsi';

describe('조건 표현식 샌드박스 — window.__g7jsi canary (KVE-2026-1915)', () => {
  const engine = new DataBindingEngine();

  afterEach(() => {
    delete (globalThis as any)[CANARY];
    delete (window as any)[CANARY];
  });

  it('정상 조건(삼항·nullish·비교)은 렌더 경로에서 정상 평가된다', () => {
    const ctx = { user: { name: '홍길동' }, count: 2 } as any;
    expect(evaluateStringCondition('{{user?.name ? true : false}}', ctx, engine)).toBe(true);
    expect(
      evaluateStringCondition('{{(user?.missing ?? "fallback") === "fallback"}}', ctx, engine)
    ).toBe(true);
    expect(evaluateStringCondition('{{count > 1}}', ctx, engine)).toBe(true);
  });

  /** @effects sandbox_escape_blocked, dangerous_payload_does_not_set_global */
  it('constructor 체인 익스플로잇은 코드를 실행하지 못한다(canary 미설정 + 거부)', () => {
    delete (globalThis as any)[CANARY];
    delete (window as any)[CANARY];

    const payloads = [
      "{{''.constructor.constructor('window.__g7jsi = 1')()}}",
      "{{''['constructor']['constructor']('window.__g7jsi = 2')()}}",
      '{{({}).__proto__}}',
      "{{Function('window.__g7jsi = 3')()}}",
      "{{eval('window.__g7jsi = 4')}}",
    ];

    const results = payloads.map((p) => {
      try {
        return evaluateStringCondition(p, {} as any, engine);
      } catch {
        return false;
      }
    });

    // 익스플로잇 코드가 실행되지 않았다 — 전역 canary 미설정
    expect((globalThis as any)[CANARY]).toBeUndefined();
    expect((window as any)[CANARY]).toBeUndefined();
    // 위험 표현식은 truthy 로 평가되지 않는다(거부)
    expect(results.every((r) => r === false)).toBe(true);
  });

  it('화살표 함수·템플릿 리터럴 정상 표현식은 통과한다(과차단 회귀 방지)', () => {
    const ctx = { items: [{ v: 'a' }, { v: 'b' }, { v: 'a' }], $args: ['x'] } as any;
    expect(
      evaluateStringCondition("{{(items ?? []).filter(i => i.v === 'a').length === 2}}", ctx, engine)
    ).toBe(true);
    expect(
      evaluateStringCondition('{{`/mypage/${$args[0]}` === "/mypage/x"}}', ctx, engine)
    ).toBe(true);
  });
});
