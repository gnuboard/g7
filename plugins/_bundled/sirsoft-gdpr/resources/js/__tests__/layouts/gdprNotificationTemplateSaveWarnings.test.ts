/**
 * @file gdprNotificationTemplateSaveWarnings.test.ts
 * @description 개인정보 플러그인 알림 템플릿 편집 창의 저장 경고와 회원 항목 변수 묶음 (#490)
 *
 * @scenario-manifest tests/scenarios/notification-template-save-warnings.yaml
 *
 * 이 창은 코어 알림 템플릿 저장 API 로 저장한다. 저장 응답의 `data.warnings` 는 제목, 본문, 이동 주소가 쓰는 회원 항목
 * 변수 가운데 지금 없는 것의 목록이다. 저장은 그대로 되므로 목록 재조회와 성공 안내는 유지하되, 경고가 있으면 창을 닫지
 * 않고 창 안의 경고 상자와 경고 토스트로 알린다. 경고가 없으면 종전처럼 창을 닫고 창 이름 공간을 비운다.
 * 코어 창(`_modal_notification_template_form`)과 같은 갈래이고 문구도 코어 키를 쓴다.
 *
 * 구조 단언만으로는 식이 런타임에 무엇을 만드는지 보장하지 못하므로 레이아웃에 적힌 식을 엔진에 넣어 잰다.
 */

