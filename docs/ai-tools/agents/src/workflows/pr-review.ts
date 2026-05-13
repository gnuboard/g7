/**
 * PR 검수 워크플로우
 * 변경된 파일 유형에 따라 적절한 에이전트가 검수
 */
import { Coordinator } from '../coordinator/Coordinator.js';
import { createWorkflowLogger } from '../utils/logger.js';
import type { WorkflowResult } from '../types/index.js';

const workflowLogger = createWorkflowLogger('pr-review');

export interface PRReviewOptions {
  cwd?: string;
  baseBranch?: string;
  skipTests?: boolean;
}

/**
 * PR 검수 워크플로우 실행
 */
export async function prReviewWorkflow(
  prIdentifier: string | number,
  options: PRReviewOptions = {}
): Promise<WorkflowResult> {
  workflowLogger.info(`Starting PR review: ${prIdentifier}`);

  const { baseBranch = 'master', skipTests = false } = options;

  const coordinator = new Coordinator({
    cwd: options.cwd,
    model: 'sonnet',
  });

  const prompt = `
PR을 검수해주세요: ${prIdentifier}

## 작업 순서

### 1. 변경 사항 분석
\`\`\`bash
# 변경된 파일 목록 확인
git diff --name-only ${baseBranch}...HEAD

# 변경 내용 확인
git diff ${baseBranch}...HEAD
\`\`\`

### 2. 파일 유형별 검수 할당

변경된 파일 확장자/경로에 따라 적절한 에이전트에게 검수 요청:

| 파일 패턴 | 담당 에이전트 |
|----------|--------------|
| app/**/*.php | [backend] |
| database/migrations/*.php | [backend] |
| templates/**/src/**/*.tsx | [frontend] |
| templates/**/layouts/**/*.json | [layout] |
| resources/layouts/**/*.json | [layout] |
| templates/**/components.json | [template] |

### 3. [backend] 백엔드 검수 (해당 시)
- [ ] RepositoryInterface 주입 확인
- [ ] Service 훅 실행 확인
- [ ] FormRequest 검증 규칙
- [ ] ResponseHelper 사용
- [ ] 마이그레이션 down() 구현
- [ ] 다국어 처리 (__())

### 4. [frontend] 프론트엔드 검수 (해당 시)
- [ ] HTML 태그 직접 사용 금지
- [ ] 다크 모드 클래스 쌍 지정
- [ ] G7Core.t() 다국어 처리
- [ ] Font Awesome 아이콘만 사용
- [ ] components.json 등록

### 5. [layout] 레이아웃 검수 (해당 시)
- [ ] text 속성 사용 (props.children 금지)
- [ ] 데이터 바인딩 문법 정확성
- [ ] 다국어 키 형식 ($t:)
- [ ] 다크 모드 클래스 쌍

### 6. [reviewer] 최종 검수
${skipTests ? '(테스트 건너뛰기)' : `
- 백엔드 테스트: php artisan test
- 프론트엔드 테스트: powershell -Command "npm run test:run"
`}
- 규정 준수 종합 확인
- 문서화 확인

## 검수 결과 형식

### 승인 시
\`\`\`
## 검수 결과: ✅ 승인

### 검증 항목
- [x] 백엔드 규정 준수
- [x] 프론트엔드 규정 준수
- [x] 테스트 통과
- [x] 문서화 완료

### 테스트 결과
- 백엔드: X passed
- 프론트엔드: Y passed

### 특이사항
- 없음
\`\`\`

### 변경 요청 시
\`\`\`
## 검수 결과: ❌ 변경 요청

### 발견된 문제점
1. [파일:라인] 문제 설명
   - 규정: docs/...
   - 해결: 구체적인 수정 방법

### 수정 권고사항
1. ...

### 참조 문서
- docs/...
\`\`\`
`;

  try {
    const result = await coordinator.orchestrate(prompt);

    if (result.success) {
      workflowLogger.info('PR review completed');
    } else {
      workflowLogger.error(`PR review failed: ${result.errors?.join(', ')}`);
    }

    return result;

  } catch (error: any) {
    workflowLogger.error(`PR review error: ${error.message}`);
    return {
      success: false,
      steps: [],
      totalCost: 0,
      errors: [error.message],
    };
  }
}

/**
 * 특정 파일만 검수
 */
export async function reviewFiles(
  files: string[],
  options: { cwd?: string } = {}
): Promise<WorkflowResult> {
  workflowLogger.info(`Reviewing files: ${files.join(', ')}`);

  const coordinator = new Coordinator({
    cwd: options.cwd,
    model: 'sonnet',
  });

  const fileList = files.map(f => `- ${f}`).join('\n');

  const prompt = `
다음 파일들을 검수해주세요:

${fileList}

## 검수 항목

각 파일 유형에 맞는 그누보드7 규정 준수 여부를 확인하세요:

### PHP 파일
- validate-backend 도구 사용
- Service-Repository 패턴
- FormRequest 검증
- 훅 시스템

### TSX 파일
- validate-frontend 도구 사용
- HTML 태그 사용 금지
- 다크 모드 클래스
- 다국어 처리

### JSON 레이아웃 파일
- validate-frontend 도구 사용
- text 속성 사용
- 데이터 바인딩 문법
- 다국어 키 형식

## 결과 형식
각 파일별로 검수 결과를 정리해주세요.
`;

  return coordinator.orchestrate(prompt);
}

export default prReviewWorkflow;
