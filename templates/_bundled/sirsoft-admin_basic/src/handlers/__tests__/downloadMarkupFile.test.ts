/**
 * @file downloadMarkupFile.test.ts
 * @description 코드 편집기 HTML 탭 본문을 `.g7.html` 파일로 내려받는 핸들러 단위 검증 (#476 C5)
 *
 * 편집기가 여는 것은 설치본(DB 행)이라 서버에 파일이 없다 — 본문을 Blob 으로 만들어 `<a download>`
 * 로 저장한다. 파일명은 경로 구분자를 `_` 로 접고 확장자는 `.g7.html` 하나만 붙인다.
 *
 * @effects markup_downloaded_as_g7_html_file,filename_normalized,empty_content_skipped
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { downloadMarkupFileHandler, normalizeMarkupFilename } from '../downloadMarkupFile';

describe('downloadMarkupFileHandler — .g7.html 내려받기 (#476)', () => {
  let createObjectURLSpy: ReturnType<typeof vi.fn>;
  let revokeObjectURLSpy: ReturnType<typeof vi.fn>;
  let clickSpy: ReturnType<typeof vi.fn>;
  let lastAnchor: HTMLAnchorElement | null;

  beforeEach(() => {
    (window as any).G7Core = {
      toast: { error: vi.fn() },
      createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
    };

    createObjectURLSpy = vi.fn().mockReturnValue('blob:mock-markup');
    revokeObjectURLSpy = vi.fn();
    (URL as any).createObjectURL = createObjectURLSpy;
    (URL as any).revokeObjectURL = revokeObjectURLSpy;

    lastAnchor = null;
    clickSpy = vi.fn(function (this: HTMLAnchorElement) {
      lastAnchor = this;
    });
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(clickSpy as any);
  });

  afterEach(() => {
    delete (window as any).G7Core;
    vi.restoreAllMocks();
  });

  /**
   * @effects code_editor_downloads_and_copies_the_markup_buffer, markup_downloaded_as_g7_html_file
   */
  it('본문을 text/html Blob 으로 만들어 <a download> 로 저장한다', async () => {
    await downloadMarkupFileHandler({
      params: { filename: 'home.g7.html', content: '<layout name="home" version="1.0.0"><div/></layout>\n' },
    });

    expect(createObjectURLSpy).toHaveBeenCalledTimes(1);
    const blob = createObjectURLSpy.mock.calls[0][0] as Blob;
    expect(blob.type).toBe('text/html;charset=utf-8');
    expect(clickSpy).toHaveBeenCalledTimes(1);
    expect(lastAnchor?.download).toBe('home.g7.html');
    expect(revokeObjectURLSpy).toHaveBeenCalledWith('blob:mock-markup');
  });

  /**
   * @effects filename_normalized
   */
  it('경로가 든 이름은 구분자를 접고 확장자를 한 번만 붙인다', () => {
    expect(normalizeMarkupFilename('board/form.g7.html')).toBe('board_form.g7.html');
    expect(normalizeMarkupFilename('auth/login')).toBe('auth_login.g7.html');
    expect(normalizeMarkupFilename('')).toBe('layout.g7.html');
    expect(normalizeMarkupFilename(undefined)).toBe('layout.g7.html');
  });

  /**
   * @effects empty_content_skipped
   */
  it('본문이 비어 있으면 아무것도 내려받지 않는다', async () => {
    await downloadMarkupFileHandler({ params: { filename: 'x.g7.html', content: '' } });

    expect(createObjectURLSpy).not.toHaveBeenCalled();
    expect(clickSpy).not.toHaveBeenCalled();
  });
});
