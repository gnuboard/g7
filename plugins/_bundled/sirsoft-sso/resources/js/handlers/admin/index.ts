// e2e:allow Playwright 스펙 없음(이 플러그인 tests/Playwright/specs/sso-*.spec.ts 는 로그인 흐름만 덮는다) — 브라우저 실측은 F1 Chrome MCP 계획서 §9.6·§10 M4~M6·M10~M11(W40) — P4 는 Vitest 로 덮음
/**
 * SSO 연동 — 관리자 화면 영역 핸들러. `resources/js/handlers/index.ts` 가 합친다.
 *
 * 레이아웃에서는 `sirsoft-sso.{키}` 로 부른다(등록할 때 플러그인 식별자가 앞에 붙는다).
 * - copyToClipboard: 「운영자가 IdP 콘솔에 넣을 값」, SCIM 주소·토큰 복사
 * - readCsvFile: 「CSV 가져오기」 파일을 글자로 읽어 화면 상태(`_local.csvText`)에 넣는다 — 서버에는 글자로 보낸다
 * - downloadCsvResult: 가져오기 결과 행을 CSV 파일로 내려받는다
 */

/** 코어 전역에서 쓰는 것 */
interface CoreGlobal {
  dispatch?: (action: unknown) => unknown;
  t?: (key: string) => string;
  state?: { setLocal?: (updates: Record<string, unknown>) => void };
}

/** 핸들러가 받는 액션 */
export interface AdminAction {
  params?: { text?: unknown; successMessage?: unknown; file?: unknown; target?: unknown; rows?: unknown; filename?: unknown };
}

/** 핸들러가 받는 문맥 (엔진이 넘긴다) */
export interface AdminContext {
  event?: Event;
  setState?: (updates: Record<string, unknown>) => void;
}

/** CSV 결과 열 (서버 `CsvImportService` 판정 행과 같은 순서) */
export const RESULT_COLUMNS = ['line', 'email', 'name', 'idp_key', 'subject', 'outcome', 'reason'] as const;

/** 읽을 CSV 파일 크기 상한 (서버 요청 검증 1 MB 와 같다) */
export const MAX_CSV_BYTES = 1024 * 1024;

/**
 * 코어 전역을 꺼냅니다.
 *
 * @returns 코어 전역 (없으면 빈 객체)
 */
function core(): CoreGlobal {
  return ((window as unknown as { G7Core?: CoreGlobal }).G7Core ?? {}) as CoreGlobal;
}

/**
 * 토스트를 띄웁니다.
 *
 * @param type - 종류 (success, error)
 * @param key - 문구 키
 */
function toast(type: 'success' | 'error', key: string): void {
  void core().dispatch?.({ handler: 'toast', params: { type, message: core().t?.(key) ?? key } });
}

/**
 * 화면 상태를 바꿉니다 — 확장의 정식 경로(`G7Core.state.setLocal`)를 먼저 쓰고, 없으면 엔진이 넘긴 setState.
 *
 * @param updates - 바꿀 값
 * @param context - 엔진 문맥
 */
function setLocal(updates: Record<string, unknown>, context?: AdminContext): void {
  const setter = core().state?.setLocal;
  if (typeof setter === 'function') {
    setter(updates);

    return;
  }

  context?.setState?.(updates);
}

/**
 * 문자열을 클립보드에 씁니다 — 비보안 출처(http 개발 사이트)에서는 텍스트 선택 복사로 물러섭니다.
 *
 * @param text - 복사할 문자열
 * @returns 복사했으면 true
 */
async function writeClipboard(text: string): Promise<boolean> {
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text);

      return true;
    }
  } catch {
    // 권한 거부 등 — 아래 방식으로 다시 시도한다.
  }

  const area = document.createElement('textarea');
  area.value = text;
  area.setAttribute('readonly', '');
  area.style.position = 'fixed';
  area.style.opacity = '0';
  document.body.appendChild(area);
  area.select();

  try {
    return document.execCommand('copy');
  } catch {
    return false;
  } finally {
    document.body.removeChild(area);
  }
}

/**
 * 값을 복사합니다.
 *
 * @param action - `{ params: { text, successMessage? } }`
 * @returns 복사했으면 true
 */
