/**
 * 링크 클릭 추적 — 내려받기·외부 링크 분류와 중복 제거.
 *
 * 분류가 틀리면 사이트 안 이동이 「외부 링크」 목록을 채우거나, 내려받기가 아무 데도
 * 안 잡힌다. 중복 제거가 없으면 더블클릭 한 번이 두 건으로 세어진다.
 *
 * @scenario source=beacon
 * @effects link_clicks_are_classified_into_download_and_outlink,
 *   same_site_links_are_not_counted_as_outlinks, repeated_clicks_on_one_link_count_once
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { startClickTracking } from '../clickTracking';
import type { AnalyticsTracker } from '../Tracker';

/** 추적 호출만 기록하는 대역 */
function createTrackerStub() {
  return {
    trackDownload: vi.fn(),
    trackOutlink: vi.fn(),
  } as unknown as AnalyticsTracker & {
    trackDownload: ReturnType<typeof vi.fn>;
    trackOutlink: ReturnType<typeof vi.fn>;
  };
}

/** 링크를 만들어 붙이고 클릭한다 */
function clickLink(href: string, attributes: Record<string, string> = {}): void {
  const anchor = document.createElement('a');
  anchor.setAttribute('href', href);

  for (const [name, value] of Object.entries(attributes)) {
    anchor.setAttribute(name, value);
  }

  document.body.appendChild(anchor);
  anchor.dispatchEvent(new MouseEvent('click', { bubbles: true }));
}

describe('startClickTracking', () => {
  let tracker: ReturnType<typeof createTrackerStub>;
  let stop: () => void;

  beforeEach(() => {
    document.body.innerHTML = '';
    tracker = createTrackerStub();
    stop = startClickTracking(tracker, ['pdf', 'zip']);
  });

  afterEach(() => {
    stop();
    vi.useRealTimers();
  });

  // @scenario source=beacon
  // @effects link_clicks_are_classified_into_download_and_outlink
  it('설정한 확장자는 내려받기로 본다', () => {
    clickLink(`${window.location.origin}/files/report.pdf`);

    expect(tracker.trackDownload).toHaveBeenCalledTimes(1);
    expect(tracker.trackOutlink).not.toHaveBeenCalled();
  });

  // @scenario source=beacon
  // @effects link_clicks_are_classified_into_download_and_outlink
  it('목록에 없는 확장자는 내려받기가 아니다', () => {
    clickLink(`${window.location.origin}/page.html`);

    expect(tracker.trackDownload).not.toHaveBeenCalled();
  });

  // @scenario source=beacon
  // @effects link_clicks_are_classified_into_download_and_outlink
  it('download 속성이 있으면 확장자와 무관하게 내려받기다', () => {
    clickLink(`${window.location.origin}/generated`, { download: 'report.csv' });

    expect(tracker.trackDownload).toHaveBeenCalledTimes(1);
  });

  // @scenario source=beacon
  // @effects link_clicks_are_classified_into_download_and_outlink
  it('첨부 경로는 확장자가 없어도 내려받기다', () => {
    clickLink(`${window.location.origin}/api/attachment/abc123`);

    expect(tracker.trackDownload).toHaveBeenCalledTimes(1);
  });

  // @scenario source=beacon
  // @effects link_clicks_are_classified_into_download_and_outlink
  it('다른 호스트로 가는 링크는 외부 링크다', () => {
    clickLink('https://other.test/page');

    expect(tracker.trackOutlink).toHaveBeenCalledTimes(1);
  });

  // @scenario source=beacon
  // @effects same_site_links_are_not_counted_as_outlinks
  it('같은 사이트 이동은 아무것도 세지 않는다', () => {
    clickLink(`${window.location.origin}/board/free`);

    expect(tracker.trackOutlink).not.toHaveBeenCalled();
    expect(tracker.trackDownload).not.toHaveBeenCalled();
  });

  // @scenario source=beacon
  // @effects same_site_links_are_not_counted_as_outlinks
  it('앵커·자바스크립트 링크는 무시한다', () => {
    clickLink('#section');
    clickLink('javascript:void(0)');

    expect(tracker.trackOutlink).not.toHaveBeenCalled();
    expect(tracker.trackDownload).not.toHaveBeenCalled();
  });

  // @scenario source=beacon
  // @effects repeated_clicks_on_one_link_count_once
  it('같은 링크를 연달아 눌러도 한 번만 센다', () => {
    clickLink('https://other.test/page');
    clickLink('https://other.test/page');

    expect(tracker.trackOutlink).toHaveBeenCalledTimes(1);
  });

  // @scenario source=beacon
  // @effects repeated_clicks_on_one_link_count_once
  it('중복 판정 시간이 지나면 다시 센다', () => {
    vi.useFakeTimers();

    clickLink('https://other.test/page');
    vi.advanceTimersByTime(2000);
    clickLink('https://other.test/page');

    expect(tracker.trackOutlink).toHaveBeenCalledTimes(2);
  });

  // @scenario source=beacon
  // @effects repeated_clicks_on_one_link_count_once
  it('중복 판정 창은 정확히 1초다 — 999ms 뒤는 같은 클릭, 1초가 되면 새 클릭', () => {
    // 같은 틱·2초 뒤만 재면 창이 0.5초나 1.5초로 바뀌어도 초록이다. 트래커의 같은 화면 되풀이
    // 이동 창(1초)과 같은 값이어야 링크 클릭과 화면 이동이 같은 기준으로 한 번이 된다.
    vi.useFakeTimers();

    clickLink('https://other.test/page');
    vi.advanceTimersByTime(999);
    clickLink('https://other.test/page');
    expect(tracker.trackOutlink, '999ms 뒤').toHaveBeenCalledTimes(1);

    // 억제된 클릭은 기준 시각을 옮기지 않는다 — 첫 클릭에서 1초가 되는 순간 다시 센다.
    vi.advanceTimersByTime(1);
    clickLink('https://other.test/page');
    expect(tracker.trackOutlink, '정확히 1초 뒤').toHaveBeenCalledTimes(2);
  });

  // @scenario source=beacon
  // @effects same_site_links_are_not_counted_as_outlinks
  it('추적을 멈추면 더 이상 세지 않는다', () => {
    stop();
    clickLink('https://other.test/page');

    expect(tracker.trackOutlink).not.toHaveBeenCalled();
  });
});
