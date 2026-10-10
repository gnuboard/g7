// e2e:allow 권한 게이트(입력·버튼 잠금) 레이아웃 계약 검증 — 브라우저 실측은 W1·W2·W3 통합 뒤 총괄 세션이 권한 회수 계정으로 함께 한다(이력 20261010_0208_710_abilities수정_W3)
/**
 * @file AdminMemoAbilityGate.test.ts
 * @description hello 관리자 메모 화면 abilities 게이트 (7.1.0 abilities 게이트 page-hello #7·#8)
 *
 * - 목록 「새 메모」: 컬렉션 abilities.can_create 로 잠근다(#7)
 * - 메모 폼: 생성은 can_create, 수정은 can_update 로 읽기 전용 — 안내·입력·저장이 잠긴다(#8)
 *
 * @scenario-manifest tests/scenarios/admin-memo-ability-gate.yaml
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

type Node = Record<string, any>;

/** 짝 JSON 을 읽는다 */
function load(name: string): Node {
  return JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../../layouts/admin', name), 'utf-8'));
}

/** 트리에서 조건에 맞는 노드를 모두 모은다 */
function collect(node: unknown, pred: (n: Node) => boolean, out: Node[] = []): Node[] {
  if (Array.isArray(node)) {
    node.forEach((v) => collect(v, pred, out));
    return out;
  }
  if (node && typeof node === 'object') {
    if (pred(node as Node)) out.push(node as Node);
    Object.values(node).forEach((v) => collect(v, pred, out));
  }
  return out;
}

/** `{{ ... }}` 표현식을 주어진 이름들로 평가한다 */
function evaluate(raw: unknown, scope: Record<string, unknown>): any {
  const expr = String(raw).trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(scope), `return (${expr});`)(...Object.values(scope));
}

describe('hello 메모 목록 「새 메모」 (#7)', () => {
  /**
   * 등록 권한이 없으면 「새 메모」가 잠긴다.
   *
   * @scenario case=memo_list_without_create
   *
   * @effects memo_create_button_locked
   */
  it('can_create=false 면 disabled', () => {
    const button = collect(load('admin_memo_list.json'), (n) => n.id === 'memo_create_button')[0];
    expect(evaluate(button.props.disabled, { memos: { data: { abilities: { can_create: false } } } })).toBe(true);
    expect(evaluate(button.props.disabled, { memos: { data: { abilities: { can_create: true } } } })).toBe(false);
  });
});

describe('hello 메모 폼 읽기 전용 (#8)', () => {
  const form = load('admin_memo_form.json');
  const isReadOnly = (form.computed ?? {}).isReadOnly;

  /**
   * 생성은 can_create, 수정은 can_update 로 읽기 전용이 된다.
   *
   * @scenario case=memo_form_without_permission
   *
   * @effects memo_form_read_only
   */
  it('isReadOnly 가 모드별 권한을 본다', () => {
    expect(isReadOnly, 'isReadOnly computed 가 없습니다').toBeTruthy();
    expect(evaluate(isReadOnly, { route: {}, memoCreateAbility: { data: { abilities: { can_create: false } } }, memoData: null })).toBe(true);
    expect(evaluate(isReadOnly, { route: {}, memoCreateAbility: { data: { abilities: { can_create: true } } }, memoData: null })).toBe(false);
    expect(evaluate(isReadOnly, { route: { id: 3 }, memoCreateAbility: null, memoData: { data: { abilities: { can_update: false } } } })).toBe(true);
    expect(evaluate(isReadOnly, { route: { id: 3 }, memoCreateAbility: null, memoData: { data: { abilities: { can_update: true } } } })).toBe(false);
  });

  /**
   * 권한 응답이 아직 없거나 실패(fallback null)하면 입력·저장은 잠긴 쪽이고, 안내는 응답이 권한 없음을 말할 때만 뜬다.
   *
   * @scenario case=memo_form_ability_unknown
   *
   * @effects memo_form_fail_closed
   */
  it('권한 응답이 없으면 잠그되 안내는 띄우지 않는다', () => {
    expect(evaluate(isReadOnly, { route: {}, memoCreateAbility: null, memoData: null })).toBe(true);
    expect(evaluate(isReadOnly, { route: {}, memoCreateAbility: { data: null }, memoData: null })).toBe(true);
    expect(evaluate(isReadOnly, { route: { id: 3 }, memoCreateAbility: null, memoData: null })).toBe(true);

    const noticeVisible = (form.computed ?? {}).readOnlyNoticeVisible;
    expect(noticeVisible, 'readOnlyNoticeVisible computed 가 없습니다').toBeTruthy();
    expect(evaluate(noticeVisible, { route: {}, memoCreateAbility: null, memoData: null })).toBe(false);
    expect(evaluate(noticeVisible, { route: { id: 3 }, memoCreateAbility: null, memoData: null })).toBe(false);
    expect(evaluate(noticeVisible, { route: {}, memoCreateAbility: { data: { abilities: { can_create: false } } }, memoData: null })).toBe(true);
    expect(evaluate(noticeVisible, { route: {}, memoCreateAbility: { data: { abilities: { can_create: true } } }, memoData: null })).toBe(false);
    expect(evaluate(noticeVisible, { route: { id: 3 }, memoCreateAbility: null, memoData: { data: { abilities: { can_update: false } } } })).toBe(true);

    const banner = collect(form, (n) => n.id === 'memo_read_only_banner')[0];
    expect(evaluate(banner.if, { _computed: { isReadOnly: true, readOnlyNoticeVisible: false } })).toBe(false);
    expect(evaluate(banner.if, { _computed: { isReadOnly: true, readOnlyNoticeVisible: true } })).toBe(true);
  });

  /**
   * 읽기 전용이면 안내가 보이고 입력·저장이 잠긴다.
   *
   * @scenario case=memo_form_without_permission
   *
   * @effects memo_form_read_only
   */
  it('안내·입력·저장 잠금', () => {
    const scope = { _computed: { isReadOnly: true, readOnlyNoticeVisible: true }, route: { id: 3 }, memoData: { data: {} } };
    expect(evaluate(collect(form, (n) => n.id === 'memo_read_only_banner')[0].if, scope)).toBe(true);
    const inputs = collect(form, (n) => n.props?.name === 'title' || n.props?.name === 'content');
    expect(inputs.length).toBe(2);
    inputs.forEach((i) => expect(evaluate(i.props.disabled, scope)).toBe(true));
    expect(evaluate(collect(form, (n) => n.id === 'memo_save_button')[0].props.disabled, scope)).toBe(true);
  });
});
