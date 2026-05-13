/**
 * 그누보드7 Multi-Agent System
 * AI Agent SDK 기반 멀티에이전트 협업 시스템
 */
import 'dotenv/config';
import { runCli } from './cli.js';
import type { CliOptions, WorkflowType } from './types/index.js';

async function main(): Promise<void> {
  const args = process.argv.slice(2);

  // 인수 파싱
  const options = parseArgs(args);

  // CLI 실행
  await runCli(options);
}

/**
 * 명령줄 인수 파싱
 */
function parseArgs(args: string[]): CliOptions {
  // 기본값
  const options: CliOptions = {
    mode: 'interactive',
  };

  if (args.length === 0) {
    return options;
  }

  const mode = args[0];

  // 모드 확인
  if (mode === 'interactive') {
    options.mode = 'interactive';
    return options;
  }

  if (mode === 'single') {
    options.mode = 'single';

    // 워크플로우 옵션 확인
    const workflowIndex = args.indexOf('--workflow');
    if (workflowIndex !== -1 && args[workflowIndex + 1]) {
      options.workflow = args[workflowIndex + 1] as WorkflowType;
      // 워크플로우 옵션 제거
      args.splice(workflowIndex, 2);
    }

    // 나머지를 프롬프트로 사용
    options.prompt = args.slice(1).join(' ');
    return options;
  }

  // 단축 워크플로우 명령어
  if (mode === 'feature' || mode === 'bugfix' || mode === 'pr-review') {
    options.mode = 'single';
    options.workflow = mode as WorkflowType;
    options.prompt = args.slice(1).join(' ');
    return options;
  }

  // 기본: 단일 모드로 전체 인수를 프롬프트로 사용
  options.mode = 'single';
  options.prompt = args.join(' ');
  return options;
}

// 에러 핸들링
process.on('unhandledRejection', (reason, promise) => {
  console.error('Unhandled Rejection:', reason);
  process.exit(1);
});

process.on('uncaughtException', (error) => {
  console.error('Uncaught Exception:', error);
  process.exit(1);
});

// 실행
main().catch((error) => {
  console.error('Fatal error:', error);
  process.exit(1);
});
