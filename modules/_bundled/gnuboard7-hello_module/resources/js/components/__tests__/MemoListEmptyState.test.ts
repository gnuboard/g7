/**
 * @file MemoListEmptyState.test.ts
 * @description 메모 목록(관리자, 사용자)의 0건 안내 — 메모가 없을 때 빈 화면 대신 안내 문구를 보인다
 *
 * 7.1.0 S2 검증: 두 목록 모두 메모가 0건이면 제목 아래가 비어 「불러오는 중인지, 없는지」 구분이 안 됐다.
 * 0건일 때만 보이는 안내 문구를 두고, 문구는 모듈 언어 파일(ko/en)의 키로 둔다.
 *
 * 레이아웃 계약만 본다(짝 JSON 트리 직접 분석).
 *
 * @scenario-manifest tests/scenarios/memo-list-empty-state.yaml
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

type LayoutNode = {
  if?: string;
  text?: string;
  props?: { className?: string };
  children?: LayoutNode[];
};

const MODULE_ROOT = path.resolve(__dirname, '../../../..');

const readJson = (rel: string) => JSON.parse(fs.readFileSync(path.join(MODULE_ROOT, rel), 'utf8'));

/** 트리를 펼쳐 모든 노드를 모은다 */
const flatten = (nodes: LayoutNode[]): LayoutNode[] =>
  nodes.flatMap((n) => [n, ...flatten(n.children ?? [])]);

/** 점 경로로 언어 값을 읽는다 */
const pick = (obj: unknown, dotted: string): unknown =>
  dotted.split('.').reduce<unknown>((acc, k) => (acc as Record<string, unknown> | undefined)?.[k], obj);

const CASES = [
  { screen: 'admin', layout: 'resources/layouts/admin/admin_memo_list.json', key: 'admin.memos.index.empty' },
  { screen: 'user', layout: 'resources/layouts/user/user_memo_list.json', key: 'user.memos.index.empty' },
] as const;

const checkNotice = (layout: string, key: string) => {
  const tree = readJson(layout) as { slots: { content: LayoutNode[] } };
  const notice = flatten(tree.slots.content).find((n) => n.text === `$t:gnuboard7-hello_module.${key}`);
  expect(notice).toBeDefined();
  expect(notice!.if ?? '').toContain('(memos?.data?.data ?? []).length === 0');
  // 다크 모드 짝이 있는 글자색
  expect(notice!.props?.className ?? '').toMatch(/text-gray-\d+ .*dark:text-gray-\d+/);
  // 안내 문구 키가 ko, en 언어 파일에 모두 있다
  for (const locale of ['ko', 'en']) {
    const value = pick(readJson(`resources/lang/${locale}.json`), key);
    expect(typeof value).toBe('string');
    expect((value as string).length).toBeGreaterThan(0);
  }
};

describe('[case:memo-list-empty-state] 메모 목록 0건 안내', () => {
  /**
   * @scenario screen=admin
   * @effects empty_list_shows_notice, empty_notice_key_exists_ko_en
   */
  it('관리자 메모 목록은 0건일 때만 보이는 안내를 모듈 다국어 키(ko/en)로 둔다', () => {
    checkNotice(CASES[0].layout, CASES[0].key);
  });

  /**
   * @scenario screen=user
   * @effects empty_list_shows_notice, empty_notice_key_exists_ko_en
   */
  it('사용자 메모 목록은 0건일 때만 보이는 안내를 모듈 다국어 키(ko/en)로 둔다', () => {
    checkNotice(CASES[1].layout, CASES[1].key);
  });
});
