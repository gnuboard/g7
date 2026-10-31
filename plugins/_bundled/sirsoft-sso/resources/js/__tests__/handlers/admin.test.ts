// e2e:allow Playwright 스펙 없음(이 플러그인 tests/Playwright/specs/sso-*.spec.ts 는 로그인 흐름만 덮는다) — 브라우저 실측은 F1 Chrome MCP 계획서 §10 M10~M11(W40) — P4 는 Vitest 로 덮음
/**
 * @file admin.test.ts
 * @description 관리자 화면 핸들러 — 복사, CSV 파일 읽기(크기 상한, 화면 상태), 결과 CSV 만들기(따옴표, 수식 주입 방지).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { buildResultCsv, csvCell, copyToClipboardHandler, readCsvFileHandler, MAX_CSV_BYTES } from '../../handlers/admin';

describe('관리자 화면 핸들러', () => {
  let setLocal: ReturnType<typeof vi.fn>;
  let dispatch: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    setLocal = vi.fn();
    dispatch = vi.fn();
    (window as any).G7Core = { state: { setLocal }, dispatch, t: (k: string) => k };
  });

  /**
   * @scenario value=comma|quote|formula|null
   * @effects csv_escaped, formula_guarded
   */
  it('CSV 칸을 따옴표로 감싸고 수식 시작 문자를 막는다', () => {
    expect(csvCell('a,b')).toBe('"a,b"');
    expect(csvCell('say "hi"')).toBe('"say ""hi"""');
    expect(csvCell('=SUM(A1)')).toBe("'=SUM(A1)");
    expect(csvCell(null)).toBe('');
    expect(buildResultCsv([{ line: 2, email: 'a@x.test', outcome: 'create' }]).split('\r\n')[0]).toBe('line,email,name,idp_key,subject,outcome,reason');
  });

  /**
   * @scenario file=small|too_large
   * @effects text_into_local_state, too_large_refused
   */
  it('CSV 파일을 글자로 읽어 _local.csvText 에 넣고, 1MB 넘는 파일은 거절한다', async () => {
    const file = new Blob(['﻿email,name,idp_key,subject\n'], { type: 'text/csv' });
    const length = await readCsvFileHandler({ params: { file } });
    expect(length).toBeGreaterThan(0);
    expect(setLocal).toHaveBeenCalledWith(expect.objectContaining({ csvText: 'email,name,idp_key,subject\n', csvPreview: null }));

    const big = { size: MAX_CSV_BYTES + 1, text: async () => '' } as unknown as Blob;
    Object.setPrototypeOf(big, Blob.prototype);
    expect(await readCsvFileHandler({ params: { file: big } })).toBe(-1);
  });

  /**
   * @scenario text=present|empty
   * @effects copied_toast, empty_ignored
   */
  it('값을 복사하고 성공 토스트를 띄우며, 빈 값은 무시한다', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });

    expect(await copyToClipboardHandler({ params: { text: 'https://sp.example/acs' } })).toBe(true);
    expect(writeText).toHaveBeenCalledWith('https://sp.example/acs');
    expect(dispatch).toHaveBeenCalledWith(expect.objectContaining({ handler: 'toast' }));
    expect(await copyToClipboardHandler({ params: { text: '' } })).toBe(false);
  });
});
