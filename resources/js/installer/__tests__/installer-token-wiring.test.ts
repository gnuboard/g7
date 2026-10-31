/**
 * 상태 변경 3액션의 **토큰 배선** 회귀 테스트 (11차 #D1).
 *
 * 10차 #M10 은 [중단]·[초기화]·[건너뛰고 계속] 을 fail-closed 로 바꾸면서 서버 쪽 게이트를
 * 배선 표로 잠갔다. 그런데 그 확인값을 **실어 보내는 쪽**은 소스 대조조차 없어,
 * `stateMutationHeaders()` 의 헤더 항이나 `rememberStepwiseToken(result.stepwise_token)`
 * 한 줄을 지워도 전 스위트가 초록이었다 — 실제 결과는 stepwise 설치에서 세 버튼이 전부
 * 403 이 되는 것이다(서버는 정상 동작하므로 오류 로그도 남지 않는다).
 *
 * installer.js 는 전역을 노출하지 않는 닫힌 IIFE 라 파일 전체를 평가할 수 없다. 그래서
 * 소스의 `region:stepwise-token` 구간만 잘라 **그대로 실행**한다 — 사본을 검증하는 것이
 * 아니라 프로덕션 소스 그 자체를 돌린다.
 *
 * 커버 축: token_source memory·injected·session·absent / request_channel header
 *
 * @effects installer_state_mutation_requests_carry_the_stepwise_token, installer_token_is_remembered_across_reload, installer_state_mutation_endpoints_are_wired_to_the_token_header
 */
import { readFileSync } from 'fs';
import path from 'path';
import { beforeEach, describe, expect, it } from 'vitest';

const INSTALLER_SOURCE = path.resolve(
  __dirname,
  '../../../../public/install/assets/js/installer.js',
);

/**
 * 원문 그대로의 소스.
 *
 * meta:allow raw-source-read — 아래 loadTokenHelpers() 가 잘라 쓰는 구간 표식
 * (`region:stepwise-token`)이 **주석 안에** 있다. 주석을 지우면 그 표식이 사라져 구간을 찾을
 * 수 없다. 문자 대조 판정은 전부 strippedSource() 를 쓴다.
 */
function source(): string {
  return readFileSync(INSTALLER_SOURCE, 'utf8');
}

/** 주석을 지운 소스 — 문자 대조 판정은 전부 이쪽을 쓴다. */
function strippedSource(): string {
  return withoutComments(source());
}

/**
 * JS 주석을 지운 소스. 문자열·템플릿·정규식 리터럴 안은 보존한다.
 *
 * 원문 대조는 주석 처리된 호출을 구분하지 못한다 — 호출을 `//` 로 감싸도 글자는 남는다.
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
 * `name(` 의 **호출** 위치 목록 — 정의(`function name(`)와 주석은 제외한다.
 */
function callSitesOf(src: string, name: string): number[] {
  const scannable = withoutComments(src);
  const sites: number[] = [];
  let cursor = 0;

  for (;;) {
    const at = scannable.indexOf(name + '(', cursor);
    if (at === -1) break;
    cursor = at + 1;
    if (/function\s+$/.test(scannable.slice(Math.max(0, at - 12), at))) continue;
    sites.push(at);
  }

  return sites;
}

