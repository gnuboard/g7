/**
 * E2E: 레이아웃 표현식 평가 샌드박스 (배포 번들 기준, KVE-2026-1915)
 *
 * 배경: 표현식 평가가 `new Function`/`with(ctx)` 로 실행되어
 * `''.constructor.constructor('code')()` 형태의 샌드박스 탈출이 가능했다.
 * engine-v1.59.0 에서 화이트리스트 AST 평가기(SafeExpressionEvaluator)로 교체했다.
 *
 * 단위 테스트(SafeExpressionEvaluator.test.ts)가 소스 레벨을 잠그지만, 실제 사용자가
 * 실행하는 것은 빌드된 번들이므로 브라우저에서 배포 번들의 공개 API(`G7Core.evaluateCondition`)를
 * 그대로 호출해 (a) 정상 표현식 회귀 없음 + (b) 익스플로잇 미실행 양면을 잠근다.
 *
 * 시나리오 축(case)·효과는 매니페스트 tests/scenarios/layout-expression-sandbox.yaml 참조.
 * 각 test 의 `// @scenario case=…` 라인 마커가 축 조합을, `// @effects …` 가 효과를 커버한다.
 *
 * 효과 목록을 이 파일 레벨에 몰아 적지 않는다 — 커버리지 룰은 마커 레벨을 구분하지 않으므로,
 * 파일 레벨 목록이 있으면 해당 test 를 지워도 효과가 "언급됨" 으로 집계돼 삭제가 무증상 green
 * 이 된다. 마커는 test 에만 둔다.
 */
import { test, expect } from '../fixtures/auth';

