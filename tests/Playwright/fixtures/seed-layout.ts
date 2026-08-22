/**
 * 레이아웃 편집기 E2E 전용 시드 화면 — 상수 + provisioning 헬퍼.
 *
 * 편집기 spec 중 **저장(PUT)까지 수행하는** 것들은 편집 결과가 그대로 영속된다. 그 대상이
 * 제품 화면(`home` / `admin_dashboard`)이면 실행할 때마다 빈 표·차트가 누적돼 개발 사이트가
 * 오염된다(실측: `home` 20,321 → 33,696 bytes, 빈 표 7개). spec 안에 "추가한 노드 삭제 후
 * 재저장" 원복을 넣어도 편집기가 캐시한 문서를 다시 밀어 넣어 오염 시점 크기로 되돌아왔다.
 *
 * 그래서 저장 spec 은 제품 화면 대신 전용 시드 화면(`e2e_sandbox`)을 대상으로 한다. 설치/제거는
 * `php artisan playwright:seed-layout` 에 위임하며, globalSetup/globalTeardown 이 호출한다.
 *
 * 시드 화면은 **활성 템플릿 디렉토리에만** 설치된다 — `_bundled`(배포 원본)은 변경되지 않고,
 * 활성 디렉토리는 Git 무시 대상이라 릴리스 산출물에도 포함되지 않는다.
 *
 * 저장하지 않는(읽기 전용) spec 은 계속 제품 화면을 대상으로 둔다 — 오염 위험이 없고,
 * 실제 제품 레이아웃에 대한 검증이 유지되는 편이 낫다.
 */
import { execFileSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

// ESM 환경(package.json "type": "module")에서는 __dirname 이 정의되지 않으므로
// import.meta.url 로 재구성한다.
const __dirname = dirname(fileURLToPath(import.meta.url));

/**
 * 시드 화면의 라우트 경로 (URL 인코딩 전 원문).
 *
 * `PlaywrightSeedLayout::DEFAULT_TARGETS` 와 동일해야 한다 — 한쪽만 바꾸면 시드는 설치되는데
 * spec 이 그 경로를 못 찾아 라우트 트리 매칭이 실패한다.
 */
export const SANDBOX_ROUTE = {
  'sirsoft-basic': '/e2e-sandbox',
  'sirsoft-admin_basic': '*/admin/e2e-sandbox',
} as const;

/**
 * 시드 화면 본문 컨테이너의 노드 id — `editorPath(page, rel, SANDBOX_ROOT_ID)` 의 기준점.
 *
 * 제품 화면에서는 어느 Div 가 선택 가능한지 정해져 있지 않아 spec 이 후보를 순회하거나
 * `children.5.children.0...` 같은 인덱스 체인을 써야 했다. 시드 화면은 이 id 를 고정하므로
 * 컨테이너를 곧바로 지목할 수 있다.
 */
export const SANDBOX_ROOT_ID = 'e2e_sandbox_root';

/**
 * 편집기 진입 URL 의 `?route=` 에 넣을 인코딩된 시드 라우트를 반환한다.
 *
 * spec 의 `gotoEditor(page, route)` 가 `?route=${route}` 로 조립하므로 인코딩된 값을 넘긴다.
 *
 * @param template 편집 대상 템플릿 identifier (기본값: 사용자 템플릿)
 * @returns URL 인코딩된 시드 라우트 경로
 */
export function sandboxRouteParam(
  template: keyof typeof SANDBOX_ROUTE = 'sirsoft-basic',
): string {
  return encodeURIComponent(SANDBOX_ROUTE[template]);
}

/**
 * `php artisan` 을 실행할 코어 루트를 결정한다.
 *
 * 확장(모듈/플러그인/템플릿) 은 자기 디렉토리의 config 로 Playwright 를 띄우므로 `process.cwd()`
 * 가 확장 디렉토리다 — 그 위치에는 artisan 이 없다. 본 파일은 항상
 * `<코어루트>/tests/Playwright/fixtures/` 에 있으므로 자기 위치에서 코어 루트를 역산한다.
 */
function resolveCoreRoot(): string {
  return process.env.G7_ROOT || resolve(__dirname, '../../../');
}

/**
 * `playwright:seed-layout` 커맨드를 실행한다.
 *
 * @param remove true 면 시드 화면 제거, false 면 설치
 */
export function runSeedLayoutCommand(remove: boolean): void {
  const args = ['artisan', 'playwright:seed-layout'];
  if (remove) args.push('--remove');

  execFileSync('php', args, {
    cwd: resolveCoreRoot(),
    encoding: 'utf-8',
    stdio: 'inherit',
    env: {
      ...process.env,
      // PlaywrightSeedLayout 의 옵트인 가드를 통과시킨다.
      // .env 영구 수정 없이 자식 프로세스에만 부여 — 운영 환경 격리 보존.
      G7_PLAYWRIGHT_BYPASS: '1',
    },
  });
}
