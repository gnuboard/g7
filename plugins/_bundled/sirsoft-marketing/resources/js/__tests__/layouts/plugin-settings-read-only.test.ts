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
  it('sirsoft-marketing 설정 화면', () => {
    assertReadOnly(layout as Node, 'settings', null);
  });

  /**
   * 채널 목록을 바꾸는 버튼(추가·편집·삭제·편집 창 확정·비활성 채널 되살리기)도 잠긴다.
   * 잠기지 않으면 저장은 막혀도 화면의 목록에서 행이 사라지거나 바뀐다.
   *
   * @scenario case=plugin_settings_without_update
   *
   * @effects channel_list_buttons_locked_read_only
   */
  it('채널 추가·편집·삭제 버튼과 채널 창의 확정 버튼이 잠긴다', () => {
    const changesChannels = (a: Node) =>
      (a.handler === 'setState' && a.params && Object.prototype.hasOwnProperty.call(a.params, 'channels'))
      || (a.handler === 'openModal' && (a.target ?? a.params?.target) === 'channel_edit_modal');
    const buttons = collect(layout, (n) => n.name === 'Button' && collect(n.actions ?? [], changesChannels).length > 0);
    // 추가 1 · 편집 1 · 삭제 1 · 편집 창 확정 1 · 되살리기 1
    expect(buttons.length).toBe(5);
    for (const button of buttons) {
      expect(evaluate(button.props?.disabled ?? false, LOCKED), `Button ${button.id ?? button.props?.['aria-label'] ?? ''}`).toBe(true);
    }
    // 수정 권한이 있으면 추가·편집·삭제 버튼은 열려 있다(편집 창 확정은 키 검증으로만 잠긴다)
    const OPEN = { _computed: { isPluginReadOnly: false }, _local: {}, _global: { editingChannel: { key: 'sms', _editing: true }, channels: [] }, query: {} };
    const enabled = buttons.filter((b) => !evaluate(b.props?.disabled ?? false, OPEN));
    expect(enabled.length).toBe(5);
  });
});
