/**
 * 버그 수정 워크플로우
 * TDD 기반: 버그 재현 테스트 → 수정 → 테스트 통과
 */
import { Coordinator } from '../coordinator/Coordinator.js';
import { createWorkflowLogger } from '../utils/logger.js';
import type { WorkflowResult } from '../types/index.js';

const workflowLogger = createWorkflowLogger('bugfix');

export type AffectedArea = 'backend' | 'frontend' | 'layout' | 'template' | 'unknown';

export interface BugfixOptions {
  cwd?: string;
  affectedAreas?: AffectedArea[];
  skipTroubleshooting?: boolean;
}

/**
 * 버그 수정 워크플로우 실행
 */
export async function bugfixWorkflow(
  bugDescription: string,
  options: BugfixOptions = {}
): Promise<WorkflowResult> {
  workflowLogger.info(`Starting bugfix: ${bugDescription}`);

  const { affectedAreas = ['unknown'], skipTroubleshooting = false } = options;

  const coordinator = new Coordinator({
    cwd: options.cwd,
    model: 'sonnet',
  });

  // 영향 영역에 따른 에이전트 선택
  const agentInstructions = getAgentInstructions(affectedAreas);

  const prompt = `
버그를 수정해주세요: ${bugDescription}

## 작업 순서 (TDD 기반)

### 1. 트러블슈팅 가이드 확인
${skipTroubleshooting ? '(건너뛰기)' : `
- search-docs 도구로 유사 사례 검색
- docs/frontend/troubleshooting-guide.md 확인
- docs/frontend/troubleshooting-state.md (상태 관련)
- docs/frontend/troubleshooting-cache.md (캐시 관련)
- docs/frontend/troubleshooting-components.md (컴포넌트 관련)
`}

### 2. 버그 분석
- 버그 재현 조건 파악
- 영향 받는 파일 식별
- 근본 원인 분석

### 3. 버그 재현 테스트 작성 (TDD - RED)
- 버그를 재현하는 테스트 케이스 작성
- 테스트 실행하여 실패 확인

### 4. 버그 수정 (TDD - GREEN)
- 최소한의 변경으로 버그 수정
- 그누보드7 규정 준수

### 5. 테스트 실행 (TDD - REFACTOR)
- 작성한 테스트 통과 확인
- 기존 테스트도 모두 통과 확인
- 필요시 코드 정리

### 6. 검수
- 규정 준수 확인
- 부작용 없는지 확인
- 문서화

${agentInstructions}

## 중요 규칙
- 버그 재현 테스트 먼저 작성 (TDD)
- 최소한의 변경으로 수정
- 모든 테스트 통과 필수
- 유사 버그 재발 방지 고려

## 참조 문서
- docs/frontend/troubleshooting-guide.md
- docs/testing-guide.md
`;

  try {
    const result = await coordinator.orchestrate(prompt);

    if (result.success) {
      workflowLogger.info('Bugfix completed successfully');
    } else {
      workflowLogger.error(`Bugfix failed: ${result.errors?.join(', ')}`);
    }

    return result;

  } catch (error: any) {
    workflowLogger.error(`Bugfix error: ${error.message}`);
    return {
      success: false,
      steps: [],
      totalCost: 0,
      errors: [error.message],
    };
  }
}

/**
 * 영향 영역에 따른 에이전트 지시사항 생성
 */
function getAgentInstructions(areas: AffectedArea[]): string {
  const instructions: string[] = [];

  if (areas.includes('backend') || areas.includes('unknown')) {
    instructions.push(`
### [backend] 백엔드 수정 시
- Service-Repository 패턴 준수
- 훅 실행 확인
- FormRequest 검증
- 테스트: php artisan test --filter=TestName
`);
  }

  if (areas.includes('frontend') || areas.includes('unknown')) {
    instructions.push(`
### [frontend] 프론트엔드 수정 시
- HTML 태그 직접 사용 금지
- 다크 모드 클래스 쌍 확인
- G7Core.t() 다국어 처리
- 테스트: powershell -Command "npm run test:run"
`);
  }

  if (areas.includes('layout') || areas.includes('unknown')) {
    instructions.push(`
### [layout] 레이아웃 수정 시
- text 속성 사용 (props.children 금지)
- 데이터 바인딩 문법 확인
- 다국어 키 형식 확인
- 갱신: php artisan template:refresh-layout
`);
  }

  if (areas.includes('template')) {
    instructions.push(`
### [template] 템플릿 수정 시
- components.json 확인
- index.ts export 확인
- 빌드: php artisan template:build sirsoft-admin_basic
`);
  }

  return instructions.join('\n');
}

/**
 * 상태 관련 버그 수정 (특화)
 */
export async function stateRelatedBugfix(
  bugDescription: string,
  options: { cwd?: string } = {}
): Promise<WorkflowResult> {
  workflowLogger.info(`Starting state-related bugfix: ${bugDescription}`);

  const coordinator = new Coordinator({
    cwd: options.cwd,
    model: 'sonnet',
  });

  const prompt = `
상태 관리 관련 버그를 수정해주세요: ${bugDescription}

## 필수 확인 사항 (troubleshooting-state.md 참조)

### 1. Stale Closure 문제
- setState 호출 후 즉시 상태 참조 시 이전 값 문제
- 해결: getter 함수, useRef, 또는 콜백 패턴 사용

### 2. sequence 내 setState 병합 문제
- 여러 setState가 하나로 병합되는 문제
- 해결: 별도 sequence로 분리 또는 직접 상태 조합

### 3. dot notation 충돌
- 깊은 경로 업데이트 시 데이터 손실
- 해결: 전체 객체 업데이트 또는 _merge 옵션

### 4. 타이밍 이슈
- API 응답과 setState 타이밍 불일치
- 해결: onSuccess/onError 콜백 사용

## 작업 순서
1. docs/frontend/troubleshooting-state.md 읽기
2. 유사 사례 확인
3. 버그 재현 테스트 작성
4. 수정 구현
5. 테스트 통과 확인
`;

  return coordinator.orchestrate(prompt);
}

export default bugfixWorkflow;
