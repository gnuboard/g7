/**
 * @file admin-settings-identity-policy-modal-reset.test.ts
 * @description 본인인증 정책 추가·편집 창 — 앞서 연 정책의 조건(conditions)이 다음 창에 남지 않는지
 *
 * 창 진입은 `_global.identity_policy_form_modal` 을 setState(기본 deep 병합)로 세운다. deep 병합은 값이 빈 객체 `{}`
 * 여도 기존 객체의 키를 지우지 않는다(ActionDispatcher `deepMergeWithState` — 공개 계약 「deep = 중첩 객체 보존」).
 * 그래서 가입 단계 조건(`conditions.signup_stage`)이 있는 정책을 편집하다 「취소」(창만 닫음) 한 뒤 「정책 추가」 를
 * 누르면 새 정책 폼의 `conditions: {}` 가 앞 값을 지우지 못하고, 저장 본문에 앞 정책의 조건이 실린다. 다른 정책 편집도
 * `policy.conditions ?? {}` 가 앞 조건과 합쳐진다. 진입 시퀀스가 먼저 창 상태의 form 을 null 로 덮고 다시 쓴다
 * (트러블슈팅 state-global 사례 7 의 `form: null` 패턴).
 *
 * 레이아웃의 실제 setState 액션을 엔진(`ActionDispatcher`)에 넣어 돌린다.
 *
 * @scenario-manifest tests/scenarios/admin-settings-identity-policy-modal.yaml
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher } from '@core/template-engine/ActionDispatcher';
import { actionsOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import policiesPartial from '../../layouts/partials/admin_settings/_tab_identity_policies.json';

type Json = Record<string, any>;

/** 트리에서 조건을 만족하는 노드를 모두 모은다. */
function collect(node: unknown, predicate: (n: Json) => boolean, out: Json[] = []): Json[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collect(child, predicate, out));
    return out;
  }
  if (node && typeof node === 'object') {
    if (predicate(node as Json)) out.push(node as Json);
    Object.values(node as Json).forEach((v) => collect(v, predicate, out));
  }
  return out;
}

/** 창 상태를 세우는 진입 시퀀스들(추가 단추 1 + 행 편집 단추 2판) — openModal 을 가진 sequence */
const entrySequences = collect(
  policiesPartial,
  (n) => n.handler === 'sequence' && JSON.stringify(actionsOf(n)).includes('identity_policy_form_modal') && actionsOf(n).some((a: Json) => a.handler === 'openModal'),
);
const addSequence = entrySequences.find((s) => JSON.stringify(s).includes('"isNew":true'));
const editSequence = entrySequences.find((s) => JSON.stringify(s).includes('"isNew":false'));

const STALE = { form: { id: 7, key: 'core.signup', purpose: 'signup', conditions: { signup_stage: 'before_submit' }, user_overrides: [{ user_id: 3 }] }, isNew: false };

describe('본인인증 정책 창 진입 — 앞 정책의 조건이 남지 않는다', () => {
  let store: Json;
  let dispatcher: ActionDispatcher;

  beforeEach(() => {
    store = { identity_policy_form_modal: structuredClone(STALE), other: 1 };
    (window as any).G7Core = { state: { get: () => store } };
    dispatcher = new ActionDispatcher({ navigate: vi.fn() });
    // TemplateApp.setGlobalState 와 같이 최상위 키를 받은 값으로 바꾼다(엔진이 deep 병합을 마친 값을 넘긴다)
    dispatcher.setGlobalStateUpdater((updates: Json) => {
      const { __setStateId, __mergeMode, ...rest } = updates;
      store = { ...store, ...rest };
    });
  });

  afterEach(() => {
    delete (window as any).G7Core;
  });

  /** 진입 시퀀스의 setState 만 차례로 돌린다(openModal 은 창 등록이 없어 뺀다). */
  async function enter(sequence: Json, data: Json = {}): Promise<void> {
    for (const action of actionsOf(sequence).filter((a: Json) => a.handler === 'setState')) {
      await dispatcher.dispatchAction(action as any, { data } as any);
    }
  }

  /**
   * 아래 두 판정의 전제 — 진입 시퀀스를 못 찾으면 판정이 공허해진다.
   *
   * @scenario surface=identity_policy_modal
   */
  it('추가·편집 진입 시퀀스를 찾는다', () => {
    expect(addSequence, '정책 추가 진입 시퀀스가 없습니다').toBeDefined();
    expect(editSequence, '정책 편집 진입 시퀀스가 없습니다').toBeDefined();
  });

  /**
   * @scenario surface=identity_policy_modal
   * @effects new_policy_init_state_uses_empty_map_for_conditions, entry_resets_stale_form_before_seed
   */
  it('편집하다 취소한 뒤 「정책 추가」: conditions 가 빈 묶음이고 앞 정책의 user_overrides·id 가 없다', async () => {
    await enter(addSequence!);

    const form = store.identity_policy_form_modal.form;
    expect(form.conditions).toEqual({});
    expect(form.id).toBeNull();
    expect(form.user_overrides).toBeUndefined();
    expect(store.identity_policy_form_modal.isNew).toBe(true);
    expect(store.other).toBe(1);
  });

  /**
   * @scenario surface=identity_policy_modal
   * @effects edit_entry_copies_policy_conditions_into_form, entry_resets_stale_form_before_seed
   */
  it('다른 정책 편집: 그 정책의 조건만 담긴다(앞 정책 조건과 합쳐지지 않는다)', async () => {
    await enter(editSequence!, { policy: { id: 9, key: 'core.profile', purpose: 'self_update', conditions: {}, source_type: 'core' } });

    const form = store.identity_policy_form_modal.form;
    expect(form.id).toBe(9);
    expect(form.conditions).toEqual({});
    expect(form.user_overrides).toEqual([]);
  });
});