/** `region:stepwise-token` ~ `endregion:stepwise-token` 사이를 잘라 평가한다. */
function loadTokenHelpers() {
  const src = source();
  const openMarker = src.indexOf('region:stepwise-token');
  const closeMarker = src.indexOf('endregion:stepwise-token');

  expect(openMarker, '토큰 구간 시작 표식이 없다 — 표식을 지우면 이 축이 통째로 사각이 된다').toBeGreaterThan(-1);
  expect(closeMarker, '토큰 구간 종료 표식이 없다').toBeGreaterThan(openMarker);

  // 두 표식은 주석 안에 있다 — 여는 주석이 **끝난 뒤**부터, 닫는 주석이 **시작하기 전**까지가
  // 실제 코드 구간이다. 표식 위치로 그냥 자르면 주석이 반쪽만 남아 파싱이 깨진다.
  const start = src.indexOf('*/', openMarker) + 2;
  const end = src.lastIndexOf('/*', closeMarker);

  const region = src.slice(start, end);

  // `stateMutationHeaders()` 는 상태변경 헤더의 공통 부분(Content-Type · 세션 CSRF)을
  // 구간 밖의 `stateChangeHeaders()` 에서 펼쳐 담는다(설치 소유 게이트 합류). 사본을 쓰지 않고
  // 그 함수의 **프로덕션 소스**를 잘라 함께 싣는다 — 헤더를 만드는 지점이 여전히 하나다.
  const commonAt = src.indexOf('function stateChangeHeaders(');
  expect(commonAt, 'stateChangeHeaders() 정의를 찾지 못했다 — 공통 헤더 지점이 옮겨졌으면 이 적재도 함께 옮긴다').toBeGreaterThan(-1);
  const commonEnd = src.indexOf('\n    }\n', commonAt);
  expect(commonEnd, 'stateChangeHeaders() 본문 끝을 찾지 못했다').toBeGreaterThan(commonAt);
  const common = src.slice(commonAt, commonEnd + '\n    }\n'.length);

  // 구간에는 `stepwiseToken` 선언이 없다(IIFE 상단에 있다) — 실행을 위해 그것만 덧댄다.
  const factory = new Function(
    `"use strict";
     let stepwiseToken = null;
     ${common}
     ${region}
     return { getStepwiseToken, rememberStepwiseToken, stateMutationHeaders };`,
  );

  return factory() as {
    getStepwiseToken: () => string | null;
    rememberStepwiseToken: (token: string | null) => void;
    stateMutationHeaders: () => Record<string, string>;
  };
}

describe('상태 변경 요청의 토큰 배선', () => {
  beforeEach(() => {
    delete (window as any).INSTALLER_STEPWISE_TOKEN;
    try {
      sessionStorage.clear();
    } catch (e) {
      /* 저장소가 없는 환경은 폴백 경로가 덮는다 */
    }
  });

  it('헤더에 토큰을 실어 보낸다 — 이 항이 빠지면 세 버튼이 전부 403 이 된다', () => {
    const helpers = loadTokenHelpers();
    helpers.rememberStepwiseToken('tok-abc');

    const headers = helpers.stateMutationHeaders();

    expect(headers['X-Installer-Token']).toBe('tok-abc');
    expect(headers['Content-Type']).toBe('application/json');
  });

  it('세션 CSRF 토큰도 같은 헤더에 실린다 — 빠지면 소유 세션의 상태변경이 403 이 된다', () => {
    (window as any).INSTALLER_CSRF = 'csrf-xyz';
    try {
      const helpers = loadTokenHelpers();
      helpers.rememberStepwiseToken('tok-abc');

      const headers = helpers.stateMutationHeaders();

      expect(headers['X-CSRF-Token']).toBe('csrf-xyz');
      expect(headers['X-Installer-Token'], 'CSRF 를 펼친 뒤에도 단계별 토큰 항이 남아야 한다').toBe('tok-abc');
    } finally {
      delete (window as any).INSTALLER_CSRF;
    }
  });

  it('토큰이 없는 진행 모드(SSE·폴링)에서는 빈 값을 실어 정상 경로를 막지 않는다', () => {
    const helpers = loadTokenHelpers();

    expect(helpers.stateMutationHeaders()['X-Installer-Token']).toBe('');
  });

  it('메모리 > 페이지 주입 > sessionStorage 순으로 찾는다 (새로고침 재개 경로)', () => {
    const helpers = loadTokenHelpers();

    sessionStorage.setItem('g7_stepwise_token', 'from-session');
    expect(helpers.getStepwiseToken()).toBe('from-session');

    (window as any).INSTALLER_STEPWISE_TOKEN = 'from-page';
    expect(helpers.getStepwiseToken(), '페이지 주입값이 sessionStorage 보다 앞선다').toBe('from-page');

    helpers.rememberStepwiseToken('from-memory');
    expect(helpers.getStepwiseToken(), '메모리 값이 가장 앞선다').toBe('from-memory');
  });

  it('토큰을 지우면 sessionStorage 에서도 사라진다 (설치 종료 후 잔존 금지)', () => {
    const helpers = loadTokenHelpers();

    helpers.rememberStepwiseToken('tok-abc');
    expect(sessionStorage.getItem('g7_stepwise_token')).toBe('tok-abc');

    helpers.rememberStepwiseToken(null);
    expect(sessionStorage.getItem('g7_stepwise_token')).toBeNull();
  });
});

