/**
 * 분류 칩 줄 배치 계산
 *
 * 칩이 한 줄에 다 들어가면 한 줄로 둔다. 넘치면 두 줄로 나눠 가로로 넘기게 한다 — 줄이 계속 늘어나
 * 상품 목록을 화면 아래로 밀어내지 않도록.
 *
 * 두 줄로도 넘치면 칩을 조금씩 줄인다(좌우 여백·칩 간격·글자 크기). 다 들어가는 단계가 있으면 거기서 멈추고,
 * 끝까지 줄여도 넘치면 화면 끝에 걸친 칩이 반쯤 보이는 단계를 고른다 — 칩이 딱 맞게 잘려 보이면 옆에 더 있다는 것을
 * 알 수 없기 때문이다.
 *
 * 단계마다 칩 폭을 다시 재지 않는다. 처음 잰 폭에서 줄어드는 여백과 글자 폭 비율로 추정한다(단계 수 × 칩 수 계산).
 */

/** 줄이기 단계 수 (0 = 줄이지 않음) */
export const CHIP_ROWS_STEPS = 12;

/** 줄이기 전 값 → 끝 단계 값 (px) */
const PADDING_X = { from: 12, to: 6 };
const GAP = { from: 8, to: 4 };
const FONT_SIZE = { from: 14, to: 13 };

/** 칩 테두리 두께 합 (좌·우, px) — 글자 폭 추정에서 뺀다 */
const CHIP_BORDER = 2;

/** 걸친 칩이 보여야 하는 비율 범위 */
const BOUNDARY_MIN = 0.35;
const BOUNDARY_MAX = 0.65;

export interface ChipRowsPlan {
  /** 줄 수 */
  rows: 1 | 2;
  /** 두 줄일 때 안쪽 폭(px) — 이 폭에서 줄바꿈하면 정확히 두 줄이 된다. 한 줄이면 null */
  rowWidth: number | null;
  /** 고른 줄이기 단계 (0 = 줄이지 않음) */
  step: number;
  /** 칩 좌우 여백 (px) */
  paddingX: number;
  /** 칩 간격 (px) */
  gap: number;
  /** 칩 글자 크기 (px) */
  fontSize: number;
  /** 두 줄로도 화면 폭을 넘는가 (가로로 넘겨 본다) */
  overflow: boolean;
  /** 화면 끝에 걸친 칩이 보이는 비율 (넘치지 않으면 1) */
  boundaryVisible: number;
}

const lerp = (range: { from: number; to: number }, step: number): number =>
  range.from + ((range.to - range.from) * step) / CHIP_ROWS_STEPS;

/**
 * 한 단계에서의 칩 폭을 추정한다.
 *
 * @param width 줄이기 전 칩 폭
 * @param paddingX 그 단계의 좌우 여백
 * @param fontSize 그 단계의 글자 크기
 * @returns 추정 폭
 */
function shrunkWidth(width: number, paddingX: number, fontSize: number): number {
  const textWidth = Math.max(0, width - PADDING_X.from * 2 - CHIP_BORDER);
  const shrunk = width - (PADDING_X.from - paddingX) * 2 - textWidth * (1 - fontSize / FONT_SIZE.from);
  return Math.max(0, shrunk);
}

/**
 * 줄 폭 (칩 폭 합 + 간격).
 */
function rowWidthOf(widths: number[], gap: number): number {
  if (widths.length === 0) return 0;
  return widths.reduce((sum, w) => sum + w, 0) + gap * (widths.length - 1);
}

/**
 * 줄에서 화면 끝에 걸친 칩이 보이는 비율.
 *
 * @param widths 줄의 칩 폭
 * @param gap 칩 간격
 * @param containerWidth 보이는 폭
 * @returns 0~1 (걸친 칩이 없으면 1)
 */
function boundaryVisibleOf(widths: number[], gap: number, containerWidth: number): number {
  let left = 0;
  for (const width of widths) {
    const right = left + width;
    if (right > containerWidth) {
      if (width <= 0) return 1;
      return Math.max(0, Math.min(1, (containerWidth - left) / width));
    }
    left = right + gap;
  }
  return 1;
}

