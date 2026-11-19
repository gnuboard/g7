/**
 * 진행 기록 렌더 회귀 테스트 (D2 — 「게시판 모듈 설치 단계 무응답」 제보).
 *
 * 실측(2026-09-11, 수정 전 · 무CLI 단계별 리허설)이 이 파일이 지키는 값의 근거다:
 *   · 한 설치가 진행 기록 13,017 줄(그중 `[cmd]` 12,798 줄 · 1.1 MB)을 만든다
 *   · 단계별은 한 응답이 그 태스크의 **모든** 줄을 싣고 온다 — 한 번에 207,699 자가 왔다
 *   · 그 순간 메인 스레드가 **1,579 ms** 멈췄다 (최장 long task, >500ms 2건, 총 블록 10.3 초)
 *
 * 그래서 이 구간의 계약은 셋이다 — 문자열 누적 금지 · 배치당 삽입 1회 · 화면 상한.
 * 프로덕션 소스의 `region:progress-log` 구간을 그대로 잘라 평가한다(사본 금지 —
 * installer-web-exposure-gate.test.ts 와 같은 하네스).
 *
 * @scenario progress_log_volume=large, task_duration=long_no_feedback
 *
 * @effects progress_log_is_rendered_in_batches_not_per_line,
 *          progress_log_has_a_screen_cap_and_says_so
 */
import { readFileSync } from 'fs';
import path from 'path';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const INSTALLER_SOURCE = path.resolve(
  __dirname,
  '../../../../public/install/assets/js/installer.js',
);

type ProgressLogRegion = {
  PROGRESS_LOG_MAX_LINES: number;
  commandOutputBody: (message: string) => string | null;
  appendCommandOutput: (lines: string | string[]) => void;
  addLogMessage: (message: string) => void;
  addLogMessages: (messages: string[]) => void;
  displayLogs: (logs: unknown[]) => void;
};

/**
 * 진행 기록 구간을 원문에서 잘라 낸다.
 *
 * meta:allow raw-source-read — 구간 표식(`region:progress-log`)이 **주석 안에** 있어 원문이
 * 필요하다. 이 결과는 실행에 쓰고, 문자 대조는 withoutComments() 를 지난 뒤에 한다.
 */
function regionSource(): string {
  const src = readFileSync(INSTALLER_SOURCE, 'utf8');
  const openMarker = src.indexOf('region:progress-log');
  const closeMarker = src.indexOf('endregion:progress-log');

  expect(openMarker, '진행 기록 구간 시작 표식이 없다 — 표식을 지우면 이 축이 통째로 사각이 된다').toBeGreaterThan(-1);
  expect(closeMarker, '진행 기록 구간 종료 표식이 없다').toBeGreaterThan(openMarker);

  const bodyStart = src.indexOf('*/', openMarker) + 2;
  const bodyEnd = src.lastIndexOf('/*', closeMarker);

  return src.slice(bodyStart, bodyEnd);
}

/**
 * JS 주석을 지운 소스. 문자열, 템플릿, 정규식 리터럴 안은 보존한다.
 *
 * 원문 대조는 주석 처리된 호출을 구분하지 못한다 — 호출을 줄 주석으로 감싸도 글자는 남는다.
 * (installer-token-wiring.test.ts 의 같은 이름 헬퍼와 같은 규칙이다.)
 */
function withoutComments(src: string): string {
  let out = '';
  let i = 0;
  let last = '';

  while (i < src.length) {
    const ch = src[i];
    const next = src[i + 1] ?? '';

    if (ch === '/' && next === '/') {
      while (i < src.length && src[i] !== '\n') i++;
      continue;
    }
    if (ch === '/' && next === '*') {
      const end = src.indexOf('*/', i + 2);
      i = end === -1 ? src.length : end + 2;
      continue;
    }
    if (ch === "'" || ch === '"' || ch === '`') {
      out += ch;
      i++;
      while (i < src.length) {
        out += src[i];
        if (src[i] === '\\') {
          i++;
          if (i < src.length) out += src[i];
          i++;
          continue;
        }
        if (src[i] === ch) {
          i++;
          break;
        }
        i++;
      }
      last = ch;
      continue;
    }
    if (ch === '/' && !/[A-Za-z0-9_$)\]]/.test(last)) {
      out += ch;
      i++;
      let inClass = false;
      while (i < src.length) {
        out += src[i];
        if (src[i] === '\\') {
          i++;
          if (i < src.length) out += src[i];
          i++;
          continue;
        }
        if (src[i] === '[') inClass = true;
        else if (src[i] === ']') inClass = false;
        else if ((src[i] === '/' && !inClass) || src[i] === '\n') {
          i++;
          break;
        }
        i++;
      }
      last = '/';
      continue;
    }

    out += ch;
    if (ch.trim() !== '') last = ch;
    i++;
  }

  return out;
}

