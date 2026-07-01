/**
 * init-actions-inheritance-render.test.tsx — 상속 병합 init_actions 의 런타임 렌더
 *
 *
 * 부모(_user_base: initTheme/initCartKey 류)와 자식(toast)의 병합 init_actions 가
 * 실제 마운트 시:
 *   ① **부모 먼저 → 자식 나중** 순서로 dispatch (ActionDispatcher 호출 순서 가드)
 *   ② `__source` 가 묻은 항목(편집기 with_source_meta 응답)도 런타임 정상 호출
 *      (actionDef 화이트리스트가 메타를 무시)
 *   ③ 자식 저장 후 재마운트 시 부모 동작은 _user_base 원본 그대로(부모 행 불변)
 * 를 검증한다.
 *
 * 엔진 사실 (병합 SSoT = LayoutService.mergeInitActions):
 * - 운영 렌더: `array_merge($parentActions, $childActions)` → 부모 먼저 + 자식 나중,
 *   `__source` **미부착**(LayoutService.php:425-427).
 * - 편집기(with_source_meta): 각 항목에 `__source:{kind,layout}` 부착
 *   (LayoutService.php:430-447) — 부모=base, 자식=route.
 * - 런타임 실행: TemplateApp.executeInitActions 가 병합된 배열을 순서대로 dispatch.
 *   actionDef 는 handler/target/params/if 만 복사 → `__source` 무시(화이트리스트).
 *
 * 병합은 백엔드 책임이므로 본 테스트는 "병합된 배열을 엔진이 어떻게 실행하는가"를
 * 검증한다(병합 순서 단위는 LayoutServiceMergeInitActions PHPUnit 이 담당).
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { ActionDispatcher, type ActionDefinition } from '../../../ActionDispatcher';

/**
 * TemplateApp.executeInitActions 런타임 경로 재현 — 병합 배열을 순서대로 dispatch.
 */
async function runInitActions(
  dispatcher: ActionDispatcher,
  initActions: Array<Record<string, unknown>>,
): Promise<void> {
  for (const initAction of initActions) {
    const actionDef = {
      type: 'click' as const,
      handler: initAction.handler,
      target: initAction.target,
      params: initAction.params,
      if: (initAction as any).if,
    } as unknown as ActionDefinition;
    const handler = dispatcher.createHandler(actionDef, {});
    await handler(new Event('init'));
  }
}

/** 부모(_user_base) 선언 init_actions — initTheme/initCartKey 류(커스텀 핸들러) */
const PARENT_INIT_ACTIONS = [
  { handler: 'initTheme' },
  { handler: 'initCartKey', params: { source: 'guest' } },
];

/**
 * 자식(라우트) 선언 init_actions — 자식 전용 커스텀 핸들러.
 * (내장 toast/setState 는 ActionDispatcher.executeAction switch 가 가로채 customHandlers
 *  로 도달하지 않으므로, 순서 관측에는 커스텀 핸들러명을 쓴다. 내장 핸들러 dispatch
 *  자체의 유효성은 init-actions-handler-spec-roundtrip 가 별도 검증.)
 */
const CHILD_INIT_ACTIONS = [
  { handler: 'initPageNotice', params: { message: '페이지 안내', type: 'info' } },
];

describe('init_actions 상속 병합 런타임 렌더', () => {
  let dispatcher: ActionDispatcher;
  let order: string[];

  beforeEach(() => {
    order = [];
    dispatcher = new ActionDispatcher();

    // 부모/자식 핸들러를 커스텀으로 등록 — dispatch 도달 순서를 기록.
    for (const name of ['initTheme', 'initCartKey', 'initPageNotice']) {
      dispatcher.registerHandler(name, async (action: ActionDefinition) => {
        order.push(name);
        return undefined;
      });
    }
  });

  it('① 운영 병합본(부모 먼저 + 자식 나중)이 그 순서대로 dispatch 된다', async () => {
    // LayoutService.mergeInitActions 운영 경로 = array_merge(parent, child)
    const merged = [...PARENT_INIT_ACTIONS, ...CHILD_INIT_ACTIONS];

    await runInitActions(dispatcher, merged);

    // 부모(initTheme, initCartKey) → 자식(initPageNotice) 순서
    expect(order).toEqual(['initTheme', 'initCartKey', 'initPageNotice']);
  });

  it('② __source 가 묻은 항목(편집기 with_source_meta 응답)도 런타임 정상 호출된다', async () => {
    // 편집기 응답은 각 항목에 __source 를 부착한다. 런타임 actionDef 화이트리스트가
    // __source 를 무시하므로 동작/순서는 동일해야 한다.
    const mergedWithMeta = [
      { ...PARENT_INIT_ACTIONS[0], __source: { kind: 'base', layout: '_user_base' } },
      { ...PARENT_INIT_ACTIONS[1], __source: { kind: 'base', layout: '_user_base' } },
      { ...CHILD_INIT_ACTIONS[0], __source: { kind: 'route', layout: 'board_list' } },
    ];

    await runInitActions(dispatcher, mergedWithMeta);

    expect(order).toEqual(['initTheme', 'initCartKey', 'initPageNotice']);
  });

  it('②-b __source 부착본과 미부착본의 실행 순서가 동일하다 (메타 무영향 회귀)', async () => {
    const plain = [...PARENT_INIT_ACTIONS, ...CHILD_INIT_ACTIONS];
    const stamped = plain.map((a, i) => ({
      ...a,
      __source: { kind: i < 2 ? 'base' : 'route', layout: i < 2 ? '_user_base' : 'board_list' },
    }));

    await runInitActions(dispatcher, plain);
    const plainOrder = [...order];

    order.length = 0;
    await runInitActions(dispatcher, stamped);
    const stampedOrder = [...order];

    expect(stampedOrder).toEqual(plainOrder);
  });

  it('③ 자식만 추가/저장한 뒤 재마운트해도 부모 동작은 원본 그대로 실행된다 (부모 행 불변)', async () => {
    // 자식이 init_actions 를 추가(자식 구간만 patch)해도 부모(_user_base) 항목은
    // 변하지 않는다 — 재병합 시 부모 원본 + 확장된 자식.
    const childAfterEdit = [
      ...CHILD_INIT_ACTIONS,
      { handler: 'initPageNotice', params: { message: '추가 안내', type: 'success' } },
    ];
    const remerged = [...PARENT_INIT_ACTIONS, ...childAfterEdit];

    await runInitActions(dispatcher, remerged);

    // 부모 두 항목이 맨 앞에서 원본 그대로 실행, 자식은 두 번 실행.
    expect(order).toEqual(['initTheme', 'initCartKey', 'initPageNotice', 'initPageNotice']);
  });

  it('③-b 부모 항목은 자식 편집 전후로 동일 핸들러·동일 순서를 유지한다', async () => {
    const before = [...PARENT_INIT_ACTIONS, ...CHILD_INIT_ACTIONS];
    await runInitActions(dispatcher, before);
    // 부모 구간(앞 2개)만 추출
    expect(order.slice(0, 2)).toEqual(['initTheme', 'initCartKey']);

    order.length = 0;
    const after = [
      ...PARENT_INIT_ACTIONS,
      ...CHILD_INIT_ACTIONS,
      { handler: 'initPageNotice', params: { message: 'x' } },
    ];
    await runInitActions(dispatcher, after);
    // 부모 구간은 동일
    expect(order.slice(0, 2)).toEqual(['initTheme', 'initCartKey']);
  });
});
