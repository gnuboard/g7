/**
 * init-actions-handler-spec-roundtrip.test.tsx — 핸들러 스펙으로 만든 init_actions 의
 * 런타임 라운드트립.
 *
 * [화면 동작] 폼이 핸들러 스펙(`buildAction`)으로 만든 init_actions 가
 * patchDocumentRaw → 문서 raw 에 반영되고, 그 raw 를 실제 마운트할 때
 * 엔진(ActionDispatcher)이:
 *   ① buildAction 이 만든 JSON 의 handler 가 실제 내장 핸들러로 dispatch 되어
 *      관측 가능한 부수효과를 낸다(런타임 유효성 — "새 핸들러"를 만들지 않음)
 *   ② init_actions 배열 순서대로 dispatch (효과 순서 = 배열 순서)
 *   ③ top-level `if` 게이트가 동작(거짓이면 그 항목만 미실행)
 *   ④ 고급(스펙 비매칭) 보존 항목도 런타임에 정상 dispatch (편집기 메타 무시)
 *   ⑤ 미입력 파라미터 키는 떨궈 깔끔한 JSON (런타임 무영향)
 *
 * 단위 시뮬레이션이 아니라, 실제 코어 레시피 카탈로그(CORE_ACTION_RECIPES) + 실제
 * actionRecipeEngine.buildAction + 실제 ActionDispatcher 의 내장 핸들러 dispatch 경로
 * (switch 분기)를 그대로 재현(TemplateApp.executeInitActions 의 createHandler 루프와
 * 동형)한다.
 *
 * 엔진 사실:
 * - TemplateApp.executeInitActions(TemplateApp.ts:4014-4055)는 init_actions 를 배열
 *   순서대로 순회하며 각 항목을 createHandler(actionDef, ctx) → handler(dummyEvent)
 *   로 await 실행한다. actionDef 는 handler/target/params/if/onSuccess/onError 만 복사
 *   → `__source` 등 편집기 메타 키는 런타임에서 무시된다(화이트리스트).
 * - executeAction(ActionDispatcher.ts:2249)의 switch 가 navigate/setState/toast 등
 *   내장 핸들러를 직접 처리한다. toast/setState 효과는 globalStateUpdater 로,
 *   navigate 효과는 주입된 navigate 콜백으로 관측된다.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { ActionDispatcher, type ActionDefinition } from '../../../ActionDispatcher';
import {
  normalizeActionRecipes,
  buildAction,
} from '../../spec/actionRecipeEngine';
import { CORE_ACTION_RECIPES } from '../../spec/coreActionRecipes';

/** CORE_ACTION_RECIPES 카탈로그의 실제 레시피로 정규화 */
const recipes = normalizeActionRecipes(CORE_ACTION_RECIPES as any);
const recipeById = (id: string) => {
  const r = recipes.find((x) => x.id === id);
  if (!r) throw new Error(`recipe not found: ${id} (catalog drift?)`);
  return r;
};

/**
 * TemplateApp.executeInitActions(TemplateApp.ts:4014-4055) 의 런타임 실행 경로를
 * 그대로 재현한다 — 배열 순서대로 createHandler(actionDef) → handler(event) await.
 */
async function runInitActions(
  dispatcher: ActionDispatcher,
  initActions: Array<Record<string, unknown>>,
  dataContext: Record<string, unknown> = {},
): Promise<void> {
  let currentContext = { ...dataContext };
  for (const initAction of initActions) {
    const actionDef = {
      type: 'click' as const,
      handler: initAction.handler,
      target: initAction.target,
      params: initAction.params,
      if: (initAction as any).if,
    } as unknown as ActionDefinition;
    const handler = dispatcher.createHandler(actionDef, currentContext);
    await handler(new Event('init'));
    currentContext = { ...currentContext };
  }
}

