/**
 * coreActionRecipes.test.ts — 코어 핸들러 스펙 카탈로그 무결성
 *
 *  C1~C22 + C23~C27 의 스펙 카탈로그가 누락 없이 정의됐는지, 각 스펙이
 * actionRecipeEngine 으로 정상 빌드되는지, advanced 경계·transition_overlay_target·seed
 * 3블록이 계획대로인지 잠근다(회귀 가드 — [화면 동작] 탭 핵심 27 row).
 */

import { describe, it, expect } from 'vitest';
import {
  CORE_ACTION_RECIPES,
  buildCoreActionRecipeSeed,
} from '../../spec/coreActionRecipes';
import { normalizeActionRecipes, buildAction, resolveActionCard } from '../../spec/actionRecipeEngine';

//  C1~C22 핸들러 + C23~C27 제어흐름(if=C23 은 엔진 처리라 카탈로그 제외) = 26 핸들러.
const EXPECTED_HANDLERS = [
  // C1~C3 상태
  'setState', 'loadFromLocalStorage', 'saveToLocalStorage',
  // C4~C8 이동/창
  'navigate', 'openWindow', 'navigateBack', 'navigateForward', 'replaceUrl',
  // C9~C13 알림/모달
  'toast', 'openModal', 'closeModal', 'showAlert', 'setError',
  // C14~C16 데이터
  'refetchDataSource', 'appendDataSource', 'updateDataSource',
  // C17~C22 기타
  'scrollIntoView', 'login', 'loginTwoFactor', 'loginTwoFactorResend', 'loginPasswordChange', 'loginPasswordChangePostpone', 'logout', 'setLocale', 'emitEvent', 'apiCall',
  // 방문자 동의 스냅샷 다시 읽기 (engine-v1.78.0)
  'refreshConsent',
  // C24~C27 제어흐름(C23 top-level if 는 엔진 처리 — 카탈로그 비포함) + C29 conditions
  'conditions', 'sequence', 'parallel', 'switch', 'suppress',
  // 결제 진입(requestPgPayment)은 코어 카탈로그에서 제외됨 — 결제는 커머스 도메인이라
  // ecommerce 모듈 editor-spec 이 소유한다(코어는 PG/도메인 무지). placeholder 핸들러 recipe
  // 메커니즘 자체의 검증은 actionRecipeEngine.test.ts 가 담당한다.
];

// 코어 카탈로그에는 placeholder 핸들러 recipe 가 없다(결제 진입이 모듈로 이동). 빈 집합 유지 —
// 향후 코어가 placeholder recipe 를 도입하면 여기 추가한다.
const PLACEHOLDER_HANDLER_RECIPES = new Set<string>();

