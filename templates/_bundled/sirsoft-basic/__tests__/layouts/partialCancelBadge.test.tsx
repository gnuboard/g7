/**
 * @file partialCancelBadge.test.tsx
 * @description 마이페이지 주문내역 "일부취소" 보조 배지 구조 회귀 테스트
 *
 * 배경:
 * - partial_cancelled 주문상태 제거 후, 일부 취소 사실은 파생 플래그 is_partially_cancelled 로 표시한다.
 * - 목록(_list.json) 헤더 행은 flex justify-between [좌: 날짜/주문번호] vs [우: 상태 배지] 구조다.
 *   보조 배지를 헤더 행의 3번째 형제로 넣으면 justify-between 이 3개를 분산시켜 레이아웃이 깨진다(검수 중 발견).
 *   → 상태 배지와 보조 배지를 하나의 세로 묶음(flex-col items-end) Div 로 감싸 "결제완료" 아래에 배치한다.
 *
 * 회귀 차단:
 * - 보조 배지는 상태 배지와 같은 부모 Div(flex-col) 안의 형제여야 한다(헤더 행 직속 형제 금지).
 * - 보조 배지는 if=is_partially_cancelled 게이트 + $t:mypage.orders.partial_cancelled_badge 라벨을 쓴다.
 * - 보조 배지는 라이트/다크 클래스 쌍을 모두 갖는다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const listPath = path.resolve(__dirname, '../../layouts/partials/mypage/orders/_list.json');

interface Node {
  type?: string;
  name?: string;
  if?: string;
  comment?: string;
  props?: Record<string, any>;
  classMap?: Record<string, any>;
  text?: string;
  children?: Node[];
}

function load(p: string): Node {
  return JSON.parse(fs.readFileSync(p, 'utf8'));
}

function flatten(node: Node | undefined, acc: Node[] = []): Node[] {
  if (!node) return acc;
  acc.push(node);
  if (Array.isArray(node.children)) {
    for (const c of node.children) flatten(c, acc);
  }
  return acc;
}

const BADGE_LABEL = '$t:mypage.orders.partial_cancelled_badge';

describe('_list.json — 일부취소 보조 배지 구조', () => {
  const list = load(listPath);
  const nodes = flatten(list);

  const badge = nodes.find((n) => n.text === BADGE_LABEL);

  it('일부취소 보조 배지 노드가 존재한다', () => {
    expect(badge).toBeTruthy();
  });

  it('보조 배지는 is_partially_cancelled 파생 플래그로 게이트된다', () => {
    expect(badge?.if).toBe('{{order.is_partially_cancelled}}');
  });

  it('보조 배지는 상태 배지와 같은 세로 묶음(flex-col) Div 안의 형제다 (헤더 행 직속 형제 금지)', () => {
    // 보조 배지를 자식으로 갖는 부모 Div 를 찾는다.
    const parent = nodes.find(
      (n) => Array.isArray(n.children) && n.children.some((c) => c.text === BADGE_LABEL),
    );
    expect(parent).toBeTruthy();
    expect(parent?.name).toBe('Div');
    // 부모 묶음은 세로 배치 + 우측 정렬이어야 "결제완료" 아래에 배지가 온다.
    expect(parent?.props?.className).toContain('flex-col');
    expect(parent?.props?.className).toContain('items-end');

    // 같은 묶음 안에 상태 배지(order.status_label)도 형제로 있어야 한다.
    const hasStatusBadge = parent!.children!.some((c) => c.text === '{{order.status_label}}');
    expect(hasStatusBadge).toBe(true);
  });

  it('보조 배지는 라이트/다크 클래스 쌍을 모두 갖는다', () => {
    const cls = badge?.props?.className ?? '';
    expect(cls).toContain('bg-orange-100');
    expect(cls).toContain('dark:bg-orange-900/30');
    expect(cls).toContain('text-orange-800');
    expect(cls).toContain('dark:text-orange-400');
  });

  it('헤더 행에 더 이상 partial_cancelled order_status 필터 옵션이 없다 (별도 상태 제거)', () => {
    const json = JSON.stringify(list);
    // 필터 드롭다운 Option value 로서의 partial_cancelled 가 없어야 한다.
    expect(json.includes('"value": "partial_cancelled"')).toBe(false);
  });
});