/**
 * 구간이 참조하는 화면 공용 헬퍼(`lang`)와 모듈 상태(`renderedLogLineCount`)는 주입한다 —
 * 구간 안에 사본을 두면 「프로덕션 소스를 그대로 평가한다」는 전제가 깨진다.
 */
function loadProgressLogRegion(): ProgressLogRegion {
  const lang = (key: string) => `L:${key}`;

  // eslint-disable-next-line no-new-func
  return new Function(
    'lang',
    `
    let renderedLogLineCount = 0;
    ${regionSource()}
    return {
      PROGRESS_LOG_MAX_LINES,
      commandOutputBody,
      appendCommandOutput,
      addLogMessage,
      addLogMessages,
      displayLogs,
    };
  `,
  )(lang) as ProgressLogRegion;
}

let region: ProgressLogRegion;

beforeEach(() => {
  document.body.innerHTML = `
    <div id="install-log"><div class="log-placeholder">아직 기록이 없습니다</div></div>
    <div class="command-output-section" id="command-output-section" style="display: none;">
      <pre class="command-output-log" id="command-output-log"></pre>
    </div>
  `;
  region = loadProgressLogRegion();
});

const logDiv = () => document.getElementById('install-log') as HTMLElement;
const cmdLog = () => document.getElementById('command-output-log') as HTMLElement;
const entries = () => logDiv().querySelectorAll('.log-entry').length;
const cmdLines = () => cmdLog().querySelectorAll('.command-output-line').length;

describe('배치 렌더 — 한 응답이 몰고 온 줄을 한 번에 그린다 (D2 L1·L2·L4)', () => {
  /**
   * 종전에는 줄마다 `appendChild` + `scrollTop` 대입이었다. 단계별 실행은 한 응답이
   * 태스크 전체의 줄을 싣고 오므로, 그 순간 줄 수만큼 **강제 동기 레이아웃**이 난다.
   *
   * @scenario progress_log_volume=large, task_duration=short
   *
   * @effects progress_log_is_rendered_in_batches_not_per_line
   */
  it('2,000줄 배치가 삽입 1회로 들어간다 — 줄마다 붙이지 않는다', () => {
    const messages = Array.from({ length: 2000 }, (_, i) => `진행 기록 ${i}`);

    const spy = vi.spyOn(logDiv(), 'appendChild');
    region.addLogMessages(messages);

    expect(spy, '배치 하나에 appendChild 가 여러 번이면 줄 수만큼 리플로우가 난다').toHaveBeenCalledTimes(1);
    expect(
      (spy.mock.calls[0][0] as Node).nodeType,
      'DocumentFragment(11) 로 모아 한 번에 붙여야 한다',
    ).toBe(11);
    expect(entries()).toBe(2000);
    spy.mockRestore();
  });

  /**
   * @scenario progress_log_volume=large
   *
   * @effects progress_log_is_rendered_in_batches_not_per_line
   */
  it('배치 뒤 자동 스크롤은 1회다 — 줄마다 scrollHeight 를 읽지 않는다', () => {
    let scrollWrites = 0;
    Object.defineProperty(logDiv(), 'scrollTop', {
      configurable: true,
      get: () => 0,
      set: () => { scrollWrites += 1; },
    });

    region.addLogMessages(Array.from({ length: 500 }, (_, i) => `줄 ${i}`));

    expect(scrollWrites, '줄마다 스크롤을 대입하면 줄 수만큼 강제 리플로우가 난다').toBeLessThanOrEqual(1);
  });

  /**
   * 운영자가 위쪽을 읽는 중이면 화면을 바닥으로 끌어내리지 않는다 — 종전에는 줄이
   * 도착할 때마다 무조건 내려가 위를 읽는 것이 불가능했다.
   *
   * @scenario progress_log_volume=large
   *
   * @effects progress_log_is_rendered_in_batches_not_per_line
   */
  it('바닥이 아니면 배치 뒤에도 스크롤 위치가 유지된다', () => {
    const el = logDiv();
    Object.defineProperty(el, 'scrollHeight', { configurable: true, get: () => 10000 });
    Object.defineProperty(el, 'clientHeight', { configurable: true, get: () => 200 });
    let current = 0;
    Object.defineProperty(el, 'scrollTop', {
      configurable: true,
      get: () => current,
      set: (v) => { current = v; },
    });

    region.addLogMessages(['가', '나', '다']);

    expect(current, '위를 읽는 중에 화면이 끌려 내려가면 기록을 따라 읽을 수 없다').toBe(0);
  });

  /**
   * @scenario progress_log_volume=small
   *
   * @effects progress_log_is_rendered_in_batches_not_per_line
   */
  it('단건 API 는 배치 API 의 1건 호출과 같은 결과를 낸다 (호출부 호환)', () => {
    region.addLogMessage('한 줄');
    const single = logDiv().innerHTML;

    document.getElementById('install-log')!.innerHTML = '<div class="log-placeholder">x</div>';
    region.addLogMessages(['한 줄']);

    expect(logDiv().innerHTML).toBe(single);
  });
});

