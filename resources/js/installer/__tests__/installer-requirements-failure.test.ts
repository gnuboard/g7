/**
 * 요구사항 검사 실패 사유가 화면까지 도달하는지 고정한다.
 *
 * 서버는 실패를 `message_key`(번역 키) · `message`(서버 로케일 문구) · `details`(예외
 * 원문) 셋으로 갈라 보낸다. 종전 클라이언트는 `response.ok` 를 **본문 읽기보다 먼저**
 * 판정해 그 셋을 통째로 버렸고, 화면에는 일반 문구와 `HTTP 500: ` 만 남았다 — 운영자는
 * 레이트리밋(429)인지 예외(500)인지 알 수 없었다. 형제 소비부(`scan-extensions`)는 같은
 * 사유로 이미 「본문을 먼저 읽는다」로 고쳐져 있었다.
 *
 * 프로덕션 소스의 `region:requirements-failure` 구간을 그대로 잘라 평가한다 — 사본을 두면
 * 사본만 옳은 상태가 생긴다.
 *
 * @scenario failure_kind=rate_limited, failure_kind=unexpected_exception, failure_kind=unparsable_body
 *
 * @effects requirements_failure_reason_reaches_the_screen,
 *          requirements_failure_detail_omits_empty_status_text
 */
import { readFileSync } from 'fs';
import path from 'path';
import { describe, expect, it } from 'vitest';

const INSTALLER_SOURCE = path.resolve(
  __dirname,
  '../../../../public/install/assets/js/installer.js',
);

type Failure = { heading: string; detail: string };

const LANG: Record<string, string> = {
  requirements_check_failed: '요구사항 검증 실패',
  error_probe_rate_limited: '확인 요청이 너무 잦습니다. 잠시 후 다시 시도해주세요.',
  error_unexpected_exception: '예기치 않은 오류가 발생했습니다',
};

/**
 * `region:requirements-failure` 구간을 잘라 그대로 평가한다.
 */
function describeFailure(status: number, payload: unknown): Failure {
  const src = readFileSync(INSTALLER_SOURCE, 'utf8');

  const openMarker = src.indexOf('region:requirements-failure');
  const closeMarker = src.indexOf('endregion:requirements-failure');

  expect(
    openMarker,
    '구간 시작 표식이 없다 — 표식을 지우면 이 축이 통째로 사각이 된다',
  ).toBeGreaterThan(-1);
  expect(closeMarker, '구간 종료 표식이 없다').toBeGreaterThan(openMarker);

  const blockStart = src.indexOf('*/', openMarker) + 2;
  const blockEnd = src.lastIndexOf('/*', closeMarker);
  const block = src.slice(blockStart, blockEnd);

  // 프로덕션 구간이 참조하는 것만 최소로 세운다.
  const lang = (key: string): string => LANG[key] ?? key;
  const translateWithKey = (messageKey: string, fallback = ''): string =>
    messageKey ? lang(messageKey) : fallback;

  // eslint-disable-next-line no-new-func
  const runner = new Function(
    'lang',
    'translateWithKey',
    'status',
    'payload',
    `${block}\nreturn describeRequirementsFailure(status, payload);`,
  );

  return runner(lang, translateWithKey, status, payload) as Failure;
}

describe('요구사항 검사 실패 사유', () => {
  // @scenario failure_kind=rate_limited
  // @effects requirements_failure_reason_reaches_the_screen
  it('레이트리밋(429)의 번역 키가 제목에 실린다', () => {
    const failure = describeFailure(429, {
      success: false,
      message: 'Too many check requests.',
      message_key: 'error_probe_rate_limited',
    });

    expect(failure.heading).toBe(LANG.error_probe_rate_limited);
    expect(failure.heading).not.toBe(LANG.requirements_check_failed);
  });

  // @scenario failure_kind=unexpected_exception
  // @effects requirements_failure_reason_reaches_the_screen
  it('예외(500)의 원문이 진단 상세에 실린다', () => {
    const failure = describeFailure(500, {
      success: false,
      message: '예기치 않은 오류가 발생했습니다',
      message_key: 'error_unexpected_exception',
      details: 'Call to undefined function exec()',
    });

    expect(failure.heading).toBe(LANG.error_unexpected_exception);
    expect(failure.detail).toContain('Call to undefined function exec()');
    expect(failure.detail).toContain('HTTP 500');
  });

  // @scenario failure_kind=unparsable_body
  // @effects requirements_failure_reason_reaches_the_screen
  it('본문을 파싱할 수 없으면 일반 문구로 내려간다', () => {
    const failure = describeFailure(502, null);

    expect(failure.heading).toBe(LANG.requirements_check_failed);
    expect(failure.detail).toBe('HTTP 502');
  });

  // @scenario failure_kind=unexpected_exception
  // @effects requirements_failure_detail_omits_empty_status_text
  it('진단 상세가 빈 사유로 끝나지 않는다 (HTTP/2 의 빈 statusText)', () => {
    // 종전 형태(`HTTP ${status}: ${response.statusText}`)는 HTTP/2 에서 언제나
    // `HTTP 500: ` 로 끝났다 — 콜론 뒤가 비어 있으면 사유가 있는 것처럼 보인다.
    for (const status of [401, 403, 429, 500, 502]) {
      const failure = describeFailure(status, null);

      expect(failure.detail).not.toMatch(/[:—]\s*$/u);
      expect(failure.detail).toBe(`HTTP ${status}`);
    }
  });

  // @scenario failure_kind=unexpected_exception
  // @effects requirements_failure_detail_omits_empty_status_text
  it('소스가 statusText 를 더 이상 참조하지 않는다', () => {
    const src = readFileSync(INSTALLER_SOURCE, 'utf8');
    const openMarker = src.indexOf('async function checkRequirements');

    expect(openMarker).toBeGreaterThan(-1);

    const body = src.slice(openMarker, openMarker + 2500);

    expect(
      body.includes('statusText'),
      'checkRequirements 가 다시 statusText 를 쓴다 — HTTP/2 에서 빈 문자열이라 사유가 사라진다',
    ).toBe(false);
  });

  // @scenario failure_kind=unexpected_exception
  // @effects requirements_recheck_does_not_resubmit_the_post
  it('[다시 검증]은 직전 POST 를 다시 보내지 않고 같은 주소를 GET 으로 다시 연다', () => {
    // 2단계 오류(노출됨 차단, 요구사항 재검사 실패)는 POST 응답 화면에 그려진다. 그 화면에서
    // reload() 를 부르면 브라우저가 POST 를 다시 보내 「양식 다시 제출」 확인 창이 뜨고,
    // 서버는 차단 기록을 한 줄 더 남긴다.
    const src = readFileSync(INSTALLER_SOURCE, 'utf8');
    const open = src.indexOf('function recheckRequirements');

    expect(open, 'recheckRequirements 가 없다').toBeGreaterThan(-1);

    const close = src.indexOf('\n    }', open);
    const body = src.slice(src.indexOf('{', open) + 1, close);
    const calls: string[] = [];
    const location = {
      href: 'https://example.test/install/?step=2#requirements',
      reload: () => calls.push('reload'),
      replace: (url: string) => calls.push(`replace:${url}`),
      assign: (url: string) => calls.push(`assign:${url}`),
    };

    // eslint-disable-next-line no-new-func
    new Function('window', body)({ location });

    expect(calls).toEqual(['replace:https://example.test/install/?step=2']);
  });
});
