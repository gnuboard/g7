/**
 * 방문자 통계 E2E 보조 (이슈 #682 S3)
 *
 * - 설정 가드: 테스트가 바꾼 방문자 통계 설정 키를 원래 값으로 되돌리고, 테스트 전에 없던 설정 파일
 *   (`storage/app/settings/analytics.json`)은 지운다 — 설정 파일이 없는 설치(기본값으로 동작)를
 *   테스트가 파일 있는 설치로 바꿔 놓으면 그 경로를 실측할 길이 사라진다.
 * - artisan·시드 실행: 같은 머신의 코어 루트에서 돈다(토큰 발급 fixture 와 같은 전제).
 */
import { execSync } from 'node:child_process';
import { existsSync, unlinkSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import type { APIRequestContext } from '@playwright/test';

// ESM 환경(package.json "type": "module")에서는 __dirname 이 없으므로 import.meta.url 로 만든다.
const fixtureDir = dirname(fileURLToPath(import.meta.url));

/** 코어 루트 (tests/Playwright/fixtures 기준 세 단계 위) */
export const G7_ROOT = process.env.G7_ROOT || resolve(fixtureDir, '../../../');

/** 방문자 통계 설정 API */
export const ANALYTICS_SETTINGS_API = '/api/admin/analytics/settings';

const SETTINGS_FILE = resolve(G7_ROOT, 'storage/app/settings/analytics.json');

/**
 * 코어 루트에서 artisan 을 실행한다.
 *
 * @param args artisan 인자 문자열 (예: `analytics:archive --period=hour`)
 * @returns 표준 출력
 */
export function runArtisan(args: string): string {
  return execSync(`php artisan ${args}`, { cwd: G7_ROOT, encoding: 'utf-8' });
}

/**
 * 시드 도구(`analytics-seed.php`)를 실행한다.
 *
 * @param mode month | live | privacy-visitor | cleanup-privacy-visitor | cleanup-month
 * @returns 표준 출력
 */
export function runSeed(mode: string): string {
  return execSync(`php tests/Playwright/fixtures/analytics-seed.php ${mode}`, { cwd: G7_ROOT, encoding: 'utf-8' });
}

/**
 * Bearer 헤더를 만든다.
 *
 * @param token Sanctum 토큰
 * @returns 요청 헤더
 */
export function bearer(token: string): Record<string, string> {
  return { Authorization: `Bearer ${token}`, Accept: 'application/json' };
}

/**
 * 방문자 통계 설정 가드 — 바꾼 키만 원래 값으로 되돌린다.
 */
export class AnalyticsSettingsGuard {
  private original: Record<string, unknown> = {};

  private fileExisted = true;

  /**
   * @param request API 요청 컨텍스트 (페이지의 request 를 넘기면 baseURL·인증서 설정을 물려받는다)
   * @param token core.analytics.update 권한 토큰
   */
  constructor(private readonly request: APIRequestContext, private readonly token: string) {}

  /**
   * 현재 값을 기억해 두고 새 값을 저장한다.
   *
   * @param changes 바꿀 설정 키와 값
   */
  async apply(changes: Record<string, unknown>): Promise<void> {
    this.fileExisted = existsSync(SETTINGS_FILE);
    const current = await this.read();
    for (const key of Object.keys(changes)) {
      if (!(key in this.original)) this.original[key] = current[key];
    }
    const res = await this.request.put(ANALYTICS_SETTINGS_API, { headers: bearer(this.token), data: changes });
    if (res.status() !== 200) {
      throw new Error(`설정 저장 실패 ${res.status()}: ${await res.text()}`);
    }
  }

  /**
   * 지금 저장된 설정을 읽는다.
   *
   * @returns 설정 값
   */
  async read(): Promise<Record<string, unknown>> {
    const res = await this.request.get(ANALYTICS_SETTINGS_API, { headers: bearer(this.token) });
    return (await res.json()).data.settings as Record<string, unknown>;
  }

  /**
   * 바꾼 키를 원래 값으로 되돌리고, 테스트 전에 없던 설정 파일은 지운다.
   */
  async restore(): Promise<void> {
    if (Object.keys(this.original).length > 0) {
      await this.request.put(ANALYTICS_SETTINGS_API, { headers: bearer(this.token), data: this.original });
    }
    if (!this.fileExisted && existsSync(SETTINGS_FILE)) {
      unlinkSync(SETTINGS_FILE);
    }
  }
}

/**
 * 오늘 페이지 리포트에서 한 경로의 조회 수를 읽는다 (집계 표 기준).
 *
 * @param request API 요청 컨텍스트
 * @param token core.analytics.read 권한 토큰
 * @param path 화면에 보이는 경로 (예: `/boards`)
 * @returns 그 경로의 조회 수 (행이 없으면 0)
 */
export async function todayPageHits(request: APIRequestContext, token: string, path: string): Promise<number> {
  const res = await request.get(`/api/admin/analytics/reports/page_url?preset=today&q=${encodeURIComponent(path.replace(/^\//, ''))}&per_page=100`, {
    headers: bearer(token),
  });
  const rows = ((await res.json()).data?.data ?? []) as Array<{ label_display?: string; hits?: number }>;
  return rows.filter((r) => r.label_display === path).reduce((sum, r) => sum + (r.hits ?? 0), 0);
}
