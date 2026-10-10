// e2e:allow 레이아웃 속성 한 줄의 단위 검증 — 같은 창은 Playwright 브라우저로 ko/en 스위치 접근 이름과 Space 토글을 재실측해 이력(20261008_*_710_KLM_PF)에 남김
/**
 * @file admin-board-notification-template-exclude-trigger-switch.test.ts
 * @description 게시판 환경설정 알림 템플릿 편집 창 — 「이벤트 유발자 제외」 스위치가 접근 이름을 갖는다
 *
 * 회귀 배경(7.1.0 실측 P-5): 스위치 옆 글자를 따로 둔 span 으로 그려 스위치와 이어지지 않았다.
 * 화면 읽기 프로그램에는 이름 없는 스위치로 읽혔다. 스위치 label 로 이름과 옆 글자를 함께 준다.
 */

import { describe, it, expect } from 'vitest';
import modal from '../../../layouts/admin/partials/admin_board_settings/_modal_notification_template_edit.json';

type Json = Record<string, any>;

const KEY = '$t:admin.settings.notification_definitions.recipients.exclude_trigger_user';

/** 조건에 맞는 노드를 모두 찾는다. */
function findAll(node: unknown, pred: (n: Json) => boolean, acc: Json[] = []): Json[] {
  if (Array.isArray(node)) {
    for (const item of node) findAll(item, pred, acc);
    return acc;
  }
  if (node && typeof node === 'object') {
    const obj = node as Json;
    if (pred(obj)) acc.push(obj);
    for (const v of Object.values(obj)) findAll(v, pred, acc);
  }
  return acc;
}

describe('게시판 환경설정 알림 템플릿 편집 창 — 이벤트 유발자 제외 스위치 이름', () => {
  const toggles = findAll(modal, (n) => n.name === 'Toggle' && String(n.props?.checked ?? '').includes('exclude_trigger_user'));

  /**
   * @scenario case=board_exclude_trigger_switch
   * @effects exclude_trigger_switch_has_accessible_name
   */
  it('스위치가 label 로 이름을 받는다', () => {
    expect(toggles).toHaveLength(1);
    expect(toggles[0].props.label).toBe(KEY);
  });

  /**
   * @scenario case=board_exclude_trigger_switch
   * @effects exclude_trigger_switch_text_shown_once
   */
  it('옆 글자를 따로 두지 않아 문구가 한 번만 보인다', () => {
    const loose = findAll(modal, (n) => n.name !== 'Toggle' && (n.text === KEY || n.props?.text === KEY));
    expect(loose).toHaveLength(0);
  });
});
