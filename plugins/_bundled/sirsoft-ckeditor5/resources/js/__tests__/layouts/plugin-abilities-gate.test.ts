// e2e:allow 권한 게이트(입력·버튼 잠금) 레이아웃 계약 검증 — 브라우저 실측은 W1·W2·W3 통합 뒤 총괄 세션이 권한 회수 계정으로 함께 한다(이력 20261010_0208_710_abilities수정_W3)
/**
 * @file plugin-abilities-gate.test.ts
 * @description sirsoft-ckeditor5 화면 abilities 게이트 (7.1.0 abilities 게이트 plugins·G04)
 */

import { describe, it, expect } from 'vitest';
import uploads from '../../../layouts/admin/ckeditor5_uploads.json';

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
/** 쓰기 apiCall 을 품은 버튼 */
function writeButtons(tree: unknown, exclude: (target: string) => boolean = () => false): Node[] {
  return collect(tree, (n) => n.name === 'Button' && collect(n, (a) => a.handler === 'apiCall' && WRITE.includes(String(a.params?.method ?? '')) && !exclude(String(a.target ?? ''))).length > 0);
}

describe('업로드 관리 (#9·#13)', () => {
  /**
   * 삭제 권한이 없으면 행 선택·행 삭제·일괄 바가 잠기고, 설정 수정 권한이 없으면 [설정 열기]가 잠긴다.
   *
   * @scenario case=uploads_without_delete
   *
   * @effects uploads_delete_controls_locked
   */
  it('삭제·설정 열기 잠금', () => {
    const scope = { ckeditor5Uploads: { data: { abilities: { can_delete: false, can_open_settings: false } } }, _global: { selectedIds_ckeditor5_uploads: [1] } };
    const grid = collect(uploads, (n) => n.name === 'DataGrid')[0];
    expect(evaluate(grid.props.selectable, scope)).toBe(false);
    const del = (grid.props.rowActions as Node[]).find((a) => a.id === 'delete')!;
    expect(evaluate(del.disabled, scope)).toBe(true);
    expect(evaluate(collect(uploads, (n) => n.id === 'bulk_action_bar')[0].if, scope)).toBe(false);
    expect(evaluate(collect(uploads, (n) => n.id === 'open_settings_button')[0].props.disabled, scope)).toBe(true);
  });
});
