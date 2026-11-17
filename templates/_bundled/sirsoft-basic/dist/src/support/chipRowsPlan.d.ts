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
export declare const CHIP_ROWS_STEPS = 12;
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
/**
 * 분류 칩 줄 배치를 계산한다.
 *
 * @param chipWidths 줄이기 전 칩 폭 목록 (px, 화면 순서)
 * @param containerWidth 보이는 폭 (px)
 * @param forcedStep 이 단계로 고정해 계산한다 (시험·진단용 — 주지 않으면 알맞은 단계를 고른다)
 * @returns 배치
 */
export declare function planChipRows(chipWidths: number[], containerWidth: number, forcedStep?: number): ChipRowsPlan;
