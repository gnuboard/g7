/**
 * 게시판 환경설정 — 관리자·스텝 일괄 지정 카드가 설정 폼을 오염시키지 않는가 (#708)
 *
 * 회귀 배경(브라우저 실측): 카드가 설정 화면의 자동 바인딩 폼(`data-key="form"`) 안에 있어,
 * `name` 이 있는 라디오 그룹 3개(대상·구분·작업)가 `_local.form.board_bulk_members_*` 로 자동 바인딩됐다.
 * 그 결과 ① 탭을 옮겨 전역 `boardBulkMembers` 를 비워도 라디오는 폼 값을 따라 「고른 게시판」「제거」 로
 * 남아 화면과 실제 요청 값이 어긋났고 ② 설정 저장 본문에 일괄 지정 입력이 섞일 수 있었다.
 *
 * 카드의 입력은 전역 `boardBulkMembers` 만 쓰므로 `name` 을 가진 입력은 모두 자동 바인딩을 꺼야 한다.
 */

import { describe, it, expect } from 'vitest';
import { actionsOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import section from '../../../layouts/admin/partials/admin_board_settings/_section_bulk_members.json';
import confirmModal from '../../../layouts/admin/partials/admin_board_settings/_modal_bulk_members_confirm.json';

/** 노드 트리에서 조건에 맞는 노드를 모두 모은다. */
function collect(node: any, match: (n: any) => boolean, found: any[] = []): any[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collect(child, match, found));
    return found;
  }
  if (node && typeof node === 'object') {
    if (match(node)) found.push(node);
    Object.values(node).forEach((value) => {
      if (value && typeof value === 'object') collect(value, match, found);
    });
  }
  return found;
}

describe('게시판 관리자·스텝 일괄 지정 — 설정 폼 자동 바인딩 차단 (#708)', () => {
  // @scenario member_type=manager, mode=remove, target=selected
  // @effects bulk_members_inputs_do_not_pollute_settings_form
  it('name 을 가진 입력은 모두 자동 바인딩을 끈다 — 값은 전역 boardBulkMembers 만 따른다', () => {
    const INPUTS = ['RadioGroup', 'Input', 'Select', 'Checkbox', 'Toggle', 'TagInput', 'Textarea'];
    const named = collect(section, (n) => INPUTS.includes(n?.name) && typeof n?.props?.name === 'string');

    expect(named.map((n) => n.props.name).sort()).toEqual([
      'board_bulk_members_mode',
      'board_bulk_members_target',
      'board_bulk_members_type',
    ]);
    for (const node of named) {
      expect(node.props.autoBinding, node.props.name).toBe(false);
      expect(String(node.props.value)).toContain('_global.boardBulkMembers');
    }
  });
});

/** `{{ ... }}` 식 하나를 주어진 문맥으로 평가한다 (엔진과 같은 JS 식). */
function evaluate(expression: string, context: Record<string, any>): any {
  const body = expression.trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(context), `return (${body});`)(...Object.values(context));
}

describe('게시판 관리자·스텝 일괄 지정 — 읽기 전용·요청 본문 (#708)', () => {
  // @scenario member_type=manager, mode=add, target=all
  // @effects role_membership_synced
  it('읽기 전용이면 입력과 적용 버튼이 모두 잠기고, 회원이 없으면 적용이 잠긴다', () => {
    const disabledNodes = collect(section, (n) => typeof n?.props?.disabled === 'string');
    const apply = collect(section, (n) => n?.id === 'bulk_members_apply_button')[0];

    expect(disabledNodes.length).toBeGreaterThanOrEqual(6);
    for (const node of disabledNodes) {
      expect(evaluate(node.props.disabled, { _computed: { isReadOnly: true }, _global: { boardBulkMembers: { userUuids: ['u'] } } }), node.id ?? node.name).toBe(true);
    }
    expect(evaluate(apply.props.disabled, { _computed: { isReadOnly: false }, _global: { boardBulkMembers: { userUuids: [] } } })).toBe(true);
    expect(evaluate(apply.props.disabled, { _computed: { isReadOnly: false }, _global: { boardBulkMembers: { userUuids: ['u'], all: false, boardIds: [] } } })).toBe(true);
    expect(evaluate(apply.props.disabled, { _computed: { isReadOnly: false }, _global: { boardBulkMembers: { userUuids: ['u'], all: false, boardIds: [3] } } })).toBe(false);
  });

  // @scenario member_type=step, mode=remove, target=selected
  // @effects last_manager_board_skipped
  it('확인 창은 대상·구분·작업·회원을 그대로 보내고, 전체 대상이면 board_ids 를 싣지 않으며 연타를 300ms 로 모은다', () => {
    const button = collect(confirmModal, (n) => n?.id === 'bulk_members_confirm_button')[0];
    const click = actionsOf(button).find((a: any) => a.type === 'click');
    const call = collect(click, (n) => n?.handler === 'apiCall')[0];
    const body = call.params.body;
    const state = (bbm: any) => ({ _global: { boardBulkMembers: bbm } });

    expect(click.debounce).toBe(300);
    expect(call.target).toBe('/api/modules/sirsoft-board/admin/boards/bulk-members');
    expect(call.params.method).toBe('POST');

    const selected = { all: false, boardIds: [3, 5], userUuids: ['u1'], type: 'step', mode: 'remove' };
    expect(evaluate(body.apply_all, state(selected))).toBe(false);
    expect(evaluate(body.board_ids, state(selected))).toEqual([3, 5]);
    expect(evaluate(body.member_type, state(selected))).toBe('step');
    expect(evaluate(body.mode, state(selected))).toBe('remove');
    expect(evaluate(body.user_uuids, state(selected))).toEqual(['u1']);

    expect(evaluate(body.apply_all, state({ userUuids: ['u1'] }))).toBe(true);
    expect(evaluate(body.board_ids, state({ userUuids: ['u1'] }))).toBeUndefined();
    expect(evaluate(body.member_type, state({}))).toBe('manager');
    expect(evaluate(body.mode, state({}))).toBe('add');
  });
});