describe('coreActionRecipes — 카탈로그 무결성 (C1~C27)', () => {
  /**
   * @scenario catalog_aspect=handler_set
   * @effects editor_core_catalog_lists_every_core_handler, core_action_catalog_matches_expected_handler_set
   */
  it('계획 의 코어 핸들러 스펙을 누락 없이 정의한다', () => {
    const keys = Object.keys(CORE_ACTION_RECIPES);
    for (const handler of EXPECTED_HANDLERS) {
      expect(keys, `핸들러 "${handler}" 스펙 누락`).toContain(handler);
    }
    // 카탈로그가 기대 집합과 정확히 일치(임의 추가/누락 차단).
    expect(keys.sort()).toEqual([...EXPECTED_HANDLERS].sort());
  });

  /**
   * @scenario catalog_aspect=build_contract
   * @effects editor_core_catalog_lists_every_core_handler, core_action_recipe_build_handler_matches_catalog_key
   */
  it('각 스펙은 label·build.handler 를 갖고 build.handler 가 key 와 일치한다 (placeholder 핸들러 recipe 예외)', () => {
    for (const [key, spec] of Object.entries(CORE_ACTION_RECIPES)) {
      expect(typeof spec.label, `${key}.label`).toBe('string');
      if (PLACEHOLDER_HANDLER_RECIPES.has(key)) {
        // 핸들러를 응답값으로 결정하는 recipe — build.handler 는 `{{paramKey}}` placeholder.
        expect(spec.build?.handler, `${key}.build.handler placeholder`).toMatch(/^\{\{.+\}\}$/);
      } else {
        expect(spec.build?.handler, `${key}.build.handler`).toBe(key);
      }
    }
  });

  /**
   * @scenario catalog_aspect=build_contract
   * @effects editor_core_catalog_lists_every_core_handler, core_action_recipes_all_normalize_and_build
   */
  it('모든 스펙이 actionRecipeEngine 으로 정규화·빌드된다(미등록 0)', () => {
    const normalized = normalizeActionRecipes(CORE_ACTION_RECIPES);
    // 빌드 불가 스펙은 normalize 가 떨구므로, 전부 정규화돼야 한다.
    expect(normalized.length).toBe(EXPECTED_HANDLERS.length);
    // 각 레시피 빌드 결과의 handler 가 build 틀과 일치한다. placeholder 핸들러 recipe 는
    // 핸들러 입력값을 제공해야 substitute 되므로(빈 값이면 undefined 로 떨궈짐) 값을 채워 빌드한다.
    for (const recipe of normalized) {
      if (PLACEHOLDER_HANDLER_RECIPES.has(recipe.id)) {
        // build.handler `{{paymentHandler}}` → 입력값으로 치환되는지 확인.
        const built = buildAction(recipe, { paymentHandler: 'vendor.pay' });
        expect(built.handler).toBe('vendor.pay');
      } else {
        const built = buildAction(recipe, {});
        expect(built.handler).toBe(recipe.build.handler);
      }
    }
  });

  /**
   * @scenario catalog_aspect=param_widget
   * @effects editor_core_catalog_lists_every_core_handler, navigate_transition_overlay_target_param_gated_by_replace
   */
  it('navigate 는 transition_overlay_target param(component-target-picker, replace 종속)을 갖는다 (C4)', () => {
    const navigate = CORE_ACTION_RECIPES.navigate;
    const param = navigate.params?.find((p) => p.key === 'transition_overlay_target');
    expect(param).toBeDefined();
    expect(param?.widget).toBe('component-target-picker');
    // replace=true 시만 노출(dependsOn 게이팅).
    expect((param as Record<string, unknown>)?.dependsOn).toEqual({ param: 'replace', equals: true });
  });

  /**
   * @scenario catalog_aspect=param_widget
   * @effects editor_core_catalog_lists_every_core_handler, navigate_refetch_data_sources_survives_editor_roundtrip
   */
  it('navigate(replace) 의 refetch_data_sources 는 편집기 폼 왕복 뒤에도 남는다 (engine-v1.79.0)', () => {
    // 본인인증 탭 목록 조작은 navigate replace + refetch_data_sources 로 목록만 다시 받는다(engine-v1.79.0).
    // 레시피 build 틀에 이 키가 없으면 편집기 폼으로 그 navigate 를 고쳐 저장할 때 파라미터가 빠져,
    // 목록 조작이 설정 소스까지 다시 받아 고친 설정을 덮던 결함이 되살아난다.
    const param = CORE_ACTION_RECIPES.navigate.params?.find((p) => p.key === 'refetch_data_sources');
    expect(param).toBeDefined();
    expect((param as Record<string, unknown>)?.dependsOn).toEqual({ param: 'replace', equals: true });

    const normalized = normalizeActionRecipes(CORE_ACTION_RECIPES);
    for (const refetch of ['policies', ['boardIdentityPolicies', 'boardIdentityPurposes']]) {
      const action = {
        handler: 'navigate',
        params: { path: '/admin/settings', query: { tab: 'identity', page: 2 }, mergeQuery: true, replace: true, refetch_data_sources: refetch },
      };
      const card = resolveActionCard(action, normalized);
      expect(card.kind).toBe('preset');
      if (card.kind !== 'preset') continue;
      const recipe = normalized.find((r) => r.id === card.recipeId)!;
      const rebuilt = buildAction(recipe, card.values) as { params?: Record<string, unknown> };
      expect(rebuilt.params?.refetch_data_sources).toEqual(refetch);
      expect(rebuilt.params?.replace).toBe(true);
    }
  });

  /**
   * @scenario catalog_aspect=param_widget
   * @effects editor_core_catalog_lists_every_core_handler, switch_cases_param_locked_advanced
   */
  it('switch 의 cases 는 advanced 잠금 — cases 는 객체맵(Record)이라 action-list 로 표현 불가', () => {
    const advancedOf = (handler: string, key: string) =>
      CORE_ACTION_RECIPES[handler]?.params?.find((p) => p.key === key)?.advanced;
    expect(advancedOf('switch', 'cases')).toBe(true);
  });

  /**
   * @scenario catalog_aspect=param_widget
   * @effects editor_core_catalog_lists_every_core_handler, nested_action_containers_use_action_list_not_advanced
   */
  it('중첩 액션 컨테이너(apiCall onSuccess/onError·sequence·parallel)는 친화 중첩 빌더(action-list) — advanced 아님', () => {
    // 응답 후속 동작/다단 동작(결제 진입·상태 저장·이동 등)을 코드 없이 추가/편집할 수 있어야 한다.
    const paramOf = (handler: string, key: string) =>
      CORE_ACTION_RECIPES[handler]?.params?.find((p) => p.key === key);
    for (const [handler, key] of [['apiCall', 'onSuccess'], ['apiCall', 'onError'], ['sequence', 'actions'], ['parallel', 'actions']] as const) {
      expect(paramOf(handler, key)?.widget, `${handler}.${key} widget`).toBe('action-list');
      expect(paramOf(handler, key)?.advanced, `${handler}.${key} advanced`).toBeFalsy();
    }
  });

  /**
   * @scenario catalog_aspect=param_widget
   * @effects editor_core_catalog_lists_every_core_handler, conditions_recipe_builds_top_level_conditions_key
   */
  it('conditions recipe — branches 는 branch-list 위젯, build 는 conditions 최상위 키', () => {
    // conditions 는 액션 최상위 키(handleConditions 가 action.conditions 만 읽음).
    const conditions = CORE_ACTION_RECIPES.conditions;
    expect(conditions).toBeDefined();
    const branchesParam = conditions.params?.find((p) => p.key === 'branches');
    expect(branchesParam?.widget).toBe('branch-list');
    expect(conditions.build?.handler).toBe('conditions');
    // conditions 키가 build 최상위(params 아래 아님)에 sole-binding 으로 있어야 한다.
    expect((conditions.build as Record<string, unknown>)?.conditions).toBe('{{branches}}');
    expect((conditions.build?.params as Record<string, unknown> | undefined)?.conditions).toBeUndefined();
  });

  /**
   * @scenario catalog_aspect=apiCall_identity_target
   * @effects editor_core_catalog_lists_every_core_handler, apiCall_identity_target_params_are_editable_data_chip
   */
  it('apiCall identity_target email/phone 은 data-chip 일반 입력칸이다 (advanced 잠금 아님)', () => {
    // advanced: true 면 편집기가 입력칸 대신 "[고급] 코드 편집" 잠금 배지만 렌더하므로,
    // 직접 입력 가능한 data-chip 위젯이어야 한다 (body/query 와 동일).
    const params = CORE_ACTION_RECIPES.apiCall?.params ?? [];
    const emailParam = params.find((p) => p.key === 'identity_target_email');
    const phoneParam = params.find((p) => p.key === 'identity_target_phone');
    expect(emailParam?.widget).toBe('data-chip');
    expect(phoneParam?.widget).toBe('data-chip');
    expect(emailParam?.advanced).toBeFalsy();
    expect(phoneParam?.advanced).toBeFalsy();
  });

  /**
   * @scenario catalog_aspect=apiCall_identity_target
   * @effects editor_core_catalog_lists_every_core_handler, apiCall_identity_target_built_at_action_top_level
   */
  it('apiCall identity_target 입력값이 액션 최상위 identity_target 으로 빌드된다', () => {
    const normalized = normalizeActionRecipes(CORE_ACTION_RECIPES);
    const apiCall = normalized.find((r) => r.build.handler === 'apiCall')!;

    const built = buildAction(apiCall, {
      target: '/api/orders',
      method: 'POST',
      identity_target_email: '{{_local.orderer.email}}',
      identity_target_phone: '{{_local.orderer.phone}}',
    });

    expect(built.identity_target).toEqual({
      email: '{{_local.orderer.email}}',
      phone: '{{_local.orderer.phone}}',
    });
  });

  /**
   * @scenario catalog_aspect=apiCall_identity_target
   * @effects editor_core_catalog_lists_every_core_handler, apiCall_identity_target_dropped_when_empty
   */
  it('apiCall identity_target 미입력 시 키가 떨궈진다 (깔끔한 JSON)', () => {
    const normalized = normalizeActionRecipes(CORE_ACTION_RECIPES);
    const apiCall = normalized.find((r) => r.build.handler === 'apiCall')!;

    const built = buildAction(apiCall, { target: '/api/orders', method: 'POST' });

    expect(built.identity_target).toBeUndefined();
  });

  /**
   * @scenario catalog_aspect=apiCall_identity_target
   * @effects editor_core_catalog_lists_every_core_handler, apiCall_without_identity_target_matches_friendly_preset
   */
  it('identity_target 없는 apiCall 도 친화 폼으로 매칭된다 (회귀 차단 — advanced 로 떨어지면 입력칸 사라짐)', () => {
    const normalized = normalizeActionRecipes(CORE_ACTION_RECIPES);

    // identity_target 미선언 + onSuccess 동작이 있는 일반 apiCall (대부분의 레이아웃 apiCall)
    const action = {
      handler: 'apiCall',
      target: '/api/users',
      params: { method: 'GET' },
      onSuccess: [{ handler: 'toast', params: { message: 'ok' } }],
    };

    const card = resolveActionCard(action, normalized);
    // matchAction 이 build 틀의 identity_target(객체)을 실제 노드에 없다고 매칭 실패시키면
    // advanced 로 떨어져 "서버에 보내고 결과 처리"를 골라도 친화 입력칸이 전혀 안 보인다.
    expect(card.kind).toBe('preset');
    if (card.kind === 'preset') {
      expect(card.handler).toBe('apiCall');
    }
  });

  /**
   * @scenario catalog_aspect=i18n_label
   * @effects editor_core_catalog_lists_every_core_handler, core_action_recipe_labels_are_i18n_keys
   */
  it('친화 라벨·param 라벨은 모두 $t: 다국어 키다(평문 박기 금지)', () => {
    for (const [key, spec] of Object.entries(CORE_ACTION_RECIPES)) {
      expect(spec.label, `${key}.label 은 $t: 키`).toMatch(/^\$t:/);
      for (const p of spec.params ?? []) {
        if (typeof p.label === 'string') {
          expect(p.label, `${key}.${p.key}.label 은 $t: 키`).toMatch(/^\$t:/);
        }
      }
    }
  });
});

