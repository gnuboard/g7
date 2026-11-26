/**
 * Playwright 회원 항목, 표시 이름 fixture (코어 영역).
 *
 * 회원 항목(가입 항목, 운영자 추가 항목), 표시 이름, 닉네임 정책은 **사이트 전역 설정**이라 브라우저 시험 하나가 바꾸면
 * 같은 사이트의 다른 화면이 모두 따라 바뀐다. 그래서 이 도우미는 만들고 바꾼 것을 모두 되돌릴 수 있는 길만 연다.
 *
 * | 준비 | 방법 | 원복 |
 * | --- | --- | --- |
 * | 운영자 추가 항목 | `POST /api/admin/field-sets/core.user/fields` (키 접두 `e2e490_`) | `DELETE …/fields/{id}`. 지난 실행이 남긴 접두 항목도 `purgeE2eMemberFields()` 로 지운다 |
 * | 항목 순서 | 끌어 놓기(화면) | 시작 때 뜬 순서를 `PUT …/fields/order` 로 다시 보낸다 |
 * | 회원 탭 설정(표시 이름, 닉네임 정책) | `POST /api/admin/settings` (`_tab: member`) | 시작 때 뜬 탭 전체 값을 그대로 다시 저장한다 |
 * | 시험 회원 | `POST /api/admin/users` (이메일 접두 `e2e490.`) | `DELETE /api/admin/users/{uuid}`. 접두 회원도 `purgeE2eMembers()` 로 지운다 |
 *
 * 토큰과 비밀번호는 출력하지 않는다. 오류 메시지에는 상태 코드와 응답 본문 앞부분만 남긴다.
 */
import type { APIRequestContext, APIResponse, Page, TestInfo } from '@playwright/test';

/** 회원 항목 집합 경로 */
export const MEMBER_FIELDS_PATH = '/api/admin/field-sets/core.user/fields';

/** 설정 저장, 조회 경로 */
export const SETTINGS_PATH = '/api/admin/settings';

/** 시험이 만드는 운영자 추가 항목의 키 접두 — 지난 실행의 잔여물을 이 접두로만 찾아 지운다 */
export const E2E_FIELD_PREFIX = 'e2e490_';

/** 시험이 만드는 회원의 이메일 접두 */
export const E2E_EMAIL_PREFIX = 'e2e490.';

/** 시험 회원의 비밀번호 — 대문자, 소문자, 숫자, 특수문자를 섞고 아이디, 반복, 연속 글자를 피했다 */
export const E2E_MEMBER_PASSWORD = 'Qz7!vLm#2pRt';

/**
 * 관리자가 만든 회원이 첫 로그인 때 바꾸는 비밀번호 — 관리자가 정해 준 비밀번호는 첫 로그인 때 바꿔야 한다(코어 규칙).
 * 시험 회원은 만든 직후 이 비밀번호로 바꿔 두고, 이후 화면 로그인도 이 비밀번호를 쓴다(`E2eMember.password`).
 */
export const E2E_MEMBER_SETTLED_PASSWORD = 'Wk4@nRp8#Ty2';

/** 항목 표의 한 행 (필요한 필드만) */
export type MemberFieldRow = {
  id: number;
  key: string;
  label?: string;
  is_custom?: boolean;
  enabled?: boolean;
  required?: boolean;
  show_on_signup?: boolean;
  sort_order?: number;
  lock_reasons?: Record<string, string>;
  [key: string]: unknown;
};

/** 시험 회원 */
export type E2eMember = {
  uuid: string;
  email: string;
  password: string;
  name: string;
  nickname: string | null;
};

/**
 * 인증 헤더를 만든다.
 *
 * 화면이 ko 로 고정돼 있으므로(설정 파일 `locale: 'ko-KR'`) 응답의 항목 이름, 종류 이름도 ko 로 받아 화면 글자와 대조한다.
 *
 * @param token Sanctum 토큰
 * @returns 요청 헤더
 */