describe('커맨드 상세 — 문자열 누적을 쓰지 않는다 (D2 L1)', () => {
  /**
   * `textContent += …` 는 매 줄마다 상자 전체를 다시 만들고 다시 파싱한다 — n 줄이면
   * O(n²) 바이트 복사다. 실측에서 이 영역이 한 설치에 643,167 자까지 자란다.
   *
   * @scenario progress_log_volume=large
   *
   * @effects progress_log_is_rendered_in_batches_not_per_line
   */
  it('소스에 textContent 누적이 없다 (정적 계약)', () => {
    // 주석을 먼저 지운다 — 이 구간의 주석이 「`textContent += …` 는 …」이라고 **금지 사유를
    // 설명**하므로, 원문 그대로 대조하면 설명문이 위반으로 잡힌다(문자 대조 게이트는 주석을
    // 지운다는 저장소 규약과 같은 이유다).
    const code = withoutComments(regionSource()).replace(/\s+/g, '');

    expect(
      code,
      '진행 기록 구간에 문자열 누적이 남아 있다 — 줄 수의 제곱에 비례하는 복사가 된다',
    ).not.toContain('textContent+=');
  });

  /**
   * @scenario progress_log_volume=large
   *
   * @effects progress_log_is_rendered_in_batches_not_per_line
   */
  it('[cmd] 1,000줄이 각자의 요소로 들어가고 접기 영역이 열린다', () => {
    const messages = Array.from({ length: 1000 }, (_, i) => `[cmd] migrating ${i}`);

    const spy = vi.spyOn(cmdLog(), 'appendChild');
    region.addLogMessages(messages);

    expect(spy, '커맨드 원문도 배치당 1회로 붙인다').toHaveBeenCalledTimes(1);
    expect(cmdLines()).toBe(1000);
    expect(entries(), '커맨드 원문은 진행 기록으로 새지 않는다 (§12.0 W3)').toBe(0);
    expect(
      (document.getElementById('command-output-section') as HTMLElement).style.display,
      '첫 줄이 도착하면 접기 영역이 드러나야 한다',
    ).toBe('');
    spy.mockRestore();
  });

  /**
   * @scenario progress_log_volume=small
   *
   * @effects progress_log_is_rendered_in_batches_not_per_line
   */
  it('진행 기록과 커맨드 원문이 섞여 와도 각자의 상자로 한 번씩 간다', () => {
    region.addLogMessages(['시작합니다', '[cmd] INFO Running migrations', '완료했습니다', '[cmd] DONE']);

    expect(entries()).toBe(2);
    expect(cmdLines()).toBe(2);
    expect(cmdLog().textContent).toContain('INFO Running migrations');
  });
});

