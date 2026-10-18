/**
 * 대시보드 「오늘」 상태 배지 링크의 상태 조건 (7.1.0 전수 실측 · 결정 2026-10-08 4차)
 *
 * 배지 숫자는 서버(EcommerceDashboardService::getOverview)가 배지 링크와 같은 목록 조건으로 센 주문 수다.
 * 링크가 싣는 상태 조건이 서버가 센 조건과 어긋나면 배지 「N건」 → 목록 「총 M개」로 다시 갈린다.
 * 서버 쪽 표(EcommerceDashboardService::BADGE_LIST_FILTERS)와 이 링크가 같은지는 PHPUnit
 * tests/Feature/Admin/DashboardBadgeOrderCountTest.php 가 이 레이아웃 JSON 을 직접 읽어 잠근다 — 이 시험은 링크 쪽 표를 따로 고정한다.
 */

import fs from 'fs';
import path from 'path';
import { describe, expect, it } from 'vitest';

const EXTENSION = path.resolve(__dirname, '../../../extensions/admin_dashboard_commerce.json');

/** 배지 data-ref → 링크가 싣는 상태 조건(배열 인자 이름=값 목록). 날짜·기준 인자는 다른 테스트가 잠근다. */
const BADGE_LINK_STATUS: Record<string, Record<string, string[]>> = {
  today_pending_payment_badge: { 'order_status[]': ['pending_payment'] },
  today_payment_complete_badge: { 'order_status[]': ['payment_complete'] },
  today_preparing_badge: { 'order_status[]': ['preparing'] },
  today_shipping_ready_badge: { 'order_status[]': ['shipping_ready'] },
  today_shipping_badge: { 'order_status[]': ['shipping'] },
  today_cancellations_badge: { 'order_status[]': ['cancelled'] },
  today_returns_badge: { 'claim_return_statuses[]': ['completed'] },
};

/** 날짜·기준 인자 — 상태 조건이 아니다. */
const NON_STATUS_KEYS = ['date_type', 'date_basis', 'start_date', 'end_date'];

/** id(data-ref)로 노드를 찾는다. */
function findById(node: any, id: string): any {
  if (Array.isArray(node)) {
    for (const child of node) {
      const found = findById(child, id);
      if (found) return found;
    }
    return null;
  }
  if (node && typeof node === 'object') {
    if (node.id === id) return node;
    for (const value of Object.values(node)) {
      if (value && typeof value === 'object') {
        const found = findById(value, id);
        if (found) return found;
      }
    }
  }
  return null;
}

describe('대시보드 상태 배지 링크 — 서버가 센 목록 조건과 같다', () => {
  // @scenario metric=order_count, badge=all
  // @effects dashboard_badge_links_carry_list_status_conditions
  it('배지 7개 링크의 상태 조건이 서버 배지 집계 조건과 정확히 같다', () => {
    const layout = JSON.parse(fs.readFileSync(EXTENSION, 'utf-8'));

    for (const [id, expected] of Object.entries(BADGE_LINK_STATUS)) {
      const badge = findById(layout, id);
      expect(badge, `${id} 누락`).not.toBeNull();
      const click = (badge.actions ?? []).find((a: any) => a.type === 'click' && a.handler === 'navigate');
      expect(click, `${id} navigate 누락`).toBeDefined();

      const query = String(click.params.path).split('?')[1] ?? '';
      const actual: Record<string, string[]> = {};
      for (const pair of query.split('&')) {
        const [key, value] = pair.split('=');
        if (!key || NON_STATUS_KEYS.includes(key)) continue;
        (actual[key] ??= []).push(value);
      }
      expect(actual, id).toEqual(expected);
    }
  });
});
