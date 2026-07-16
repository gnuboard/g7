/**
 * actionRecipeEngine.test.ts — 친화 액션 레시피 → 핸들러 JSON DataProvider
 *
 * 코어 핸들러 규칙(API/핸들러 호출) 전수 회귀 가드 포함:
 *  - apiCall(api 아님)/navigate(nav 아님)/setState+target/toast(showToast 아님)/
 *    refetchDataSource.dataSourceId(id 아님).
 *  - apiCall 의 target/onSuccess/onError 는 액션 top-level.
 *  - 중첩 action-list onSuccess 조립.
 */

import { describe, it, expect, vi } from 'vitest';
import {
  normalizeActionRecipes,
  buildAction,
  matchAction,
  assertHandlerRules,
  resolveActionCard,
  summarizeAction,
  withActionIf,
  extractActionIf,
} from '../../spec/actionRecipeEngine';

const RECIPES = {
  comment: '메모는 레시피가 아님',
  goToPage: {
    label: '$t:editor.action.go_to_page.label',
    params: [{ key: 'url', widget: 'page-picker' }],
    build: { handler: 'navigate', params: { path: '{{url}}' } },
  },
  showMessage: {
    label: '$t:editor.action.show_message.label',
    params: [{ key: 'text', widget: 'i18n-text' }, { key: 'tone', widget: 'select' }],
    build: { handler: 'toast', params: { message: '{{text}}', type: '{{tone}}' } },
  },
  changeState: {
    label: 'change',
    params: [{ key: 'key', widget: 'state-key-picker' }, { key: 'value', widget: 'text' }],
    build: { handler: 'setState', target: 'local', params: { '{{key}}': '{{value}}' } },
  },
  refreshData: {
    label: 'refresh',
    params: [{ key: 'src', widget: 'datasource-picker' }],
    build: { handler: 'refetchDataSource', params: { dataSourceId: '{{src}}' } },
  },
  callServerThen: {
    label: 'call',
    params: [
      { key: 'endpoint', widget: 'text' },
      { key: 'onSuccess', widget: 'action-list' },
      { key: 'onError', widget: 'action-list' },
    ],
    build: { handler: 'apiCall', target: '{{endpoint}}', onSuccess: '{{onSuccess}}', onError: '{{onError}}' },
  },
};

describe('normalizeActionRecipes', () => {
  it('comment 메타 키를 레시피에서 제외한다', () => {
    const recipes = normalizeActionRecipes(RECIPES);
    expect(recipes.map((r) => r.id)).toEqual(['goToPage', 'showMessage', 'changeState', 'refreshData', 'callServerThen']);
  });

  it('S6-2 단축형(label+handler)을 빈 params + build 로 흡수한다', () => {
    const recipes = normalizeActionRecipes({ x: { label: 'x', handler: 'toast' } });
    expect(recipes[0]).toMatchObject({ id: 'x', params: [], build: { handler: 'toast' } });
  });

  it('핸들러 없는 레시피는 무시한다', () => {
    const recipes = normalizeActionRecipes({ bad: { label: 'bad' } });
    expect(recipes).toEqual([]);
  });
});

