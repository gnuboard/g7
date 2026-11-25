/**
 * @file couponIssueHistorySource.test.ts
 * @description 관리자 쿠폰 「발급 내역」 창의 발급 경로·쿠폰 코드 (1.4.1 「발급 내역과 쿠폰함에 발급 경로가 보입니다」, 7.2.1 출시 전 실측 W3-002)
 *
 * 발급 내역 API 는 행마다 `issue_source_label`(가입·첫구매·생일·직접발급·다운로드)과 `coupon_code`(자동발급 `AU-`)를 내려 주는데
 * 창의 표에 그 열이 없어 운영자가 어느 경로로 발급됐는지 볼 수 없었다.
 */

import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

const modal: any = JSON.parse(
  fs.readFileSync(
    path.resolve(__dirname, '../../../layouts/admin/partials/admin_ecommerce_promotion_coupon_list/_modal_issue_history.json'),
    'utf-8',
  ),
);

function collect(node: any, match: (n: any) => boolean, out: any[] = []): any[] {
  if (!node || typeof node !== 'object') return out;
  if (Array.isArray(node)) {
    node.forEach((n) => collect(n, match, out));
    return out;
  }
  if (match(node)) out.push(node);
  Object.values(node).forEach((v) => collect(v, match, out));
  return out;
}

describe('쿠폰 발급 내역 창 — 발급 경로 (W3-002)', () => {
  // @scenario case=issue_history_source_column
  // @effects coupon_issue_source_visible_to_admin
  it('발급 경로 열이 있고 그 칸이 발급 경로 이름과 쿠폰 코드를 그린다', () => {
    const grid = collect(modal, (n) => n.name === 'DataGrid')[0];
    expect(grid).toBeDefined();
    const text = JSON.stringify(grid);
    const columns = grid.props?.columns ?? [];
    const source = columns.find((c: any) => c.field === 'issue_source_label');
    expect(source, '발급 경로 열이 없습니다').toBeDefined();
    expect(String(source.header)).toContain('promotion_coupon.table.columns.issue_method');
    expect(text).toContain('row.issue_source_label');
    expect(text).toContain('row.coupon_code');
  });
});
