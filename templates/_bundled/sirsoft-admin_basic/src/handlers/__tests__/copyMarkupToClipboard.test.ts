/**
 * @file copyMarkupToClipboard.test.ts
 * @description 코드 편집기 HTML 탭 본문을 클립보드에 복사하는 핸들러 단위 검증 (#476 C5)
 *
 * 클립보드 API 가 있으면 그것을, 없거나 실패하면 숨긴 textarea + execCommand('copy') 폴백을 쓴다.
 * 성공·실패는 토스트로 알린다(문구는 레이아웃이 params 로 넘긴다).
 *
 * @effects markup_copied_via_clipboard_api,execcommand_fallback_when_api_missing,empty_content_skipped
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { copyMarkupToClipboardHandler } from '../copyMarkupToClipboard';

describe('copyMarkupToClipboardHandler — 마크업 복사 (#476)', () => {
  let toastSuccess: ReturnType<typeof vi.fn>;
  let toastError: ReturnType<typeof vi.fn>;
  const originalClipboard = (navigator as any).clipboard;

  beforeEach(() => {
    toastSuccess = vi.fn();
    toastError = vi.fn();
    (window as any).G7Core = {
      toast: { success: toastSuccess, error: toastError },
      t: (key: string) => key,
      createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
    };
  });

  afterEach(() => {
    delete (window as any).G7Core;
    Object.defineProperty(navigator, 'clipboard', { value: originalClipboard, configurable: true });
    vi.restoreAllMocks();
  });

  /**
   * @effects code_editor_downloads_and_copies_the_markup_buffer, markup_copied_via_clipboard_api
   */
  it('클립보드 API 로 복사하고 성공 토스트를 띄운다', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });

    const copied = await copyMarkupToClipboardHandler({
      params: { content: '<layout name="x" version="1.0.0"><div/></layout>', successMessage: '복사됨' },
    });

    expect(copied).toBe(true);
    expect(writeText).toHaveBeenCalledWith('<layout name="x" version="1.0.0"><div/></layout>');
    expect(toastSuccess).toHaveBeenCalledWith('복사됨');
    expect(toastError).not.toHaveBeenCalled();
  });

  /**
   * @effects execcommand_fallback_when_api_missing
   */
  it('클립보드 API 가 없으면 execCommand 폴백으로 복사한다', async () => {
    Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true });
    const execCommand = vi.fn().mockReturnValue(true);
    (document as any).execCommand = execCommand;

    const copied = await copyMarkupToClipboardHandler({ params: { content: '<layout/>', successMessage: 'ok' } });

    expect(copied).toBe(true);
    expect(execCommand).toHaveBeenCalledWith('copy');
    expect(document.querySelector('textarea')).toBeNull();
    expect(toastSuccess).toHaveBeenCalledWith('ok');
  });

  /**
   * @effects clipboard_failure_reports_false_with_toast
   */
  it('둘 다 실패하면 실패 토스트를 띄우고 false 를 돌려준다', async () => {
    Object.defineProperty(navigator, 'clipboard', {
      value: { writeText: vi.fn().mockRejectedValue(new Error('denied')) },
      configurable: true,
    });
    (document as any).execCommand = vi.fn().mockReturnValue(false);

    const copied = await copyMarkupToClipboardHandler({ params: { content: '<layout/>', failureMessage: '실패' } });

    expect(copied).toBe(false);
    expect(toastError).toHaveBeenCalledWith('실패');
  });

  /**
   * @effects empty_content_skipped
   */
  it('본문이 비어 있으면 아무것도 하지 않는다', async () => {
    const writeText = vi.fn();
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });

    expect(await copyMarkupToClipboardHandler({ params: { content: '' } })).toBe(false);
    expect(writeText).not.toHaveBeenCalled();
    expect(toastSuccess).not.toHaveBeenCalled();
  });
});
