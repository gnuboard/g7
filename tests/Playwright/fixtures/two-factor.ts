/**
 * Playwright 2단계 인증 fixture (코어 영역).
 *
 * 로그인 2단계 인증은 **사이트 설정과 메일 발송**에 의존한다. 브라우저만으로는 재현할 수 없으므로
 * 이 fixture 가 세 가지를 가역적으로 준비한다.
 *
 *   ① 보안 환경설정 `security.two_factor_auth` 를 켜고, 끝나면 원래 값으로 되돌린다.
 *   ② 메일을 받아서 버리는 로컬 SMTP 싱크를 띄우고 메일 설정을 그쪽으로 돌린다. 끝나면
 *      원래 값으로 되돌린다. (실제 메일을 보내면 테스트가 외부 상태를 바꾼다)
 *      `log` 메일러를 쓰지 않는 이유는 `smtp-sink.ts` 머리말 참조.
 *   ③ 인증번호는 해시로만 저장되어 되읽을 수 없으므로, 알려진 코드를 심는다
 *      (`php artisan playwright:seed-two-factor --plant=…`).
 *
 * 보안 설정은 **관리자 설정 API** 로 바꾼다 — 운영자가 화면에서 하는 것과 같은 경로다.
 * 메일 설정만 파일을 직접 다룬다: 원래 값(빈 SMTP 호스트)은 저장 검증을 통과하지 못해
 * API 로는 **되돌릴 수 없기 때문**이다. 파일은 바이트 단위로 백업했다가 그대로 복원한다.
 *
 * 원복은 실패해도 조용히 넘기지 않는다 — 되돌리지 못한 채 끝나면 사이트가 2단계 인증이 켜진
 * 상태로 남아 이후 모든 로그인이 막힌다.
 */
import type { APIRequestContext } from '@playwright/test';
import { startSmtpSink, type SmtpSink } from './smtp-sink';
import { execSync } from 'node:child_process';
import { copyFileSync, existsSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));

/** artisan 이 있는 코어 루트 */
function coreRoot(): string {
  return process.env.G7_ROOT || resolve(__dirname, '../../../');
}

/**
 * 관리자 설정 API 경로.
 *
 * 절대 URL 을 만들지 않는다 — Playwright 의 `request` 컨텍스트가 `baseURL` 과
 * `ignoreHTTPSErrors` 를 이미 갖고 있다. Node 의 `fetch` 를 쓰면 자체 서명 인증서를 쓰는
 * 개발 호스트에서 TLS 로 막히고, 그것을 우회하려면 이 파일이 TLS 검증을 끄게 된다.
 */
const SETTINGS_PATH = '/api/admin/settings';

/**
 * `playwright:seed-two-factor` 를 실행하고 마지막 출력 줄을 돌려준다.
 *
 * @param args 커맨드 인자 (예: ['--plant=<uuid>', '--code=135790'])
 * @returns stdout 의 마지막 비어있지 않은 줄
 */
export function runSeedTwoFactor(args: string[]): string {
  const command = `php artisan playwright:seed-two-factor ${args.join(' ')}`.trim();

  let lastError: unknown;
  for (let attempt = 0; attempt < 3; attempt += 1) {
    try {
      const stdout = execSync(command, {
        cwd: coreRoot(),
        encoding: 'utf-8',
        env: { ...process.env, G7_PLAYWRIGHT_BYPASS: '1' },
      });
      const lines = stdout.split(/\r?\n/).filter((line) => line.trim().length > 0);
      if (lines.length === 0) {
        throw new Error(`playwright:seed-two-factor 가 빈 응답을 반환했습니다: ${command}`);
      }
      return lines[lines.length - 1].trim();
    } catch (error) {
      lastError = error;
      if (attempt < 2) {
        Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 400);
      }
    }
  }
  throw lastError instanceof Error ? lastError : new Error(String(lastError));
}

