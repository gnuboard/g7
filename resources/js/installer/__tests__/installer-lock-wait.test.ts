/**
 * 점유 해제 대기 화면 회귀 테스트 (D7 — 「공간 확보 후 재시도하니 이미 진행중이라고 한다」 제보).
 *
 * 현장(2026-09-12, 무CLI 단계별 · 공유 호스팅): 디스크가 차서 태스크가 죽었고, 그 죽음은
 * 해제 기록을 남기지 못해 점유가 남았다. 운영자가 공간을 비우고 [재시도] 를 눌렀지만 화면은
 * 「다른 창에서 설치가 이미 진행 중입니다. 그 창에서 이어서 진행해주세요」라고 답했다 —
 * **그 창은 없었다.** 실측: 경과 429초 / 창 600초. 10분 동안 존재하지 않는 창을 가리켰고,
 * 남은 출구는 [처음부터 다시](전부 폐기)뿐이었다.
 *
 * 서버는 이제 남은 시간(`retry_after`)을 함께 준다. 이 구간의 계약은 셋이다.
 *   1. 그 숫자를 **세어 보여 준다** — 안내가 ":seconds초 뒤" 라고 약속했다.
 *   2. 0 에서 **한 번** 스스로 이어간다 — 세기만 하면 그 약속이 거짓이 된다.
 *   3. 자동 재시도는 **정확히 1회**다 — 무한 재시도는 D2 가 고친 폭주를 다시 만든다.
 *
 * 프로덕션 소스의 `region:lock-wait` 구간을 그대로 잘라 평가한다(사본 금지 —
 * installer-progress-log-render.test.ts 와 같은 하네스).
 *
 * @scenario task_outcome=done
 *
 * @effects lock_wait_counts_down_and_resumes_once
 */
import { readFileSync } from 'fs';
import path from 'path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const INSTALLER_SOURCE = path.resolve(
  __dirname,
  '../../../../public/install/assets/js/installer.js',
);

type LockWaitRegion = {
  startLockWait: (seconds: number, message: string) => void;
  cancelLockWait: () => void;
};

function installerSource(): string {
  return readFileSync(INSTALLER_SOURCE, 'utf8');
}

function regionSource(): string {
  const src = installerSource();
  const openMarker = src.indexOf('region:lock-wait');
  const closeMarker = src.indexOf('endregion:lock-wait');

  expect(openMarker, '해제 대기 구간 시작 표식이 없다 — 표식을 지우면 이 축이 통째로 사각이 된다').toBeGreaterThan(-1);
  expect(closeMarker, '해제 대기 구간 종료 표식이 없다').toBeGreaterThan(openMarker);

  const bodyStart = src.indexOf('*/', openMarker) + 2;
  const bodyEnd = src.lastIndexOf('/*', closeMarker);

  return src.slice(bodyStart, bodyEnd);
}

/**
 * 구간이 부르는 화면 공용 함수(`showError` · `continueInstallation` · `lang`)는 주입한다 —
 * 구간 안에 사본을 두면 「프로덕션 소스를 그대로 평가한다」는 전제가 깨진다.
 */
type Stubs = {
  showError: (m: string) => void;
  continueInstallation: () => void;
  fetchInstallationState: () => Promise<unknown>;
  stepwiseResumeApplies: (s: unknown) => boolean;
  resumeStepwiseInstallation: (s: unknown) => void;
};

function loadRegion(stubs: Stubs): LockWaitRegion {
  // 치환값을 **보이게** 돌려준다 — 실제 문구(`:seconds초 뒤 …`)를 흉내 내면 이 테스트가
  // 문구 자체를 잠그게 되어, 번역을 다듬을 때마다 깨진다. 여기서 잴 것은 「그 값이 실제로
  // 넘어갔는가」다.
  const lang = (key: string, replace?: Record<string, string>) => {
    const args = Object.entries(replace ?? {});

    return args.length === 0
      ? `L:${key}`
      : `L:${key}(${args.map(([k, v]) => `${k}=${v}`).join(',')})`;
  };

  // eslint-disable-next-line no-new-func
  return new Function(
    'lang',
    'showError',
    'continueInstallation',
    'fetchInstallationState',
    'stepwiseResumeApplies',
    'resumeStepwiseInstallation',
    `
    ${regionSource()}
    return { startLockWait, cancelLockWait };
  `,
  )(
    lang,
    stubs.showError,
    stubs.continueInstallation,
    stubs.fetchInstallationState,
    stubs.stepwiseResumeApplies,
    stubs.resumeStepwiseInstallation,
  ) as LockWaitRegion;
}

function seedFailureCard(): void {
  document.body.innerHTML = `
    <div id="failure-section">
      <div class="result-message" id="failure-message"></div>
    </div>`;
}