describe('buildCoreActionRecipeSeed — 시드 3블록 ', () => {
  /**
   * @scenario catalog_aspect=seed_blocks
   * @effects editor_core_recipe_seed_builds, core_action_seed_shares_catalog_and_error_recipes_add_show_error_page
   */
  it('actionRecipes/initActionRecipes 는 동일 카탈로그, errorRecipes 는 부분집합 + showErrorPage', () => {
    const seed = buildCoreActionRecipeSeed();
    expect(seed.actionRecipes).toBe(CORE_ACTION_RECIPES);
    expect(seed.initActionRecipes).toBe(CORE_ACTION_RECIPES);
    // [에러 처리] 탭 친화 동작 7종(-63) + showErrorPage.
    const errorKeys = Object.keys(seed.errorRecipes);
    expect(errorKeys).toEqual(
      expect.arrayContaining(['navigate', 'openModal', 'toast', 'setState', 'sequence', 'parallel', 'showErrorPage']),
    );
    // showErrorPage 는 에러 탭 전용 — 코어 핸들러 카탈로그엔 없다(화면 동작 탭 제외).
    expect(CORE_ACTION_RECIPES.showErrorPage).toBeUndefined();
    expect(seed.errorRecipes.showErrorPage?.build?.handler).toBe('showErrorPage');
  });
});