test.describe('레이아웃 표현식 평가 샌드박스', () => {
  test.beforeEach(async ({ page }) => {
    // 코어 번들이 부팅된 화면이면 충분하다(로그인 화면도 엔진을 부팅한다).
    await page.goto('/admin/login');
    await page.waitForFunction(
      () => typeof (window as any).G7Core?.evaluateCondition === 'function',
      null,
      { timeout: 30_000 },
    );
  });

  // @scenario case=safe_expression
  // @effects same_expression_same_value_across_paths
  test('정상 표현식(삼항·옵셔널체이닝·nullish)이 배포 번들에서 정상 평가된다', async ({ page }) => {
    const result = await page.evaluate(() => {
      const g7 = (window as any).G7Core;
      const ctx = { user: { name: '홍길동' }, count: 2 };
      return {
        ternary: g7.evaluateCondition('{{user?.name ? true : false}}', ctx),
        nullish: g7.evaluateCondition('{{(user?.missing ?? "fallback") === "fallback"}}', ctx),
        compare: g7.evaluateCondition('{{count > 1}}', ctx),
      };
    });

    expect(result.ternary).toBe(true);
    expect(result.nullish).toBe(true);
    expect(result.compare).toBe(true);
  });

  // @scenario case=arrow_template_literal
  // @effects same_expression_same_value_across_paths
  test('화살표 함수·스프레드·템플릿 리터럴·new Date 가 배포 번들에서 동작한다', async ({ page }) => {
    const result = await page.evaluate(() => {
      const g7 = (window as any).G7Core;
      const ctx = { items: [{ v: 'a' }, { v: 'b' }, { v: 'a' }], $args: ['x'] };
      return {
        // 화살표 함수 + includes
        arrow: g7.evaluateCondition("{{(items ?? []).filter(i => i.v === 'a').length === 2}}", ctx),
        // 스프레드 + Set + Array.from(new Set(...))
        spreadSet: g7.evaluateCondition("{{Array.from(new Set((items ?? []).map(i => i.v))).length === 2}}", ctx),
        // 템플릿 리터럴 (내비게이션 경로 조립 형태)
        template: g7.evaluateCondition('{{`/mypage/${$args[0]}` === "/mypage/x"}}', ctx),
        // new Date
        date: g7.evaluateCondition('{{new Date("2020-01-01").getTime() > 0}}', ctx),
      };
    });

    expect(result.arrow).toBe(true);
    expect(result.spreadSet).toBe(true);
    expect(result.template).toBe(true);
    expect(result.date).toBe(true);
  });

  // @scenario case=destructuring_param
  // @effects same_expression_same_value_across_paths
  test('화살표 파라미터 배열 구조분해(([k, v])·홀)가 배포 번들에서 동작한다 (engine-v1.60.5 회귀)', async ({ page }) => {
    const result = await page.evaluate(() => {
      const g7 = (window as any).G7Core;
      const ctx = {
        sel: { 5: 50, 6: null },
        perms: { 'admin.manage': true, read: true, write: false },
        errors: { name: ['이름은 필수입니다'] },
      };
      return {
        // _purchase_card.json 장바구니/바로구매 본문 형태 — 홀 + 쌍 분해
        hole: g7.evaluateCondition('{{Object.entries(sel ?? {}).filter(([, vid]) => vid != null).length === 1}}', ctx),
        pair: g7.evaluateCondition('{{Object.entries(sel ?? {}).filter(([, v]) => v != null).map(([gid, vid]) => Number(gid) + Number(vid)).includes(55)}}', ctx),
        single: g7.evaluateCondition('{{Object.entries(sel ?? {}).map(([k]) => k).includes("5")}}', ctx),
        // 게시판 환경설정 권한 computed 형태: filter([key]) + startsWith + map([key])
        entriesFilterMap: g7.evaluateCondition(
          "{{Object.entries(perms ?? {}).filter(([key]) => !key.startsWith('admin.')).map(([key]) => key).length === 2}}",
          ctx,
        ),
        // 검증 오류 표시 형태: ([field, messages])
        fieldMessages: g7.evaluateCondition(
          "{{Object.entries(errors ?? {}).map(([field, messages]) => field + ':' + messages[0])[0] === 'name:이름은 필수입니다'}}",
          ctx,
        ),
      };
    });

    expect(result.hole).toBe(true);
    expect(result.pair).toBe(true);
    expect(result.single).toBe(true);
    expect(result.entriesFilterMap).toBe(true);
    expect(result.fieldMessages).toBe(true);
  });

  // @scenario case=constructor_escape
  // @effects sandbox_escape_blocked, dangerous_payload_does_not_set_global
  test('constructor 체인 샌드박스 탈출이 코드를 실행하지 못한다', async ({ page }) => {
    const result = await page.evaluate(() => {
      const g7 = (window as any).G7Core;
      // 익스플로잇: 성공하면 window.__g7jsi 전역이 설정된다.
      delete (window as any).__g7jsi;
      const payloads = [
        "{{''.constructor.constructor('window.__g7jsi = 1')()}}",
        "{{''['constructor']['constructor']('window.__g7jsi = 2')()}}",
        '{{({}).__proto__}}',
        "{{Function('window.__g7jsi = 3')()}}",
      ];
      // 각 페이로드를 평가 — 평가기는 거부(예외→false)해야 하며 코드는 실행되지 않아야 한다.
      const results = payloads.map((p) => {
        try {
          return g7.evaluateCondition(p, {});
        } catch {
          return false;
        }
      });
      return {
        globalSet: (window as any).__g7jsi !== undefined,
        allBlocked: results.every((r) => !r),
      };
    });

    // 익스플로잇 코드가 실행되지 않았다(전역 canary 미설정).
    expect(result.globalSet).toBe(false);
    // 위험 표현식은 truthy 로 평가되지 않는다(거부).
    expect(result.allBlocked).toBe(true);
  });

  // @scenario case=nonstring_key_escape
  // @effects sandbox_escape_blocked, dangerous_payload_does_not_set_global
  test('비-문자열 computed 키·Object 리플렉션 탈출이 배포 번들에서 코드를 실행하지 못한다', async ({ page }) => {
    const result = await page.evaluate(() => {
      const g7 = (window as any).G7Core;
      delete (window as any).__g7jsi;
      const payloads = [
        // 배열 키가 ToPropertyKey 강제변환으로 constructor 도달 (engine-v1.60.1 이전 우회)
        "{{''[['constructor']][['constructor']]('window.__g7jsi = 1')()}}",
        // 문자열 조립 난독화 — 정적 룰이 못 잡는 형태(런타임 정규화가 최종 게이트)
        "{{''[['const' + 'ructor']][['const' + 'ructor']]('window.__g7jsi = 2')()}}",
        // 객체 toString 강제변환 키
        "{{''[{ toString: () => 'constructor' }]}}",
        // Object 리플렉션 static 으로 Function 도달
        "{{Object.getOwnPropertyDescriptor(Object.getPrototypeOf(String), 'constructor').value('window.__g7jsi = 3')()}}",
        // 프로토타입 오염 시도
        '{{Object.setPrototypeOf({}, { polluted: 1 })}}',
      ];
      const results = payloads.map((p) => {
        try {
          return g7.evaluateCondition(p, {});
        } catch {
          return false;
        }
      });
      return {
        globalSet: (window as any).__g7jsi !== undefined,
        allBlocked: results.every((r) => !r),
      };
    });

    expect(result.globalSet).toBe(false);
    expect(result.allBlocked).toBe(true);
  });

  // @scenario case=object_facade_safe
  // @effects same_expression_same_value_across_paths
  test('안전한 Object 데이터 메서드(keys/assign/create)가 배포 번들에서 정상 동작한다', async ({ page }) => {
    const result = await page.evaluate(() => {
      const g7 = (window as any).G7Core;
      const ctx = { o: { a: 1, b: 2 }, base: { x: 1 }, k: 'y', v: 2 };
      return {
        keys: g7.evaluateCondition("{{Object.keys(o).join(',') === 'a,b'}}", ctx),
        // 실제 레이아웃(_tab_reviews.json)의 필터 맵 생성 패턴
        createAssign: g7.evaluateCondition('{{Object.assign(Object.create(null), base, { [k]: v }).y === 2}}', ctx),
      };
    });

    expect(result.keys).toBe(true);
    expect(result.createAssign).toBe(true);
  });
});
