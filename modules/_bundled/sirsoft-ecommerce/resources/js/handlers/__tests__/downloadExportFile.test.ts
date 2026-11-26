/**
 * @file downloadExportFile.test.ts
 * @description 내보내기 파일 다운로드 핸들러 — 일회용 주소를 발급받아 브라우저가 직접(스트리밍으로) 받게 한다
 *
 * 같은 모듈의 `downloadFile`(blob 저장)과 받는 방식이 달라 따로 둔다 — 큰 파일을 브라우저 메모리에 올리지 않는다.
 *
 * @scenario-manifest tests/scenarios/export.yaml
 * @vitest-environment jsdom
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { downloadExportFileHandler, RECORD_POLL_ATTEMPTS, RECORD_POLL_INTERVAL_MS } from '../downloadExportFile';
import { handlerMap } from '../index';

describe('sirsoft-ecommerce.downloadExportFile', () => {
  const post = vi.fn();
  const get = vi.fn();
  let clicked: { href: string; download: string }[] = [];

  beforeEach(() => {
    clicked = [];
    (window as any).G7Core = { api: { post, get } };
    post.mockReset();
    get.mockReset();
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
      clicked.push({ href: this.getAttribute('href') ?? '', download: this.download });
    });
  });

  afterEach(() => {
    vi.restoreAllMocks();
    delete (window as any).G7Core;
  });

  /**
   * @scenario action=download
   * @effects download_uses_own_handler_with_auth, download_streams_via_one_time_link
   */
  it('주소 발급 API 를 토큰 동반으로 부르고, 받은 일회용 주소를 브라우저가 직접 열게 한다(파일을 메모리에 올리지 않는다)', async () => {
    vi.useFakeTimers();
    post.mockResolvedValue({ success: true, data: { url: '/api/modules/sirsoft-ecommerce/exports/file/abc', expires_in: 60, download_count: 0 } });
    // 파일 요청이 서버에 늦게 닿는다 — 두 번은 아직 0, 세 번째에 기록이 보인다
    get.mockResolvedValueOnce({ data: { download_count: 0 } }).mockResolvedValueOnce({ data: { download_count: 0 } }).mockResolvedValue({ data: { download_count: 1 } });

    let settled = false;
    const pending = downloadExportFileHandler({ params: { url: '/api/modules/sirsoft-ecommerce/admin/exports/7/download-link', filename: '주문내역.csv' } })
      .then((value) => { settled = true; return value; });

    // 기록이 보이기 전에는 끝나지 않는다 — 그래야 레이아웃의 목록 재조회가 기록 뒤에 도착한다
    await vi.advanceTimersByTimeAsync(RECORD_POLL_INTERVAL_MS * 2);
    expect(clicked.length).toBe(1);
    expect(settled).toBe(false);
    await vi.advanceTimersByTimeAsync(RECORD_POLL_INTERVAL_MS);
    const ok = await pending;
    vi.useRealTimers();

    expect(ok).toBe(true);
    expect(get).toHaveBeenCalledTimes(3);
    expect(get).toHaveBeenCalledWith('/api/modules/sirsoft-ecommerce/admin/exports/7');
    expect(post).toHaveBeenCalledWith('/api/modules/sirsoft-ecommerce/admin/exports/7/download-link', {});
    expect(clicked).toEqual([{ href: '/api/modules/sirsoft-ecommerce/exports/file/abc', download: '주문내역.csv' }]);
    expect(document.querySelectorAll('a[download]').length).toBe(0);
  });

  /**
   * @scenario action=download
   * @effects download_uses_own_handler_with_auth
   */
  it('기록이 끝내 보이지 않아도 정해진 횟수만 확인하고 끝낸다(화면이 멈추지 않는다)', async () => {
    vi.useFakeTimers();
    post.mockResolvedValue({ success: true, data: { url: '/f/abc', download_count: 3 } });
    get.mockResolvedValue({ data: { download_count: 3 } });

    const pending = downloadExportFileHandler({ params: { url: '/api/x/admin/exports/9/download-link', filename: 'x.csv' } });
    await vi.advanceTimersByTimeAsync(RECORD_POLL_INTERVAL_MS * RECORD_POLL_ATTEMPTS);
    expect(await pending).toBe(true);
    vi.useRealTimers();

    expect(get).toHaveBeenCalledTimes(RECORD_POLL_ATTEMPTS);
  });

  /**
   * @scenario action=download
   * @effects download_uses_own_handler_with_auth
   */
  it('주소가 없으면 요청하지 않고, ApiClient 가 없거나 주소를 받지 못하면 실패를 던진다', async () => {
    expect(await downloadExportFileHandler({ params: { url: '' } })).toBe(false);
    expect(post).not.toHaveBeenCalled();

    post.mockResolvedValue({ success: true, data: {} });
    await expect(downloadExportFileHandler({ params: { url: '/x', filename: 'x.csv' } })).rejects.toThrow('Download link was not issued');

    delete (window as any).G7Core;
    await expect(downloadExportFileHandler({ params: { url: '/x' } })).rejects.toThrow('G7Core.api is not available');
  });

  /**
   * @scenario action=download
   * @effects download_uses_own_handler_with_auth
   */
  it('핸들러 맵에 downloadExportFile 로 실리고, blob 저장용 downloadFile 과 같은 함수가 아니다', () => {
    expect(handlerMap.downloadExportFile).toBe(downloadExportFileHandler);
    expect(handlerMap.downloadFile).not.toBe(downloadExportFileHandler);
  });
});
