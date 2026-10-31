/**
 * 코어 E2E — 정해 둔 관리자 계정 로그인 토큰 fixture.
 *
 * 기본 경로(`issueToken`)는 `playwright_test_*` 계정을 만들어 토큰을 발급한다. 실측 세션처럼 테스트 데이터를
 * 정해 둔 계정으로만 남겨야 할 때는 다음 두 환경변수를 주면 그 계정으로 `POST /api/auth/admin/login` 해 토큰을 받는다.
 *   - G7_E2E_ADMIN_EMAIL          관리자 계정 이메일
 *   - G7_E2E_ADMIN_PASSWORD_FILE  비밀번호를 담은 파일 경로(비밀번호를 명령줄·로그에 남기지 않으려고 파일로만 받는다)
 * 둘 중 하나라도 없으면 `issueToken(...permissions)` 로 돌아간다(저장소 기본 경로).
 *
 * 같은 계약의 템플릿 사본: `templates/_bundled/sirsoft-admin_basic/tests/Playwright/fixtures/admin-login.ts`.
 */
import { readFileSync } from 'node:fs';
import type { APIRequestContext } from '@playwright/test';
import { issueToken } from './auth';

/**
 * 관리자 토큰을 얻는다.
 *
 * @param request Playwright API 요청 컨텍스트(baseURL 사용)
 * @param permissions 발급 경로일 때 넘길 권한 식별자
 * @returns Sanctum 토큰 문자열
 */
export async function adminToken(request: APIRequestContext, ...permissions: string[]): Promise<string> {
  const email = process.env.G7_E2E_ADMIN_EMAIL;
  const passwordFile = process.env.G7_E2E_ADMIN_PASSWORD_FILE;
  if (!email || !passwordFile) {
    return issueToken(...permissions);
  }
  const password = readFileSync(passwordFile, 'utf-8').trim();
  const res = await request.post('/api/auth/admin/login', {
    data: { email, password },
    headers: { Accept: 'application/json' },
  });
  if (res.status() !== 200) {
    throw new Error(`관리자 로그인 실패: ${res.status()}`);
  }
  const body = await res.json();
  return body.data.token as string;
}
