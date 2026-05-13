#!/usr/bin/env node
/**
 * 그누보드7 에이전트 MCP 서버 진입점
 * AI 코딩 도구에서 멀티에이전트 시스템을 호출할 수 있는 MCP 서버
 *
 * 사용법:
 *   .mcp.json에 등록하여 AI 코딩 도구에서 자동으로 호출
 *
 * 환경 변수:
 *   G7_PROJECT_ROOT: 프로젝트 루트 경로 (기본: .)
 *   ANTHROPIC_API_KEY: Anthropic API 키 (필수)
 *   LOG_LEVEL: 로그 레벨 (기본: info)
 */
import 'dotenv/config';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z } from 'zod';
import { Coordinator } from '../coordinator/Coordinator.js';
import { featureDevelopmentWorkflow, simpleFeatureWorkflow } from '../workflows/feature.js';
import { bugfixWorkflow, stateRelatedBugfix } from '../workflows/bugfix.js';
import { prReviewWorkflow } from '../workflows/pr-review.js';
import { logger, createWorkflowLogger } from '../utils/logger.js';

const workflowLogger = createWorkflowLogger('mcp-agents');

// 프로젝트 루트 설정
const projectRoot = process.env.G7_PROJECT_ROOT || '.';

// API 키 확인
if (!process.env.ANTHROPIC_API_KEY) {
  logger.warn('ANTHROPIC_API_KEY가 설정되지 않았습니다. 에이전트 호출 시 오류가 발생할 수 있습니다.');
}

// MCP 서버 생성
const server = new McpServer({
  name: 'g7-agents',
  version: '1.0.0',
});

/**
 * 워크플로우 결과 포맷팅
 */
function formatWorkflowResult(result: any): string {
  const lines: string[] = [];

  if (result.success) {
    lines.push('## ✅ 작업 완료\n');
  } else {
    lines.push('## ❌ 작업 실패\n');
  }

  if (result.steps && result.steps.length > 0) {
    lines.push('### 실행 단계');
    for (const step of result.steps) {
      const statusIcon = step.status === 'completed' ? '✅' :
                         step.status === 'failed' ? '❌' :
                         step.status === 'running' ? '🔄' : '⏳';
      lines.push(`${statusIcon} [${step.agent}] ${step.task}`);

      if (step.result?.filesModified && step.result.filesModified.length > 0) {
        lines.push(`   수정된 파일: ${step.result.filesModified.join(', ')}`);
      }
    }
    lines.push('');
  }

  if (result.output) {
    lines.push('### 결과');
    lines.push(result.output);
    lines.push('');
  }

  if (result.errors && result.errors.length > 0) {
    lines.push('### 오류');
    for (const error of result.errors) {
      lines.push(`- ${error}`);
    }
    lines.push('');
  }

  if (result.totalCost && result.totalCost > 0) {
    lines.push(`### 비용: $${result.totalCost.toFixed(4)}`);
  }

  return lines.join('\n');
}

// Tool: orchestrate - 자동 분배
server.tool(
  'orchestrate',
  '사용자 요청을 분석하여 적절한 에이전트(backend, frontend, layout, template, reviewer)에 자동 분배합니다.',
  {
    prompt: z.string().describe('사용자 요청 내용'),
  },
  async ({ prompt }) => {
    workflowLogger.info(`Orchestrating: ${prompt}`);

    try {
      const coordinator = new Coordinator({ cwd: projectRoot, model: 'sonnet' });
      const result = await coordinator.orchestrate(prompt);

      return {
        content: [{ type: 'text', text: formatWorkflowResult(result) }],
        isError: !result.success,
      };
    } catch (error: any) {
      workflowLogger.error(`Orchestration failed: ${error.message}`);
      return {
        content: [{ type: 'text', text: `❌ 오케스트레이션 실패: ${error.message}` }],
        isError: true,
      };
    }
  }
);

// Tool: feature-workflow - 기능 개발
server.tool(
  'feature-workflow',
  '기능 개발 워크플로우를 실행합니다. Backend → Layout → Frontend → Template → Reviewer 순서로 에이전트가 협업합니다.',
  {
    description: z.string().describe('개발할 기능 설명'),
    skipReview: z.boolean().optional().describe('리뷰 단계 생략 여부 (기본: false)'),
  },
  async ({ description, skipReview }) => {
    workflowLogger.info(`Feature workflow: ${description}`);

    try {
      const result = await featureDevelopmentWorkflow(description, {
        cwd: projectRoot,
        skipReview: skipReview ?? false,
      });

      return {
        content: [{ type: 'text', text: formatWorkflowResult(result) }],
        isError: !result.success,
      };
    } catch (error: any) {
      workflowLogger.error(`Feature workflow failed: ${error.message}`);
      return {
        content: [{ type: 'text', text: `❌ 기능 개발 워크플로우 실패: ${error.message}` }],
        isError: true,
      };
    }
  }
);

