/**
 * sirsoft-admin_basic 템플릿 — 실제 관리자 계정 로그인 토큰 fixture.
 *
 * 회원 언어(`users.language`)를 바꾸는 spec(헤더 언어 메뉴)은 발급 계정이 아니라 정해 둔 계정으로 재야 할 때가
 * 있다. 다음 두 환경변수가 모두 있으면 그 계정으로 `POST /api/auth/admin/login` 해 토큰을 받는다.
 *   - G7_E2E_ADMIN_EMAIL          관리자 계정 이메일
 *   - G7_E2E_ADMIN_PASSWORD_FILE  비밀번호를 담은 파일 경로(비밀번호를 명령줄·로그에 남기지 않으려고 파일로만 받는다)
 * 없으면 코어 `playwright:issue-token` 으로 관리자 역할 토큰을 발급한다(저장소 기본 경로).
 */
import { readFileSync } from 'node:fs';
import type { APIRequestContext } from '@playwright/test';
import { issueToken } from './admin-template-auth';

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

/**
 * 회원 언어를 API 로 바로 맞춘다(spec 이 끝나거나 실패한 뒤 원래 언어로 되돌릴 때).
 *
 * @param request Playwright API 요청 컨텍스트
 * @param token 관리자 토큰
 * @param language 맞출 언어 코드
 */
export async function setAdminLanguage(request: APIRequestContext, token: string, language: string): Promise<void> {
  await request.patch('/api/admin/users/me/language', {
    data: { language },
    headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
  });
}