describe('buildAction — 코어 핸들러 규칙 준수', () => {
  const recipes = normalizeActionRecipes(RECIPES);
  const byId = (id: string) => recipes.find((r) => r.id === id)!;

  it('goToPage → navigate (nav 아님) + path 치환', () => {
    const a = buildAction(byId('goToPage'), { url: '/board' });
    expect(a).toEqual({ handler: 'navigate', params: { path: '/board' } });
  });

  it('showMessage → toast (showToast 아님) + message/type', () => {
    const a = buildAction(byId('showMessage'), { text: '저장되었습니다', tone: 'success' });
    expect(a).toEqual({ handler: 'toast', params: { message: '저장되었습니다', type: 'success' } });
  });

  it('changeState → setState + target top-level (params 밖)', () => {
    const a = buildAction(byId('changeState'), { key: 'activeTab', value: 'detail' });
    expect(a.handler).toBe('setState');
    expect(a.target).toBe('local');
    expect(a.params).toEqual({ activeTab: 'detail' });
  });

  it('refreshData → refetchDataSource + dataSourceId (id 아님)', () => {
    const a = buildAction(byId('refreshData'), { src: 'recent_posts' });
    expect(a).toEqual({ handler: 'refetchDataSource', params: { dataSourceId: 'recent_posts' } });
  });

  it('callServerThen → apiCall + target/onSuccess/onError 가 top-level', () => {
    const onSuccess = [{ handler: 'toast', params: { message: 'ok' } }];
    const a = buildAction(byId('callServerThen'), { endpoint: 'admin/users', onSuccess, onError: [] });
    expect(a.handler).toBe('apiCall');
    expect(a.target).toBe('admin/users');
    expect(a.onSuccess).toEqual(onSuccess);
    // 빈 onError 는 떨군다
    expect('onError' in a).toBe(false);
    // params 안에 target/onSuccess/onError 가 들어가지 않음
    expect(a.params).toBeUndefined();
  });

  it('중첩 action-list — onSuccess 안에 N개 액션 배열 조립', () => {
    const onSuccess = [
      { handler: 'toast', params: { message: 'saved' } },
      { handler: 'refetchDataSource', params: { dataSourceId: 'list' } },
    ];
    const a = buildAction(byId('callServerThen'), { endpoint: 'x', onSuccess, onError: [] });
    expect(Array.isArray(a.onSuccess)).toBe(true);
    expect((a.onSuccess as unknown[]).length).toBe(2);
  });

  it('미입력 파라미터는 깔끔히 떨군다 (빈 path)', () => {
    const a = buildAction(byId('goToPage'), {});
    expect(a).toEqual({ handler: 'navigate' });
  });
});

describe('assertHandlerRules — 금지 별칭 교정', () => {
  it.each([
    ['api', 'apiCall'],
    ['nav', 'navigate'],
    ['showToast', 'toast'],
    ['setLocalState', 'setState'],
  ])('%s → %s 로 교정 + 경고', (bad, good) => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const out = assertHandlerRules({ handler: bad });
    expect(out.handler).toBe(good);
    warn.mockRestore();
  });

  it('올바른 핸들러는 그대로', () => {
    expect(assertHandlerRules({ handler: 'navigate' }).handler).toBe('navigate');
  });
});

describe('matchAction — 역해석', () => {
  const recipes = normalizeActionRecipes(RECIPES);

  it('navigate 액션 → goToPage 레시피 + url 복원', () => {
    const m = matchAction({ handler: 'navigate', params: { path: '/board' } }, recipes);
    expect(m?.recipeId).toBe('goToPage');
    expect(m?.values.url).toBe('/board');
  });

  it('toast 액션 → showMessage + text/tone 복원', () => {
    const m = matchAction({ handler: 'toast', params: { message: 'hi', type: 'error' } }, recipes);
    expect(m?.recipeId).toBe('showMessage');
    expect(m?.values).toMatchObject({ text: 'hi', tone: 'error' });
  });

  it('apiCall 액션 → callServerThen + onSuccess 배열 복원', () => {
    const onSuccess = [{ handler: 'toast', params: { message: 'ok' } }];
    const m = matchAction({ handler: 'apiCall', target: 'x', onSuccess }, recipes);
    expect(m?.recipeId).toBe('callServerThen');
    expect(m?.values.endpoint).toBe('x');
    expect(m?.values.onSuccess).toEqual(onSuccess);
  });

  it('알 수 없는 핸들러 → null (고급 동작)', () => {
    expect(matchAction({ handler: 'customUnknown' }, recipes)).toBeNull();
  });
});

