/**
 * 관리자 주문 목록/카드 "일부취소" 보조 배지 구조 회귀 테스트
 *
 * 배경:
 * - partial_cancelled 주문상태 제거 후, 일부 취소 사실은 파생 플래그 is_partially_cancelled 로 표시한다.
 * - DataGrid order_status 컬럼 cellChildren 에 StatusBadge + 보조 배지를 평면 형제로 두면
 *   목록 셀과 모바일 카드(responsiveBreakpoint)에서 한 줄로 나란히 떠 깨진다(검수 발견).
 *   → 둘을 flex-col 묶음 Div 로 감싸 상태 배지 아래(두 줄)에 보조 배지를 배치한다.
 *   DataGrid 는 동일 columns 정의를 목록·카드 양쪽에 재사용하므로 한 곳 수정으로 둘 다 적용된다.
 *
 * 회귀 차단:
 * - order_status 셀의 보조 배지는 StatusBadge 와 같은 flex-col 묶음의 형제여야 한다(셀 직속 형제 금지).
 * - 보조 배지는 if=is_partially_cancelled + 라이트/다크 클래스 쌍을 갖는다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import * as fs from 'node:fs';
import * as path from 'node:path';

function findProjectRoot(startDir: string): string {
  return path.resolve(startDir, '../../../../../../..');
}

const REPO_ROOT = findProjectRoot(__dirname);
const datagrid = JSON.parse(
  fs.readFileSync(
    path.join(
      REPO_ROOT,
      'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_order_list/_partial_order_datagrid.json',
    ),
    'utf-8',
  ),
);

interface Node {
  type?: string;
  name?: string;
  if?: string;
  field?: string;
  props?: Record<string, any>;
  text?: string;
  children?: Node[];
  cellChildren?: Node[];
  columns?: Node[];
  [k: string]: any;
}

// DataGrid columns 는 props.columns 에 중첩되므로 모든 객체 값을 재귀 순회한다.
function flatten(node: any, acc: Node[] = []): Node[] {
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

const BADGE_LABEL = '$t:sirsoft-ecommerce.admin.order.detail.partial_cancelled_badge';

describe('관리자 주문 datagrid — 일부취소 보조 배지 구조', () => {
  const nodes = flatten(datagrid);
  const badge = nodes.find((n) => n.text === BADGE_LABEL);

  it('일부취소 보조 배지 노드가 존재한다', () => {
    expect(badge).toBeTruthy();
  });

  it('보조 배지는 is_partially_cancelled 파생 플래그로 게이트된다', () => {
    expect(badge?.if).toBe('{{row.is_partially_cancelled}}');
  });

  it('보조 배지는 StatusBadge 와 같은 flex-col 묶음 Div 안의 형제다 (두 줄 배치)', () => {
    const parent = nodes.find(
      (n) => Array.isArray(n.children) && n.children.some((c) => c.text === BADGE_LABEL),
    );
    expect(parent).toBeTruthy();
    expect(parent?.name).toBe('Div');
    expect(parent?.props?.className).toContain('flex-col');
    // 같은 묶음에 StatusBadge 형제가 있어야 한다.
    const hasStatusBadge = parent!.children!.some((c) => c.name === 'StatusBadge');
    expect(hasStatusBadge).toBe(true);
  });

  it('보조 배지는 라이트/다크 클래스 쌍을 모두 갖는다', () => {
    const cls = badge?.props?.className ?? '';
    expect(cls).toContain('bg-orange-100');
    expect(cls).toContain('dark:bg-orange-900/30');
  });

  it('order_status 컬럼 cellChildren 직속에는 배지 묶음 Div 하나만 있다 (StatusBadge/Span 평면 형제 금지)', () => {
    const col = nodes.find((n) => n.field === 'order_status' && Array.isArray(n.cellChildren));
    expect(col).toBeTruthy();
    expect(col!.cellChildren!.length).toBe(1);
    expect(col!.cellChildren![0].name).toBe('Div');
  });
});
