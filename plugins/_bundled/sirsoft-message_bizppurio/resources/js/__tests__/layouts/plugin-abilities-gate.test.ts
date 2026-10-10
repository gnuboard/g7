// e2e:allow 권한 게이트(입력·버튼 잠금) 레이아웃 계약 검증 — 브라우저 실측은 W1·W2·W3 통합 뒤 총괄 세션이 권한 회수 계정으로 함께 한다(이력 20261010_0208_710_abilities수정_W3)
/**
 * @file plugin-abilities-gate.test.ts
 * @description 비즈뿌리오 확장 화면 abilities 게이트 (7.1.0 abilities 게이트 plugins #12·N6)
 *
 * - 알림 템플릿 편집 창의 비즈뿌리오 섹션: 메시징 관리 권한(templates/map 응답 abilities)으로 입력·쓰기 버튼 잠금
 * - 하단 등록·검수 신청 버튼: 메시징 관리 권한자에게만 그린다(노드 권한)
 * - 환경설정 알림 탭의 [설정하기]: 플러그인 설정 수정 권한자에게만 그린다(노드 권한)
 */

import { describe, it, expect } from 'vitest';
import sections from '../../../extensions/notification_template_form_sections.json';
import footer from '../../../extensions/notification_template_form_footer_actions.json';
import tabCore from '../../../extensions/notification_tab_core.json';
import tabBoard from '../../../extensions/notification_tab_board.json';
import tabEcommerce from '../../../extensions/notification_tab_ecommerce.json';

type Node = Record<string, any>;

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

const WRITE = ['POST', 'PUT', 'PATCH', 'DELETE'];

describe('비즈뿌리오 확장 abilities (#12·N6)', () => {
  /**
   * 메시징 관리 권한 판정이 없거나 false 면 템플릿 섹션의 입력·쓰기 버튼이 잠긴다.
   *
   * @scenario case=template_section_without_manage
   *
   * @effects template_section_locked
   */
  it('템플릿 섹션 입력·쓰기 버튼 잠금', () => {
    const scope = { bizppurioTemplates: { data: { templates: {} } }, _global: {}, _local: {} };
    const inputs = collect(sections, (n) => ['Input', 'Select', 'Textarea'].includes(n.name));
    expect(inputs.length).toBeGreaterThan(0);
    inputs.forEach((i) => expect(evaluate(i.props?.disabled ?? false, scope)).toBe(true));
    const buttons = collect(sections, (n) => n.name === 'Button' && collect(n, (a) => a.handler === 'apiCall' && WRITE.includes(String(a.params?.method ?? ''))).length > 0);
    expect(buttons.length).toBeGreaterThan(0);
    buttons.forEach((b) => expect(evaluate(b.props?.disabled ?? false, scope)).toBe(true));
  });

  /**
   * 내용을 바꾸는 버튼(메시지·강조 유형 선택, 아이템·버튼·바로연결 행 추가·삭제, 치환 변수 끼워 넣기)도
   * 입력란과 같은 메시징 관리 조건으로 잠기고, 권한이 있으면 열린다.
   *
   * @scenario case=template_section_without_manage
   *
   * @effects template_section_edit_buttons_locked
   */
  it('템플릿 섹션 내용 변경 버튼 잠금', () => {
    const isContentEdit = (a: Node) => String(a.handler ?? '').endsWith('.insertVariable')
      || (a.handler === 'setState' && Object.keys(a.params ?? {}).some((k) => k.startsWith('bz_tpl_modal.content') || k.startsWith('bz_sms_modal.body')));
    // 서버에 보내는 버튼은 제외한다(위 쓰기 버튼 계약이 잠근다)
    const buttons = collect(sections, (n) => n.name === 'Button' && collect(n.actions ?? [], isContentEdit).length > 0 && collect(n.actions ?? [], (a) => a.handler === 'apiCall').length === 0);
    // 메시지 유형 4 · 강조 유형 4 · 행 추가 3 · 행 삭제 3 · 변수 칩(알림톡 1 · SMS 1)
    expect(buttons.length).toBe(16);
    const locked = { bizppurioTemplates: { data: { templates: {} } }, _global: {}, _local: {} };
    buttons.forEach((b) => expect(evaluate(b.props?.disabled ?? false, locked), JSON.stringify(b.actions ?? []).slice(0, 80)).toBe(true));
    const open = { bizppurioTemplates: { data: { abilities: { can_manage_messaging: true } } }, _global: {}, _local: {} };
    expect(buttons.filter((b) => !evaluate(b.props?.disabled ?? false, open)).length).toBe(16);
  });

  /**
   * 하단 등록·검수 신청 버튼은 메시징 관리 권한자에게만 그린다.
   *
   * @scenario case=template_section_without_manage
   *
   * @effects footer_buttons_require_manage
   */
  it('하단 버튼 노드 권한', () => {
    const buttons = collect(footer, (n) => n.name === 'Button');
    expect(buttons.length).toBe(2);
    buttons.forEach((b) => expect(b.permissions).toEqual(['sirsoft-message_bizppurio.messaging.manage']));
  });

  /**
   * 알림 탭의 [설정하기]는 플러그인 설정 수정 권한자에게만 그린다.
   *
   * @scenario case=settings_shortcut_without_plugin_update
   *
   * @effects settings_shortcut_requires_plugin_update
   */
  it('[설정하기] 노드 권한', () => {
    for (const tree of [tabCore, tabBoard, tabEcommerce]) {
      const buttons = collect(tree, (n) => n.name === 'Button' && JSON.stringify(n).includes('/admin/plugins/sirsoft-message_bizppurio/settings'));
      expect(buttons.length).toBeGreaterThan(0);
      buttons.forEach((b) => expect(b.permissions).toEqual(['core.plugins.update']));
    }
  });
});