export function bearer(token: string): Record<string, string> {
  return { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Accept-Language': 'ko' };
}

/**
 * 실행마다 다른 짧은 꼬리표(영문 소문자와 숫자)를 만든다. 항목 키와 이메일에 그대로 쓸 수 있다.
 *
 * @returns 꼬리표
 */
export function uniqueTag(): string {
  return `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
}

/**
 * 워커 범위 API 요청 문맥을 연다(`beforeAll` 은 테스트 범위 `request` 를 받지 못한다).
 *
 * baseURL 은 설정 파일이 해석한 값을 프로젝트 설정에서 받는다(환경변수만 읽으면 `.env` APP_URL 폴백이 빠진다).
 *
 * @param playwright Playwright 워커 객체
 * @param testInfo 테스트 정보
 * @returns 요청 문맥 (호출자가 dispose 한다)
 */
export async function openApiContext(
  playwright: { request: { newContext: (options: Record<string, unknown>) => Promise<APIRequestContext> } },
  testInfo: TestInfo,
): Promise<APIRequestContext> {
  return playwright.request.newContext({
    baseURL: testInfo.project.use.baseURL,
    ignoreHTTPSErrors: true,
  });
}

/**
 * 응답이 성공이 아니면 상태 코드와 본문 앞부분을 담아 던진다.
 *
 * @param res 응답
 * @param what 무엇을 하던 중인지
 * @returns 파싱한 본문
 */
async function okJson(res: APIResponse, what: string): Promise<any> {
  const text = await res.text();
  if (!res.ok()) {
    throw new Error(`${what} 실패: HTTP ${res.status()} ${text.slice(0, 600)}`);
  }

  return text ? JSON.parse(text) : {};
}

/**
 * 회원 항목 표를 읽는다.
 *
 * @param request 요청 문맥
 * @param token 관리자 토큰
 * @returns 표시 순서의 항목과 정의 버전
 */
export async function listMemberFields(
  request: APIRequestContext,
  token: string,
): Promise<{ fields: MemberFieldRow[]; version: string }> {
  const body = await okJson(await request.get(MEMBER_FIELDS_PATH, { headers: bearer(token) }), '회원 항목 조회');

  return { fields: (body?.data?.fields ?? []) as MemberFieldRow[], version: String(body?.data?.fields_version ?? '') };
}

/**
 * 운영자 추가 항목을 만든다.
 *
 * @param request 요청 문맥
 * @param token 관리자 토큰
 * @param body 추가 요청 본문 (`key` 는 `E2E_FIELD_PREFIX` 로 시작해야 정리 대상이 된다)
 * @returns 만든 항목
 */
export async function createMemberField(
  request: APIRequestContext,
  token: string,
  body: Record<string, unknown>,
): Promise<MemberFieldRow> {
  const key = String(body.key ?? '');
  if (!key.startsWith(E2E_FIELD_PREFIX)) {
    throw new Error(`시험 항목 키는 ${E2E_FIELD_PREFIX} 로 시작해야 합니다: ${key}`);
  }
  const res = await request.post(MEMBER_FIELDS_PATH, {
    headers: { ...bearer(token), 'Content-Type': 'application/json' },
    data: body,
  });

  return (await okJson(res, `회원 항목 추가(${key})`))?.data?.field as MemberFieldRow;
}

/**
 * 항목의 속성을 바꾼다(보낸 속성만 바뀐다).
 *
 * @param request 요청 문맥
 * @param token 관리자 토큰
 * @param id 항목 ID
 * @param body 바꿀 속성
 * @returns 바뀐 항목
 */
export async function updateMemberField(
  request: APIRequestContext,
  token: string,
  id: number,
  body: Record<string, unknown>,
): Promise<MemberFieldRow> {
  const res = await request.put(`${MEMBER_FIELDS_PATH}/${id}`, {
    headers: { ...bearer(token), 'Content-Type': 'application/json' },
    data: body,
  });

  return (await okJson(res, `회원 항목 수정(${id})`))?.data?.field as MemberFieldRow;
}

/**
 * 운영자 추가 항목을 지운다. 이미 없으면(404) 조용히 넘어간다.
 *
 * @param request 요청 문맥
 * @param token 관리자 토큰
 * @param id 항목 ID
 */
export async function deleteMemberField(request: APIRequestContext, token: string, id: number): Promise<void> {
  const res = await request.delete(`${MEMBER_FIELDS_PATH}/${id}`, { headers: bearer(token) });
  if (res.status() === 404) {
    return;
  }
  await okJson(res, `회원 항목 삭제(${id})`);
}

/**
 * 지난 실행이 남긴 시험 항목(키 접두 `e2e490_`)을 모두 지운다.
 *
 * @param request 요청 문맥
 * @param token 관리자 토큰
 */
export async function purgeE2eMemberFields(request: APIRequestContext, token: string): Promise<void> {
  const { fields } = await listMemberFields(request, token);
  for (const field of fields) {
    if (field.is_custom === true && String(field.key).startsWith(E2E_FIELD_PREFIX)) {
      await deleteMemberField(request, token, field.id);
    }
  }
}

/**
 * 항목 순서를 시작 때 순서로 되돌린다.
 *
 * 지금 없는 항목(시험이 지운 항목)은 빼고, 그 사이 다른 사람이 만든 항목은 지금 순서대로 뒤에 붙인다.
 *
 * @param request 요청 문맥
 * @param token 관리자 토큰
 * @param snapshot 시작 때 표시 순서의 항목 ID
 */
export async function restoreMemberFieldOrder(
  request: APIRequestContext,
  token: string,
  snapshot: number[],
): Promise<void> {
  const current = (await listMemberFields(request, token)).fields.map((field) => field.id);
  const order = [...snapshot.filter((id) => current.includes(id)), ...current.filter((id) => !snapshot.includes(id))];
  if (order.length === 0) {
    return;
  }
  const res = await request.put(`${MEMBER_FIELDS_PATH}/order`, {
    headers: { ...bearer(token), 'Content-Type': 'application/json' },
    data: { order },
  });
  await okJson(res, '회원 항목 순서 복원');
}

/**
 * 설정 탭 하나를 읽는다(`_` 로 시작하는 내부 키는 뺀다).
 *
 * @param request 요청 문맥
 * @param token 관리자 토큰
 * @param tab 설정 탭
 * @returns 탭 전체 값
 */
export async function readSettingsTab(
  request: APIRequestContext,
  token: string,
  tab: string,
): Promise<Record<string, unknown>> {
  const body = await okJson(await request.get(`${SETTINGS_PATH}?tab=${tab}`, { headers: bearer(token) }), `${tab} 설정 조회`);
  const current = (body?.data?.[tab] ?? {}) as Record<string, unknown>;

  return Object.fromEntries(Object.entries(current).filter(([key]) => !key.startsWith('_')));
}

/**
 * 설정 탭 하나를 통째로 저장한다.
 *
 * @param request 요청 문맥
 * @param token 관리자 토큰
 * @param tab 설정 탭
 * @param values 저장할 탭 전체 값
 */
export async function writeSettingsTab(
  request: APIRequestContext,
  token: string,
  tab: string,
  values: Record<string, unknown>,
): Promise<void> {
  const res = await request.post(SETTINGS_PATH, {
    headers: { ...bearer(token), 'Content-Type': 'application/json' },
    data: { _tab: tab, [tab]: values },
  });
  await okJson(res, `${tab} 설정 저장`);
}

/**
 * 설정 탭 하나에서 지정한 키만 바꿔 저장하고, 바꾸기 전 탭 전체 값을 돌려준다(원복에 그대로 쓴다).
 *
 * @param request 요청 문맥
 * @param token 관리자 토큰
 * @param tab 설정 탭
 * @param patch 바꿀 키와 값
 * @returns 바꾸기 전 탭 전체 값
 */
export async function patchSettingsTab(
  request: APIRequestContext,
  token: string,
  tab: string,
  patch: Record<string, unknown>,
): Promise<Record<string, unknown>> {
  const previous = await readSettingsTab(request, token, tab);
  await writeSettingsTab(request, token, tab, { ...previous, ...patch });

  return previous;
}

/**
 * 표시 이름 영역 설정에 바꿀 영역만 덮어 쓴 새 영역 객체를 만든다.
 *
 * @param member 회원 탭 값
 * @param areas 바꿀 영역 설정
 * @returns 새 영역 객체
 */
export function withDisplayNameAreas(
  member: Record<string, unknown>,
  areas: Record<string, { mode: string; kind?: string }>,
): Record<string, unknown> {
  return { ...((member.display_name_areas ?? {}) as Record<string, unknown>), ...areas };
}

/**
 * 기본 `user` 역할의 ID 를 찾는다.
 *
 * @param request 요청 문맥
 * @param token 관리자 토큰
 * @returns 역할 ID
 */
export async function userRoleId(request: APIRequestContext, token: string): Promise<number> {
  const body = await okJson(await request.get('/api/admin/roles/active', { headers: bearer(token) }), '역할 조회');
  const roles = (body?.data?.data ?? body?.data ?? []) as Array<{ id: number; identifier: string }>;
  const role = roles.find((r) => r.identifier === 'user');
  if (!role) {
    throw new Error('기본 user 역할을 찾지 못했습니다');
  }

  return role.id;
}

/**
 * 관리자 회원 추가 화면의 항목 계약에서 운영자가 만든 필수 항목을 골라 유형에 맞는 시험 값을 만든다.
 *
 * 시험은 자기가 만든 항목(`e2e490_`)만 다루지만, 같은 사이트에 운영자가 만든 필수 항목이 있으면 관리자 회원 추가가 422 로
 * 막힌다. 그 항목에는 유형별 시험 값을 넣는다. 다룰 수 없는 유형(파일, 이미지)은 관리자 추가에서 받지 않으므로 넣지 않는다.
 *
 * @param request 요청 문맥
 * @param token 관리자 토큰
 * @returns 항목 키 → 시험 값
 */
async function requiredCustomFieldFillers(request: APIRequestContext, token: string): Promise<Record<string, unknown>> {
  const res = await request.get('/api/admin/users/form-fields', { headers: bearer(token) });
  const body = await okJson(res, '관리자 회원 추가 항목 계약 조회');
  const fields: any[] = Array.isArray(body?.data?.fields) ? body.data.fields : [];
  const fillers: Record<string, unknown> = {};

  for (const field of fields) {
    if (field?.owner !== 'custom' || field?.required !== true || field?.deferred === true) continue;
    const firstOption = Array.isArray(field.options) && field.options.length > 0 ? field.options[0]?.value : undefined;
    const value = ((): unknown => {
      switch (field.type) {
        case 'text':
        case 'textarea':
          return 'e2e';
        case 'number':
          return Number(field.constraints?.min ?? 1);
        case 'boolean':
          return true;
        case 'date':
          return '2000-01-01';
        case 'datetime':
          return '2000-01-01 00:00:00';
        case 'phone':
          return '010-1234-5678';
        case 'email':
          return 'e2e@example.test';
        case 'url':
          return 'https://example.test';
        case 'choice':
          return firstOption;
        case 'multi_choice':
          return firstOption === undefined ? undefined : [firstOption];
        default:
          return undefined;
      }
    })();
    if (value !== undefined) fillers[String(field.key)] = value;
  }

  return fillers;
}

/**
 * 관리자가 만든 회원의 첫 로그인 비밀번호 변경 단계를 API 로 마친다.
 *
 * 로그인 응답이 `password_change_required` 면 `POST /api/auth/login/password-change` 로 새 비밀번호를 정한다. 그 단계가 없는
 * 사이트(정책을 끈 경우)는 처음 비밀번호를 그대로 쓴다. 2단계 인증이 켜진 사이트는 시험 회원으로 잴 수 없으므로 알린다.
 *
 * @param request 요청 문맥
 * @param email 회원 이메일
 * @param password 관리자가 정해 준 비밀번호
 * @returns 이후 로그인에 쓸 비밀번호
 */
async function settleFirstLoginPassword(request: APIRequestContext, email: string, password: string): Promise<string> {
  const res = await request.post('/api/auth/login', {
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    data: { email, password },
  });
  const body = await okJson(res, '시험 회원 첫 로그인');
  if (body?.data?.two_factor_required) {
    throw new Error('시험 회원 로그인에 2단계 인증이 요구됩니다 — 2단계 인증을 끈 사이트에서 돌린다');
  }
  if (!body?.data?.password_change_required) {
    return password;
  }

  const change = await request.post('/api/auth/login/password-change', {
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    data: {
      challenge_id: String(body.data.challenge_id ?? ''),
      password: E2E_MEMBER_SETTLED_PASSWORD,
      password_confirmation: E2E_MEMBER_SETTLED_PASSWORD,
    },
  });
  await okJson(change, '시험 회원 첫 로그인 비밀번호 변경');

  return E2E_MEMBER_SETTLED_PASSWORD;
}

/**
 * 시험 회원을 관리자 API 로 만든다.
 *
 * @param request 요청 문맥
 * @param token 관리자 토큰
 * @param options 이메일 꼬리표, 이름, 닉네임, 운영자 추가 항목 값
 * @returns 만든 회원
 */
export async function createE2eMember(
  request: APIRequestContext,
  token: string,
  options: { tag: string; role: string; name: string; nickname?: string | null; customFields?: Record<string, unknown> },
): Promise<E2eMember> {
  const email = `${E2E_EMAIL_PREFIX}${options.tag}.${options.role}@example.test`;
  const data: Record<string, unknown> = {
    name: options.name,
    email,
    password: E2E_MEMBER_PASSWORD,
    password_confirmation: E2E_MEMBER_PASSWORD,
    status: 'active',
    role_ids: [await userRoleId(request, token)],
  };
  if (options.nickname) data.nickname = options.nickname;
  // 사이트에 운영자가 만든 필수 항목이 있으면 그 칸도 채워야 회원이 만들어진다(시험이 다루지 않는 항목은 시험 값으로 채운다)
  const customFields = { ...(await requiredCustomFieldFillers(request, token)), ...(options.customFields ?? {}) };
  if (Object.keys(customFields).length > 0) data.custom_fields = customFields;

  const res = await request.post('/api/admin/users', {
    headers: { ...bearer(token), 'Content-Type': 'application/json' },
    data,
  });
  const body = await okJson(res, `시험 회원 생성(${options.role})`);
  const password = await settleFirstLoginPassword(request, email, E2E_MEMBER_PASSWORD);

  return {
    uuid: String(body?.data?.uuid ?? ''),
    email,
    password,
    name: options.name,
    nickname: options.nickname ?? null,
  };
}

/**
 * 회원을 이메일로 찾는다(관리자 목록 검색).
 *
 * @param request 요청 문맥
 * @param token 관리자 토큰
 * @param email 이메일
 * @returns 목록 행 또는 null
 */
export async function findMemberByEmail(
  request: APIRequestContext,
  token: string,
  email: string,
): Promise<Record<string, any> | null> {
  const query = new URLSearchParams({
    'filters[0][field]': 'email',
    'filters[0][value]': email,
    'filters[0][operator]': 'eq',
    per_page: '10',
  });
  const body = await okJson(await request.get(`/api/admin/users?${query.toString()}`, { headers: bearer(token) }), '회원 검색');
  const rows = (body?.data?.data ?? []) as Array<Record<string, any>>;

  return rows.find((row) => row.email === email) ?? null;
}

/**
 * 회원을 지운다. 이미 없으면(404) 조용히 넘어간다.
 *
 * @param request 요청 문맥
 * @param token 관리자 토큰
 * @param uuid 회원 UUID
 */
export async function deleteE2eMember(request: APIRequestContext, token: string, uuid: string): Promise<void> {
  if (!uuid) {
    return;
  }
  const res = await request.delete(`/api/admin/users/${uuid}`, { headers: bearer(token) });
  if (res.status() === 404) {
    return;
  }
  await okJson(res, `시험 회원 삭제(${uuid})`);
}

/**
 * 지난 실행이 남긴 시험 회원(이메일 접두 `e2e490.`)을 모두 지운다.
 *
 * @param request 요청 문맥
 * @param token 관리자 토큰
 * @param emailPart 찾을 이메일 조각 (기본은 접두 전체)
 */
export async function purgeE2eMembers(
  request: APIRequestContext,
  token: string,
  emailPart: string = E2E_EMAIL_PREFIX,
): Promise<void> {
  const query = new URLSearchParams({
    'filters[0][field]': 'email',
    'filters[0][value]': emailPart,
    'filters[0][operator]': 'starts_with',
    per_page: '100',
  });
  const body = await okJson(await request.get(`/api/admin/users?${query.toString()}`, { headers: bearer(token) }), '시험 회원 검색');
  for (const row of (body?.data?.data ?? []) as Array<{ uuid: string; email: string }>) {
    if (String(row.email ?? '').startsWith(E2E_EMAIL_PREFIX)) {
      await deleteE2eMember(request, token, row.uuid);
    }
  }
}

/**
 * 회원으로 로그인해 토큰을 받는다(API).
 *
 * @param request 요청 문맥
 * @param email 이메일
 * @param password 비밀번호
 * @returns 토큰과 비어 있는 필수 항목 키
 */
export async function loginMemberApi(
  request: APIRequestContext,
  email: string,
  password: string,
): Promise<{ token: string; pending: string[] }> {
  const res = await request.post('/api/auth/login', {
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    data: { email, password },
  });
  const body = await okJson(res, '회원 로그인');
  const token = String(body?.data?.token ?? '');
  if (!token) {
    throw new Error('로그인 응답에 토큰이 없습니다(2단계 인증이나 비밀번호 변경 단계가 켜진 사이트일 수 있다)');
  }

  return { token, pending: (body?.data?.pending_required_fields ?? []) as string[] };
}

/**
 * GDPR 쿠키 동의 배너를 닫는다(새 문맥에는 동의 이력이 없어 하단에 뜬다). 없으면 조용히 지나간다.
 *
 * @param page 대상 페이지
 */
export async function dismissCookieBanner(page: Page): Promise<void> {
  const accept = page.getByRole('button', { name: '모두 동의' }).first();
  try {
    await accept.waitFor({ state: 'visible', timeout: 3000 });
  } catch {
    return;
  }
  try {
    await accept.click({ timeout: 5000 });
  } catch {
    // 배너가 스스로 다시 그려지거나 사라졌다
  }
  await accept.waitFor({ state: 'hidden', timeout: 5000 }).catch(() => undefined);
}

/**
 * 환경설정 > 회원 탭에 들어가 가입 항목 표와 설정 폼이 채워질 때까지 기다린다.
 *
 * 설정 조회가 폼에 들어오기 전에 값을 고치면 그 칸 하나만 담긴 폼이 저장되어 422 가 난다.
 *
 * @param page 관리자 토큰을 심은 페이지
 */
export async function gotoMemberSettingsTab(page: Page): Promise<void> {
  const defs = page.waitForResponse(
    (r) => r.url().includes(MEMBER_FIELDS_PATH) && r.request().method() === 'GET',
    { timeout: 60_000 },
  );
  const settings = page.waitForResponse(
    (r) => new URL(r.url()).pathname === SETTINGS_PATH && r.request().method() === 'GET',
    { timeout: 60_000 },
  );
  await page.goto('/admin/settings?tab=member');
  await Promise.all([defs, settings]);
  await page.locator('#member_fields_card').waitFor({ state: 'visible', timeout: 30_000 });
}

/**
 * 사용자 화면의 비밀번호 재확인을 지나 내 정보 수정 폼을 연다.
 *
 * 확인 단추는 입력값이 상태에 들어가야 풀린다 — 풀리기 전에 Enter 를 누르면 요청이 나가지 않으므로 보이는 단추가 풀린 뒤 누른다.
 *
 * @param page 회원 토큰을 심은 페이지
 * @param password 회원 비밀번호
 */
export async function openProfileEdit(page: Page, password: string): Promise<void> {
  await page.goto('/mypage/profile/edit');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await dismissCookieBanner(page);

  const verify = page.locator('#verifyPasswordForm input[name="password"]');
  await verify.waitFor({ state: 'visible', timeout: 30_000 });
  await verify.fill(password);
  const confirm = page.locator('button[type="submit"][form="verifyPasswordForm"]:visible').first();
  await confirm.waitFor({ state: 'visible', timeout: 10_000 });
  const [verified] = await Promise.all([
    page.waitForResponse((r) => r.url().endsWith('/api/me/verify-password') && r.request().method() === 'POST'),
    confirm.click(),
  ]);
  if (verified.status() !== 200) {
    throw new Error(`비밀번호 재확인 실패: HTTP ${verified.status()}`);
  }
  await page.locator('#profileEditForm').waitFor({ state: 'visible', timeout: 30_000 });
  // 폼은 먼저 보이고 회원 값은 약 1.5초 뒤 칸에 그려진다(상태에는 먼저 들어와 있다). 칸이 그려지기 전에 다른 칸을 고치면
  // 아직 그려지지 않은 칸(이름 등)이 빈 값으로 덮인다 — 이메일(읽기 전용)과 이름 또는 닉네임 칸에 값이 그려질 때까지 기다린다.
  await page.waitForFunction(
    () => {
      const value = (selector: string) => ((document.querySelector(selector) as HTMLInputElement | null)?.value ?? '');
      const email = value('#profileEditForm input[type="email"][readonly]');
      const named = value('#profileEditForm input[name="name"]') !== '' || value('#profileEditForm input[name="nickname"]') !== '';

      return email !== '' && named;
    },
    undefined,
    { timeout: 30_000 },
  );
}