let resumed: number;
let stepwiseResumed: number;
let region: LockWaitRegion;
/** 이 상태를 재개로 이어야 하는가 — 케이스마다 갈아 끼운다. */
let resumeApplies: boolean;
/** 상태 조회가 던지는가 (서버 불통 폴백 축) */
let stateThrows: boolean;

function build(): void {
  region = loadRegion({
    showError: () => {},
    continueInstallation: () => {
      resumed += 1;
    },
    fetchInstallationState: async () => {
      if (stateThrows) throw new Error('unreachable');

      return { status: 'running', installation_mode: 'stepwise' };
    },
    stepwiseResumeApplies: () => resumeApplies,
    resumeStepwiseInstallation: () => {
      stepwiseResumed += 1;
    },
  });
}

beforeEach(() => {
  vi.useFakeTimers();
  seedFailureCard();
  resumed = 0;
  stepwiseResumed = 0;
  resumeApplies = false;
  stateThrows = false;
  build();
});

afterEach(() => {
  region.cancelLockWait();
  vi.useRealTimers();
});

const countdown = () => document.getElementById('lock-wait-countdown');

describe('해제 대기 — 남은 시간을 세고 한 번 이어간다 (D7)', () => {
  /**
   * @scenario task_outcome=done
   *
   * @effects lock_wait_counts_down_and_resumes_once
   */
  it('남은 초를 실제로 센다 — 안내가 약속한 숫자가 줄어든다', () => {
    region.startLockWait(5, 'held');

    expect(countdown()?.textContent).toContain('5');

    vi.advanceTimersByTime(1000);
    expect(countdown()?.textContent).toContain('4');

    vi.advanceTimersByTime(2000);
    expect(countdown()?.textContent).toContain('2');
  });

  /**
   * @scenario task_outcome=done
   *
   * @effects lock_wait_counts_down_and_resumes_once
   */
  it('0 에서 스스로 이어간다 — 세기만 하면 안내가 거짓이 된다', async () => {
    region.startLockWait(3, 'held');

    await vi.advanceTimersByTimeAsync(2000);
    expect(resumed, '아직 남았는데 이어가면 같은 벽에 부딪힌다').toBe(0);

    await vi.advanceTimersByTimeAsync(1000);
    expect(resumed).toBe(1);
    expect(countdown()?.textContent).toBe('L:installation_lock_wait_resuming');
  });

  /**
   * 무한 재시도는 D2 가 고친 폭주를 다시 만든다 — 자동은 **정확히 1회**다.
   *
   * @scenario task_outcome=done
   *
   * @effects lock_wait_counts_down_and_resumes_once
   */
  it('자동 재시도는 한 번뿐이다', async () => {
    region.startLockWait(1, 'held');

    await vi.advanceTimersByTimeAsync(60_000);

    expect(resumed).toBe(1);
  });

  /**
   * **무엇으로** 이어갈지는 화면 로드가 쓰는 규칙과 같아야 한다 (D7 브라우저 실측이 잡은 자리).
   *
   * 단계별 + `running` 에서 시작 경로는 토큰 게이트에 403 으로 막힌다 — 카운트다운이 돌고도
   * 끝이 또 막다른 길이 된다.
   *
   * @scenario task_outcome=done
   *
   * @effects lock_wait_counts_down_and_resumes_once
   */
  it('재개가 맞는 상태에서는 시작이 아니라 재개로 간다', async () => {
    resumeApplies = true;
    region.startLockWait(1, 'held');

    await vi.advanceTimersByTimeAsync(1500);

    expect(stepwiseResumed, '단계별 running 은 재개가 정본이다').toBe(1);
    expect(resumed, '시작 경로로 가면 403 으로 막힌다').toBe(0);
  });

  /**
   * 상태를 못 읽으면 종전 동작(시작)으로 떨어진다 — 확인하지 못한 것을 근거로 경로를 바꾸지 않는다.
   *
   * @scenario task_outcome=done
   *
   * @effects lock_wait_counts_down_and_resumes_once
   */
  it('상태 조회가 실패하면 시작 경로로 떨어진다', async () => {
    stateThrows = true;
    region.startLockWait(1, 'held');

    await vi.advanceTimersByTimeAsync(1500);

    expect(resumed).toBe(1);
    expect(stepwiseResumed).toBe(0);
  });

  /**
   * @scenario task_outcome=done
   *
   * @effects lock_wait_counts_down_and_resumes_once
   */
  it('취소하면 이어가지 않는다 — 운영자가 먼저 눌렀을 때의 이중 발사를 막는다', async () => {
    region.startLockWait(3, 'held');
    await vi.advanceTimersByTimeAsync(1000);

    region.cancelLockWait();
    await vi.advanceTimersByTimeAsync(10_000);

    expect(resumed).toBe(0);
    expect(stepwiseResumed).toBe(0);
  });

  /**
   * 두 번 시작해도 타이머는 하나다 — 남으면 한 번의 대기가 두 번 이어가기를 쏜다.
   *
   * @scenario task_outcome=done
   *
   * @effects lock_wait_counts_down_and_resumes_once
   */
  it('다시 시작하면 이전 타이머가 남지 않는다', async () => {
    region.startLockWait(2, 'held');
    await vi.advanceTimersByTimeAsync(1000);
    region.startLockWait(2, 'held');

    await vi.advanceTimersByTimeAsync(30_000);

    expect(resumed, '이전 타이머가 살아 있으면 두 번 나가고, 두 번째가 자기 워커에 막힌다').toBe(1);
  });

  /**
   * 초마다 바뀌는 값이라 라이브 리전으로 두지 않는다 — 화면 판독기가 매초 읽으면
   * 형제(실패 안내)가 묻힌다. D2 의 경과 표시와 같은 판단이다.
   *
   * @scenario task_outcome=done
   *
   * @effects lock_wait_counts_down_and_resumes_once
   */
  it('카운트다운 줄은 라이브 리전이 아니다', () => {
    region.startLockWait(5, 'held');

    const row = countdown();
    expect(row).not.toBeNull();
    expect(row!.getAttribute('aria-live')).toBeNull();
    expect(row!.getAttribute('role')).toBeNull();
  });

  /**
   * 실패 카드가 없는 화면에서도 죽지 않는다 — 이 경로는 오류 처리 중이다.
   *
   * @scenario task_outcome=done
   *
   * @effects lock_wait_counts_down_and_resumes_once
   */
  it('실패 카드가 없으면 조용히 물러난다', () => {
    document.body.innerHTML = '';

    expect(() => region.startLockWait(3, 'held')).not.toThrow();
  });
});

