/**
 * @file adminOrderInfoAdditionalOptions.test.ts
 * @description 관리자 주문서 상세 — 추가옵션(유료 옵션) 스냅샷 별행 표시 구조 검증
 *
 * D14: 주문서 상세에서 옵션명 아래 추가옵션·추가금 별행 표시.
 * OrderOptionResource.additional_options(스냅샷) = [{ additional_option_id, value_id, name, price_adjustment }].
 */

import { describe, it, expect } from 'vitest';
import * as fs from 'node:fs';
import * as path from 'node:path';

function findProjectRoot(startDir: string): string {
  return path.resolve(startDir, '../../../../../../..');
}

const REPO_ROOT = findProjectRoot(__dirname);
const orderInfo = JSON.parse(
  fs.readFileSync(
    path.join(
      REPO_ROOT,
      'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_order_detail/_partial_order_info.json',
    ),
    'utf-8',
  ),
);

function flatten(node: any, acc: any[] = []): any[] {
  if (!node || typeof node !== 'object') return acc;
  if (Array.isArray(node)) {
    for (const n of node) flatten(n, acc);
    return acc;
  }
  acc.push(node);
  for (const key of Object.keys(node)) {
    const v = node[key];
    if (v && typeof v === 'object') flatten(v, acc);
  }
  return acc;
}

describe('관리자 주문서 상세 — 추가옵션 스냅샷 별행 (D14)', () => {
  const nodes = flatten(orderInfo);
  const str = JSON.stringify(orderInfo);

  it('row.additional_options iteration 노드가 존재한다', () => {
    const node = nodes.find(
      (n) => n.iteration?.source && String(n.iteration.source).includes('row.additional_options'),
    );
    expect(node).toBeTruthy();
    expect(node.iteration.item_var).toBe('addOpt');
  });

  it('선택지명(addOpt.name)과 추가금(price_adjustment)을 표시한다', () => {
    expect(str).toContain('addOpt.name');
    expect(str).toContain('addOpt.price_adjustment');
  });

  it('직접입력(addOpt.custom_text)을 선택지명에 병기한다', () => {
    expect(str).toContain('addOpt.custom_text');
  });

  it('추가옵션 별행은 옵션명(row.option_name) 표시 이후에 위치한다', () => {
    // 같은 셀 children 배열에서 option_name span 다음에 additional_options div 가 와야 함
    const cell = nodes.find(
      (n) =>
        Array.isArray(n.children) &&
        n.children.some((c: any) => String(c?.text ?? '').includes('row.option_name')) &&
        n.children.some((c: any) =>
          String(c?.iteration?.source ?? '').includes('row.additional_options'),
        ),
    );
    expect(cell).toBeTruthy();
    const idxName = cell.children.findIndex((c: any) =>
      String(c?.text ?? '').includes('row.option_name'),
    );
    const idxAdd = cell.children.findIndex((c: any) =>
      String(c?.iteration?.source ?? '').includes('row.additional_options'),
    );
    expect(idxAdd).toBeGreaterThan(idxName);
  });
});
