/**
 * @file export-cleanup.ts
 * @description 내보내기 E2E 가 만든 작업·파일을 사양이 끝날 때 지운다(#787)
 *
 * `playwright:cleanup` 은 시험 계정·역할만 회수하고 그 계정이 만든 내보내기 작업과 파일은 남긴다. 큐가 계정 삭제 뒤에
 * 돌면 「요청한 운영자 계정을 찾을 수 없어」 실패 작업도 쌓이고, 개인정보가 담긴 파일이 개발 사이트에 남는다.
 *
 * 이 파일이 내보내는 `issueToken`·`issueScopedToken` 은 코어 발급기를 그대로 부르되 발급한 토큰을 기억한다.
 * `purgeTrackedExports()` 는 그 토큰 주인이 요청한 작업만 서비스 삭제 경로(파일 삭제 → 소프트 삭제)로 지운 뒤
 * 다운로드 기록과 행까지 물리 삭제한다 — 소프트 삭제만 하면 시험 행이 회차마다 쌓인다. 사양이 화면에서 이미 지운
 * (소프트 삭제된) 작업도 같이 거둔다. 다른 세션이 같은 사이트에서 돌리는 E2E 의 작업은 건드리지 않는다.
 * 진행중 작업은 끝날 때까지(최대 90초) 기다린다.
 */

import { execFileSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  authenticatePage,
  issueToken as issueCoreToken,
  issueScopedToken as issueCoreScopedToken,
} from '../../../../../../tests/Playwright/fixtures/auth';

export { authenticatePage };

const CORE_ROOT = process.env.G7_ROOT || resolve(dirname(fileURLToPath(import.meta.url)), '../../../../../../');

/** 이 작업자(worker)가 발급한 토큰 */
const issuedTokens = new Set<string>();

/**
 * 코어 발급기로 토큰을 발급하고 정리 대상으로 기억한다(사이트 관리자 역할 포함).
 *
 * @param permissions 권한 식별자
 * @returns 발급된 토큰
 */
export function issueToken(...permissions: string[]): string {
  const token = issueCoreToken(...permissions);
  issuedTokens.add(token);
  return token;
}

/**
 * 지정 권한만 가진 토큰을 발급하고 정리 대상으로 기억한다.
 *
 * @param permissions 권한 식별자
 * @returns 발급된 토큰
 */
export function issueScopedToken(...permissions: string[]): string {
  const token = issueCoreScopedToken(...permissions);
  issuedTokens.add(token);
  return token;
}

/** 토큰 주인이 요청한 작업을 지우는 tinker 본문 — 토큰은 환경변수로 넘긴다(따옴표 이스케이프를 피한다) */
const PURGE_PHP = [
  '$tokens = array_filter(explode(",", (string) getenv("G7_E2E_EXPORT_TOKENS")));',
  '$userIds = collect($tokens)->map(fn ($t) => \\Laravel\\Sanctum\\PersonalAccessToken::findToken($t)?->tokenable_id)->filter()->unique()->values();',
  '$service = app(\\Modules\\Sirsoft\\Ecommerce\\Services\\ExportService::class);',
  '$deadline = time() + 90; $deleted = 0;',
  'do {',
  '  $waiting = 0;',
  '  foreach (\\Modules\\Sirsoft\\Ecommerce\\Models\\Export::withTrashed()->whereIn("requested_by", $userIds)->get() as $export) {',
  '    if (! $export->trashed()) {',
  '      if (! $export->status->isDeletable()) { $waiting++; continue; }',
  '      $service->delete($export, \\App\\Models\\User::findOrFail($export->requested_by));',
  '    }',
  '    $export->downloads()->delete(); $export->forceDelete(); $deleted++;',
  '  }',
  '  if ($waiting > 0) { sleep(3); }',
  '} while ($waiting > 0 && time() < $deadline);',
  'echo "purged={$deleted} waiting={$waiting}";',
].join(' ');

/**
 * 이 작업자가 발급한 토큰의 주인이 요청한 내보내기 작업과 파일을 지운다. `test.afterAll` 에서 부른다.
 *
 * @returns tinker 출력(지운 건수·남은 진행중 건수)
 */
export function purgeTrackedExports(): string {
  if (issuedTokens.size === 0) {
    return 'purged=0 waiting=0';
  }
  const tokens = [...issuedTokens].join(',');
  issuedTokens.clear();

  return execFileSync('php', ['artisan', 'tinker', `--execute=${PURGE_PHP}`], {
    cwd: CORE_ROOT,
    env: { ...process.env, G7_E2E_EXPORT_TOKENS: tokens },
    stdio: 'pipe',
    timeout: 150_000,
  }).toString().trim();
}