// ── 회귀: recipe build 의 sole-binding 키가 실제 액션에 없어도 매칭 유지 ──
// 배경: 결제하기 sequence 안의 apiCall 이 query 미사용·body 만 쓰는데, apiCall recipe build
// 의 params 는 { method, body, query } 상위집합 스키마다. 실제 액션 params 에 query 가 없으면
// extractValues 의 중첩 객체 walk 가 "틀에 있으나 실제에 없는 키" 를 매칭 실패(return false)로
// 떨어뜨려 → advanced("알 수 없는 동작") 강등됐다. sole-binding 키(`{{paramKey}}`)는 그
// 파라미터 "미입력" 일 뿐 매칭 실패가 아니므로, 실제에 없어도 허용해야 한다.
describe('matchAction — recipe build 상위집합 스키마(미사용 sole-binding 키) 회귀', () => {
  // 실제 코어 apiCall recipe 와 동형: params 가 { method, body, query } 상위집합.
  const APICALL = {
    apiCall: {
      label: '$t:layout_editor.action_recipe.api_call.label',
      params: [
        { key: 'target', widget: 'page-picker' },
        { key: 'method', widget: 'select' },
        { key: 'body', widget: 'key-value' },
        { key: 'query', widget: 'key-value' },
        { key: 'onSuccess', widget: 'action-list' },
        { key: 'onError', widget: 'action-list' },
      ],
      build: {
        handler: 'apiCall',
        target: '{{target}}',
        identity_target: { email: '{{identity_target_email}}', phone: '{{identity_target_phone}}' },
        onSuccess: '{{onSuccess}}',
        onError: '{{onError}}',
        params: { method: '{{method}}', body: '{{body}}', query: '{{query}}' },
      },
    },
  };
  const recipes = normalizeActionRecipes(APICALL);

  it('query 미사용(body 만) apiCall 도 apiCall recipe 로 매칭된다 (advanced 강등 금지)', () => {
    // 결제하기 sequence 안 apiCall 과 동형: params 에 query 없음, body 만 존재.
    const action = {
      handler: 'apiCall',
      target: '/api/modules/sirsoft-ecommerce/user/orders',
      params: {
        method: 'POST',
        body: { temp_order_id: '{{checkoutData.data.temp_order_id}}' },
      },
      onSuccess: [{ handler: 'navigate', params: { path: '/x' } }],
    };
    const m = matchAction(action, recipes);
    expect(m?.recipeId).toBe('apiCall');
    expect(m?.values.target).toBe('/api/modules/sirsoft-ecommerce/user/orders');
    expect(m?.values.method).toBe('POST');
    expect(m?.values.body).toEqual({ temp_order_id: '{{checkoutData.data.temp_order_id}}' });
  });

  it('실제 액션 params 에 build 미선언 키(headers/auth_mode 등)가 있어도 매칭 유지', () => {
    // 실데이터엔 build 가 모르는 headers 가 더 있다 — 매칭을 깨면 안 된다.
    const action = {
      handler: 'apiCall',
      target: '/x',
      auth_mode: 'optional',
      params: {
        method: 'POST',
        headers: { 'X-Cart-Key': '{{_global.cartKey}}' },
        body: { a: 1 },
      },
    };
    const m = matchAction(action, recipes);
    expect(m?.recipeId).toBe('apiCall');
    expect(m?.values.method).toBe('POST');
  });

  it('params 자체가 없는 apiCall(target 만)도 매칭 유지', () => {
    const m = matchAction({ handler: 'apiCall', target: '/ping' }, recipes);
    expect(m?.recipeId).toBe('apiCall');
    expect(m?.values.target).toBe('/ping');
  });
});

// ── 결제 진입 recipe — placeholder 핸들러 (provider-agnostic 동적 dispatch) ──

