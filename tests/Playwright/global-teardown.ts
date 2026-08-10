/**
 * Playwright globalTeardown (코어 E2E).
 *
 * 레이아웃 편집기 저장 spec 전용 시드 화면(`e2e_sandbox`)을 제거해 개발 사이트에 흔적을
 * 남기지 않는다. 실행이 비정상 종료해 시드가 남더라도 다음 globalSetup 이 원본으로 덮어쓰므로
 * 테스트 격리는 유지된다.
 *
 * 배경과 제거 범위는 `fixtures/seed-layout.ts` 참조.
 */
import { runSeedLayoutCommand } from './fixtures/seed-layout';

export default function globalTeardown(): void {
  runSeedLayoutCommand(true);
}
