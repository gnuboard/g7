/**
 * 코디네이터
 * 작업 분배 및 에이전트 오케스트레이션 담당
 */
import { query, type Options, type SDKMessage } from '@anthropic-ai/claude-agent-sdk';
import { COORDINATOR_PROMPT } from '../prompts/index.js';
import { agentDefinitions } from '../agents/index.js';
import { createG7McpServer } from '../mcp/g7-tools-server.js';
import { logger } from '../utils/logger.js';
import type { CoordinatorConfig, WorkflowResult, WorkflowStep } from '../types/index.js';

export class Coordinator {
  private cwd: string;
  private model: 'opus' | 'sonnet';
  private mcpServer: ReturnType<typeof createG7McpServer>;

  constructor(config: CoordinatorConfig = {}) {
    this.cwd = config.cwd || process.env.G7_PROJECT_ROOT || '.';
    this.model = config.model || 'sonnet';
    this.mcpServer = createG7McpServer(this.cwd);

    logger.info(`Coordinator initialized with cwd: ${this.cwd}`);
  }

  /**
   * 사용자 요청을 분석하고 적절한 에이전트에게 작업 분배
   */
  async orchestrate(userRequest: string): Promise<WorkflowResult> {
    logger.info('Starting orchestration');
    logger.debug(`User request: ${userRequest}`);

    const result: WorkflowResult = {
      success: false,
      steps: [],
      totalCost: 0,
    };

    const options: Options = {
      cwd: this.cwd,
      model: this.model,
      systemPrompt: COORDINATOR_PROMPT,
      tools: { type: 'preset', preset: 'claude_code' },
      allowedTools: [
        'Read', 'Write', 'Edit', 'Glob', 'Grep', 'Bash', 'Task',
        // MCP 도구
        'validate-code', 'validate-frontend', 'run-tests',
        'run-test-file', 'search-docs', 'read-doc', 'list-docs',
      ],
      agents: agentDefinitions,
      mcpServers: {
        'g7-tools': this.mcpServer,
      },
      settingSources: ['project'], // AGENTS.md 로드
      permissionMode: 'acceptEdits',
      maxTurns: 50,
      hooks: this.getHooks(result),
    };

    try {
      const queryResult = query({
        prompt: userRequest,
        options,
      });

      for await (const message of queryResult) {
        this.processMessage(message, result);
      }

      // 최종 결과 처리
      const finalResult = result.steps.find(s => s.status === 'completed' && s.agent === 'coordinator');
      if (finalResult?.result) {
        result.output = finalResult.result.output;
      }

      result.success = result.steps.every(s => s.status === 'completed');

    } catch (error: any) {
      logger.error(`Orchestration failed: ${error.message}`);
      result.success = false;
      result.errors = [error.message];
    }

    return result;
  }

  /**
   * 특정 에이전트에게 직접 작업 요청
   */
  async delegateToAgent(
    agentType: keyof typeof agentDefinitions,
    task: string
  ): Promise<WorkflowResult> {
    logger.info(`Delegating to ${agentType}: ${task}`);

    const agent = agentDefinitions[agentType];
    if (!agent) {
      return {
        success: false,
        steps: [],
        totalCost: 0,
        errors: [`Unknown agent: ${agentType}`],
      };
    }

    const result: WorkflowResult = {
      success: false,
      steps: [],
      totalCost: 0,
    };

    const options: Options = {
      cwd: this.cwd,
      model: agent.model || 'sonnet',
      systemPrompt: agent.prompt,
      tools: { type: 'preset', preset: 'claude_code' },
      allowedTools: [
        ...(agent.tools || []),
        'validate-code', 'validate-frontend', 'run-tests',
        'search-docs', 'read-doc',
      ],
      mcpServers: {
        'g7-tools': this.mcpServer,
      },
      settingSources: ['project'],
      permissionMode: 'acceptEdits',
      maxTurns: 30,
      hooks: this.getHooks(result),
    };

    try {
      const queryResult = query({
        prompt: task,
        options,
      });

      for await (const message of queryResult) {
        this.processMessage(message, result);
      }

      result.success = !result.errors?.length;

    } catch (error: any) {
      logger.error(`Agent ${agentType} failed: ${error.message}`);
      result.success = false;
      result.errors = [error.message];
    }

    return result;
  }

  /**
   * 훅 설정
   */
  private getHooks(result: WorkflowResult): Options['hooks'] {
    return {
      SubagentStart: [{
        hooks: [async (input) => {
          // SubagentStartHookInput 타입 처리
          if (input.hook_event_name === 'SubagentStart') {
            const step: WorkflowStep = {
              agent: input.agent_type as any,
              task: 'Subagent task',
              status: 'running',
            };
            result.steps.push(step);

            logger.info(`Subagent started: ${input.agent_type}`);
          }
          return { continue: true };
        }],
      }],

      SubagentStop: [{
        hooks: [async () => {
          const runningStep = result.steps.find(s => s.status === 'running');
          if (runningStep) {
            runningStep.status = 'completed';
          }

          logger.info('Subagent completed');
          return { continue: true };
        }],
      }],

      PreToolUse: [{
        hooks: [async (input) => {
          // PreToolUseHookInput 타입 처리
          if (input.hook_event_name === 'PreToolUse') {
            logger.debug(`Tool use: ${input.tool_name}`);
          }
          return { continue: true };
        }],
      }],

      PostToolUse: [{
        hooks: [async (input) => {
          // PostToolUseHookInput 타입 처리
          if (input.hook_event_name === 'PostToolUse') {
            // 테스트 실패 시 로깅
            if (input.tool_name === 'run-tests') {
              const output = (input.tool_response as any)?.content?.[0]?.text || '';
              if (output.includes('failed')) {
                logger.warn('Some tests failed');
              }
            }
          }
          return { continue: true };
        }],
      }],
    };
  }

  /**
   * 메시지 처리
   */
  private processMessage(message: SDKMessage, result: WorkflowResult): void {
    if (message.type === 'result') {
      if (message.subtype === 'success') {
        result.totalCost = message.total_cost_usd || 0;
        result.output = message.result;

        // 코디네이터 결과 기록
        result.steps.push({
          agent: 'coordinator' as any,
          task: 'Orchestration',
          status: 'completed',
          result: {
            success: true,
            agent: 'coordinator',
            task: 'Orchestration',
            output: message.result,
            cost: message.total_cost_usd,
          },
        });

      } else if (
        message.subtype === 'error_during_execution' ||
        message.subtype === 'error_max_turns' ||
        message.subtype === 'error_max_budget_usd' ||
        message.subtype === 'error_max_structured_output_retries'
      ) {
        result.errors = message.errors || ['Unknown error'];
      }
    }
  }
}