describe('requestPgPayment — placeholder 핸들러 build/match 라운드트립', () => {
  // 코어 "결제 진입" recipe 형태(build.handler 가 placeholder). matchAction placeholder-aware
  // 가드를 검증한다. 결제 데이터 칸은 응답값 칩.
  const PG_RECIPES = {
    ...RECIPES,
    requestPgPayment: {
      label: '$t:layout_editor.action_recipe.request_pg_payment.label',
      params: [
        { key: 'paymentHandler', widget: 'data-chip', required: true },
        { key: 'pgPaymentData', widget: 'data-chip', required: true },
      ],
      build: { handler: '{{paymentHandler}}', params: { pgPaymentData: '{{pgPaymentData}}' } },
    },
  };
  const recipes = normalizeActionRecipes(PG_RECIPES);

  it('build — 핸들러명을 입력값으로 치환한다 (응답 칩)', () => {
    const recipe = recipes.find((r) => r.id === 'requestPgPayment')!;
    const action = buildAction(recipe, {
      paymentHandler: '{{response.data.pg_payment_handler}}',
      pgPaymentData: '{{response.data.pg_payment_data}}',
    });
    expect(action.handler).toBe('{{response.data.pg_payment_handler}}');
    expect((action.params as any).pgPaymentData).toBe('{{response.data.pg_payment_data}}');
  });

  // 회귀 — "+동작 추가" 로 결제 진입을 고르면 ActionAddPicker 가 buildAction(recipe, {}) 로 빈 값
  // 빌드한다. placeholder 핸들러({{paymentHandler}})가 빈 값에서 undefined 로 떨어져 사라지면
  // handler 없는 액션이 되어 "알 수 없는 동작"(advanced)으로 강등된다(제보 회귀). 빈 빌드에서도
  // handler placeholder 는 보존돼야 추가 직후 친화 카드로 매칭되고 사용자가 칩으로 채울 수 있다.
  it('빈 값 빌드(+동작 추가) — placeholder 핸들러가 보존되어 handler 가 사라지지 않는다', () => {
    const recipe = recipes.find((r) => r.id === 'requestPgPayment')!;
    const action = buildAction(recipe, {});
    // handler 가 placeholder 토큰으로 남아야 한다(빈 미입력이라도 키 자체는 보존).
    expect(typeof action.handler).toBe('string');
    expect(action.handler).toBe('{{paymentHandler}}');
  });

  it('빈 값 빌드 결과가 곧바로 requestPgPayment 친화 카드로 역매칭된다(advanced 강등 금지)', () => {
    const recipe = recipes.find((r) => r.id === 'requestPgPayment')!;
    const action = buildAction(recipe, {});
    const m = matchAction(action, recipes);
    expect(m?.recipeId).toBe('requestPgPayment');
  });

  // 회귀(제보) — 핸들러 필드를 데이터 칩으로 바꾸고 결제 데이터는 비워두면, buildAction 이
  // 미입력 required pgPaymentData 를 떨궈 params 가 사라진다. 그러면 fingerprint(pgPaymentData)
  // 가 깨져 친화 카드가 [고급]으로 강등됐다("변경하니 고급으로 넘어간다"). required placeholder
  // param 은 빈 값이어도 토큰을 보존해 fingerprint 를 유지하고, 핸들러를 어떤 칩으로 바꿔도 친화
  // 카드가 유지돼야 한다(핸들러 필드 바인딩 가능성 ↔ greedy 가드 공존).
  it('핸들러를 임의 칩으로 바꾸고 데이터는 비워도 requestPgPayment 친화 카드 유지 (고급 강등 금지)', () => {
    const recipe = recipes.find((r) => r.id === 'requestPgPayment')!;
    // 핸들러만 데이터 칩으로 채우고 pgPaymentData 는 미입력(빈 값) — 제보 시나리오.
    const action = buildAction(recipe, { paymentHandler: '{{checkoutData?.data?.temp_order_id}}' });
    // required pgPaymentData 가 토큰으로 보존돼 params 가 살아있어야 한다.
    expect(action.handler).toBe('{{checkoutData?.data?.temp_order_id}}');
    expect((action.params as Record<string, unknown>)?.pgPaymentData).toBe('{{pgPaymentData}}');
    // 친화 카드로 역매칭 — [고급] 강등 금지.
    const m = matchAction(action, recipes);
    expect(m?.recipeId).toBe('requestPgPayment');
    // 핸들러 값(바인딩)이 paymentHandler 로 역추출된다.
    expect(m?.values.paymentHandler).toBe('{{checkoutData?.data?.temp_order_id}}');
  });

  it('회귀 — required placeholder 토큰 보존이 greedy 를 깨지 않는다 (params 없는 임의 동적 핸들러는 미흡수)', () => {
    // pgPaymentData 키가 없는 임의 동적 핸들러 액션은 결제 진입이 아니다(fingerprint 부재).
    const m = matchAction({ handler: '{{some.other.value}}', params: { foo: 'bar' } }, recipes);
    expect(m?.recipeId).not.toBe('requestPgPayment');
  });

  it('match — 동적 핸들러 액션을 requestPgPayment 로 역매칭하고 핸들러/데이터 값을 복원한다', () => {
    const action = {
      handler: '{{response.data.pg_payment_handler}}',
      params: { pgPaymentData: '{{response.data.pg_payment_data}}' },
    };
    const m = matchAction(action, recipes);
    expect(m?.recipeId).toBe('requestPgPayment');
    expect(m?.values.paymentHandler).toBe('{{response.data.pg_payment_handler}}');
    expect(m?.values.pgPaymentData).toBe('{{response.data.pg_payment_data}}');
  });

  it('round-trip — build → match 가 입력값을 무손실 왕복한다', () => {
    const recipe = recipes.find((r) => r.id === 'requestPgPayment')!;
    const values = {
      paymentHandler: '{{response.data.pg_payment_handler}}',
      pgPaymentData: '{{response.data.pg_payment_data}}',
    };
    const action = buildAction(recipe, values);
    const m = matchAction(action, recipes);
    expect(m?.values).toMatchObject(values);
  });

  // ── 회귀: placeholder 핸들러 recipe 공존이 리터럴 핸들러 역매칭을 깨지 않는다 ──

  it('회귀 — placeholder recipe 공존 시에도 리터럴 핸들러(navigate)는 정확히 goToPage 로 매칭', () => {
    const m = matchAction({ handler: 'navigate', params: { path: '/board' } }, recipes);
    expect(m?.recipeId).toBe('goToPage');
    expect(m?.values.url).toBe('/board');
  });

  it('회귀 — 리터럴 핸들러(toast)도 placeholder recipe 보다 showMessage 로 정확 매칭', () => {
    const m = matchAction({ handler: 'toast', params: { message: 'hi', type: 'error' } }, recipes);
    expect(m?.recipeId).toBe('showMessage');
    expect(m?.values).toMatchObject({ text: 'hi', tone: 'error' });
  });

  it('회귀 — setState 액션은 requestPgPayment(placeholder)로 흡수되지 않는다 (greedy 방지)', () => {
    // setState 액션은 pgPaymentData 키가 없으므로 placeholderRecipeStructureMatches 가 false →
    // requestPgPayment 후보 미인정. (테스트의 changeState mock 은 동적 키 '{{key}}' 라
    // setState 를 매칭하지 못하므로 결과는 null — 핵심은 requestPgPayment 로 잘못 걸리지 않음.)
    const m = matchAction(
      { handler: 'setState', target: 'local', params: { count: 5 } },
      recipes,
    );
    expect(m?.recipeId).not.toBe('requestPgPayment');
  });

  it('회귀 — pgPaymentData 없는 임의 커스텀 핸들러 액션도 requestPgPayment 로 흡수되지 않는다', () => {
    // 동적 핸들러처럼 보여도 params 에 pgPaymentData 가 없으면 결제 진입 recipe 가 아니다.
    const m = matchAction(
      { handler: '{{some.other.handler}}', params: { foo: 'bar' } },
      recipes,
    );
    expect(m?.recipeId).not.toBe('requestPgPayment');
  });

  it('placeholder recipe 는 핸들러가 리터럴이어도 역추출 시도 — 다른 리터럴 recipe 가 더 구체적이면 그쪽 우선(score)', () => {
    // navigate 리터럴 액션은 goToPage(리터럴, 정확 일치) 가 requestPgPayment(placeholder, 느슨)
    // 보다 우선되어야 한다. placeholder recipe 가 모든 핸들러를 흡수하지 않음을 보증.
    const m = matchAction({ handler: 'navigate', params: { path: '/x' } }, recipes);
    expect(m?.recipeId).toBe('goToPage');
  });
});