/**
 * 알려진 인증번호를 challenge 에 심는다.
 *
 * @param challengeId 로그인 응답이 돌려준 challenge UUID
 * @param code 심을 인증번호 (기본 135790)
 * @returns 심어 둔 인증번호
 */
export function plantTwoFactorCode(challengeId: string, code = '135790'): string {
  return runSeedTwoFactor([`--plant=${challengeId}`, `--code=${code}`, '--gc-hours=0']);
}

/**
 * 인증 앱 플러그인의 `playwright:seed-totp` 를 실행하고 마지막 출력 줄을 돌려준다.
 *
 * 인증 앱 자격증명은 그 플러그인 소유라 코어 커맨드가 아니라 플러그인 커맨드가 심는다.
 *
 * @param args 커맨드 인자 (예: ['--enroll=<email>'])
 * @returns stdout 의 마지막 비어있지 않은 줄
 */
function runSeedTotp(args: string[]): string {
  const command = `php artisan playwright:seed-totp ${args.join(' ')}`.trim();
  const stdout = execSync(command, {
    cwd: coreRoot(),
    encoding: 'utf-8',
    env: { ...process.env, G7_PLAYWRIGHT_BYPASS: '1' },
  });
  const lines = stdout.split(/\r?\n/).filter((line) => line.trim().length > 0);
  if (lines.length === 0) {
    throw new Error(`playwright:seed-totp 가 빈 응답을 반환했습니다: ${command}`);
  }
  return lines[lines.length - 1].trim();
}

/**
 * 대상 계정에 «인증 앱이 이미 등록된 상태» 를 만들고 평문 시크릿을 돌려준다.
 *
 * 실측은 휴대폰을 들 수 없으므로 QR 스캔 단계를 서버에서 건너뛴다. 돌려받은 시크릿으로
 * `totpCodeFor()` 를 불러 그 순간의 코드를 계산한다 — 인증 앱 대역이다.
 *
 * @param email 대상 계정 이메일
 * @returns Base32 시크릿
 */
export function enrollTotpFor(email: string): string {
  return runSeedTotp([`--enroll=${email}`]);
}

/**
 * 시크릿의 «지금 이 순간» 코드를 계산한다.
 *
 * 코드는 30초마다 바뀌므로 제출 직전에 부른다 — 미리 받아 두면 경계에서 만료된다.
 *
 * @param secret Base32 시크릿
 * @returns 6자리 코드
 */
export function totpCodeFor(secret: string): string {
  return runSeedTotp([`--code=${secret}`]);
}

/**
 * `enrollTotpFor()` 가 심는 고정 백업 코드.
 *
 * 평문을 알아야 «백업 코드로 로그인» 축을 자동화할 수 있다.
 */
export const TOTP_BACKUP_CODES = ['pwtst-aaaa1', 'pwtst-bbbb2'] as const;

/**
 * 실측이 만든 테스트 계정을 제거한다.
 *
 * 이 계정들은 알려진 비밀번호를 갖고, 관리자용은 관리자 역할까지 갖는다 — 나이 기준 정리를
 * 기다리면 그 사이가 그대로 열린 문이다. 실측이 끝나면 즉시 지운다.
 *
 * **여러 spec 이 같은 fixture 를 쓰면 `prefix` 로 자기 계정만 지운다.** 전체 정리는 아직
 * 돌고 있는 형제 spec 의 계정까지 지워 그쪽을 엉뚱한 실패로 떨어뜨린다.
 *
 * @param prefix 이 접미사로 시작하는 계정만 지운다 (미지정 시 전체)
 * @returns 커맨드의 마지막 출력 줄
 */
export function purgeTwoFactorUsers(prefix?: string): string {
  const args = ['--purge-users', '--gc-hours=0'];
  if (prefix) args.push(`--purge-prefix=${prefix}`);

  return runSeedTwoFactor(args);
}

