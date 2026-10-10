// e2e:allow 권한 게이트(입력·버튼 잠금) 레이아웃 계약 검증 — 브라우저 실측은 W1·W2·W3 통합 뒤 총괄 세션이 권한 회수 계정으로 함께 한다(이력 20261010_0208_710_abilities수정_W3)
/**
 * @file plugin-settings-read-only.test.ts
 * @description 플러그인 설정 화면 읽기 전용 (7.1.0 abilities 게이트 G19)
 *
 * 코어 설정 응답의 `data.abilities.can_update`(= core.plugins.update)가 true 가 아니면 — 응답 전·키 없음 포함(fail-closed) —
 * 안내를 보이고 설정 입력·저장·쓰기 버튼을 잠근다. 저장 PUT 은 코어가 본문의 abilities 를 버린다.
 */

import { describe, it, expect } from 'vitest';
import layout from '../../../layouts/admin/plugin_settings.json';

type Node = Record<string, any>;

const INPUT_NAMES = ['Input', 'Select', 'Textarea', 'Toggle', 'TagInput', 'MultilingualInput', 'Checkbox', 'HtmlEditor'];
const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

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
  if (typeof raw === 'boolean') return raw;
  const expr = String(raw).trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(scope), `return (${expr});`)(...Object.values(scope));
}

/** 잠금 상태의 평가 범위 */
const LOCKED = { $parent: { _computed: { isPluginReadOnly: true } }, _computed: { isPluginReadOnly: true, isMessagingReadOnly: true, isNotificationReadOnly: true }, _local: {}, _global: {}, query: {} };

/** 한 레이아웃의 읽기 전용 계약을 검사한다 */
function assertReadOnly(tree: Node, settingsId: string, scopeId: string | null) {
  const computed = (tree.computed ?? {}) as Record<string, string>;
  expect(computed.isPluginReadOnly, 'isPluginReadOnly computed 가 없습니다').toBeTruthy();
  expect(evaluate(computed.isPluginReadOnly, { [settingsId]: { data: {} } })).toBe(true);
  expect(evaluate(computed.isPluginReadOnly, { [settingsId]: undefined })).toBe(true);
  expect(evaluate(computed.isPluginReadOnly, { [settingsId]: { data: { abilities: { can_update: true } } } })).toBe(false);

  const banner = collect(tree, (n) => n.id === 'plugin_read_only_banner')[0];
  expect(banner, '읽기 전용 안내가 없습니다').toBeTruthy();
  expect(evaluate(banner.if, { _computed: { isPluginReadOnly: true }, [settingsId]: { data: {} } })).toBe(true);

  const root = scopeId ? collect(tree, (n) => n.id === scopeId)[0] : tree;
  expect(root, `잠금 범위 ${scopeId} 가 없습니다`).toBeTruthy();
  const inputs = collect(root, (n) => INPUT_NAMES.includes(n.name) && n.props?.readOnly !== true && n.props?.disabled !== true && !String(n.props?.value ?? '').includes('query.'));
  expect(inputs.length).toBeGreaterThan(0);
  for (const input of inputs) {
    expect(evaluate(input.props?.disabled ?? false, LOCKED), `${input.name} ${input.props?.name ?? input.id ?? ''}`).toBe(true);
  }
  const writeButtons = collect(root, (n) => n.name === 'Button' && collect(n, (a) => a.handler === 'apiCall' && WRITE_METHODS.includes(String(a.params?.method ?? ''))).length > 0);
  expect(writeButtons.length).toBeGreaterThan(0);
  for (const button of writeButtons) {
    expect(evaluate(button.props?.disabled ?? false, LOCKED), `Button ${button.id ?? ''}`).toBe(true);
  }
}

describe('플러그인 설정 읽기 전용 (G19)', () => {
  /**
   * 수정 권한 판정(can_update)이 없거나 false 면 안내·입력·저장·쓰기 버튼이 잠긴다.
   *
   * @scenario case=plugin_settings_without_update
   *
   * @effects plugin_settings_locked_read_only
   */
  it('sirsoft-message_bizppurio 설정 화면', () => {
    assertReadOnly(layout as Node, 'settings', null);
  });

  /**
   * 템플릿·SMS 편집 창에서 내용을 바꾸는 버튼(메시지·강조 유형 선택, 아이템·버튼·바로연결 행 추가·삭제,
   * 치환 변수 끼워 넣기)도 입력란과 같은 메시징 관리 조건으로 잠긴다.
   *
   * @scenario case=plugin_settings_without_update
   *
   * @effects template_modal_edit_buttons_locked
   */
  it('템플릿·SMS 편집 창의 내용 변경 버튼이 잠긴다', () => {
    // 창을 여는 버튼·서버에 보내는 버튼은 제외한다(여는 버튼은 조회용으로 열려 있고, 보내는 버튼은 위 계약이 잠근다)
    const buttons = collect(layout, (n) => n.name === 'Button' && collect(n.actions ?? [], isContentEditAction).length > 0 && collect(n.actions ?? [], isOpenOrSend).length === 0);
    // 메시지 유형 4 · 강조 유형 4 · 행 추가 3 · 행 삭제 3 · 변수 칩(알림톡 1 · SMS 1)
    expect(buttons.length).toBe(16);
    for (const button of buttons) {
      expect(evaluate(button.props?.disabled ?? false, LOCKED), `Button ${JSON.stringify(button.actions ?? []).slice(0, 80)}`).toBe(true);
    }
    const open = { _computed: { isPluginReadOnly: false, isMessagingReadOnly: false }, _local: {}, _global: {}, query: {} };
    expect(buttons.filter((b) => !evaluate(b.props?.disabled ?? false, open)).length).toBe(16);
  });
});

/** 창을 열거나 서버에 보내는 액션인지 */
function isOpenOrSend(a: Node): boolean {
  return a.handler === 'openModal' || a.handler === 'apiCall';
}

/** 편집 중인 템플릿·SMS 내용을 바꾸는 액션인지 */
function isContentEditAction(a: Node): boolean {
  if (String(a.handler ?? '').endsWith('.insertVariable')) return true;
  return a.handler === 'setState'
    && Object.keys(a.params ?? {}).some((k) => k.startsWith('bz_tpl_modal.content') || k.startsWith('bz_sms_modal.body'));
}