describe('상태 변경 엔드포인트의 헤더 배선', () => {
  /**
   * 헤더를 만드는 함수가 옳아도 **부르지 않으면** 소용이 없다. 세 액션의 fetch 가
   * 그 함수를 지나는지 고정한다 — 여기가 10차 fail-closed 의 나머지 절반이다.
   */
  it('중단·초기화·건너뛰기·재개가 모두 stateMutationHeaders() 를 지난다', () => {
    const src = strippedSource();
    const wired = (src.match(/headers:\s*stateMutationHeaders\(\)/g) ?? []).length;

    expect(wired, '상태를 바꾸는 요청은 전부 이 한 지점에서 헤더를 만든다').toBeGreaterThanOrEqual(4);
  });

  it('발급받은 토큰을 기억한다 — 이 한 줄이 빠지면 다음 요청부터 403 이다', () => {
    // **정의를 뺀 나머지**에서 센다. 종전에는 `toContain('rememberStepwiseToken(')` 하나였는데,
    // 그 조건은 정의(`function rememberStepwiseToken(token)`)만으로 충족된다 — 실제 호출
    // (`rememberStepwiseToken(result.stepwise_token || null)`) 을 지워도 초록이었다 (12차 GI-1).
    // 그 상태의 결과는 발급 토큰이 어디에도 남지 않아 다음 상태 변경 요청부터 전부 403 이 되는 것이다.
    const callSites = callSitesOf(strippedSource(), 'rememberStepwiseToken');

    expect(
      callSites.length,
      '정의는 배선이 아니다 — 발급받은 토큰을 실제로 기억하는 호출이 있어야 한다',
    ).toBeGreaterThanOrEqual(1);
  });

  it('그 호출은 설치 시작 응답 처리 안에 있다 — 다른 곳으로 옮기면 재개 경로가 끊긴다', () => {
    const src = strippedSource();
    const start = src.indexOf('async function startInstallation');
    expect(start, 'startInstallation 을 찾지 못했다 — 앵커 이름이 바뀌었다').toBeGreaterThan(-1);

    const next = src.indexOf('\n    function ', start + 1);
    const body = src.slice(start, next === -1 ? undefined : next);

    expect(callSitesOf(body, 'rememberStepwiseToken').length).toBeGreaterThanOrEqual(1);
    expect(body).toContain('result.stepwise_token');
  });

  it('검출기 자신이 정의와 호출을 구분한다 (부정 픽스처)', () => {
    const definitionOnly = 'function rememberStepwiseToken(token) { stepwiseToken = token; }';
    const commentedCall = '// rememberStepwiseToken(result.stepwise_token);';
    const realCall = 'rememberStepwiseToken(result.stepwise_token || null);';

    expect(callSitesOf(definitionOnly, 'rememberStepwiseToken')).toHaveLength(0);
    expect(callSitesOf(commentedCall, 'rememberStepwiseToken')).toHaveLength(0);
    expect(callSitesOf(definitionOnly + '\n' + realCall, 'rememberStepwiseToken')).toHaveLength(1);
  });
});
