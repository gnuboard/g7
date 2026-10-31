// E2E: tests/Playwright/specs/sso-*.spec.ts 가 이 등록으로 실린 핸들러를 탄다(로그인 흐름). 그 밖의 화면은 F1 Chrome MCP 계획서 §10 실측, Vitest 로 덮음
import { adminHandlers } from './admin';
import { flowHandlers } from './flow';
import { policyHandlers } from './policy';

/**
 * 이 플러그인이 레이아웃에 내주는 핸들러 목록 — 영역(흐름, 정책, 관리자 화면)별 파일을 합친다.
 *
 * 레이아웃에서는 `sirsoft-sso.{키}` 로 부른다 — 등록할 때 플러그인 식별자가 앞에 붙는다(`index.ts`). 영역끼리 키가 겹치면 뒤 영역이 이기므로
 * 키는 영역 안에서 고유하게 짓는다.
 */
export const handlerMap: Record<string, (...args: never[]) => unknown> = {
  ...flowHandlers,
  ...policyHandlers,
  ...adminHandlers,
};