describe('화면 상한 — 전체 기록은 서버 로그가 갖는다 (D2 L3)', () => {
  /**
   * @scenario progress_log_volume=large
   *
   * @effects progress_log_has_a_screen_cap_and_says_so
   */
  it('상한을 넘기면 앞에서 걷어내고 그 사실을 한 줄로 알린다', () => {
    const cap = region.PROGRESS_LOG_MAX_LINES;
    expect(cap, '상한이 없으면 재개 렌더에서 누적 전량이 한 프레임에 그려진다').toBeGreaterThan(0);

    region.addLogMessages(Array.from({ length: cap + 500 }, (_, i) => `줄 ${i}`));

    expect(entries(), '화면 줄 수가 상한을 넘었다').toBeLessThanOrEqual(cap);

    const notice = logDiv().querySelector('.log-truncated-notice');
    expect(notice, '걷어낸 사실을 알리지 않으면 운영자에게는 기록이 사라진 것이다').not.toBeNull();
    expect(notice!.textContent).toContain('L:log_truncated_notice');
  });

  /**
   * @scenario progress_log_volume=large
   *
   * @effects progress_log_has_a_screen_cap_and_says_so
   */
  it('상한을 계속 넘겨도 안내 줄은 하나뿐이고 걷은 총량이 누적된다', () => {
    const cap = region.PROGRESS_LOG_MAX_LINES;
    region.addLogMessages(Array.from({ length: cap + 100 }, (_, i) => `a${i}`));
    region.addLogMessages(Array.from({ length: 300 }, (_, i) => `b${i}`));

    expect(logDiv().querySelectorAll('.log-truncated-notice').length, '안내가 배치마다 붙으면 그 자체가 로그 폭주다').toBe(1);
    expect(entries()).toBeLessThanOrEqual(cap);
  });

  /**
   * @scenario progress_log_volume=large
   *
   * @effects progress_log_has_a_screen_cap_and_says_so
   */
  it('커맨드 상세에도 같은 상한이 걸린다', () => {
    const cap = region.PROGRESS_LOG_MAX_LINES;
    region.addLogMessages(Array.from({ length: cap + 200 }, (_, i) => `[cmd] line ${i}`));

    expect(cmdLines()).toBeLessThanOrEqual(cap);
    expect(cmdLog().querySelector('.log-truncated-notice')).not.toBeNull();
  });
});

describe('새로고침 복원 — 가장 무거운 렌더가 같은 배치 경로를 쓴다 (D2 L4)', () => {
  /**
   * 재개는 누적 기록 **전량**을 한 번에 그린다 — 가장 무거운 렌더인데 종전에는 이 자리가
   * 자기 루프로 줄마다 붙이고 있었다.
   *
   * @scenario progress_log_volume=large, task_duration=short
   *
   * @effects progress_log_is_rendered_in_batches_not_per_line
   */
  it('displayLogs 가 배치 1회로 그린다 — 객체·문자열 형태를 모두 받는다', () => {
    const logs = [
      ...Array.from({ length: 400 }, (_, i) => ({ message: `복원 ${i}` })),
      ...Array.from({ length: 100 }, (_, i) => `문자열 ${i}`),
    ];

    const spy = vi.spyOn(logDiv(), 'appendChild');
    region.displayLogs(logs);

    expect(spy).toHaveBeenCalledTimes(1);
    expect(entries()).toBe(500);
    spy.mockRestore();
  });

  /**
   * @scenario progress_log_volume=small
   *
   * @effects progress_log_is_rendered_in_batches_not_per_line
   */
  it('복원 경로도 커맨드 원문을 같은 규칙으로 분류한다', () => {
    region.displayLogs([{ message: '준비합니다' }, { message: '[cmd] migrating table' }]);

    expect(entries()).toBe(1);
    expect(cmdLines()).toBe(1);
  });

  /**
   * @scenario progress_log_volume=small
   *
   * @effects progress_log_has_a_screen_cap_and_says_so
   */
  it('로그가 없으면 플레이스홀더를 유지한다 (회귀 축)', () => {
    region.displayLogs([]);

    expect(logDiv().querySelector('.log-placeholder'), '빈 검은 상자로 남기지 않는다').not.toBeNull();
  });
});
