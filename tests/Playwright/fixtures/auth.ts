/**
 * Playwright 인증 fixture (코어 영역).
 *
 * `php artisan playwright:issue-token` 커맨드를 호출하여 Sanctum 토큰을 발급하고,
 * 브라우저 페이지의 localStorage 에 주입해 G7Core 부트스트랩 시 자동 인증되도록 한다.
 *
 * 환경 가드 (PlaywrightIssueToken 3중 가드 통과 조건):
 *   ① CLI 한정 — `php_sapi_name() === 'cli'` (execSync 호출이므로 자동 충족)
 *   ② G7_PLAYWRIGHT_BYPASS=1 환경변수 — 본 헬퍼가 자동 부착
 *   ③ APP_DEBUG=true 강제 — 커맨드 내부에서 inline override
 *
 * `--env=testing` 옵션은 부착하지 않는다. 이는 호출자가 활성 환경의 DB 에 토큰을 발급할 수 있도록
 * 하기 위함이다 (예: PLAYWRIGHT_BASE_URL 이 production 호스트를 가리키면 production DB 에,
 * testing 호스트를 가리키면 testing DB 에 토큰 발급).
 *
 * 확장(모듈/플러그인/템플릿) 은 본 모듈에서 `issueToken` / `authenticatePage` 헬퍼만 import 하여
 * 자체 fixture 를 구성한다. 권한 식별자는 임의 string 이므로 코어/모듈/플러그인 공통 API.
 */
import { test as base, type Page } from '@playwright/test';
import { execSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

// ESM 환경(package.json "type": "module")에서는 __dirname 이 정의되지 않으므로
// import.meta.url 로 재구성한다.
const __dirname = dirname(fileURLToPath(import.meta.url));

/**
 * `php artisan` 을 실행할 코어 루트를 결정한다.
 *
 * 확장(모듈/플러그인/템플릿) 은 자기 디렉토리의 config 로 Playwright 를 띄우므로 `process.cwd()`
 * 가 확장 디렉토리다 — 그 위치에는 artisan 이 없다. 본 파일은 항상 `<코어루트>/tests/Playwright/fixtures/`
 * 에 있으므로 자기 위치에서 코어 루트를 역산한다 (경로 하드코딩 회피).
 *
 * @returns artisan 이 존재하는 코어 루트 절대경로
 */
function resolveCoreRoot(): string {
  return process.env.G7_ROOT || resolve(__dirname, '../../../');
}

/**
 * 임의 권한 식별자(코어/모듈/플러그인) 로 Sanctum 토큰을 발급한다.
 *
 * @param permissions 권한 식별자 가변 인자 (예: 'core.templates.layouts.edit')
 * @returns 발급된 plainText Sanctum 토큰 (커맨드 stdout 의 마지막 비어있지 않은 줄)
 */
export function issueToken(...permissions: string[]): string {
  return runIssueToken(permissions, false);
}

/**
 * **지정한 권한만** 가진 Sanctum 토큰을 발급한다 (admin 역할 미부여).
 *
 * `issueToken` 은 커맨드 기본 동작대로 사이트의 `admin` 역할을 함께 부여한다. admin 역할은
 * 전체 권한을 보유하므로(실측 263건), 권한을 좁혀 넘겨도 화면은 항상 최대 권한으로 렌더된다.
 * 읽기 전용 분기·권한 미보유 분기처럼 **권한 경계 자체를 검증**할 때만 이 함수를 쓴다.
 *
 * @param permissions 권한 식별자 가변 인자 (빈 배열이면 권한 0건 계정)
 * @returns 발급된 plainText Sanctum 토큰
 */
export function issueScopedToken(...permissions: string[]): string {
  return runIssueToken(permissions, true);
}

/**
 * `playwright:issue-token` 을 실행해 토큰 문자열을 얻는다.
 *
 * @param permissions 권한 식별자 목록
 * @param noAdminRole admin 역할 미부여 여부
 * @returns 발급된 plainText Sanctum 토큰 (커맨드 stdout 의 마지막 비어있지 않은 줄)
 */
function runIssueToken(permissions: string[], noAdminRole: boolean): string {
  const args = permissions.map((p) => `--permissions=${p}`).join(' ');
  const scopeFlag = noAdminRole ? ' --no-admin-role' : '';
  const command = `php artisan playwright:issue-token ${args}${scopeFlag}`.trim();
  // 다수 워커가 동시에 PHP 아티즌을 부팅하면 일시적 부팅 경합으로 커맨드가
  // 드물게 실패한다(테스트 정합성 문제 아님 — 토큰 발급 인프라의 병렬 부팅 경합).
  // 짧은 백오프로 최대 3회 재시도해 6 워커 병렬에서도 안정화한다.
  let lastError: unknown;
  for (let attempt = 0; attempt < 3; attempt += 1) {
    try {
      const stdout = execSync(command, {
        cwd: resolveCoreRoot(),
        encoding: 'utf-8',
        env: {
          ...process.env,
          // PlaywrightIssueToken 의 ② 옵트인 가드를 통과시킨다.
          // .env 영구 수정 없이 자식 프로세스에만 부여 — 운영 환경 격리 보존.
          G7_PLAYWRIGHT_BYPASS: '1',
        },
      });
      const lines = stdout.split(/\r?\n/).filter((line) => line.trim().length > 0);
      if (lines.length === 0) {
        throw new Error(`playwright:issue-token 가 빈 응답을 반환했습니다. 출력: ${JSON.stringify(stdout)}`);
      }
      return lines[lines.length - 1].trim();
    } catch (error) {
      lastError = error;
      // 마지막 시도가 아니면 짧게 대기 후 재시도(동기 블로킹 — 경합 해소용).
      // 자식 프로세스 spawn 없이 Atomics.wait 로 메인 스레드만 블로킹한다
      // (sleep 커맨드를 execSync 하면 워커 teardown 이 그 자식에 묶여 hang).
      if (attempt < 2) {
        Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 400);
      }
    }
  }
  throw lastError instanceof Error ? lastError : new Error(String(lastError));
}

/**
 * 브라우저 세션에 Sanctum 토큰을 주입한다.
 *
 * `page.goto` 호출 전에 실행해야 한다. `addInitScript` 는 모든 navigation 마다
 * 동일하게 실행되므로 페이지 새로고침/이동 후에도 인증 상태가 유지된다.
 */
export async function authenticatePage(page: Page, token: string): Promise<void> {
  await page.addInitScript((t) => {
    localStorage.setItem('auth_token', t);
  }, token);
}

/**
 * 코어 권한 토큰 fixture.
 *
 * - editToken     : core.templates.layouts.edit (위지윅 편집 권한)
 * - readOnlyToken : core.templates.read (읽기 전용 — 편집 차단 검증용)
 *
 * 토큰은 spec 별로 lazy 발급되어 격리된 testRole 에 첨부된다 (uniqid 접미사).
 */
type AuthFixtures = {
  editToken: string;
  readOnlyToken: string;
};

export const test = base.extend<AuthFixtures>({
  editToken: async ({}, use) => {
    await use(issueToken('core.templates.layouts.edit'));
  },
  readOnlyToken: async ({}, use) => {
    await use(issueToken('core.templates.read'));
  },
});

export { expect } from '@playwright/test';
