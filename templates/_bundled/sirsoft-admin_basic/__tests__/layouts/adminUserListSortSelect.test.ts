/**
 * @file adminUserListSortSelect.test.ts
 * @description 회원 목록 정렬 선택 칸이 표 머리글 정렬을 모두 비추는지 검증한다
 *
 * 표는 이름·이메일·국가·언어·상태·가입일시·마지막 로그인 머리글로 정렬할 수 있는데, 위쪽 정렬
 * 선택 칸에는 가입일시·마지막 로그인·이름만 있었다. 이메일·국가·언어·상태 머리글로 정렬하면 선택 칸의
 * 값(`email_asc` 등)이 어느 항목과도 맞지 않아 칸이 빈 칸으로 보였다(7.1.0 브라우저 실측).
 *
 * 정렬 가능한 열마다 오름·내림 두 항목이 있고, 각 항목 문구가 ko·en·ja 에 있는지 고정한다.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import userList from '../../layouts/admin_user_list.json';

type Node = Record<string, any>;

/** JSON 트리에서 조건을 만족하는 노드를 모두 모은다. */
function collect(obj: any, predicate: (n: Node) => boolean, out: Node[] = []): Node[] {
  if (!obj || typeof obj !== 'object') return out;
  if (!Array.isArray(obj) && predicate(obj)) out.push(obj);
  for (const value of Object.values(obj)) collect(value, predicate, out);
  return out;
}

/** 점 경로로 값을 읽는다. */
function pick(obj: any, dotted: string): unknown {
  return dotted.split('.').reduce((acc, key) => (acc && typeof acc === 'object' ? acc[key] : undefined), obj);
}

const LANG = {
  ko: resolve(__dirname, '../../lang/partial/ko/admin.json'),
  en: resolve(__dirname, '../../lang/partial/en/admin.json'),
  ja: resolve(__dirname, '../../../../../lang-packs/_bundled/g7-template-sirsoft-admin_basic-ja/frontend/partial/admin.json'),
};

describe('회원 목록 정렬 선택 칸', () => {
  const grid = collect(userList, (n) => n.name === 'DataGrid' && n.props?.idField === 'uuid')[0];
  const select = collect(userList, (n) => n.name === 'Select' && Array.isArray(n.props?.options) && n.props.options.some((o: Node) => o.value === 'created_at_desc'))[0];

  /**
   * @scenario filter_axis=sort, outcome=narrows
   * @effects admin_user_list_sort_select_mirrors_header_sort
   */
  it('정렬 가능한 열마다 오름·내림 두 항목이 있다', () => {
    expect(grid, '회원 표를 찾지 못했습니다.').toBeDefined();
    expect(select, '정렬 선택 칸을 찾지 못했습니다.').toBeDefined();

    const sortable = (grid.props.columns as Node[]).filter((c) => c.sortable === true).map((c) => c.field as string);
    expect(sortable).toEqual(expect.arrayContaining(['email', 'country', 'language', 'status']));

    const values = new Set((select.props.options as Node[]).map((o) => o.value as string));
    const missing = sortable.flatMap((field) => [`${field}_asc`, `${field}_desc`]).filter((v) => !values.has(v));
    expect(missing).toEqual([]);
  });

  /**
   * @scenario filter_axis=sort, outcome=narrows
   * @effects admin_user_list_sort_select_mirrors_header_sort
   */
  it('항목 문구가 ko·en·ja 에 모두 있다', () => {
    const dicts = Object.fromEntries(Object.entries(LANG).map(([locale, file]) => [locale, JSON.parse(readFileSync(file, 'utf8'))]));

    for (const option of select.props.options as Node[]) {
      expect(option.label).toBe(`$t:admin.users.sort.${option.value}`);
      for (const [locale, dict] of Object.entries(dicts)) {
        const text = pick(dict, `users.sort.${option.value}`);
        expect(typeof text === 'string' && text.length > 0, `${locale}: users.sort.${option.value}`).toBe(true);
      }
    }
  });
});
