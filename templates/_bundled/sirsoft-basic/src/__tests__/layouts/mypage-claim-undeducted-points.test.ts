/**
 * @file mypage-claim-undeducted-points.test.ts
 * @description 회원 클레임 신청 — 사용 마일리지를 차감하지 않은 주문은 반환액 「0원 (차감되지 않은 마일리지)」, 환불 우선순위 선택 숨김 (Q15)
 *
 * 수동 결제완료에서 「마일리지 차감」을 끈 주문처럼 사용 마일리지가 차감되지 않은 주문은, 그 마일리지를 돌려주지 않는다.
 * 미리보기는 이제 마일리지 몫 0 을 내므로 반환 줄이 사라지는데, 마일리지를 썼는데 왜 안 돌아오는지 알 수 없다 — 주문
 * 응답의 `points_not_deducted` 로 줄을 세워 0 과 이유를 보이고, 뜻이 없는 환불 우선순위 선택은 숨긴다.
 */

import { describe, expect, it } from 'vitest';

import comparison from '../../../layouts/partials/mypage/claims/_apply_comparison.json';
import summary from '../../../layouts/partials/mypage/claims/_apply_summary.json';

type Node = Record<string, any>;

/**
 * JSON 트리의 모든 노드를 평탄화한다.
 *
 * @param node 시작 노드
 * @param acc 누적 배열
 * @returns 노드 목록
 */
function flatten(node: unknown, acc: Node[] = []): Node[] {
  if (Array.isArray(node)) {
    node.forEach((child) => flatten(child, acc));

    return acc;
  }
  if (node && typeof node === 'object') {
    acc.push(node as Node);
    Object.values(node as Node).forEach((value) => flatten(value, acc));
  }

  return acc;
}

/**
 * 노드의 `if` 를 화면 문맥으로 평가한다.
 *
 * @param node 노드
 * @param ctx `_local`·`order` 문맥
 * @returns 표시 여부
 */
function visible(node: Node | undefined, ctx: { _local: any; order: any }): boolean {
  const body = String(node?.if ?? 'true').replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return Boolean(new Function('_local', 'order', `return (${body});`)(ctx._local, ctx.order));
}

const estimateWithPointsZero = {
  claimEstimateLoading: false,
  claimType: 'return',
  claimEstimate: { adjustment: { refund_points_amount: 0, original_snapshot: { total_points_used_amount: 3000 } } },
};

const pointsRow = flatten(comparison).find((n) => n.props?.['data-line'] === 'refund_points' || n['data-line'] === 'refund_points');
const priority = flatten(summary).find((n) => n.props?.['data-testid'] === 'claim-refund-priority');

describe('사용 마일리지 미차감 주문의 신청 화면', () => {
  /**
   * @effects undeducted_points_row_shows_zero_with_reason
   */
  it('반환 줄이 서고 「0원 (차감되지 않은 마일리지)」를 보인다', () => {
    expect(pointsRow, '마일리지 반환 줄 노드가 없다').toBeDefined();
    expect(visible(pointsRow, { _local: estimateWithPointsZero, order: { data: { points_not_deducted: true } } })).toBe(true);
    expect(JSON.stringify(pointsRow)).toContain('claim.apply.comparison.refund_points_not_deducted');
  });

  /**
   * @effects undeducted_points_row_shows_zero_with_reason
   */
  it('차감된 주문에서 반환 몫이 0 이면 종전처럼 줄이 서지 않는다 (회귀 가드)', () => {
    expect(visible(pointsRow, { _local: estimateWithPointsZero, order: { data: { points_not_deducted: false } } })).toBe(false);
  });

  /**
   * @effects undeducted_points_row_shows_zero_with_reason
   */
  it('환불 우선순위 선택을 숨긴다 — 돌려줄 마일리지가 없어 뜻이 없다', () => {
    expect(priority, '환불 우선순위 노드가 없다').toBeDefined();
    expect(visible(priority, { _local: estimateWithPointsZero, order: { data: { points_not_deducted: true } } })).toBe(false);
    expect(visible(priority, { _local: estimateWithPointsZero, order: { data: { points_not_deducted: false } } })).toBe(true);
  });
});
