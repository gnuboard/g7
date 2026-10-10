// e2e:allow Vitest 레이아웃 계약 파일 — 일괄 지정 결과 줄의 「변화 없음」 수(unchanged_count)는 7.1.0 조치 G4 에서 Playwright 로 게시판 환경설정 카드를 열어 회원 고르기·확인 창·적용까지 처음부터 끝까지 ko/en/ja 재측정했다(R1~R6).
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
import settingsPage from '../../../layouts/admin/admin_board_settings.json';

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
  it('게시판 수정 권한이 없으면 입력과 적용 버튼이 모두 잠기고, 회원이 없으면 적용이 잠긴다', () => {
    const disabledNodes = collect(section, (n) => typeof n?.props?.disabled === 'string');
    const apply = collect(section, (n) => n?.id === 'bulk_members_apply_button')[0];

    expect(disabledNodes.length).toBeGreaterThanOrEqual(6);
    for (const node of disabledNodes) {
      expect(evaluate(node.props.disabled, { _computed: { bulkMembersLocked: true, isReadOnly: false }, _global: { boardBulkMembers: { userUuids: ['u'] } } }), node.id ?? node.name).toBe(true);
    }
    expect(evaluate(apply.props.disabled, { _computed: { bulkMembersLocked: false }, _global: { boardBulkMembers: { userUuids: [] } } })).toBe(true);
    expect(evaluate(apply.props.disabled, { _computed: { bulkMembersLocked: false }, _global: { boardBulkMembers: { userUuids: ['u'], all: false, boardIds: [] } } })).toBe(true);
    expect(evaluate(apply.props.disabled, { _computed: { bulkMembersLocked: false }, _global: { boardBulkMembers: { userUuids: ['u'], all: false, boardIds: [3] } } })).toBe(false);
  });

  // 서버(bulk-members)는 게시판 수정 권한(boards.update)으로 허용하는데, 카드는 환경설정 수정 권한(settings.update)으로
  // 잠겨 있었다(7.1.0 실측 O-K3-5, 결정 2026-10-08). 카드 잠금은 boards.update 만 따른다.
  // @scenario member_type=manager, mode=add, target=all
  // @effects bulk_members_card_lock_follows_boards_update
  it('환경설정 수정 권한과 무관하게 게시판 수정 권한만으로 카드를 쓴다', () => {
    const disabledNodes = collect(section, (n) => typeof n?.props?.disabled === 'string');
    const user = { userUuids: ['u'] };
    // settings.update 없음(isReadOnly) + boards.update 있음 → 잠기지 않는다
    for (const node of disabledNodes) {
      expect(evaluate(node.props.disabled, { _computed: { isReadOnly: true, bulkMembersLocked: false }, _global: { boardBulkMembers: user } }), node.id ?? node.name).toBe(false);
    }
    // 잠금 안내는 게시판 수정 권한이 없다고 판정됐을 때만 보인다
    const notice = collect(section, (n) => n?.id === 'bulk_members_no_permission')[0];
    expect(notice, '권한 안내가 없습니다').toBeTruthy();
    expect(evaluate(notice.if, { _computed: { bulkMembersNoPermission: true } })).toBe(true);
    expect(evaluate(notice.if, { _computed: { bulkMembersNoPermission: false } })).toBe(false);
    expect(JSON.stringify(notice)).toContain('sirsoft-board.admin.settings.bulk_members.no_permission');
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

describe('게시판 관리자·스텝 일괄 지정 — 회원 검색칸과 결과 줄 (K3 실측 2026-10-07)', () => {
  // 공백 3자를 치면 `filters[0][value]=+++` 로, 지우면 검색어 없이 회원 전체 조회가 나갔다.
  // 게시판 폼 권한 칸·알림 템플릿 특정 회원 칸과 같이 비거나 공백뿐이면 부르지 않는다.
  // @scenario member_type=step, mode=add, target=selected
  // @effects bulk_members_user_search_skips_blank_keyword
  it('회원 검색 요청은 검색어가 비거나 공백뿐이면 보내지 않는다', () => {
    const userInput = collect(section, (n) => n?.id === 'bulk_members_users_input')[0];
    expect(userInput).toBeTruthy();
    const call = collect(userInput, (n) => n?.handler === 'apiCall' && String(n?.target ?? '').includes('/api/admin/users'))[0];
    expect(call).toBeTruthy();
    expect(call.if).toBeTruthy();
    for (const value of ['', '   ', undefined]) {
      expect(evaluate(call.if, { $event: { target: { value } } }), JSON.stringify(value)).toBe(false);
    }
    expect(evaluate(call.if, { $event: { target: { value: 'qa' } } })).toBe(true);
  });

  // 관리 범위 밖 게시판은 화면 선택지(boardOptions)에 없어 결과 줄이 「1 / 관리 권한 범위 밖」 처럼 번호로 보였다.
  // @scenario member_type=step, mode=add, target=all
  // @effects skipped_entries_carry_board_name
  it('건너뛴 줄은 서버가 싣는 board_name 을 먼저 쓴다', () => {
    const label = collect(section, (n) => typeof n?.text === 'string' && n.text.includes('skip.board_id'))[0];
    expect(label).toBeTruthy();
    const render = (skip: any, boardOptions: any[]) => evaluate(label.text, { skip, _computed: { boardOptions } });
    expect(render({ board_id: 1, reason: 'scope', board_name: '공지사항' }, [])).toBe('공지사항');
    expect(render({ board_id: 5, reason: 'last_manager' }, [{ value: 5, label: '자유게시판' }])).toBe('자유게시판');
    expect(render({ board_id: 7, reason: 'scope' }, [])).toBe(7);
  });

  // 결과 줄은 다섯 줄만 보인다(O-K3-12 유지). 「모든 게시판」 이면 범위 밖(scope) 줄이 수십 개라, 운영자가 알아야 할
  // 사유(not_grantable·last_manager)가 그 뒤로 밀려 보이지 않았다(7.1.0 실측 G1a). 범위 밖이 아닌 사유를 앞에 세운다.
  // @scenario member_type=step, mode=add, target=all
  // @effects not_grantable_board_skipped_on_apply_all
  it('결과 줄은 범위 밖이 아닌 사유를 먼저 보인다', () => {
    const li = collect(section, (n) => typeof n?.iteration?.source === 'string' && n.iteration.source.includes('skipped'))[0];
    expect(li, '건너뛴 줄 반복 노드가 없습니다').toBeTruthy();
    const scopeRows = Array.from({ length: 8 }, (_, i) => ({ board_id: i + 1, reason: 'scope' }));
    const skipped = [...scopeRows, { board_id: 99, reason: 'not_grantable' }, { board_id: 98, reason: 'last_manager' }];
    const rows = evaluate(li.iteration.source, { _global: { boardBulkMembers: { result: { skipped } } } });
    expect(rows).toHaveLength(5);
    expect(rows.slice(0, 2).map((r: any) => r.reason).sort()).toEqual(['last_manager', 'not_grantable']);
    expect(skipped[0].reason, '원본 배열을 바꾸지 않는다').toBe('scope');
  });

  // 「모든 게시판」 에서 역할을 줄 수 없는 게시판은 서버가 not_grantable 사유로 건너뛴다(7.1.0 실측 O-K3T-7).
  // @scenario member_type=step, mode=add, target=all
  // @effects not_grantable_board_skipped_on_apply_all
  it('건너뛴 사유 줄은 not_grantable·last_manager·scope 를 각자의 문구로 보인다', () => {
    const reason = collect(section, (n) => typeof n?.text === 'string' && n.text.includes('skip.reason'))[0];
    expect(reason).toBeTruthy();
    expect(reason.text).toContain('sirsoft-board.admin.settings.bulk_members.reason_not_grantable');
    expect(reason.text).toContain('sirsoft-board.admin.settings.bulk_members.reason_last_manager');
    expect(reason.text).toContain('sirsoft-board.admin.settings.bulk_members.reason_scope');
    const $t = (key: string) => key;
    const pick = (r: string) => evaluate(reason.text.replace(/\$t:([\w.-]+)/g, "'$1'"), { skip: { reason: r }, $t });
    expect(pick('not_grantable')).toBe('sirsoft-board.admin.settings.bulk_members.reason_not_grantable');
    expect(pick('last_manager')).toBe('sirsoft-board.admin.settings.bulk_members.reason_last_manager');
    expect(pick('scope')).toBe('sirsoft-board.admin.settings.bulk_members.reason_scope');
  });

  // 역할 행이 없는(데이터 손상) 게시판은 서버 로그 경고뿐이라 운영자가 알 수 없었다 — 서버가 role_missing 사유로 건너뛰고
  // 결과 줄이 그 사유를 자기 문구로 보인다(7.1.0 실측, 결정 2026-10-09). 범위 밖보다 앞에 선다.
  // @scenario member_type=step, mode=add, target=all
  // @effects role_missing_boards_skipped
  it('역할 행이 없는 게시판은 role_missing 문구로 보이고 범위 밖 줄보다 앞에 선다', () => {
    const reason = collect(section, (n) => typeof n?.text === 'string' && n.text.includes('skip.reason'))[0];
    expect(reason.text).toContain('sirsoft-board.admin.settings.bulk_members.reason_role_missing');
    const $t = (key: string) => key;
    const pick = (r: string) => evaluate(reason.text.replace(/\$t:([\w.-]+)/g, "'$1'"), { skip: { reason: r }, $t });
    expect(pick('role_missing')).toBe('sirsoft-board.admin.settings.bulk_members.reason_role_missing');
    expect(pick('scope')).toBe('sirsoft-board.admin.settings.bulk_members.reason_scope');

    const li = collect(section, (n) => typeof n?.iteration?.source === 'string' && n.iteration.source.includes('skipped'))[0];
    const skipped = [...Array.from({ length: 6 }, (_, i) => ({ board_id: i + 1, reason: 'scope' })), { board_id: 77, reason: 'role_missing' }];
    const rows = evaluate(li.iteration.source, { _global: { boardBulkMembers: { result: { skipped } } } });
    expect(rows[0].reason).toBe('role_missing');
  });

  // 「제거」 에서 그 회원이 없는 게시판은 변경에도 제외에도 들지 않아 합이 대상 수보다 작게 보였다(7.1.0 실측 G1a 관측,
  // 결정 2026-10-08). 서버가 싣는 unchanged_count 를 요약 줄에 「변화 없음」 수로 넘긴다.
  // @scenario member_type=step, mode=remove, target=all
  // @effects unchanged_boards_counted
  it('결과 요약 줄은 변경·제외와 함께 변화 없음 수를 넘긴다', () => {
    const summary = collect(section, (n) => typeof n?.text === 'string' && n.text.includes('bulk_members.result_summary'))[0];
    expect(summary, '결과 요약 줄이 없습니다').toBeTruthy();
    const params = Object.fromEntries(
      summary.text.split('|').slice(1).map((pair: string) => {
        const [key, ...rest] = pair.split('=');
        return [key, rest.join('=')];
      }),
    );
    // count 는 단수·복수 갈래(result_summary_one)를 고르는 수 — 바뀐 게시판 수와 같아야 한다
    expect(Object.keys(params).sort()).toEqual(['count', 'skipped', 'unchanged', 'updated']);
    const ctx = { _global: { boardBulkMembers: { result: { updated_count: 1, skipped_count: 2, unchanged_count: 31 } } } };
    expect(evaluate(params.count, ctx)).toBe(evaluate(params.updated, ctx));
    expect(evaluate(params.unchanged, ctx)).toBe(31);
    expect(evaluate(params.unchanged, { _global: { boardBulkMembers: { result: { updated_count: 1 } } } })).toBe(0);
  });
});

/**
 * 게시판 목록 조회 실패의 종류를 가른다 — 목록 조회가 서버 오류(5xx)로 실패해도 fallback(빈 목록)이 abilities 없이 그려져
 * 「게시판 수정 권한이 있어야…」 이유 문구가 떴다(7.1.0 S2 #8). 403 일 때만 권한 없음, 그 밖의 실패는 「불러오지 못함」.
 */
describe('게시판 관리자·스텝 일괄 지정 — 목록 조회 실패 이유 (S2 #8)', () => {
  const page: any = settingsPage;
  const boardsList = page.data_sources.find((ds: any) => ds.id === 'boards_list');
  const computed = (name: string, ctx: Record<string, any>) => evaluate(page.computed[name], ctx);
  const ctxOf = (boards_list: any, error?: string) => ({ boards_list, _local: error ? { bulkMembersBoardsError: error } : {} });
  const FALLBACK = { data: { data: [] } };

  // @scenario member_type=manager, mode=add, target=all
  // @effects bulk_members_reason_distinguishes_forbidden_and_failed
  it('목록 데이터 소스는 403 과 그 밖의 실패를 다른 값으로 남긴다', () => {
    expect(boardsList.errorHandling?.['403']).toEqual({ handler: 'setState', params: { target: 'local', bulkMembersBoardsError: 'forbidden' } });
    expect(boardsList.errorHandling?.default).toEqual({ handler: 'setState', params: { target: 'local', bulkMembersBoardsError: 'failed' } });
  });

  // @scenario member_type=manager, mode=add, target=all
  // @effects bulk_members_reason_distinguishes_forbidden_and_failed
  it('403 이면 권한 없음, 5xx 면 불러오지 못함, 성공이면 abilities 를 따른다', () => {
    const cases: Array<[string, any, string | undefined, boolean, boolean]> = [
      ['403', FALLBACK, 'forbidden', true, false],
      ['500', FALLBACK, 'failed', false, true],
      ['응답 전', undefined, undefined, false, false],
      ['성공(권한 없음)', { data: { data: [], abilities: { can_update: false } } }, undefined, true, false],
      ['성공(권한 있음)', { data: { data: [], abilities: { can_update: true } } }, undefined, false, false],
      ['실패 뒤 다시 성공', { data: { data: [], abilities: { can_update: true } } }, 'failed', false, false],
    ];
    for (const [label, data, error, noPermission, loadFailed] of cases) {
      expect(computed('bulkMembersNoPermission', ctxOf(data, error)), label).toBe(noPermission);
      expect(computed('bulkMembersLoadFailed', ctxOf(data, error)), label).toBe(loadFailed);
      // 어느 실패든 카드는 잠근 채 둔다
      if (!data?.data?.abilities) expect(computed('bulkMembersLocked', ctxOf(data, error)), label).toBe(true);
    }
  });

  // @scenario member_type=manager, mode=add, target=all
  // @effects bulk_members_reason_distinguishes_forbidden_and_failed
  it('불러오지 못함 이유 줄은 bulkMembersLoadFailed 일 때만 보이고 자기 문구를 쓴다', () => {
    const failed = collect(section, (n) => n?.id === 'bulk_members_load_failed')[0];
    expect(failed, '불러오지 못함 안내가 없습니다').toBeTruthy();
    expect(evaluate(failed.if, { _computed: { bulkMembersLoadFailed: true } })).toBe(true);
    expect(evaluate(failed.if, { _computed: { bulkMembersLoadFailed: false } })).toBe(false);
    expect(JSON.stringify(failed)).toContain('sirsoft-board.admin.settings.bulk_members.load_failed');
    expect(JSON.stringify(failed)).not.toContain('bulk_members.no_permission');
  });
});
