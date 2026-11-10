/**
 * @file mypage-mileage-expiring-date.test.ts
 * @description 마이페이지 마일리지 요약 「소멸 예정」 칸의 날짜 문구가 서버의 보는 사람 날짜를 쓰는지 (레이아웃 구조 + 표현식 평가).
 *
 * 결함(#160): 「…까지」 문구에 ISO 원문(`expiring_date` — 「2026-12-31T14:59:59+00:00」)을 그대로 넣어 시각·오프셋이 보였다.
 *   서버가 보는 사람 시간대 날짜로 만든 `expiring_date_formatted`(「2026-12-31」)를 쓴다.
 */

import { describe, it, expect } from 'vitest';

import mileageList from '../../../layouts/partials/mypage/mileage/_list.json';

/** 조건에 맞는 노드를 트리에서 모두 모은다 */
function collect(node: unknown, match: (value: Record<string, unknown>) => boolean, out: Record<string, unknown>[] = []): Record<string, unknown>[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collect(child, match, out));

    return out;
  }

  if (node === null || typeof node !== 'object') {
    return out;
  }

  const value = node as Record<string, unknown>;

  if (match(value)) {
    out.push(value);
  }

  Object.values(value).forEach((child) => collect(child, match, out));

  return out;
}

/** `{{ … }}` 식을 mileage_balance 컨텍스트로 평가한다 */
function evaluate(text: string, mileageBalance: unknown): string {
  // eslint-disable-next-line no-new-func
  return text.replace(/\{\{([\s\S]+?)\}\}/g, (_, expr) => String(new Function('mileage_balance', `return (${expr});`)(mileageBalance)));
}

describe('마일리지 요약 — 소멸 예정일', () => {
  const nodes = collect(mileageList, (value) => typeof value.text === 'string' && value.text.includes('mypage.mileage.summary.expiring_until'));
  const balance = {
    data: {
      mileage: {
        expiring_soon: 100,
        expiring_date: '2026-12-31T14:59:59+00:00',
        expiring_date_formatted: '2026-12-31',
      },
    },
  };

  /**
   * @scenario value=formatted_present
   * @effects expiring_until_uses_viewer_date
   */
  it('문구의 날짜 인자는 expiring_date_formatted 이고 ISO 원문(T·오프셋)이 섞이지 않는다', () => {
    expect(nodes.length).toBe(1);
    const text = nodes[0].text as string;
    expect(text).toContain('expiring_date_formatted');
    const rendered = evaluate(text, balance);
    expect(rendered).toContain('date=2026-12-31');
    expect(rendered).not.toContain('T14:59');
    expect(rendered).not.toContain('+00:00');
  });

  /**
   * @scenario value=formatted_absent
   * @effects expiring_until_hidden_without_date
   */
  it('표시 조건도 formatted 값 기준이라 소멸 예정이 없으면 문구가 숨는다', () => {
    const condition = String(nodes[0].if ?? '');
    expect(condition).toContain('expiring_date_formatted');
    expect(evaluate(condition, { data: { mileage: { expiring_date_formatted: null } } })).toBe('null');
    expect(evaluate(condition, balance)).toBe('2026-12-31');
  });
});
