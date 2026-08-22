/**
 * CLI 인터페이스
 * 인터랙티브 모드 및 단일 명령어 모드 지원
 */
import * as readline from 'readline';
import { Coordinator } from './coordinator/Coordinator.js';
import {
  featureDevelopmentWorkflow,
  bugfixWorkflow,
  prReviewWorkflow,
} from './workflows/index.js';
import { logger } from './utils/logger.js';
import type { CliOptions, WorkflowResult, WorkflowType } from './types/index.js';

const PROJECT_ROOT = process.env.G7_PROJECT_ROOT || '.';

/**
 * CLI 실행
 */
export async function runCli(options: CliOptions): Promise<void> {
  if (options.mode === 'interactive') {
    await runInteractiveMode();
  } else {
    if (!options.prompt) {
      console.error('Error: prompt is required for single mode');
      process.exit(1);
    }
    await runSingleMode(options.prompt, options.workflow);
  }
}

/**
 * 인터랙티브 모드
 */
async function runInteractiveMode(): Promise<void> {
  console.log(`
╔════════════════════════════════════════════════════════════╗
║           그누보드7 Multi-Agent System v1.0.0                     ║
║                                                            ║
║  사용 가능한 명령어:                                       ║
║    /feature <설명>  - 기능 개발 워크플로우                 ║
║    /bugfix <설명>   - 버그 수정 워크플로우                 ║
║    /review <PR#>    - PR 검수 워크플로우                   ║
║    /help            - 도움말                               ║
║    /exit            - 종료                                 ║
║                                                            ║
║  또는 자유롭게 요청을 입력하세요.                          ║
╚════════════════════════════════════════════════════════════╝
`);

  const rl = readline.createInterface({
    input: process.stdin,
    output: process.stdout,
  });

  const coordinator = new Coordinator({ cwd: PROJECT_ROOT });

  const promptUser = () => {
    rl.question('\n🤖 > ', async (input) => {
      const trimmedInput = input.trim();

      if (!trimmedInput) {
        promptUser();
        return;
      }

      // 종료 명령
      if (trimmedInput === '/exit' || trimmedInput === 'exit') {
        console.log('\n👋 안녕히 가세요!');
        rl.close();
        return;
      }

      // 도움말
      if (trimmedInput === '/help') {
        showHelp();
        promptUser();
        return;
      }

      try {
        let result: WorkflowResult;

        // 워크플로우 명령어
        if (trimmedInput.startsWith('/feature ')) {
          const description = trimmedInput.substring(9);
          console.log('\n🚀 기능 개발 워크플로우를 시작합니다...\n');
          result = await featureDevelopmentWorkflow(description, { cwd: PROJECT_ROOT });

        } else if (trimmedInput.startsWith('/bugfix ')) {
          const description = trimmedInput.substring(8);
          console.log('\n🔧 버그 수정 워크플로우를 시작합니다...\n');
          result = await bugfixWorkflow(description, { cwd: PROJECT_ROOT });

        } else if (trimmedInput.startsWith('/review ')) {
          const prId = trimmedInput.substring(8);
          console.log('\n🔍 PR 검수 워크플로우를 시작합니다...\n');
          result = await prReviewWorkflow(prId, { cwd: PROJECT_ROOT });

        } else {
          // 자유 형식 요청
          console.log('\n🤔 요청을 처리하고 있습니다...\n');
          result = await coordinator.orchestrate(trimmedInput);
        }

        // 결과 출력
        printResult(result);

      } catch (error: any) {
        console.error(`\n❌ 오류: ${error.message}`);
      }

      promptUser();
    });
  };

  promptUser();
}

/**
 * 단일 명령어 모드
 */
async function runSingleMode(
  prompt: string,
  workflow?: WorkflowType
): Promise<void> {
  console.log(`\n🤖 그누보드7 Multi-Agent System\n`);

  let result: WorkflowResult;

  try {
    if (workflow === 'feature') {
      console.log('🚀 기능 개발 워크플로우 실행\n');
      result = await featureDevelopmentWorkflow(prompt, { cwd: PROJECT_ROOT });

    } else if (workflow === 'bugfix') {
      console.log('🔧 버그 수정 워크플로우 실행\n');
      result = await bugfixWorkflow(prompt, { cwd: PROJECT_ROOT });

    } else if (workflow === 'pr-review') {
      console.log('🔍 PR 검수 워크플로우 실행\n');
      result = await prReviewWorkflow(prompt, { cwd: PROJECT_ROOT });

    } else {
      console.log('🤔 요청 처리 중...\n');
      const coordinator = new Coordinator({ cwd: PROJECT_ROOT });
      result = await coordinator.orchestrate(prompt);
    }

    printResult(result);

    // 종료 코드 설정
    process.exit(result.success ? 0 : 1);

  } catch (error: any) {
    console.error(`\n❌ 오류: ${error.message}`);
    process.exit(1);
  }
}

/**
 * 결과 출력
 */
function printResult(result: WorkflowResult): void {
  console.log('\n' + '='.repeat(60));

  if (result.success) {
    console.log('✅ 작업 완료');
  } else {
    console.log('❌ 작업 실패');
  }

  if (result.output) {
    console.log('\n📝 결과:');
    console.log(result.output);
  }

  if (result.errors && result.errors.length > 0) {
    console.log('\n⚠️ 오류:');
    result.errors.forEach(e => console.log(`  - ${e}`));
  }

  if (result.steps.length > 0) {
    console.log('\n📊 실행 단계:');
    result.steps.forEach(step => {
      const icon = step.status === 'completed' ? '✓' : step.status === 'failed' ? '✗' : '○';
      console.log(`  ${icon} [${step.agent}] ${step.task}`);
    });
  }

  console.log(`\n💰 비용: $${result.totalCost.toFixed(4)}`);
  console.log('='.repeat(60));
}

/**
 * 도움말 출력
 */
function showHelp(): void {
  console.log(`
📖 그누보드7 Multi-Agent System 도움말

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

🔧 워크플로우 명령어:

  /feature <설명>
    새로운 기능을 개발합니다.
    예: /feature 상품 할인 기능 추가

  /bugfix <설명>
    버그를 수정합니다. TDD 방식으로 진행됩니다.
    예: /bugfix Form 저장 시 데이터가 사라지는 문제

  /review <PR번호 또는 설명>
    PR을 검수합니다.
    예: /review #123
    예: /review 현재 브랜치 변경사항

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

🤖 에이전트:

  • backend   - 백엔드 개발 (Service, Repository, Controller)
  • frontend  - 프론트엔드 개발 (TSX 컴포넌트)
  • layout    - 레이아웃 개발 (JSON 스키마)
  • template  - 템플릿 개발 (빌드, 등록)
  • reviewer  - 코드 검수 (규정, 테스트)

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

📚 자유 형식 요청:

  워크플로우 명령어 외에도 자유롭게 요청할 수 있습니다.
  코디네이터가 적절한 에이전트에게 작업을 분배합니다.

  예:
    - "ProductService의 할인 로직을 설명해줘"
    - "사용자 목록 페이지의 레이아웃 JSON을 검토해줘"
    - "DataGrid 컴포넌트에 정렬 기능을 추가해줘"

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

⌨️ 기타 명령어:

  /help     - 이 도움말 표시
  /exit     - 종료

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
`);
}

export default runCli;