/**
 * 알려진 비밀번호를 가진 Active 테스트 계정을 준비한다.
 *
 * @param suffix 계정 구분 접미사 (예: 'user' / 'nonadmin')
 * @param options admin 역할 부여 여부와 비밀번호, 추가 역할 identifier 목록
 * @returns 준비된 계정 이메일
 */
export function ensureTwoFactorUser(
  suffix: string,
  options: { admin?: boolean; password?: string; roles?: readonly string[] } = {}
): string {
  const args = [`--ensure-user=${suffix}`, `--password=${options.password ?? 'Passw0rd!2fa'}`];
  if (options.admin) args.push('--admin');
  // 확장 전용 역할은 admin 에 딸려오지 않는다 — 그 권한으로만 보이는 화면을 측정할 때 쓴다.
  for (const role of options.roles ?? []) args.push(`--role=${role}`);
  return runSeedTwoFactor(args);
}

/** 되돌리기 위해 보관하는 상태 */
type TwoFactorFixtureState = {
  /** 보안 탭 전체 값 (API 로 그대로 되쓴다) */
  security: Record<string, unknown>;
  /** 메일 설정 파일 백업 경로 (없으면 파일 자체가 없던 설치) */
  mailBackupPath: string | null;
  sink: SmtpSink;
};

/** 메일 설정 파일 경로 */
function mailSettingsPath(): string {
  return resolve(coreRoot(), 'storage/app/settings/mail.json');
}

/**
 * 메일 설정을 SMTP 싱크로 돌린다.
 *
 * 값 4개만 줄 단위로 치환한다 — 재직렬화하면 운영자 파일의 서식이 통째로 바뀐다.
 * `mailer` 를 smtp 로 바꾸는 이유는 사이트가 API 메일러(Mailgun·SES 등)를 쓰면 host·port 를 바꿔도
 * 메일이 싱크가 아니라 실제 서비스로 나가 발송이 실패하고 로그인이 503 으로 막히기 때문이다
 * (그런 설정은 `host` 가 null 이기도 하다). `encryption` 을 비우는 이유는 싱크가 STARTTLS 를 제공하지 않기 때문이다.
 *
 * @param port 싱크 포트
 * @returns 백업 파일 경로 (설정 파일이 없으면 null)
 */
