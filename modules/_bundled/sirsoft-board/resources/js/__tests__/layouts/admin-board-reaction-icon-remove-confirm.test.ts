/**
 * @file admin-board-reaction-icon-remove-confirm.test.ts
 * @description 게시판 환경설정 반응 유형 아이콘 — 업로더 「삭제」가 확인 창을 거치는지 (7.1.0 실측 O-K1-5, 결정 2026-10-08)
 *
 * 아이콘 업로더의 「삭제」는 저장을 누르기 전에 서버의 첨부와 파일을 곧바로 지운다. 확인 없이 지우면 실수로 눌러도
 * 되돌릴 수 없었다. 넓은 화면 표, 좁은 화면 카드, 두 곳의 「유형 추가」 행 업로더 모두 확인 창을 띄우고, 창 문구는
 * 저장과 무관하게 바로 지워져 되돌릴 수 없다는 사실을 알린다. 취소하면 요청이 나가지 않는다(업로더 계약).
 */

import { describe, it, expect } from 'vitest';
import editor from '../../../layouts/admin/partials/admin_board_settings/_reaction_type_editor.json';
import cards from '../../../layouts/admin/partials/admin_board_settings/_reaction_type_cards.json';
import koSettings from '../../../lang/partial/ko/admin/settings.json';
import enSettings from '../../../lang/partial/en/admin/settings.json';
import jaSettings from '../../../../../../../lang-packs/_bundled/g7-module-sirsoft-board-ja/frontend/partial/admin/settings.json';

/** 트리에서 반응 유형 아이콘 업로더 노드를 모두 모은다 */
function collectIconUploaders(node: unknown, out: any[] = []): any[] {
  if (Array.isArray(node)) {
    node.forEach((v) => collectIconUploaders(v, out));
    return out;
  }
  if (node && typeof node === 'object') {
    const n = node as any;
    if (n.name === 'FileUploader' && n.props?.collection === 'reaction_type_icon') {
      out.push(n);
    }
    Object.values(n).forEach((v) => collectIconUploaders(v, out));
  }
  return out;
}

const TITLE_KEY = '$t:sirsoft-board.admin.settings.reaction_types.icon_remove_confirm_title';
const MESSAGE_KEY = '$t:sirsoft-board.admin.settings.reaction_types.icon_remove_confirm_message';

describe('반응 유형 아이콘 업로더 — 삭제 확인 창', () => {
  /**
   * @scenario viewport=desktop_table
   *
   * @effects reaction_icon_remove_requires_confirm
   */
  it('넓은 화면 표의 업로더(기존 행, 추가 행)는 확인 창을 거친다', () => {
    const uploaders = collectIconUploaders(editor);
    expect(uploaders.length).toBe(2);
    for (const u of uploaders) {
      expect(u.props.confirmBeforeRemove).toBe(true);
      expect(u.props.confirmRemoveTitle).toBe(TITLE_KEY);
      expect(u.props.confirmRemoveMessage).toBe(MESSAGE_KEY);
    }
  });

  /**
   * @scenario viewport=mobile_cards
   *
   * @effects reaction_icon_remove_requires_confirm
   */
  it('좁은 화면 카드의 업로더(기존 행, 추가 행)도 같은 확인 창을 거친다', () => {
    const uploaders = collectIconUploaders(cards);
    expect(uploaders.length).toBe(2);
    for (const u of uploaders) {
      expect(u.props.confirmBeforeRemove).toBe(true);
      expect(u.props.confirmRemoveTitle).toBe(TITLE_KEY);
      expect(u.props.confirmRemoveMessage).toBe(MESSAGE_KEY);
    }
  });

  /**
   * @scenario viewport=desktop_table
   *
   * @effects reaction_icon_remove_confirm_message_localized
   */
  it('확인 창 문구가 ko/en/ja 에 있고 되돌릴 수 없음을 알린다', () => {
    const ko = (koSettings as any).reaction_types;
    const en = (enSettings as any).reaction_types;
    const ja = (jaSettings as any).reaction_types;
    for (const block of [ko, en, ja]) {
      expect(typeof block.icon_remove_confirm_title).toBe('string');
      expect(typeof block.icon_remove_confirm_message).toBe('string');
    }
    expect(ko.icon_remove_confirm_message).toContain('되돌릴 수 없');
    expect(en.icon_remove_confirm_message.toLowerCase()).toContain('cannot be undone');
    expect(ja.icon_remove_confirm_message).toContain('元に戻せません');
  });
});