import { describe, it, expect } from 'vitest';
import { dataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { actionsOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import modal from '../../../layouts/admin/partials/plugin_settings/_modal_notification_template_edit.json';

type Json = Record<string, any>;

const NS = 'gdpr_notification_template_form_modal';
const MODAL_ID = 'modal_gdpr_notification_template_edit';
const DATA_SOURCE = 'gdprNotificationDefinitions';
const PREFIX = 'gdpr_template';

/**
 * 조건에 맞는 노드를 모두 찾는다.
 *
 * @param node 탐색 대상
 * @param pred 조건
 * @param acc 누적 목록
 * @returns 찾은 노드 목록
 */
function findAll(node: unknown, pred: (n: Json) => boolean, acc: Json[] = []): Json[] {
  if (Array.isArray(node)) {
    node.forEach((item) => findAll(item, pred, acc));
    return acc;
  }
  if (node && typeof node === 'object') {
    const obj = node as Json;
    if (pred(obj)) acc.push(obj);
    Object.values(obj).forEach((v) => findAll(v, pred, acc));
  }
  return acc;
}

/**
 * id 로 노드 하나를 찾는다(없거나 둘 이상이면 실패).
 *
 * @param id 노드 id
 * @returns 노드
 */
function byId(id: string): Json {
  const nodes = findAll(modal, (n) => n.id === id);
  expect(nodes.length, `${id} 노드`).toBe(1);
  return nodes[0];
}

/**
 * `{{ … }}` 한 덩어리 바인딩을 값으로 평가한다.
 *
 * @param binding 바인딩 문자열
 * @param context 평가 문맥
 * @returns 평가 값
 */
function evalBinding(binding: string, context: Json): unknown {
  return dataBindingEngine.evaluateExpression(binding.trim().replace(/^\{\{|\}\}$/g, ''), context);
}

const WARNING = {
  step: 'template_variable',
  target: 'member.custom.job',
  message: '지금 없는 변수입니다: {member.custom.job}',
  level: 'warning',
};

/** 저장 요청(PUT) 액션 */
const saveCall = findAll(modal, (n) => n.handler === 'apiCall' && n.params?.method === 'PUT')[0];
const onSuccess: Json[] = saveCall.onSuccess;

/**
 * 응답으로 성공 처리 가운데 실제로 도는 액션만 고른다(조건이 없으면 늘 돈다).
 *
 * @param response 저장 응답
 * @returns 도는 액션 목록
 */
function ranActions(response: Json): Json[] {
  return onSuccess.filter((a) => a.if === undefined || evalBinding(a.if, { response }) === true);
}

describe('개인정보 플러그인 알림 템플릿 편집 창 — 저장 경고', () => {
  /**
   * 경고가 없으면 종전 동작이다: 목록을 다시 받고 성공을 알리고 창을 닫고 창 이름 공간을 비운다.
   *
   * @scenario case=save_without_warnings
   * @effects ext_template_save_without_warnings_closes_modal
   */
  it('경고가 없으면 종전처럼 창을 닫고 창 상태를 비운다', () => {
    for (const response of [{ data: { id: 3, warnings: [] } }, { data: { id: 3 } }, {}]) {
      const ran = ranActions(response);

      expect(ran.map((a) => a.handler)).toEqual(['refetchDataSource', 'toast', 'closeModal', 'setState']);
      expect(ran[0].params.dataSourceId).toBe(DATA_SOURCE);
      expect(ran[1].params.type).toBe('success');
      expect(ran[2].target).toBe(MODAL_ID);
      expect(ran[3].params[NS]).toBeNull();
    }
  });

  /**
   * 경고가 있어도 저장은 된 것이다: 목록 재조회와 성공 안내는 그대로 하고, 창은 닫지 않은 채 경고를 창 상태에 담고
   * 경고 토스트를 띄운다. 저장 중 표시도 풀어야 흐림이 걷히고 단추가 다시 눌린다. 갈래는 응답 값으로만 가른다.
   *
   * @scenario case=save_with_warnings
   * @effects ext_template_save_with_warnings_keeps_modal_open_and_shows_list
   */
  it('경고가 있으면 창이 닫히지 않고 경고를 창 상태에 담아 보인다', () => {
    const response = { data: { id: 3, warnings: [WARNING] } };
    const ran = ranActions(response);

    expect(ran.map((a) => a.handler)).toEqual(['refetchDataSource', 'toast', 'setState', 'toast']);
    expect(ran.some((a) => a.handler === 'closeModal')).toBe(false);

    // 창 이름 공간을 통째로 비우지 않고 두 키만 쓴다
    expect(Object.keys(ran[2].params).sort()).toEqual([`${NS}.isSaving`, `${NS}.warnings`, 'target'].sort());
    expect(ran[2].params[`${NS}.isSaving`]).toBe(false);
    expect(evalBinding(ran[2].params[`${NS}.warnings`], { response })).toEqual([WARNING]);

    expect(ran[3].params.type).toBe('warning');
    expect(ran[3].params.message).toBe(
      '$t:admin.settings.notification_definitions.edit_modal.warnings_toast|count={{(response?.data?.warnings ?? []).length}}',
    );

    // 성공 처리의 조건은 응답만 읽는다
    const conditions = onSuccess.map((a) => a.if).filter((c) => c !== undefined);
    expect(conditions.length).toBe(4);
    conditions.forEach((c) => {
      expect(c).toContain('response?.data?.warnings');
      expect(c).not.toContain('_global');
    });

    // 경고 상자는 창 상태의 warnings 가 비어 있지 않을 때만 보이고 항목의 message 를 줄마다 보인다
    const box = byId(`${PREFIX}_save_warnings`);
    expect(box.props.role).toBe('alert');
    expect(evalBinding(box.if, { _global: { [NS]: { warnings: [WARNING] } } })).toBe(true);
    expect(evalBinding(box.if, { _global: { [NS]: { warnings: [] } } })).toBe(false);
    expect(evalBinding(box.if, { _global: { [NS]: { warnings: null } } })).toBe(false);
    expect(evalBinding(box.if, { _global: { [NS]: null } })).toBe(false);
    expect(String(box.props.className)).toContain('dark:');

    const item = byId(`${PREFIX}_save_warning_{{warnIdx}}`);
    expect(item.iteration.source).toBe(`{{_global.${NS}?.warnings ?? []}}`);
    expect(item.iteration.item_var).toBe('warn');
    expect(evalBinding(item.text, { warn: WARNING })).toBe(WARNING.message);
    expect(evalBinding(item.text, { warn: { target: 'member.custom.job' } })).toBe('member.custom.job');
    expect(String(item.props.className)).toContain('break-words');

    // 스크롤 영역의 형제이고 그보다 앞에 놓여 본문을 내린 채 저장해도 보인다
    const container = byId(`${PREFIX}_form_container`);
    const children = container.children as Json[];
    const boxIndex = children.findIndex((c) => c.id === `${PREFIX}_save_warnings`);
    const scrollIndex = children.findIndex((c) => String(c.props?.className ?? '').includes('overflow-y-auto'));
    expect(boxIndex).toBeGreaterThanOrEqual(0);
    expect(scrollIndex).toBeGreaterThan(boxIndex);
  });

  /**
   * 다시 저장하면 지난 경고를 먼저 지운다. 고친 뒤 저장했는데 옛 경고가 남으면 고쳐지지 않은 것으로 읽힌다.
   * 경고만 보이는 상태의 취소 단추는 되돌릴 것이 없으므로 「닫기」로 읽힌다.
   *
   * @scenario case=resave_clears_warnings
   * @effects ext_template_resave_clears_previous_warnings, ext_template_cancel_button_reads_close_after_warned_save
   */
  it('다시 저장하면 경고가 비워지고, 경고가 보이는 동안 취소 단추는 닫기로 읽힌다', () => {
    const saveButton = findAll(modal, (n) => n.name === 'Button' && JSON.stringify(actionsOf(n)).includes('"method":"PUT"'))[0];
    const sequence = actionsOf(saveButton)[0];
    const first = (sequence.actions ?? sequence.params?.actions)[0];

    expect(first.handler).toBe('setState');
    expect(first.params[`${NS}.isSaving`]).toBe(true);
    expect(first.params[`${NS}.errors`]).toBeNull();
    expect(first.params[`${NS}.warnings`]).toBeNull();

    const cancelLabel = findAll(modal, (n) => typeof n.text === 'string' && n.text.includes('$t:common.cancel'));
    expect(cancelLabel.length).toBe(1);
    const label = (warnings: unknown) =>
      String(dataBindingEngine.resolveBindings(cancelLabel[0].text, { _global: { [NS]: { warnings } } }));
    expect(label([WARNING])).toMatch(/common\.close$/);
    expect(label([])).toMatch(/common\.cancel$/);
    expect(label(undefined)).toMatch(/common\.cancel$/);
  });
});

describe('개인정보 플러그인 알림 템플릿 편집 창 — 회원 항목 변수 묶음', () => {
  const variables = [
    { key: 'name', description: '받는 사람 이름' },
    { key: 'app_name', description: '사이트 이름' },
    { key: 'member.display_name', description: '표시 이름', group: 'member' },
    { key: 'member.nickname', description: '닉네임', group: 'member' },
    { key: 'member.custom.job', description: '직업', group: 'member' },
  ];
  const state = (extra: Json = {}, list: Json[] = variables) => ({ _global: { [NS]: { definition: { variables: list }, ...extra } } });

  /**
   * 서버가 정의 응답의 variables 뒤에 회원 공통 변수를 group: member 로 덧붙인다. 이 알림의 변수와 회원 항목 묶음을
   * 갈라 그리고, 회원 변수가 없으면(본인인증 알림) 묶음을 그리지 않는다. 묶음은 접힌 채로 시작하고 제목 단추로 펼친다.
   *
   * @scenario case=member_variables_group
   * @effects ext_template_variables_split_into_own_and_member_groups, ext_template_member_variable_group_is_collapsible
   */
  it('이 알림의 변수와 회원 항목 변수를 갈라 그리고, 회원 묶음은 접혀서 시작한다', () => {
    const own = byId(`${PREFIX}_variables_own`);
    const ownEach = findAll(own, (n) => typeof n.iteration?.source === 'string')[0];
    expect((evalBinding(ownEach.iteration.source, state()) as Json[]).map((v) => v.key)).toEqual(['name', 'app_name']);

    const member = byId(`${PREFIX}_variables_member`);
    const list = byId(`${PREFIX}_variables_member_list`);
    const memberEach = findAll(list, (n) => typeof n.iteration?.source === 'string')[0];
    expect((evalBinding(memberEach.iteration.source, state()) as Json[]).map((v) => v.key)).toEqual([
      'member.display_name',
      'member.nickname',
      'member.custom.job',
    ]);

    expect(evalBinding(member.if, state())).toBe(true);
    expect(evalBinding(member.if, state({}, variables.slice(0, 2)))).toBe(false);
    expect(evalBinding(own.if, state({}, variables.slice(2)))).toBe(false);

    const toggle = byId(`${PREFIX}_variables_member_toggle`);
    expect(evalBinding(list.if, state())).toBe(false);
    expect(evalBinding(list.if, state({ memberVariablesOpen: true }))).toBe(true);
    expect(toggle.props['aria-controls']).toBe(`${PREFIX}_variables_member_list`);
    expect(evalBinding(toggle.props['aria-expanded'], state())).toBe(false);
    expect(String(toggle.props.className)).toContain('min-h-[44px]');

    const action = actionsOf(toggle)[0];
    expect(action.handler).toBe('setState');
    const next = action.params[`${NS}.memberVariablesOpen`];
    expect(evalBinding(next, state())).toBe(true);
    expect(evalBinding(next, state({ memberVariablesOpen: true }))).toBe(false);

    const title = findAll(toggle, (n) => typeof n.text === 'string' && n.text.includes('member_variables_title'))[0];
    expect(title.text).toContain("v.group === 'member'");
  });
});
