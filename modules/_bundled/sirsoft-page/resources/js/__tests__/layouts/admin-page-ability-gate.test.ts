// e2e:allow 권한 게이트(입력·버튼 잠금) 레이아웃 계약 검증 — 브라우저 실측은 W1·W2·W3 통합 뒤 총괄 세션이 권한 회수 계정으로 함께 한다(이력 20261010_0208_710_abilities수정_W3)
/**
 * @file admin-page-ability-gate.test.ts
 * @description 관리자 페이지 화면 abilities 게이트 (7.1.0 abilities 게이트 page-hello #1·#5)
 *
 * - #1 본문 편집기: disabled 와 함께 readOnly 도 넘겨 편집기 컴포넌트가 어느 쪽을 받든 읽기 전용이면 잠긴다(G09 사용처)
 * - #5 페이지 목록: 수정 권한이 없으면 행 체크박스와 일괄 바를 그리지 않는다
 */

import { describe, it, expect } from 'vitest';
import pageForm from '../../../layouts/admin/admin_page_form.json';
import pageList from '../../../layouts/admin/admin_page_list.json';

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
  const expr = String(raw).trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(scope), `return (${expr});`)(...Object.values(scope));
}

describe('페이지 폼 본문 편집기 (#1)', () => {
  /**
   * 편집기 확장점과 기본 편집기 둘 다 readOnly 를 받는다.
   *
   * @scenario case=page_editor_read_only
   *
   * @effects page_editor_read_only_prop
   */
  it('html_editor 확장점·HtmlEditor 에 readOnly', () => {
    const editors = collect(pageForm, (n) => (n.name === 'HtmlEditor' || n.name === 'html_editor' || n.type === 'extension_point') && n.props?.disabled === '{{_computed.isReadOnly}}');
    expect(editors.length).toBeGreaterThanOrEqual(2);
    editors.forEach((e) => expect(e.props.readOnly).toBe('{{_computed.isReadOnly}}'));
  });
});

describe('페이지 목록 선택·일괄 바 (#5)', () => {
  /**
   * 수정 권한이 없으면 행 선택과 일괄 바를 그리지 않는다.
   *
   * @scenario case=page_list_without_update
   *
   * @effects page_list_selection_hidden
   */
  it('selectable·일괄 바가 can_update 를 본다', () => {
    const grid = collect(pageList, (n) => n.name === 'DataGrid')[0];
    const denied = { pages: { data: { abilities: { can_update: false } } } };
    expect(evaluate(grid.props.selectable, denied)).toBe(false);
    const bar = collect(pageList, (n) => n.id === 'bulk_actions_area')[0];
    expect(evaluate(bar.if, denied)).toBe(false);
    expect(evaluate(bar.if, { pages: { data: { abilities: { can_update: true } } } })).toBe(true);
  });
});
