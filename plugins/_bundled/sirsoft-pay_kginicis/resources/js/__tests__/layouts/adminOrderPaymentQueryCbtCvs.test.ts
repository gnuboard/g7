/**
 * @file adminOrderPaymentQueryCbtCvs.test.ts
 * @description 관리자 주문 상세의 CBT 편의점결제 운영 패널 레이아웃 검증
 */

import { describe, expect, it } from 'vitest';
import orderPaymentQueryExtension from '../../../extensions/admin_order_payment_query.json';

function findById(node: unknown, id: string): Record<string, unknown> | undefined {
  if (!node || typeof node !== 'object') {
    return undefined;
  }

  const value = node as Record<string, unknown>;
  if (value.id === id) {
    return value;
  }

  for (const child of Object.values(value)) {
    const found = findById(child, id);
    if (found) {
      return found;
    }
  }

  return undefined;
}

function collectApiTargets(node: unknown, targets: string[] = []): string[] {
  if (!node || typeof node !== 'object') {
    return targets;
  }

  const value = node as Record<string, unknown>;
  if (value.handler === 'apiCall' && typeof value.target === 'string') {
    targets.push(value.target);
  }

  for (const child of Object.values(value)) {
    collectApiTargets(child, targets);
  }

  return targets;
}

describe('admin_order_payment_query CBT CVS operations', () => {
  it('CBT CVS data source를 자동 조회해야 한다', () => {
    const dataSources = orderPaymentQueryExtension.data_sources as Array<Record<string, unknown>>;
    const dataSource = dataSources.find((item) => item.id === 'kginicis_cbt_cvs');

    expect(dataSource).toBeDefined();
    expect(dataSource?.endpoint).toBe('/api/plugins/sirsoft-pay_kginicis/admin/orders/{{route.orderNumber}}/cbt-cvs');
    expect(dataSource?.auto_fetch).toBe(true);
    expect(dataSource?.auth_required).toBe(true);
  });

  it('CBT CVS 운영 패널과 관리자 액션 버튼이 존재해야 한다', () => {
    const panel = findById(orderPaymentQueryExtension, 'kginicis_cbt_cvs_ops_panel');
    expect(panel).toBeDefined();
    expect(panel?.if).toBe('{{kginicis_cbt_cvs.data?.is_cbt_cvs === true}}');

    expect(findById(orderPaymentQueryExtension, 'kginicis_cbt_cvs_recheck_button')).toBeDefined();
    expect(findById(orderPaymentQueryExtension, 'kginicis_cbt_cvs_simulate_button')).toBeDefined();
    expect(findById(orderPaymentQueryExtension, 'kginicis_cbt_cvs_expire_button')).toBeDefined();

    const targets = collectApiTargets(orderPaymentQueryExtension);
    expect(targets).toContain('/api/plugins/sirsoft-pay_kginicis/admin/orders/{{route.orderNumber}}/cbt-cvs/recheck');
    expect(targets).toContain('/api/plugins/sirsoft-pay_kginicis/admin/orders/{{route.orderNumber}}/cbt-cvs/simulate-notify');
    expect(targets).toContain('/api/plugins/sirsoft-pay_kginicis/admin/orders/{{route.orderNumber}}/cbt-cvs/expire');
  });
});