// ── conditions recipe — 분기(if/then) 배열 build/match 라운드트립 ──

describe('conditions — 분기 배열 build/match 라운드트립', () => {
  // conditions 는 액션 최상위 키(conditions:[{if,then}]). build.conditions='{{branches}}' sole-binding.
  const COND_RECIPES = {
    ...RECIPES,
    conditions: {
      label: '$t:layout_editor.action_recipe.conditions.label',
      params: [{ key: 'branches', widget: 'branch-list' }],
      build: { handler: 'conditions', conditions: '{{branches}}' },
    },
  };
  const recipes = normalizeActionRecipes(COND_RECIPES);

  const sampleBranches = [
    { if: '{{response.data.requires_pg_payment && response.data.pg_payment_handler}}', then: { handler: '{{response.data.pg_payment_handler}}', params: { pgPaymentData: '{{response.data.pg_payment_data}}' } } },
    { then: { handler: 'navigate', params: { path: '/complete' } } },
  ];

  it('build — branches 배열을 conditions 최상위 키로 통째 치환(params 아래 아님)', () => {
    const recipe = recipes.find((r) => r.id === 'conditions')!;
    const action = buildAction(recipe, { branches: sampleBranches });
    expect(action.handler).toBe('conditions');
    expect(action.conditions).toEqual(sampleBranches);
    // conditions 는 params 아래가 아니라 액션 최상위 (handleConditions 가 action.conditions 만 읽음).
    expect((action.params as Record<string, unknown> | undefined)?.conditions).toBeUndefined();
  });

  it('match — conditions 액션을 conditions recipe 로 역매칭하고 branches 를 통째 복원', () => {
    const action = { handler: 'conditions', conditions: sampleBranches };
    const m = matchAction(action, recipes);
    expect(m?.recipeId).toBe('conditions');
    expect(m?.values.branches).toEqual(sampleBranches);
  });

  it('round-trip — build → match 가 분기 배열(if/then placeholder 핸들러 포함)을 무손실 왕복', () => {
    const recipe = recipes.find((r) => r.id === 'conditions')!;
    const action = buildAction(recipe, { branches: sampleBranches });
    const m = matchAction(action, recipes);
    expect(m?.values.branches).toEqual(sampleBranches);
  });

  it('then 이 배열(다단 동작)인 분기도 무손실 보존', () => {
    const branches = [
      { if: '{{x}}', then: [{ handler: 'setState', params: { target: 'local', a: 1 } }, { handler: 'navigate', params: { path: '/y' } }] },
    ];
    const recipe = recipes.find((r) => r.id === 'conditions')!;
    const action = buildAction(recipe, { branches });
    expect(action.conditions).toEqual(branches);
    const m = matchAction(action, recipes);
    expect(m?.values.branches).toEqual(branches);
  });

  it('회귀 — conditions recipe 공존이 리터럴 핸들러(navigate/toast) 역매칭을 깨지 않는다', () => {
    expect(matchAction({ handler: 'navigate', params: { path: '/b' } }, recipes)?.recipeId).toBe('goToPage');
    expect(matchAction({ handler: 'toast', params: { message: 'hi' } }, recipes)?.recipeId).toBe('showMessage');
  });

  it('회귀 — conditions(리터럴 핸들러)는 다른 핸들러 액션을 흡수하지 않는다', () => {
    // setState 액션은 handler 불일치라 conditions recipe 후보 미진입.
    const m = matchAction({ handler: 'setState', target: 'local', params: { count: 1 } }, recipes);
    expect(m?.recipeId).not.toBe('conditions');
  });
});