describe('해제 대기 — 호출부 배선 (소스 단언)', () => {
  /**
   * 구간이 아무리 옳아도 **부르는 자리**가 없으면 화면은 종전대로 막다른 오류를 그린다.
   *
   * @scenario task_outcome=done
   *
   * @effects lock_wait_counts_down_and_resumes_once
   */
  it('409 분기가 showError 보다 먼저 startLockWait 로 간다', () => {
    const src = installerSource();
    const at = src.indexOf('const retryAfter = Number(result.retry_after);');

    expect(at, '시작 응답의 retry_after 분기가 없다 — 서버가 남은 시간을 줘도 화면이 버린다').toBeGreaterThan(-1);

    const branch = src.slice(at, at + 400);
    expect(branch).toContain('startLockWait(retryAfter, result.message)');
    expect(
      branch.indexOf('startLockWait'),
      '`showError` 가 먼저 나오면 그 자리에서 return 되어 대기 분기에 닿지 못한다',
    ).toBeLessThan(branch.indexOf('showError'));
  });

  /**
   * @scenario task_outcome=done
   *
   * @effects lock_wait_counts_down_and_resumes_once
   */
  /**
   * 재개 판정은 **한 함수**다 — 사본을 두면 「로드하면 이어지는데 대기 뒤에는 403 으로 죽는」
   * 상태가 생긴다. D7 브라우저 실측이 그 모양을 실제로 잡아냈다.
   *
   * @scenario task_outcome=done
   *
   * @effects lock_wait_counts_down_and_resumes_once
   */
  it('로드 복원과 대기 재개가 같은 판정 함수를 쓴다', () => {
    const src = installerSource();

    expect(
      (src.match(/stepwiseResumeApplies\(/g) || []).length,
      '정의 1 + 호출 2(로드 복원·대기 재개) = 3 미만이면 한쪽이 규칙을 복사했거나 배선이 빠졌다',
    ).toBeGreaterThanOrEqual(3);

    // 규칙 본문이 두 곳에 퍼지지 않았는지 — 원문 조건은 함수 안에만 있어야 한다.
    expect(
      (src.match(/state\.installation_mode === 'stepwise'/g) || []).length,
      '판정 조건이 복사됐다 — 한 곳만 고쳐지면 두 경로가 갈라진다',
    ).toBe(1);
  });

  /**
   * 끄지 않으면 타이머가 뒤따라 한 번 더 쏘고, 그 두 번째가 자기 워커에 막혀 같은 화면으로
   * 되돌아온다.
   *
   * @scenario task_outcome=done
   *
   * @effects lock_wait_counts_down_and_resumes_once
   */
  it('수동 [재시도] 가 대기 타이머를 먼저 끈다', () => {
    const src = installerSource();
    const at = src.indexOf('function retryInstallation()');

    expect(at).toBeGreaterThan(-1);

    const body = src.slice(at, src.indexOf('}', at));
    expect(
      body,
      '끄지 않으면 타이머가 뒤따라 한 번 더 쏘고, 그 두 번째가 자기 워커에 막혀 같은 화면으로 되돌아온다',
    ).toContain('cancelLockWait();');
  });
});
