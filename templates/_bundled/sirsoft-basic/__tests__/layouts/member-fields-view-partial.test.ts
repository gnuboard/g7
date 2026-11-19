/**
 * @file member-fields-view-partial.test.ts
 * @description 회원 항목 보기 조각(partials/member/_member_fields_view) 구조 테스트
 *
 * 이 조각은 서버가 준 항목 계약을 돌며 라벨과 보기용 값(display_value)을 그린다. 내 정보 보기 화면이 카드마다 부른다.
 * 반복 원본(부르는 화면의 반복 변수 memberFieldGroup), 행의 DOM id, 유형 갈래(한 줄, 여러 줄 글, 파일),
 * 빈 값 표시가 계약이다.
 *
 * @scenario-manifest tests/scenarios/member-fields-view-partial.yaml
 */

import { describe, it, expect } from 'vitest';
import { actionsOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import partial from '../../layouts/partials/member/_member_fields_view.json';

type Node = {
  id?: string;
  type?: string;
  name?: string;
  if?: string;
  props?: Record<string, any>;
  children?: Node[];
  iteration?: { source?: string; item_var?: string };
  text?: string;
  actions?: Array<Record<string, any>>;
};

function collect(input: Node | Node[] | undefined, out: Node[] = []): Node[] {
  if (!input) return out;
  for (const node of Array.isArray(input) ? input : [input]) {
    out.push(node);
    collect(node.children, out);
  }
  return out;
}

/** 표현식({{ }})을 주어진 변수로 계산한다 */
function evaluate(expression: string, scope: Record<string, unknown>): unknown {
  const body = expression.replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(scope), 'return (' + body + ');')(...Object.values(scope));
}

const root = partial as Node;
const nodes = collect(root);
const row = (root.children ?? [])[0];
const [line, multiline, files] = row.children ?? [];

describe('회원 항목 보기 조각', () => {
  /**
   * @scenario part=root
   * @effects view_root_id_follows_group
   */
  it('루트 노드 id 는 묶음 이름을 따른다 (한 화면에 카드마다 놓인다)', () => {
    expect(root.id).toBe('member_fields_view_group_{{memberFieldGroup.key}}');
  });

  /**
   * @scenario part=iteration
   * @effects view_iterates_group_fields_except_agreements
   */
  it('반복 변수 memberFieldGroup 의 항목을 돌되 약관 묶음은 빼고, 행 id 는 field_view_{키} 다', () => {
    expect(row.iteration?.item_var).toBe('field');
    expect(row.id).toBe('field_view_{{field.key}}');
    const fields = [{ key: 'mobile', ui: { group: 'contact' } }, { key: 'marketing', ui: { group: 'agreements' } }, { key: 'job' }];
    const result = evaluate(row.iteration!.source!, { memberFieldGroup: { key: 'contact', fields } }) as Array<{ key: string }>;
    expect(result.map((field) => field.key)).toEqual(['mobile', 'job']);
    expect(evaluate(row.iteration!.source!, { memberFieldGroup: undefined })).toEqual([]);
  });

  /**
   * @scenario part=branch
   * @effects exactly_one_branch_per_field
   */
  it('항목마다 갈래 하나만 그린다 — 한 줄, 여러 줄 글, 파일', () => {
    const shown = (field: Record<string, unknown>) => [line, multiline, files].map((node) => evaluate(node.if!, { field }));
    expect(shown({ type: 'text', renderer: 'builtin' })).toEqual([true, false, false]);
    expect(shown({ type: 'choice', renderer: 'builtin' })).toEqual([true, false, false]);
    expect(shown({ type: 'address', renderer: 'address' })).toEqual([true, false, false]);
    expect(shown({ type: 'textarea', renderer: 'builtin' })).toEqual([false, true, false]);
    expect(shown({ type: 'file', renderer: 'file' })).toEqual([false, false, true]);
    expect(shown({ type: 'image', renderer: 'file' })).toEqual([false, false, true]);
  });

  /**
   * @scenario part=value
   * @effects display_value_or_dash
   */
  it('라벨과 보기용 값을 그리고, 값이 없으면 「-」를 보인다', () => {
    for (const branch of [line, multiline]) {
      const [label, value] = branch.children ?? [];
      expect(label.text).toBe('{{field.label}}');
      expect(value.text).toBe("{{field.display_value || '-'}}");
      expect(evaluate(value.text!, { field: { display_value: '직장인' } })).toBe('직장인');
      expect(evaluate(value.text!, { field: { display_value: null } })).toBe('-');
      expect(evaluate(value.text!, { field: { display_value: '' } })).toBe('-');
      expect(evaluate(value.text!, { field: {} })).toBe('-');
    }
    // 여러 줄 글은 줄바꿈을 살린다
    expect((multiline.children ?? [])[1].props?.className).toContain('whitespace-pre-wrap');
  });

  /**
   * @scenario part=file
   * @effects file_names_listed_as_download_buttons
   */
  it('파일 항목은 올라간 파일 이름 목록을 그리고(누르면 내려받는다), 없으면 「-」를 보인다', () => {
    expect(files.id).toBe('mf_view_files_{{field.key}}');
    const empty = collect(files).find((node) => node.text === '-')!;
    const list = collect(files).find((node) => node.name === 'Ul')!;
    expect(evaluate(empty.if!, { field: { files: [] } })).toBe(true);
    expect(evaluate(empty.if!, { field: { files: null } })).toBe(true);
    expect(evaluate(empty.if!, { field: { files: [{ id: 1 }] } })).toBe(false);
    expect(evaluate(list.if!, { field: { files: [{ id: 1 }] } })).toBe(true);
    expect(evaluate(list.if!, { field: { files: null } })).toBe(false);

    const item = collect(list).find((node) => node.iteration)!;
    expect(item.iteration?.item_var).toBe('fieldFile');
    expect(item.id).toBe('mf_view_file_{{field.key}}_{{fieldFile.id}}');
    const button = collect(item).find((node) => node.name === 'Button')!;
    expect(button.props?.type).toBe('button');
    expect(actionsOf(button)[0].handler).toBe('downloadAttachment');
    expect(actionsOf(button)[0].params).toEqual({ url: '{{fieldFile.url}}', filename: '{{fieldFile.name}}' });
    expect(collect(button).some((node) => node.text === '{{fieldFile.name}}')).toBe(true);
    // 보기 화면에서는 올리거나 지우지 않는다
    expect(JSON.stringify(partial)).not.toContain('apiCall');
  });

  /**
   * @scenario part=dark
   * @effects view_color_classes_have_dark_pair
   */
  it('글자색 클래스에 다크 짝이 있다', () => {
    for (const node of nodes) {
      const className = String(node.props?.className ?? '');
      if (/\btext-(gray|blue)-\d+/.test(className)) expect(className).toMatch(/dark:text-/);
    }
  });
});