// ── 보강 — resolveActionCard / summarizeAction / if ──

describe('resolveActionCard / summarizeAction / if (S10-1 보강)', () => {
  const recipes = normalizeActionRecipes(RECIPES);

  it('매칭되는 액션은 preset 카드', () => {
    const card = resolveActionCard({ handler: 'navigate', params: { path: '/shop' } }, recipes);
    expect(card.kind).toBe('preset');
    if (card.kind === 'preset') {
      expect(card.recipeId).toBe('goToPage');
      expect(card.values.url).toBe('/shop');
      expect(card.handler).toBe('navigate');
    }
  });

  it('미매칭 액션은 advanced 카드(코드 편집기에서 작성됨)', () => {
    const card = resolveActionCard({ handler: 'customUnknown', foo: 1 }, recipes);
    expect(card.kind).toBe('advanced');
    expect(card.handler).toBe('customUnknown');
  });

  it('summarizeAction — 라벨 + 주요 입력값 (핸들러명 미노출)', () => {
    const recipe = recipes.find((r) => r.id === 'goToPage')!;
    const t = (k: string) => (k === '$t:editor.action.go_to_page.label' ? '페이지 이동' : k);
    const summary = summarizeAction(recipe, { url: '/shop' }, t);
    expect(summary).toBe('페이지 이동 (/shop)');
    expect(summary).not.toContain('navigate');
  });

  it('summarizeAction — 고급(advanced) 입력은 요약에서 제외', () => {
    const recipe = {
      label: '$t:x',
      params: [
        { key: 'a', widget: 'text' },
        { key: 'tree', widget: 'action-list', advanced: true },
      ],
    };
    const summary = summarizeAction(recipe, { a: 'v', tree: [{ handler: 'toast' }] }, (k) => k);
    expect(summary).toBe('$t:x (v)');
  });

  it('withActionIf / extractActionIf — top-level if 부착·추출', () => {
    const action = { handler: 'toast', params: { message: 'hi' } };
    const withIf = withActionIf(action, '{{ route.id }}');
    expect(withIf.if).toBe('{{ route.id }}');
    expect((withIf.params as Record<string, unknown>).message).toBe('hi'); // 원본 보존
    expect(extractActionIf(withIf)).toBe('{{ route.id }}');
    // 빈 식 → if 제거
    const cleared = withActionIf(withIf, '');
    expect('if' in cleared).toBe(false);
    expect(extractActionIf(cleared)).toBeNull();
  });
});