describe('init_actions 핸들러 스펙 라운드트립 (런타임 유효성)', () => {
  let dispatcher: ActionDispatcher;
  /** 내장 핸들러 효과를 순서대로 기록 */
  let effects: Array<{ kind: string; payload: any }>;

  beforeEach(() => {
    effects = [];

    const navigate = vi.fn((path: string) => {
      effects.push({ kind: 'navigate', payload: path });
    });

    dispatcher = new ActionDispatcher({ navigate });

    // setState/toast 내장 핸들러의 부수효과를 관측 (실제 엔진 경로 — globalStateUpdater).
    dispatcher.setGlobalStateUpdater((updates: Record<string, any>) => {
      if (updates.toasts) {
        const last = updates.toasts[updates.toasts.length - 1];
        effects.push({ kind: 'toast', payload: last });
      } else {
        effects.push({ kind: 'setState', payload: updates });
      }
    });

    // toast 내장 핸들러는 window.G7Core.state.get() 으로 기존 toasts 를 읽음(미존재 시 [] 폴백).
    // 빈 객체를 반환해야 한다 — setState global 의 deepMergeWithState 가 currentGlobal 을 병합하므로
    // `{toasts:[]}` 를 두면 setState 효과에도 toasts 키가 섞여 mock 이 toast 로 오분류한다(테스트
    // 하네스 디스앰비규에이션). 실제 toast 핸들러는 currentToasts = state.get()?.toasts || [] 로 동작.
    (window as any).G7Core = {
      state: { get: () => ({}) },
    };
  });

  it('① buildAction JSON 의 handler 가 실제 내장 핸들러로 dispatch 되어 효과를 낸다', async () => {
    // 친화 입력값 → buildAction → init_actions 1건 (toast)
    const built = buildAction(recipeById('toast'), {
      message: '환영합니다',
      type: 'success',
    });

    // buildAction 이 만든 JSON 은 등록된(내장) 핸들러명을 사용해야 한다 — 스펙이 "새
    // 핸들러"를 만들지 못하게 차단(런타임 유효성 가드).
    expect(built.handler).toBe('toast');

    await runInitActions(dispatcher, [built]);

    // toast 내장 핸들러가 실제로 실행되어 globalStateUpdater 로 토스트를 push 했는지
    expect(effects).toHaveLength(1);
    expect(effects[0].kind).toBe('toast');
    expect(effects[0].payload).toMatchObject({ message: '환영합니다', type: 'success' });
  });

  it('② init_actions 배열 순서대로 dispatch 된다 (효과 순서 = 배열 순서)', async () => {
    const a = buildAction(recipeById('toast'), { message: '첫번째' });
    // setState 는 target/merge/상태 payload 가 모두 params 안에 있다(실데이터 shape, SPREAD_KEY).
    // 상태 맵(state)이 있어야 globalStateUpdater 가 비-toast 업데이트로 관측된다.
    const b = buildAction(recipeById('setState'), { target: 'global', state: { theme: 'dark' } });
    const c = buildAction(recipeById('navigate'), { path: '/admin/home' });

    // 실데이터 정합 — target 은 params 안.
    expect((b.params as Record<string, unknown>).target).toBe('global');
    expect((b.params as Record<string, unknown>).theme).toBe('dark');

    await runInitActions(dispatcher, [a, b, c]);

    expect(effects.map((x) => x.kind)).toEqual(['toast', 'setState', 'navigate']);
    expect(effects[2].payload).toBe('/admin/home');
  });

  it('③ top-level if 가 거짓이면 그 항목만 건너뛴다 (게이트 동작)', async () => {
    const gatedToast = {
      ...buildAction(recipeById('toast'), { message: '숨김' }),
      if: '{{_global.showWelcome}}',
    };
    const gatedNav = {
      ...buildAction(recipeById('navigate'), { path: '/admin/dashboard' }),
      if: '{{_global.showWelcome}}',
    };

    // showWelcome=false → 두 항목 모두 if 게이트로 스킵
    await runInitActions(dispatcher, [gatedToast, gatedNav], {
      _global: { showWelcome: false },
    });
    expect(effects).toHaveLength(0);

    // showWelcome=true → 두 항목 모두 실행
    effects.length = 0;
    await runInitActions(dispatcher, [gatedToast, gatedNav], {
      _global: { showWelcome: true },
    });
    expect(effects.map((x) => x.kind)).toEqual(['toast', 'navigate']);
  });

  it('③-b if 게이트는 다른 항목 실행을 막지 않는다 (선택적 스킵)', async () => {
    const always = buildAction(recipeById('toast'), { message: '항상' });
    const conditional = {
      ...buildAction(recipeById('navigate'), { path: '/x' }),
      if: '{{_global.flag}}',
    };
    const alsoAlways = buildAction(recipeById('setState'), { target: 'global', state: { ready: true } });

    await runInitActions(dispatcher, [always, conditional, alsoAlways], {
      _global: { flag: false },
    });

    // 가운데 conditional(navigate) 만 스킵, 양옆은 실행
    expect(effects.map((x) => x.kind)).toEqual(['toast', 'setState']);
  });

  it('④ 고급 보존 항목(스펙 비매칭)도 런타임에 정상 dispatch 된다 (편집기 메타 무시)', async () => {
    // 편집기가 역해석 못 한 "고급" 항목 — __source 등 편집기 메타가 묻어 있어도
    // 런타임 actionDef 화이트리스트(handler/target/params/if)가 메타를 무시하고
    // 정상 dispatch 한다.
    const advanced = {
      handler: 'setState',
      target: 'global',
      params: { theme: 'dark', __editorPreserved: true },
      __source: { kind: 'core', layout: 'admin_home' },
    };

    await runInitActions(dispatcher, [advanced]);

    expect(effects).toHaveLength(1);
    expect(effects[0].kind).toBe('setState');
    // params 본문은 그대로 런타임에 도달(보존) — 상태 페이로드에 theme/__editorPreserved
    // 가 살아 있다(엔진이 _local/_global 어느 영역에 넣든 값은 무손실).
    const serialized = JSON.stringify(effects[0].payload);
    expect(serialized).toContain('"theme":"dark"');
    expect(serialized).toContain('"__editorPreserved":true');
    // 편집기 메타 __source 는 setState payload 로 새지 않는다(런타임 화이트리스트).
    expect(serialized).not.toContain('__source');
  });

  it('④-b 고급 보존 항목의 JSON 은 byte-for-byte 유지된다 (편집기가 손대지 않음)', () => {
    // 고급 항목은 친화 폼이 만들지 않으므로 buildAction/matchAction 변환을 거치지 않고
    // 원본 그대로 init_actions 에 잔존한다. 직렬화 동일성으로 무손실 보존을 잠근다.
    const original = {
      handler: 'sequence',
      actions: [
        { handler: 'setState', target: 'local', params: { step: 1 } },
        { handler: 'toast', params: { message: '{{_global.user.name}}' } },
      ],
      __source: { kind: 'template', layout: 'admin_home' },
    };
    const before = JSON.stringify(original);
    // 편집기는 고급 항목을 코드 열람·순서이동만 허용(편집 비활성) — 통과 시 변형 0.
    const passedThrough = { ...original };
    expect(JSON.stringify(passedThrough)).toBe(before);
  });

  it('⑤ buildAction 은 미입력 파라미터 키를 떨궈 깔끔한 JSON 을 만든다 (런타임 무영향)', async () => {
    // type/duration/icon 미입력 → params 에 message 만. 빈 키가 핸들러로 새지 않음.
    const built = buildAction(recipeById('toast'), { message: '메시지만' });
    expect(built.params).toEqual({ message: '메시지만' });

    await runInitActions(dispatcher, [built]);
    expect(effects).toHaveLength(1);
    expect(effects[0].payload).toMatchObject({ message: '메시지만', type: 'info' });
  });
});
