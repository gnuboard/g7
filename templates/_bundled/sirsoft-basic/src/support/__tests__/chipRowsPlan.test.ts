/**
 * @file chipRowsPlan.test.ts
 * @description 분류 칩 줄 배치 계산(`planChipRows`)을 잠근다.
 *
 * 칩이 한 줄에 다 들어가면 한 줄, 넘치면 두 줄로 나눠 가로로 넘긴다. 두 줄로도 넘치면 칩을 조금씩 줄여
 * 다 들어가는 단계를 찾고, 그래도 넘치면 화면 끝에 걸친 칩이 반쯤 보이는 단계를 골라 옆에 더 있음을 드러낸다.
 *
 * @scenario-manifest tests/scenarios/shop-category-chip-rows.yaml
 */

import { describe, it, expect } from 'vitest';
import { CHIP_ROWS_STEPS, planChipRows } from '../chipRowsPlan';

describe('planChipRows', () => {
  // @scenario case=c01
  // @effects chips_stay_on_one_row_when_they_fit
  it('다 들어가면 한 줄이고 줄이지 않는다', () => {
    const plan = planChipRows([80, 80, 80], 400);

    expect(plan.rows).toBe(1);
    expect(plan.rowWidth).toBeNull();
    expect(plan.step).toBe(0);
    expect(plan.overflow).toBe(false);
  });

  // @scenario case=c02
  // @effects chips_split_into_two_rows_when_overflowing
  it('넘치면 두 줄로 나누고 폭은 더 긴 줄에 맞춘다', () => {
    // 한 줄 합 = 6*100 + 5*8 = 640 > 400. 앞 3개 = 316, 뒤 3개 = 316 → 400 안
    const plan = planChipRows([100, 100, 100, 100, 100, 100], 400);

    expect(plan.rows).toBe(2);
    expect(plan.rowWidth).toBe(316);
    expect(plan.step).toBe(0);
    expect(plan.overflow).toBe(false);

    // 앞 절반이 더 길면 그 줄에 맞춘다 (칩 5개 → 앞 3개·뒤 2개)
    const uneven = planChipRows([150, 150, 150, 60, 60], 500);
    expect(uneven.rows).toBe(2);
    expect(uneven.rowWidth).toBe(150 * 3 + 8 * 2);
  });

  // @scenario case=c03
  // @effects chips_shrink_until_two_rows_fit
  it('두 줄로도 넘치면 다 들어가는 첫 단계까지만 줄인다', () => {
    // 칩 6개 × 130 → 두 줄 폭 406. 400 에 조금 모자라므로 한두 단계면 들어간다
    const plan = planChipRows([130, 130, 130, 130, 130, 130], 400);

    expect(plan.rows).toBe(2);
    expect(plan.step).toBeGreaterThan(0);
    expect(plan.step).toBeLessThan(CHIP_ROWS_STEPS);
    expect(plan.overflow).toBe(false);
    expect(plan.rowWidth!).toBeLessThanOrEqual(400);
    expect(plan.paddingX).toBeLessThan(12);
    expect(plan.fontSize).toBeLessThan(14);

    // 한 단계 앞은 아직 넘친다 — 필요 이상으로 줄이지 않는다
    const previous = planChipRows([130, 130, 130, 130, 130, 130], 400, plan.step - 1);
    expect(previous.overflow).toBe(true);
  });

  // @scenario case=c04
  // @effects boundary_chip_shows_half_when_still_overflowing
  it('끝까지 줄여도 넘치면 화면 끝에 걸친 칩이 35~65% 보이는 첫 단계를 고른다', () => {
    const widths = Array.from({ length: 12 }, () => 120);
    const plan = planChipRows(widths, 300);

    expect(plan.rows).toBe(2);
    expect(plan.overflow).toBe(true);
    expect(plan.boundaryVisible).toBeGreaterThanOrEqual(0.35);
    expect(plan.boundaryVisible).toBeLessThanOrEqual(0.65);

    // 그보다 앞 단계에는 조건을 만족하는 단계가 없다
    for (let step = 0; step < plan.step; step++) {
      const earlier = planChipRows(widths, 300, step);
      expect(earlier.boundaryVisible < 0.35 || earlier.boundaryVisible > 0.65).toBe(true);
    }
  });

  // @scenario case=c05
  // @effects boundary_chip_falls_back_to_closest_to_half
  it('35~65% 단계가 없으면 반에 가장 가까운 단계를 고른다', () => {
    // 넓은 칩 둘 — 한 줄에 하나씩이라 걸친 비율이 단계마다 조금씩만 움직인다. 화면 폭을 바꿔 가며
    // 「어느 단계도 35~65% 에 들지 않는」 경우를 모두 찾아, 그때마다 반에 가장 가까운 단계를 고르는지 본다.
    const widths = [400, 400];
    let checked = 0;
    for (let container = 20; container < 380; container += 3) {
      const all = Array.from({ length: CHIP_ROWS_STEPS + 1 }, (_, step) => planChipRows(widths, container, step));
      if (all.some((p) => !p.overflow || (p.boundaryVisible >= 0.35 && p.boundaryVisible <= 0.65))) continue;

      const plan = planChipRows(widths, container);
      const best = Math.min(...all.map((p) => Math.abs(p.boundaryVisible - 0.5)));
      expect(plan.overflow).toBe(true);
      expect(Math.abs(plan.boundaryVisible - 0.5)).toBeCloseTo(best, 5);
      checked++;
    }
    // 그런 경우가 실제로 있었는지(조건이 비어 통과하지 않게)
    expect(checked).toBeGreaterThan(0);
  });

  // @scenario case=c06
  // @effects chips_plan_handles_empty_and_single
  it('칩이 없거나 하나면 한 줄이다(하나가 화면보다 넓어도 두 줄로 나눌 수 없다)', () => {
    expect(planChipRows([], 300)).toMatchObject({ rows: 1, step: 0, overflow: false, rowWidth: null });
    expect(planChipRows([120], 300)).toMatchObject({ rows: 1, step: 0, overflow: false });
    expect(planChipRows([500], 300).rows).toBe(1);
    // 화면 폭을 아직 모르면(0) 아무것도 바꾸지 않는다
    expect(planChipRows([100, 100, 100], 0)).toMatchObject({ rows: 1, step: 0, rowWidth: null });
  });

  // @scenario case=c17
  // @effects chips_plan_splits_rows_like_flex_wrap
  it('줄은 화면의 줄바꿈처럼 앞에서부터 채워 나누고, 걸친 칩은 그 더 긴 줄에서 본다', () => {
    // 앞 15개는 짧고(50) 뒤 8개는 길다(110). 절반(12/12)으로 가르면 뒷줄이 더 길지만(1110),
    // 줄 폭 1110 까지 앞에서 채우는 flex-wrap 은 윗줄에 17개(1098)·아랫줄에 6개(700)를 놓는다.
    const widths = [...Array.from({ length: 15 }, () => 50), ...Array.from({ length: 8 }, () => 110)];
    const plan = planChipRows(widths, 343, 0);

    expect(plan.rows).toBe(2);
    expect(plan.rowWidth).toBe(1110);

    // 화면과 같은 방식으로 나눈 윗줄(더 긴 줄)의 경계 칩 비율
    const gap = 8;
    const top: number[] = [];
    let used = 0;
    for (const w of widths) {
      const next = top.length === 0 ? w : used + gap + w;
      if (next > plan.rowWidth!) break;
      top.push(w);
      used = next;
    }
    expect(top).toHaveLength(17);
    let left = 0;
    let expected = 1;
    for (const w of top) {
      if (left + w > 343) { expected = Math.max(0, Math.min(1, (343 - left) / w)); break; }
      left += w + gap;
    }
    expect(plan.boundaryVisible).toBeCloseTo(expected, 5);
  });

  // @scenario case=c07
  // @effects chips_plan_is_cheap
  it('칩이 많아도 단계 수 × 칩 수 계산으로 끝난다', () => {
    const widths = Array.from({ length: 2000 }, (_, i) => 60 + (i % 7) * 10);
    const started = performance.now();
    const plan = planChipRows(widths, 360);
    const elapsed = performance.now() - started;

    expect(plan.rows).toBe(2);
    expect(elapsed).toBeLessThan(50);
  });
});