// Tool: simple-feature - 단일 영역 기능 개발
server.tool(
  'simple-feature',
  '단일 영역의 간단한 기능을 개발합니다.',
  {
    description: z.string().describe('개발할 기능 설명'),
    area: z.enum(['backend', 'frontend', 'layout', 'template']).describe('기능이 속한 영역'),
  },
  async ({ description, area }) => {
    workflowLogger.info(`Simple feature (${area}): ${description}`);

    try {
      const result = await simpleFeatureWorkflow(description, {
        cwd: projectRoot,
        area,
      });

      return {
        content: [{ type: 'text', text: formatWorkflowResult(result) }],
        isError: !result.success,
      };
    } catch (error: any) {
      workflowLogger.error(`Simple feature failed: ${error.message}`);
      return {
        content: [{ type: 'text', text: `❌ 간단한 기능 개발 실패: ${error.message}` }],
        isError: true,
      };
    }
  }
);

// Tool: bugfix-workflow - 버그 수정
server.tool(
  'bugfix-workflow',
  '버그 수정 워크플로우를 실행합니다. 트러블슈팅 가이드를 참조하고 TDD 방식으로 수정합니다.',
  {
    description: z.string().describe('버그 설명'),
    affectedAreas: z.array(z.enum(['backend', 'frontend', 'layout', 'template', 'unknown'])).optional()
      .describe('영향 받는 영역 (지정하지 않으면 자동 분석)'),
  },
  async ({ description, affectedAreas }) => {
    workflowLogger.info(`Bugfix workflow: ${description}`);

    try {
      const result = await bugfixWorkflow(description, {
        cwd: projectRoot,
        affectedAreas: affectedAreas as any,
      });

      return {
        content: [{ type: 'text', text: formatWorkflowResult(result) }],
        isError: !result.success,
      };
    } catch (error: any) {
      workflowLogger.error(`Bugfix workflow failed: ${error.message}`);
      return {
        content: [{ type: 'text', text: `❌ 버그 수정 워크플로우 실패: ${error.message}` }],
        isError: true,
      };
    }
  }
);

// Tool: state-bugfix - 상태 관련 버그 수정
server.tool(
  'state-bugfix',
  '상태 관리 관련 버그를 수정합니다. Stale Closure, setState 병합, 타이밍 이슈 등에 특화되어 있습니다.',
  {
    description: z.string().describe('상태 관련 버그 설명'),
  },
  async ({ description }) => {
    workflowLogger.info(`State-related bugfix: ${description}`);

    try {
      const result = await stateRelatedBugfix(description, {
        cwd: projectRoot,
      });

      return {
        content: [{ type: 'text', text: formatWorkflowResult(result) }],
        isError: !result.success,
      };
    } catch (error: any) {
      workflowLogger.error(`State bugfix failed: ${error.message}`);
      return {
        content: [{ type: 'text', text: `❌ 상태 버그 수정 실패: ${error.message}` }],
        isError: true,
      };
    }
  }
);

// Tool: pr-review - PR 검수
server.tool(
  'pr-review',
  'PR을 검수합니다. 변경된 파일 유형에 따라 적절한 에이전트가 검수합니다.',
  {
    prIdentifier: z.string().describe('PR 번호 또는 브랜치명'),
  },
  async ({ prIdentifier }) => {
    workflowLogger.info(`PR review: ${prIdentifier}`);

    try {
      const result = await prReviewWorkflow(prIdentifier, {
        cwd: projectRoot,
      });

      return {
        content: [{ type: 'text', text: formatWorkflowResult(result) }],
        isError: !result.success,
      };
    } catch (error: any) {
      workflowLogger.error(`PR review failed: ${error.message}`);
      return {
        content: [{ type: 'text', text: `❌ PR 검수 실패: ${error.message}` }],
        isError: true,
      };
    }
  }
);

// Tool: delegate - 특정 에이전트 직접 호출
server.tool(
  'delegate',
  '특정 에이전트에 직접 작업을 위임합니다.',
  {
    agent: z.enum(['backend', 'frontend', 'layout', 'template', 'reviewer']).describe('호출할 에이전트'),
    task: z.string().describe('수행할 작업 내용'),
  },
  async ({ agent, task }) => {
    workflowLogger.info(`Delegating to ${agent}: ${task}`);

    try {
      const coordinator = new Coordinator({ cwd: projectRoot, model: 'sonnet' });
      const result = await coordinator.delegateToAgent(agent, task);

      return {
        content: [{ type: 'text', text: formatWorkflowResult(result) }],
        isError: !result.success,
      };
    } catch (error: any) {
      workflowLogger.error(`Delegation failed: ${error.message}`);
      return {
        content: [{ type: 'text', text: `❌ 에이전트 호출 실패: ${error.message}` }],
        isError: true,
      };
    }
  }
);

// 서버 시작
async function main() {
  logger.info(`그누보드7 에이전트 MCP 서버 시작 (프로젝트: ${projectRoot})`);

  const transport = new StdioServerTransport();
  await server.connect(transport);

  logger.info('MCP 서버가 stdio 모드로 실행 중입니다.');
}

main().catch((error) => {
  logger.error(`MCP 서버 시작 실패: ${error.message}`);
  process.exit(1);
});
