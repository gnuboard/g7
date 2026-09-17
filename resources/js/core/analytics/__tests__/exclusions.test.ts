/**
 * 브라우저 쪽 선제 제외 — 판정식이 서버와 같은 규칙인지.
 *
 * 브라우저와 서버의 판정이 어긋나면 브라우저는 보냈는데 서버가 버리거나(비콘이 헛돔)
 * 브라우저가 막았는데 서버는 셀 생각이었던(방문이 조용히 빠짐) 상태가 된다. 두 경우
 * 모두 화면에는 아무 증상이 없다.
 *
 * @scenario exclusion=disabled|dnt|opt_out|url_pattern|none
 * @effects tracker_honours_do_not_track_and_opt_out, client_and_server_exclusion_rules_agree,
 *   tracker_skips_excluded_requests_before_sending
 */

import { beforeEach, describe, expect, it, vi } from 'vitest';

import { hasOptOutCookie, isPrerendering, pathMatchesAny, shouldSkip } from '../exclusions';

describe('isPrerendering', () => {
  // @scenario exclusion=prefetch
  // @effects tracker_skips_excluded_requests_before_sending, client_and_server_exclusion_rules_agree
  it('미리 그리는 중이면 보내지 않고, 토글을 끄면 보낸다', () => {
    const doc = document as Document & { prerendering?: boolean };

    doc.prerendering = true;

    expect(isPrerendering()).toBe(true);
    expect(shouldSkip('/board', { enabled: true })).toBe('prefetch');
    expect(shouldSkip('/board', { enabled: true, exclude_prefetch: false })).toBeNull();

    doc.prerendering = false;

    expect(isPrerendering()).toBe(false);
    expect(shouldSkip('/board', { enabled: true })).toBeNull();
  });
});

/**
 * jsdom 의 navigator 에는 doNotTrack 이 없어 spyOn 이 통하지 않는다.
 * 속성을 직접 정의해 브라우저의 추적 거부 상태를 재현한다.
 *
 * @param value 추적 거부 값 ('1' 이면 거부)
 */
function setDoNotTrack(value: string | null): void {
  Object.defineProperty(navigator, 'doNotTrack', {
    value,
    configurable: true,
    writable: true,
  });
}

describe('pathMatchesAny', () => {
  // @scenario exclusion=url_pattern
  // @effects client_and_server_exclusion_rules_agree
  it('`*` 로 끝나면 앞부분 일치, 아니면 정확히 같아야 한다', () => {
    expect(pathMatchesAny('/admin/users', ['/admin*'])).toBe(true);
    expect(pathMatchesAny('/administrator', ['/admin*'])).toBe(true);
    expect(pathMatchesAny('/health', ['/health'])).toBe(true);
    expect(pathMatchesAny('/health/live', ['/health'])).toBe(false);
    expect(pathMatchesAny('/board/free', ['/admin*', '/health'])).toBe(false);
  });

  // @scenario exclusion=none
  // @effects client_and_server_exclusion_rules_agree
  it('목록이 비어 있으면 아무것도 걸리지 않는다', () => {
    expect(pathMatchesAny('/admin/users', [])).toBe(false);
    expect(pathMatchesAny('/admin/users', undefined)).toBe(false);
  });
});

describe('hasOptOutCookie', () => {
  beforeEach(() => {
    Object.defineProperty(document, 'cookie', { value: '', writable: true, configurable: true });
  });

  // @scenario exclusion=opt_out
  // @effects tracker_honours_do_not_track_and_opt_out
  it('제외 쿠키가 있으면 참이다', () => {
    document.cookie = 'other=1; g7_analytics_optout=1; more=2';
    expect(hasOptOutCookie()).toBe(true);
  });

  // @scenario exclusion=none
  // @effects tracker_honours_do_not_track_and_opt_out
  it('값이 1 이 아니면 제외가 아니다', () => {
    document.cookie = 'g7_analytics_optout=0';
    expect(hasOptOutCookie()).toBe(false);
  });

  // @scenario exclusion=none
  // @effects tracker_honours_do_not_track_and_opt_out
  it('쿠키가 없으면 거짓이다', () => {
    expect(hasOptOutCookie()).toBe(false);
  });
});

describe('shouldSkip', () => {
  beforeEach(() => {
    Object.defineProperty(document, 'cookie', { value: '', writable: true, configurable: true });
    setDoNotTrack(null);
  });

  // @scenario exclusion=disabled
  // @effects tracker_skips_excluded_requests_before_sending
  it('수집이 꺼져 있으면 보내지 않는다', () => {
    expect(shouldSkip('https://example.test/', { enabled: false })).toBe('disabled');
  });

  // @scenario exclusion=dnt
  // @effects tracker_honours_do_not_track_and_opt_out
  it('추적 거부를 켜면 보내지 않는다', () => {
    setDoNotTrack('1');

    expect(shouldSkip('https://example.test/', { respect_dnt: true })).toBe('dnt');
  });

  // @scenario exclusion=none
  // @effects tracker_honours_do_not_track_and_opt_out
  it('추적 거부를 존중하지 않게 설정하면 보낸다', () => {
    setDoNotTrack('1');

    expect(shouldSkip('https://example.test/', { respect_dnt: false })).toBeNull();
  });

  // @scenario exclusion=opt_out
  // @effects tracker_honours_do_not_track_and_opt_out
  it('방문자가 제외를 선택했으면 보내지 않는다', () => {
    document.cookie = 'g7_analytics_optout=1';

    expect(shouldSkip('https://example.test/', {})).toBe('opt_out');
  });

  // @scenario exclusion=url_pattern
  // @effects client_and_server_exclusion_rules_agree
  it('제외 경로는 보내지 않는다', () => {
    const settings = { excluded_url_patterns: ['/admin*'] };

    expect(shouldSkip('https://example.test/admin/users', settings)).toBe('url_pattern');
    expect(shouldSkip('https://example.test/board/free', settings)).toBeNull();
  });

  // @scenario exclusion=none
  // @effects tracker_skips_excluded_requests_before_sending
  it('정상 방문은 막지 않는다', () => {
    expect(shouldSkip('https://example.test/board/free', { enabled: true })).toBeNull();
  });
});