/**
 * 한 단계의 배치를 계산한다.
 */
function planAt(chipWidths: number[], containerWidth: number, step: number): ChipRowsPlan {
  const paddingX = lerp(PADDING_X, step);
  const gap = lerp(GAP, step);
  const fontSize = lerp(FONT_SIZE, step);
  const widths = step === 0 ? chipWidths : chipWidths.map((w) => shrunkWidth(w, paddingX, fontSize));

  // 안쪽 폭은 절반씩 나눈 두 줄 중 긴 쪽에 맞춘다 — 이 폭이면 줄바꿈이 정확히 두 줄이 된다
  const half = Math.ceil(widths.length / 2);
  const rowWidth = Math.max(rowWidthOf(widths.slice(0, half), gap), rowWidthOf(widths.slice(half), gap));
  // 실제 줄은 화면의 flex-wrap 처럼 그 폭까지 앞에서부터 채운다 — 앞 칩이 짧으면 윗줄이 절반보다 많다
  let split = 0;
  let used = 0;
  while (split < widths.length) {
    const next = split === 0 ? widths[0] : used + gap + widths[split];
    if (next > rowWidth + 0.5) break;
    used = next;
    split++;
  }
  const top = widths.slice(0, split);
  const bottom = widths.slice(split);
  const topWidth = rowWidthOf(top, gap);
  const bottomWidth = rowWidthOf(bottom, gap);
  const overflow = rowWidth > containerWidth;
  // 걸친 칩은 더 긴 줄에서 본다 — 눈에 먼저 띄는 쪽이다
  const boundaryVisible = overflow ? boundaryVisibleOf(topWidth >= bottomWidth ? top : bottom, gap, containerWidth) : 1;

  return { rows: 2, rowWidth, step, paddingX, gap, fontSize, overflow, boundaryVisible };
}

/**
 * 분류 칩 줄 배치를 계산한다.
 *
 * @param chipWidths 줄이기 전 칩 폭 목록 (px, 화면 순서)
 * @param containerWidth 보이는 폭 (px)
 * @param forcedStep 이 단계로 고정해 계산한다 (시험·진단용 — 주지 않으면 알맞은 단계를 고른다)
 * @returns 배치
 */
export function planChipRows(chipWidths: number[], containerWidth: number, forcedStep?: number): ChipRowsPlan {
  const single: ChipRowsPlan = {
    rows: 1,
    rowWidth: null,
    step: 0,
    paddingX: PADDING_X.from,
    gap: GAP.from,
    fontSize: FONT_SIZE.from,
    overflow: false,
    boundaryVisible: 1,
  };

  if (forcedStep !== undefined) {
    return planAt(chipWidths, containerWidth, Math.max(0, Math.min(CHIP_ROWS_STEPS, forcedStep)));
  }

  // 폭을 아직 모르거나 나눌 칩이 없으면 그대로 둔다
  if (!(containerWidth > 0) || chipWidths.length < 2) return single;
  if (rowWidthOf(chipWidths, GAP.from) <= containerWidth) return single;

  let firstInRange: ChipRowsPlan | null = null;
  let closest: ChipRowsPlan | null = null;

  for (let step = 0; step <= CHIP_ROWS_STEPS; step++) {
    const plan = planAt(chipWidths, containerWidth, step);
    // 다 들어가는 첫 단계에서 멈춘다 — 필요 이상으로 줄이지 않는다
    if (!plan.overflow) return plan;

    if (!firstInRange && plan.boundaryVisible >= BOUNDARY_MIN && plan.boundaryVisible <= BOUNDARY_MAX) {
      firstInRange = plan;
    }
    if (!closest || Math.abs(plan.boundaryVisible - 0.5) < Math.abs(closest.boundaryVisible - 0.5)) {
      closest = plan;
    }
  }

  return firstInRange ?? closest ?? single;
}