function redirectMailToSink(port: number): string | null {
  const path = mailSettingsPath();
  if (! existsSync(path)) {
    return null;
  }

  const backupPath = `${path}.bak-playwright-2fa`;
  copyFileSync(path, backupPath);

  const original = readFileSync(path, 'utf-8');
  const swapped = original
    .replace(/"mailer"(\s*):(\s*)("[^"]*"|null)/, '"mailer"$1:$2"smtp"')
    .replace(/"host"(\s*):(\s*)("[^"]*"|null)/, `"host"$1:$2"127.0.0.1"`)
    .replace(/"port"(\s*):(\s*)(\d+|null|"[^"]*")/, `"port"$1:$2${port}`)
    .replace(/"encryption"(\s*):(\s*)("[^"]*"|null)/, '"encryption"$1:$2""');

  writeFileSync(path, swapped, 'utf-8');

  return backupPath;
}

/**
 * 설정 탭 하나를 통째로 저장한다.
 *
 * @param request Playwright API 요청 컨텍스트
 * @param token 관리자 Sanctum 토큰
 * @param tab 설정 탭
 * @param values 저장할 탭 전체 값
 */
async function writeSettingsTab(
  request: APIRequestContext,
  token: string,
  tab: string,
  values: Record<string, unknown>
): Promise<void> {
  const write = await request.post(SETTINGS_PATH, {
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`,
    },
    data: { _tab: tab, [tab]: values },
  });

  if (!write.ok()) {
    throw new Error(`${tab} 설정 저장 실패: HTTP ${write.status()} — ${await write.text()}`);
  }
}

/**
 * 설정 탭 하나를 읽고, 지정한 키만 바꿔 저장한다.
 *
 * 저장 페이로드는 `{ _tab, <탭>: { ... } }` 형태이고 탭 **전체**가 검증되므로, 바꿀 키만
 * 보내면 나머지 필수 항목이 422 로 거부된다. 현재 값을 그대로 되돌려 보내 다른 설정은
 * 건드리지 않는다.
 *
 * @param request Playwright API 요청 컨텍스트 (baseURL·ignoreHTTPSErrors 상속)
 * @param token 관리자 Sanctum 토큰
 * @param tab 설정 탭 (`security` · `mail` 등)
 * @param patch 바꿀 키-값
 * @returns 바꾸기 전의 탭 전체 값 (원복에 그대로 쓴다)
 */
async function patchSettingsTab(
  request: APIRequestContext,
  token: string,
  tab: string,
  patch: Record<string, unknown>
): Promise<Record<string, unknown>> {
  const read = await request.get(`${SETTINGS_PATH}?tab=${tab}`, {
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${token}`,
    },
  });

  if (!read.ok()) {
    throw new Error(`${tab} 설정 조회 실패: HTTP ${read.status()}`);
  }

  const body = await read.json();
  const current = (body?.data?.[tab] ?? {}) as Record<string, unknown>;

  // `_meta` 같은 내부 키는 저장 페이로드에서 제외한다.
  const previous = Object.fromEntries(
    Object.entries(current).filter(([key]) => !key.startsWith('_'))
  );

  await writeSettingsTab(request, token, tab, { ...previous, ...patch });

  return previous;
}

/**
 * 2단계 인증 실측 환경을 준비한다.
 *
 * `extraSecurity` 로 같은 보안 탭의 다른 값을 함께 켤 수 있다 — 저장 전 스냅샷은 탭
 * **전체**를 뜨므로 무엇을 얹든 `restoreTwoFactorFixture` 가 통째로 되돌린다.
 *
 * @param request Playwright API 요청 컨텍스트
 * @param token 관리자 Sanctum 토큰 (`core.settings.read` · `core.settings.update`)
 * @param extraSecurity 보안 탭에 함께 얹을 값 (예: 계정 잠금 임계값)
 * @returns 되돌리기에 필요한 상태
 */
export async function enableTwoFactorFixture(
  request: APIRequestContext,
  token: string,
  extraSecurity: Record<string, unknown> = {}
): Promise<TwoFactorFixtureState> {
  // 메일 경로를 먼저 돌린다 — 2단계 인증을 켠 뒤에 코드가 발행되면 실제 메일이 나간다.
  const sink = await startSmtpSink();
  const mailBackupPath = redirectMailToSink(sink.port);

  const security = await patchSettingsTab(request, token, 'security', {
    two_factor_auth: true,
    ...extraSecurity,
  });

  return { security, mailBackupPath, sink };
}

/**
 * 실측 환경을 되돌린다.
 *
 * 되돌리지 못한 채 끝나면 사이트가 2단계 인증이 켜진 상태로 남아 이후 모든 로그인이 막힌다 —
 * 실패는 삼키지 않고 그대로 던진다. 보안 설정을 먼저 되돌리고, 그 실패가 메일 설정 복원을
 * 가리지 않도록 둘 다 시도한 뒤에 던진다.
 *
 * @param request Playwright API 요청 컨텍스트
 * @param token 관리자 Sanctum 토큰
 * @param state `enableTwoFactorFixture` 가 돌려준 상태
 */
export async function restoreTwoFactorFixture(
  request: APIRequestContext,
  token: string,
  state: TwoFactorFixtureState
): Promise<void> {
  let firstError: unknown;

  try {
    await writeSettingsTab(request, token, 'security', state.security);
  } catch (error) {
    firstError = error;
  }

  if (state.mailBackupPath && existsSync(state.mailBackupPath)) {
    copyFileSync(state.mailBackupPath, mailSettingsPath());
    rmSync(state.mailBackupPath, { force: true });
  }

  await state.sink.close();

  if (firstError) {
    throw firstError;
  }
}
