/**
 * 기능 개발 워크플로우
 * Backend → Layout → Frontend → Template → Reviewer 순서로 실행
 */
import { Coordinator } from '../coordinator/Coordinator.js';
import { logger, createWorkflowLogger } from '../utils/logger.js';
import type { WorkflowResult } from '../types/index.js';

const workflowLogger = createWorkflowLogger('feature');

/**
 * 기능 개발 워크플로우 실행
 */
export async function featureDevelopmentWorkflow(
  featureDescription: string,
  options: {
    cwd?: string;
    skipReview?: boolean;
  } = {}
): Promise<WorkflowResult> {
  workflowLogger.info(`Starting feature development: ${featureDescription}`);

  const coordinator = new Coordinator({
    cwd: options.cwd,
    model: 'sonnet',
  });

  const prompt = `
새로운 기능을 개발해주세요: ${featureDescription}

## 작업 순서 (순차 실행)

### 1. [backend] 백엔드 구현
- 필요한 마이그레이션 생성 (php artisan make:migration)
- Model 생성/수정
- RepositoryInterface 및 Repository 구현
- Service 클래스 구현 (훅 실행 포함)
- FormRequest 검증 클래스
- Controller 구현
- 라우트 등록

### 2. [layout] 레이아웃 JSON 작성
- 데이터 소스 정의 (API 엔드포인트 연결)
- 컴포넌트 구조 설계
- 데이터 바인딩 설정
- 다국어 키 사용

### 3. [frontend] 컴포넌트 개발 (필요한 경우)
- 기존 컴포넌트로 해결 가능한지 먼저 확인
- 필요시 새 컴포넌트 개발
- 다크 모드 지원
- G7Core.t() 다국어 처리

### 4. [template] 템플릿 등록 및 빌드
- components.json 등록 (새 컴포넌트인 경우)
- index.ts export 추가
- 빌드 실행: php artisan template:build sirsoft-admin_basic

### 5. [reviewer] 최종 검수
- 백엔드 규정 준수 확인
- 프론트엔드 규정 준수 확인
- 테스트 실행 및 통과 확인
- 문서화 확인

## 중요 규칙
- 각 단계 완료 후 다음 에이전트에게 필요한 컨텍스트 전달
- 모든 작업은 그누보드7 프로젝트 규정(docs/) 준수
- 테스트 통과가 완료 조건
- 문서화 필수

## 참조 문서
- 백엔드: docs/backend/
- 프론트엔드: docs/frontend/
- 확장 시스템: docs/extension/
`;

  try {
    const result = await coordinator.orchestrate(prompt);

    if (result.success) {
      workflowLogger.info('Feature development completed successfully');
    } else {
      workflowLogger.error(`Feature development failed: ${result.errors?.join(', ')}`);
    }

    return result;

  } catch (error: any) {
    workflowLogger.error(`Feature development error: ${error.message}`);
    return {
      success: false,
      steps: [],
      totalCost: 0,
      errors: [error.message],
    };
  }
}

/**
 * 간단한 기능 추가 (단일 도메인)
 */
export async function simpleFeatureWorkflow(
  featureDescription: string,
  options: {
    cwd?: string;
    area: 'backend' | 'frontend' | 'layout' | 'template';
  }
): Promise<WorkflowResult> {
  const { area } = options;
  workflowLogger.info(`Starting simple feature (${area}): ${featureDescription}`);

  const coordinator = new Coordinator({
    cwd: options.cwd,
    model: 'sonnet',
  });

  const areaPrompts: Record<string, string> = {
    backend: `
백엔드 기능을 구현해주세요: ${featureDescription}

작업 내용:
1. 필요한 파일 생성/수정
2. 그누보드7 백엔드 규정 준수 (Service-Repository 패턴, FormRequest, 훅)
3. 테스트 작성 및 실행
4. 문서화

참조: docs/backend/
`,
    frontend: `
프론트엔드 컴포넌트를 개발해주세요: ${featureDescription}

작업 내용:
1. 컴포넌트 파일 생성
2. 그누보드7 프론트엔드 규정 준수 (기본 컴포넌트 사용, 다크 모드, 다국어)
3. components.json 등록
4. 테스트 작성 및 실행

참조: docs/frontend/components.md
`,
    layout: `
레이아웃 JSON을 작성해주세요: ${featureDescription}

작업 내용:
1. 레이아웃 JSON 파일 생성
2. 그누보드7 레이아웃 규정 준수 (text 속성, 다국어, 다크 모드 클래스)
3. 데이터 소스 및 바인딩 설정
4. 레이아웃 갱신: php artisan template:refresh-layout

참조: docs/frontend/layout-json.md
`,
    template: `
템플릿 컴포넌트를 등록하고 빌드해주세요: ${featureDescription}

작업 내용:
1. components.json에 컴포넌트 등록
2. index.ts에 export 추가
3. 빌드 실행: php artisan template:build sirsoft-admin_basic

참조: docs/frontend/template-development.md
`,
  };

  const result = await coordinator.orchestrate(areaPrompts[area]);

  return result;
}

export default featureDevelopmentWorkflow;
