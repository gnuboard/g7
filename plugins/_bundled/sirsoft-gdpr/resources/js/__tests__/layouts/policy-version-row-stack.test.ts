/**
 * @file policy-version-row-stack.test.ts
 * @description GDPR 설정 정책 버전 카드와 정책 버전 스냅샷 모달의 상자 묶음 구조
 *
 * `.row-stack`(관리자 템플릿)은 자식에 py-2(첫 자식 pt-0, 마지막 자식 pb-0)를 준다. 자식이 테두리
 * 상자이면 그 8px 이 테두리 안쪽에 들어가 상자 첫 행이 8px 내려앉는다(설정 화면 실측 8.6px).
 * 1. 설정의 정책 버전 카드는 테두리 없는 래퍼로 감싼다.
 * 2. 스냅샷 모달의 반복 상자 묶음은 카드 묶음용 stack-tight 로 바꾼다.
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

type Json = Record<string, any>;

const LAYOUTS_ROOT = path.resolve(__dirname, '../../../layouts');
const load = (rel: string): Json => JSON.parse(fs.readFileSync(path.join(LAYOUTS_ROOT, rel), 'utf8'));

const settings = load('admin/plugin_settings.json');
const snapshotModal = load('admin/partials/_shared/_policy_version_snapshot_modal.json');

/** 조건에 맞는 노드를 모두 모은다. */
function findAll(tree: unknown, pred: (n: Json) => boolean): Json[] {
  const out: Json[] = [];
  const walk = (n: unknown): void => {
    if (Array.isArray(n)) return n.forEach(walk);
    if (n && typeof n === 'object') {
      const obj = n as Json;
      if (pred(obj)) out.push(obj);
      Object.values(obj).forEach(walk);
    }
  };
  walk(tree);
  return out;
}

const tokens = (cls: unknown): string[] => String(cls ?? '').split(/\s+/).filter(Boolean);

describe('GDPR 설정 — 정책 버전 카드', () => {
  /**
   * @scenario defect=row_stack_boxed_child_padding
   * @effects boxed_child_has_no_row_stack_padding_inside
   */
  it('카드는 field_cookie_policy_version(row-stack) 직속이 아니라 테두리 없는 래퍼 안에 있다', () => {
    const [field] = findAll(settings, (n) => n.id === 'field_cookie_policy_version');
    expect(field, '정책 버전 필드가 없습니다.').toBeDefined();
    expect(tokens(field.props?.className)).toContain('row-stack');

    const direct: Json[] = field.children ?? [];
    expect(direct.some((c) => tokens(c.props?.className).includes('border')), '테두리 상자가 아직 row-stack 직속입니다.').toBe(false);

    const wrapper = direct.find((c) => c.id === 'policy_version_card_row');
    expect(wrapper, 'policy_version_card_row 래퍼가 없습니다.').toBeDefined();
    expect(tokens(wrapper!.props?.className)).not.toContain('border');
    const card = (wrapper!.children ?? [])[0];
    expect(tokens(card?.props?.className)).toEqual(expect.arrayContaining(['border', 'rounded-lg', 'bg-gray-50']));
  });
});

describe('정책 버전 스냅샷 모달 — 반복 상자 묶음', () => {
  /**
   * @scenario defect=row_stack_boxed_child_padding
   * @effects boxed_child_has_no_row_stack_padding_inside
   */
  it('반복 상자(쿠키 카테고리·차단 도메인)를 담는 묶음은 row-stack 이 아니라 stack-tight 다', () => {
    const containers = findAll(snapshotModal, (n) =>
      (n.children ?? []).some((c: Json) => c.iteration && tokens(c.props?.className).includes('border')),
    );
    expect(containers.length, '반복 상자 묶음 두 곳이 있어야 합니다.').toBe(2);
    for (const c of containers) {
      expect(tokens(c.props?.className)).toContain('stack-tight');
      expect(tokens(c.props?.className)).not.toContain('row-stack');
    }
  });
});
