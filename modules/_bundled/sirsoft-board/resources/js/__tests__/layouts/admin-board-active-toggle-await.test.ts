/**
 * @file admin-board-active-toggle-await.test.ts
 * @description 게시판 관리 목록 「사용」 스위치가 서버 확정을 기다리는지 잠근다.
 *
 * 결함: 스위치를 누르면 Toggle 이 내부 값을 먼저 뒤집는데, PUT 이 실패(500)하면 `checked`(row.is_active)가 그대로라
 * 스위치가 눌린 쪽으로 남아 DB 값과 화면이 어긋났다(새로고침하면 맞게 돌아옴). 성공 때만 목록을 다시 받아 바꾸는 배선이라,
 * 스위치는 스스로 바뀌지 않고 다시 받은 서버 값으로만 바뀌어야 한다(`awaitConfirmation`, 이커머스 주문서 항목 표와 같은 규약).
 *
 * 조합 요약(마커 아님 — 평문): 결과 성공·실패 두 갈래
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

type Node = Record<string, any>;

const LAYOUT = path.resolve(__dirname, '../../../layouts/admin/admin_board_index.json');

const findById = (node: Node | Node[] | undefined, id: string): Node | null => {
  if (!node) return null;
  if (Array.isArray(node)) {
    for (const n of node) {
      const f = findById(n, id);
      if (f) return f;
    }
    return null;
  }
  if (typeof node !== 'object') return null;
  if (node.id === id) return node;
  for (const v of Object.values(node)) {
    if (v && typeof v === 'object') {
      const f = findById(v as Node, id);
      if (f) return f;
    }
  }
  return null;
};

describe('게시판 관리 목록 — 사용 스위치 서버 확정 대기', () => {
  const layout = JSON.parse(fs.readFileSync(LAYOUT, 'utf8'));
  const toggle = findById(layout, 'active_toggle');

  /**
   * @scenario outcome=fail
   * @effects active_toggle_stays_on_server_value_when_put_fails
   */
  it('스위치는 누른다고 스스로 바뀌지 않는다(awaitConfirmation) — 실패하면 원래 모양 그대로다', () => {
    expect(toggle).not.toBeNull();
    expect(toggle!.props?.awaitConfirmation).toBe(true);
    expect(toggle!.props?.checked).toBe('{{row.is_active}}');
  });

  /**
   * @scenario outcome=success
   * @effects active_toggle_follows_refetched_value_on_success
   */
  it('성공하면 목록을 동기로 다시 받아 바뀐 서버 값으로 스위치가 바뀐다', () => {
    const json = JSON.stringify(toggle);
    expect(json).toContain('"dataSourceId":"boards"');
    expect(json).toMatch(/"sync":true/);
  });
});