export async function copyToClipboardHandler(action: AdminAction): Promise<boolean> {
  const text = action?.params?.text;
  if (typeof text !== 'string' || text === '') {
    return false;
  }

  const copied = await writeClipboard(text);
  const success = typeof action?.params?.successMessage === 'string' ? action.params.successMessage : 'sirsoft-sso.admin.common.copied';
  toast(copied ? 'success' : 'error', copied ? success : 'sirsoft-sso.admin.common.copy_failed');

  return copied;
}

/**
 * 파일 내용을 UTF-8 글자로 읽습니다 — `Blob.text()` 가 없는 브라우저는 FileReader 로 읽는다.
 *
 * @param file - 파일
 * @returns 글자
 */
function readBlobText(file: Blob): Promise<string> {
  if (typeof (file as Blob & { text?: unknown }).text === 'function') {
    return file.text();
  }

  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(typeof reader.result === 'string' ? reader.result : '');
    reader.onerror = () => reject(reader.error);
    reader.readAsText(file, 'utf-8');
  });
}

/**
 * 선택한 CSV 파일을 글자로 읽어 화면 상태에 넣습니다.
 *
 * @param action - `{ params: { file?, target? } }` — file 이 없으면 이벤트의 입력 칸에서 고른 첫 파일
 * @param context - 엔진 문맥 (change 이벤트)
 * @returns 읽은 글자 수 (실패하면 -1)
 */
export async function readCsvFileHandler(action: AdminAction, context?: AdminContext): Promise<number> {
  const fromParams = action?.params?.file;
  const input = context?.event?.target as HTMLInputElement | undefined;
  const file = (fromParams instanceof Blob ? fromParams : input?.files?.[0]) as Blob | undefined;
  const target = typeof action?.params?.target === 'string' && action.params.target !== '' ? action.params.target : 'csvText';

  if (!file) {
    return -1;
  }

  if (file.size > MAX_CSV_BYTES) {
    toast('error', 'sirsoft-sso.admin.accounts.csv.too_large');

    return -1;
  }

  try {
    const text = (await readBlobText(file)).replace(/^﻿/, '');
    setLocal({ [target]: text, csvPreview: null, csvResult: null }, context);

    return text.length;
  } catch {
    toast('error', 'sirsoft-sso.admin.accounts.csv.read_failed');

    return -1;
  }
}

/** 따옴표로 감싸야 하는 CSV 칸 — 쉼표, 따옴표, 줄바꿈 */
const NEEDS_CSV_QUOTE = /[",\r\n]/;

/**
 * CSV 한 칸을 따옴표로 감쌉니다 (쉼표, 따옴표, 줄바꿈이 있으면).
 *
 * @param value - 값
 * @returns CSV 칸
 */
export function csvCell(value: unknown): string {
  const text = value === null || value === undefined ? '' : String(value);
  const guarded = /^[=+\-@]/.test(text) ? `'${text}` : text;

  return NEEDS_CSV_QUOTE.test(guarded) ? `"${guarded.replace(/"/g, '""')}"` : guarded;
}

/**
 * 결과 행을 CSV 글자로 만듭니다.
 *
 * @param rows - 결과 행
 * @returns CSV (머리 행 포함)
 */
export function buildResultCsv(rows: Array<Record<string, unknown>>): string {
  const lines = [RESULT_COLUMNS.join(',')];
  for (const row of rows) {
    lines.push(RESULT_COLUMNS.map((column) => csvCell(row?.[column])).join(','));
  }

  return lines.join('\r\n') + '\r\n';
}

/**
 * 가져오기 결과를 CSV 파일로 내려받습니다.
 *
 * @param action - `{ params: { rows, filename? } }`
 * @returns 내려받기를 시작했으면 true
 */
export function downloadCsvResultHandler(action: AdminAction): boolean {
  const rows = action?.params?.rows;
  if (!Array.isArray(rows) || rows.length === 0) {
    return false;
  }

  const filename = typeof action?.params?.filename === 'string' && action.params.filename !== '' ? action.params.filename : 'sso-import-result.csv';
  const blob = new Blob(['﻿' + buildResultCsv(rows as Array<Record<string, unknown>>)], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);

  return true;
}

/** 관리자 화면 영역 핸들러 */
export const adminHandlers: Record<string, (...args: never[]) => unknown> = {
  copyToClipboard: copyToClipboardHandler,
  readCsvFile: readCsvFileHandler,
  downloadCsvResult: downloadCsvResultHandler,
};
