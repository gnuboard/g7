/**
 * Playwright globalSetup (코어 E2E).
 *
 * 레이아웃 편집기 저장 spec 전용 시드 화면(`e2e_sandbox`)을 설치한다. 매 실행마다 fixture
 * 원본으로 덮어쓰므로, 직전 실행이 남긴 편집 결과가 다음 실행에 새지 않는다(테스트 격리).
 *
 * 배경과 설치 범위는 `fixtures/seed-layout.ts` 참조.
 */
import { runSeedLayoutCommand } from './fixtures/seed-layout';

export default function globalSetup(): void {
  runSeedLayoutCommand(false);
}
