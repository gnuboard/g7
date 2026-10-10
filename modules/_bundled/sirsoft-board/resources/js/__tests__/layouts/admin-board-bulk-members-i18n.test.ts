/**
 * 게시판 환경설정 — 관리자·스텝 일괄 지정 카드의 문구 키가 실제 언어 파일에서 풀리는가 (#708)
 *
 * 회귀 배경(브라우저 실측): 키를 로더가 읽지 않는 `partial/{locale}/admin.json` 에만 넣어, 화면에
 * `sirsoft-board.admin.settings.bulk_members.title` 같은 키가 그대로 노출됐다. 모듈 언어는 루트
 * `{locale}.json` 의 `$partial` 참조(`partial/{locale}/admin/settings.json` 등)로 조립된다.
 *
 * 이 테스트는 루트 매니페스트에서 `$partial` 을 따라가 실제로 조립되는 사전을 만들고, 일괄 지정
 * 레이아웃 3개가 쓰는 `$t:sirsoft-board.*` 키가 ko·en 모두에서 문자열로 풀리는지 본다.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { describe, it, expect } from 'vitest';

const here = path.dirname(fileURLToPath(import.meta.url));
const moduleRoot = path.resolve(here, '../../../..');
const langDir = path.join(moduleRoot, 'resources/lang');
const layoutDir = path.join(moduleRoot, 'resources/layouts/admin');

const LAYOUT_FILES = [
  'partials/admin_board_settings/_section_bulk_members.g7.html',
  'partials/admin_board_settings/_modal_bulk_members_confirm.g7.html',
  'admin_board_settings.g7.html',
];

/** 루트 언어 파일에서 `$partial` 참조를 따라가 조립된 사전을 만든다. */
function assemble(node: unknown): unknown {
  if (Array.isArray(node)) return node.map(assemble);
  if (node && typeof node === 'object') {
    const record = node as Record<string, unknown>;
    if (typeof record.$partial === 'string') {
      return assemble(JSON.parse(fs.readFileSync(path.join(langDir, record.$partial), 'utf8')));
    }
    return Object.fromEntries(Object.entries(record).map(([key, value]) => [key, assemble(value)]));
  }
  return node;
}

const dictionaries = Object.fromEntries(
  ['ko', 'en'].map((locale) => [locale, assemble(JSON.parse(fs.readFileSync(path.join(langDir, `${locale}.json`), 'utf8')))]),
) as Record<string, any>;

/** 레이아웃에서 일괄 지정 관련 `$t:sirsoft-board.*` 키를 모은다. */
function bulkMemberKeys(): string[] {
  const keys = new Set<string>();
  for (const file of LAYOUT_FILES) {
    const source = fs.readFileSync(path.join(layoutDir, file), 'utf8');
    for (const match of source.matchAll(/\$t:sirsoft-board\.([a-z0-9_.]+)/g)) {
      if (/bulk_members/.test(match[1])) keys.add(match[1]);
    }
  }
  return [...keys].sort();
}

const lookup = (dictionary: any, key: string) => key.split('.').reduce((acc, part) => acc?.[part], dictionary);

describe('게시판 관리자·스텝 일괄 지정 — 문구 키 해석 (#708)', () => {
  // @scenario member_type=manager, mode=add, target=all
  // @effects bulk_members_labels_resolve_in_both_locales
  it('레이아웃이 쓰는 키를 실제로 모은다 (하네스 자체 회귀 방지)', () => {
    const keys = bulkMemberKeys();

    expect(keys).toContain('admin.settings.bulk_members.title');
    expect(keys).toContain('admin.settings.board_settings_tabs.bulk_members');
    expect(keys.length).toBeGreaterThan(15);
  });

  // @scenario member_type=step, mode=remove, target=selected
  // @effects bulk_members_labels_resolve_in_both_locales
  it.each(['ko', 'en'])('%s: 모든 키가 문자열로 풀린다', (locale) => {
    const missing = bulkMemberKeys().filter((key) => typeof lookup(dictionaries[locale], key) !== 'string');

    expect(missing).toEqual([]);
  });
});