// setState 동적 payload(가변 키 맵)를 params 로 흡수하는 스프레드 키(`"..."`).
describe('SPREAD_KEY (`"..."`) — setState 동적 payload 라운드트립', () => {
  const SETSTATE = {
    setState: {
      label: '$t:화면 상태',
      params: [
        { key: 'target', widget: 'select' },
        { key: 'state', widget: 'state-key-value' },
        { key: 'merge', widget: 'toggle' },
      ],
      // 실데이터 shape — target/merge 명시 키 + 상태 맵 스프레드.
      build: { handler: 'setState', params: { target: '{{target}}', merge: '{{merge}}', '...': '{{state}}' } },
    },
  };
  const recipes = normalizeActionRecipes(SETSTATE);
  const setStateRecipe = recipes.find((r) => r.id === 'setState')!;

  it('buildAction — state 맵을 params 로 펼치고 target/merge 와 공존', () => {
    const a = buildAction(setStateRecipe, {
      target: 'local',
      merge: true,
      state: { form: null, isSaving: false },
    });
    expect(a.handler).toBe('setState');
    expect(a.params).toEqual({ target: 'local', merge: true, form: null, isSaving: false });
    // 스프레드 키 자체(`"..."`)는 결과에 남지 않는다.
    expect('...' in (a.params as Record<string, unknown>)).toBe(false);
  });

  it('matchAction — 명시 키 외 나머지 params 를 state 맵으로 역추출(라운드트립)', () => {
    const action = { handler: 'setState', params: { target: 'global', merge: false, selectedId: null, mode: 'create' } };
    const m = matchAction(action, recipes);
    expect(m).not.toBeNull();
    expect(m!.recipeId).toBe('setState');
    expect(m!.values.target).toBe('global');
    expect(m!.values.merge).toBe(false);
    expect(m!.values.state).toEqual({ selectedId: null, mode: 'create' });
  });

  it('build → match 왕복 동등', () => {
    const values = { target: 'local', merge: true, state: { a: 1, b: 'x' } };
    const built = buildAction(setStateRecipe, values);
    const matched = matchAction(built, recipes)!;
    expect(matched.values).toEqual(values);
  });

  it('일반 핸들러(스프레드 키 없음)는 영향 없음', () => {
    const a = buildAction(normalizeActionRecipes(RECIPES).find((r) => r.id === 'goToPage')!, { url: '/x' });
    expect(a).toEqual({ handler: 'navigate', params: { path: '/x' } });
  });
});
