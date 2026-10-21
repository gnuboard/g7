/**
 * @file claim-shell-placeholder-before-data.test.ts
 * @description 클레임 신청서·상세 껍데기가 데이터 전에 「{{number}}」 자리표시자를 보이지 않는다 (7.1.1)
 *
 * `$t:키|이름={{값}}` 은 값이 비면 인자를 넘기지 않아 자리표시자(`{{number}}`)가 남는다. 404 화면 전 1~2초 동안
 * 「주문번호 {{number}}」「신청내역 {{at}}」 이 보였다(2026-10-18 실측). 그 줄은 값이 있을 때만 그린다.
 *
 * @scenario-manifest tests/scenarios/claim-shell-placeholder-before-data.yaml
 */

import fs from 'node:fs';
import path from 'node:path';
import { describe, it, expect } from 'vitest';

const load = (rel: string): any => JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../', rel), 'utf-8'));

/** text 에 지정 번역 키를 가진 노드 목록. */
function nodesWithKey(root: unknown, key: string): any[] {
  const out: any[] = [];
  (function walk(o: unknown): void {
    if (Array.isArray(o)) { o.forEach(walk); return; }
    if (!o || typeof o !== 'object') return;
    const n = o as Record<string, any>;
    if (typeof n.text === 'string' && n.text.includes(`$t:${key}|`)) out.push(n);
    Object.values(n).forEach(walk);
  })(root);

  return out;
}

/** 그 화면에서 번역 키 줄이 하나이고, 그 if 가 값 필드를 본다. */
function expectGated(file: string, key: string, field: string): void {
  const nodes = nodesWithKey(load(file), key);
  expect(nodes.length, `${file} ${key}`).toBe(1);
  expect(String(nodes[0].if ?? '')).toContain(field);
}

describe('클레임 껍데기 — 값이 빈 동안 자리표시자 줄을 그리지 않는다 (7.1.1)', () => {
  /**
   * @scenario screen=member_apply
   * @effects placeholder_line_hidden_until_value
   */
  it('회원 신청서: 주문번호 줄은 order_number 가 있을 때만', () => {
    expectGated('layouts/mypage/claims/apply.json', 'claim.apply.order_number', 'order_number');
  });

  /**
   * @scenario screen=guest_apply
   * @effects placeholder_line_hidden_until_value
   */
  it('비회원 신청서: 주문번호 줄은 order_number 가 있을 때만', () => {
    expectGated('layouts/shop/guest_claim_apply.json', 'claim.apply.order_number', 'order_number');
  });

  /**
   * @scenario screen=member_detail
   * @effects placeholder_line_hidden_until_value
   */
  it('회원 상세: 신청 시각 줄은 requested_at_formatted 가 있을 때만', () => {
    expectGated('layouts/mypage/claims/show.json', 'claim.list.requested_at', 'requested_at_formatted');
  });

  /**
   * @scenario screen=guest_detail
   * @effects placeholder_line_hidden_until_value
   */
  it('비회원 상세: 신청 시각 줄은 requested_at_formatted 가 있을 때만', () => {
    expectGated('layouts/shop/guest_claim_show.json', 'claim.list.requested_at', 'requested_at_formatted');
  });
});
