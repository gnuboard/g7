#!/usr/bin/env node
/**
 * 인터랙티브 CLI - 단계별 승인 기능
 * 각 에이전트 실행 전 사용자 확인을 받고 진행
 */
import 'dotenv/config';
import * as readline from 'readline';
import Anthropic from '@anthropic-ai/sdk';
import { agentDefinitions } from './agents/definitions.js';
import type { WorkflowResult, WorkflowStep, AgentType, AgentResult } from './types/index.js';

const PROJECT_ROOT = process.env.G7_PROJECT_ROOT || '.';

// 색상 코드
const colors = {
  reset: '\x1b[0m',
  bright: '\x1b[1m',
  dim: '\x1b[2m',
  red: '\x1b[31m',
  green: '\x1b[32m',
  yellow: '\x1b[33m',
  blue: '\x1b[34m',
  magenta: '\x1b[35m',
  cyan: '\x1b[36m',
  white: '\x1b[37m',
  bgBlue: '\x1b[44m',
  bgGreen: '\x1b[42m',
  bgYellow: '\x1b[43m',
  bgRed: '\x1b[41m',
};

// 에이전트별 색상
const agentColors: Record<string, string> = {
  backend: colors.blue,
  frontend: colors.magenta,
  layout: colors.cyan,
  template: colors.yellow,
  reviewer: colors.green,
  coordinator: colors.white,
};

/**
 * 인터랙티브 세션 클래스
 */
class InteractiveSession {
  private rl: readline.Interface;
  private client: Anthropic;
  private result: WorkflowResult;
  private currentStep: number = 0;

  constructor() {
    this.rl = readline.createInterface({
      input: process.stdin,
      output: process.stdout,
    });

    this.client = new Anthropic({
      apiKey: process.env.ANTHROPIC_API_KEY,
    });

    this.result = {
      success: false,
      steps: [],
      totalCost: 0,
    };
  }

  /**
   * 세션 시작
   */
  async start(): Promise<void> {
    this.printBanner();
    this.promptUser();
  }

  /**
   * 배너 출력
   */
  private printBanner(): void {
    console.log(`
${colors.bgBlue}${colors.bright}                                                              ${colors.reset}
${colors.bgBlue}${colors.bright}       그누보드7 Multi-Agent Interactive System v1.0.0              ${colors.reset}
${colors.bgBlue}${colors.bright}                                                              ${colors.reset}

${colors.cyan}사용 가능한 명령어:${colors.reset}
  ${colors.yellow}/feature${colors.reset} <설명>  - 기능 개발 (단계별 승인)
  ${colors.yellow}/bugfix${colors.reset} <설명>   - 버그 수정 (단계별 승인)
  ${colors.yellow}/quick${colors.reset} <요청>    - 빠른 실행 (승인 없이)
  ${colors.yellow}/agents${colors.reset}          - 에이전트 목록
  ${colors.yellow}/help${colors.reset}            - 도움말
  ${colors.yellow}/exit${colors.reset}            - 종료

${colors.dim}또는 자유롭게 요청을 입력하세요. (단계별 승인 모드)${colors.reset}
`);
  }

  /**
   * 사용자 입력 대기
   */
  private promptUser(): void {
    this.rl.question(`\n${colors.green}🤖 >${colors.reset} `, async (input) => {
      const trimmedInput = input.trim();

      if (!trimmedInput) {
        this.promptUser();
        return;
      }

      await this.handleInput(trimmedInput);
    });
  }

  /**
   * 입력 처리
   */
  private async handleInput(input: string): Promise<void> {
    // 종료
    if (input === '/exit' || input === 'exit') {
      console.log(`\n${colors.cyan}👋 안녕히 가세요!${colors.reset}`);
      this.rl.close();
      return;
    }

    // 도움말
    if (input === '/help') {
      this.showHelp();
      this.promptUser();
      return;
    }

    // 에이전트 목록
    if (input === '/agents') {
      this.showAgents();
      this.promptUser();
      return;
    }

    // 기능 개발
    if (input.startsWith('/feature ')) {
      const description = input.substring(9);
      await this.runFeatureWorkflow(description);
      this.promptUser();
      return;
    }

    // 버그 수정
    if (input.startsWith('/bugfix ')) {
      const description = input.substring(8);
      await this.runBugfixWorkflow(description);
      this.promptUser();
      return;
    }

    // 빠른 실행 (승인 없이)
    if (input.startsWith('/quick ')) {
      const prompt = input.substring(7);
      await this.runQuickMode(prompt);
      this.promptUser();
      return;
    }

    // 자유 형식 (단계별 승인)
    await this.runWithApproval(input);
    this.promptUser();
  }

  /**
   * 기능 개발 워크플로우 (단계별 승인)
   */
  private async runFeatureWorkflow(description: string): Promise<void> {
    console.log(`\n${colors.bgGreen}${colors.bright} 기능 개발 워크플로우 ${colors.reset}`);
    console.log(`${colors.dim}설명: ${description}${colors.reset}\n`);

    this.result = { success: false, steps: [], totalCost: 0 };

    // 워크플로우 단계 정의
    const workflow: Array<{ agent: AgentType; task: string }> = [
      { agent: 'backend', task: `백엔드 구현: ${description}` },
      { agent: 'layout', task: `레이아웃 JSON 작성: ${description}` },
      { agent: 'frontend', task: `프론트엔드 컴포넌트 개발 (필요시): ${description}` },
      { agent: 'template', task: `템플릿 빌드 및 컴포넌트 등록: ${description}` },
      { agent: 'reviewer', task: `코드 검수: ${description}` },
    ];

    // 워크플로우 개요 표시
    this.printWorkflowPlan(workflow);

    // 각 단계 실행
    for (let i = 0; i < workflow.length; i++) {
      const step = workflow[i];
      const approved = await this.requestApproval(i + 1, workflow.length, step.agent, step.task);

      if (!approved) {
        console.log(`\n${colors.yellow}⏭️  ${step.agent} 단계를 건너뜁니다.${colors.reset}`);
        this.result.steps.push({
          agent: step.agent,
          task: step.task,
          status: 'skipped',
        });
        continue;
      }

      await this.executeAgent(step.agent, step.task);
    }

    this.printFinalResult();
  }

  /**
   * 버그 수정 워크플로우 (단계별 승인)
   */
  private async runBugfixWorkflow(description: string): Promise<void> {
    console.log(`\n${colors.bgYellow}${colors.bright} 버그 수정 워크플로우 ${colors.reset}`);
    console.log(`${colors.dim}설명: ${description}${colors.reset}\n`);

    this.result = { success: false, steps: [], totalCost: 0 };

    // 1단계: 분석
    console.log(`${colors.cyan}📋 버그 영향 영역을 분석합니다...${colors.reset}\n`);

    const analysisPrompt = `
다음 버그 설명을 분석하고, 영향 받는 영역을 판단해주세요:

버그 설명: ${description}

그누보드7 프로젝트의 영역:
- backend: Service, Repository, Controller, FormRequest 등 PHP 코드
- frontend: TSX 컴포넌트, React 코드
- layout: 레이아웃 JSON 파일
- template: 템플릿 설정, 빌드

어떤 영역이 영향 받는지 알려주세요. 답변 형식:
영향 영역: [backend/frontend/layout/template] (쉼표로 구분)
분석 근거: [간단한 설명]
`;

    const analysis = await this.callClaude(analysisPrompt);
    console.log(`${colors.dim}${analysis}${colors.reset}\n`);

    // 영역 추출 (간단한 파싱)
    const affectedAreas: AgentType[] = [];
    const lowerAnalysis = analysis.toLowerCase();
    if (lowerAnalysis.includes('backend')) affectedAreas.push('backend');
    if (lowerAnalysis.includes('frontend')) affectedAreas.push('frontend');
    if (lowerAnalysis.includes('layout')) affectedAreas.push('layout');
    if (lowerAnalysis.includes('template')) affectedAreas.push('template');

    if (affectedAreas.length === 0) {
      affectedAreas.push('backend'); // 기본값
    }

    // 워크플로우 구성
    const workflow: Array<{ agent: AgentType; task: string }> = [];

    // 트러블슈팅 가이드 참조 단계
    workflow.push({
      agent: 'reviewer',
      task: `트러블슈팅 가이드에서 유사 사례 확인: ${description}`,
    });

    // 영향 받는 영역별 수정
    for (const area of affectedAreas) {
      workflow.push({
        agent: area,
        task: `버그 수정 (TDD 방식): ${description}`,
      });
    }

    // 최종 검수
    workflow.push({
      agent: 'reviewer',
      task: `테스트 통과 확인 및 최종 검수: ${description}`,
    });

    // 워크플로우 개요 표시
    this.printWorkflowPlan(workflow);

    // 각 단계 실행
    for (let i = 0; i < workflow.length; i++) {
      const step = workflow[i];
      const approved = await this.requestApproval(i + 1, workflow.length, step.agent, step.task);

      if (!approved) {
        console.log(`\n${colors.yellow}⏭️  ${step.agent} 단계를 건너뜁니다.${colors.reset}`);
        this.result.steps.push({
          agent: step.agent,
          task: step.task,
          status: 'skipped',
        });
        continue;
      }

      await this.executeAgent(step.agent, step.task);
    }

    this.printFinalResult();
  }

  /**
   * 단계별 승인 모드로 자유 형식 요청 실행
   */
  private async runWithApproval(prompt: string): Promise<void> {
    console.log(`\n${colors.bgBlue}${colors.bright} 요청 처리 ${colors.reset}`);
    console.log(`${colors.dim}${prompt}${colors.reset}\n`);

    this.result = { success: false, steps: [], totalCost: 0 };

    // 요청 분석
    console.log(`${colors.cyan}📋 요청을 분석하고 워크플로우를 계획합니다...${colors.reset}\n`);

    const planPrompt = `
다음 요청을 분석하고 실행 계획을 세워주세요:

요청: ${prompt}

그누보드7 프로젝트의 에이전트:
- backend: 백엔드 개발 (Service, Repository, Controller, FormRequest, Migration)
- frontend: 프론트엔드 개발 (TSX 컴포넌트, 상태 관리)
- layout: 레이아웃 개발 (JSON 스키마, 데이터 바인딩)
- template: 템플릿 개발 (빌드, 컴포넌트 등록)
- reviewer: 코드 검수 (규정 준수, 테스트)

어떤 에이전트가 어떤 순서로 작업해야 하는지 계획해주세요.

답변 형식 (JSON):
{
  "steps": [
    { "agent": "backend", "task": "구체적인 작업 내용" },
    { "agent": "layout", "task": "구체적인 작업 내용" }
  ]
}
`;

    const planResponse = await this.callClaude(planPrompt);

    // JSON 파싱 시도
    let workflow: Array<{ agent: AgentType; task: string }> = [];
    try {
      const jsonMatch = planResponse.match(/\{[\s\S]*\}/);
      if (jsonMatch) {
        const plan = JSON.parse(jsonMatch[0]);
        workflow = plan.steps || [];
      }
    } catch {
      // 파싱 실패 시 기본 워크플로우
      console.log(`${colors.dim}${planResponse}${colors.reset}\n`);
      workflow = [{ agent: 'backend', task: prompt }];
    }

    if (workflow.length === 0) {
      workflow = [{ agent: 'backend', task: prompt }];
    }

    // 워크플로우 개요 표시
    this.printWorkflowPlan(workflow);

    // 각 단계 실행
    for (let i = 0; i < workflow.length; i++) {
      const step = workflow[i];
      const validAgent = this.validateAgent(step.agent);
      const approved = await this.requestApproval(i + 1, workflow.length, validAgent, step.task);

      if (!approved) {
        console.log(`\n${colors.yellow}⏭️  ${validAgent} 단계를 건너뜁니다.${colors.reset}`);
        this.result.steps.push({
          agent: validAgent,
          task: step.task,
          status: 'skipped',
        });
        continue;
      }

      await this.executeAgent(validAgent, step.task);
    }

    this.printFinalResult();
  }

  /**
   * 빠른 모드 (승인 없이)
   */
  private async runQuickMode(prompt: string): Promise<void> {
    console.log(`\n${colors.bgBlue}${colors.bright} 빠른 실행 ${colors.reset}`);
    console.log(`${colors.dim}승인 없이 자동으로 실행됩니다.${colors.reset}\n`);

    this.result = { success: false, steps: [], totalCost: 0 };

    // 단일 에이전트로 빠른 실행
    await this.executeAgent('backend', prompt);
    this.printFinalResult();
  }

  /**
   * 워크플로우 계획 출력
   */
  private printWorkflowPlan(workflow: Array<{ agent: AgentType; task: string }>): void {
    console.log(`${colors.bright}📊 실행 계획:${colors.reset}\n`);

    workflow.forEach((step, index) => {
      const agentColor = agentColors[step.agent] || colors.white;
      console.log(`  ${colors.dim}${index + 1}.${colors.reset} ${agentColor}[${step.agent}]${colors.reset} ${step.task}`);
    });

    console.log('');
  }

  /**
   * 승인 요청
   */
  private async requestApproval(
    current: number,
    total: number,
    agent: AgentType,
    task: string
  ): Promise<boolean> {
    const agentColor = agentColors[agent] || colors.white;

    console.log(`\n${colors.bright}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${colors.reset}`);
    console.log(`${colors.bright}단계 ${current}/${total}${colors.reset}`);
    console.log(`${colors.bright}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${colors.reset}`);
    console.log(`\n${agentColor}🤖 에이전트: ${agent}${colors.reset}`);
    console.log(`${colors.dim}📋 작업: ${task}${colors.reset}\n`);

    // 에이전트 설명 표시
    const agentDef = agentDefinitions[agent];
    if (agentDef) {
      console.log(`${colors.dim}ℹ️  ${agentDef.description}${colors.reset}\n`);
    }

    return new Promise((resolve) => {
      this.rl.question(
        `${colors.yellow}실행하시겠습니까? ${colors.reset}[${colors.green}Y${colors.reset}es/${colors.red}N${colors.reset}o/${colors.cyan}S${colors.reset}kip all] `,
        (answer) => {
          const lowerAnswer = answer.toLowerCase().trim();

          if (lowerAnswer === 'n' || lowerAnswer === 'no') {
            resolve(false);
          } else if (lowerAnswer === 's' || lowerAnswer === 'skip') {
            // Skip all은 모든 후속 단계를 건너뜀
            resolve(false);
          } else {
            resolve(true);
          }
        }
      );
    });
  }

  /**
   * 에이전트 실행
   */
  private async executeAgent(agent: AgentType, task: string): Promise<void> {
    const agentColor = agentColors[agent] || colors.white;
    const agentDef = agentDefinitions[agent];

    console.log(`\n${agentColor}▶ ${agent} 에이전트 실행 중...${colors.reset}\n`);

    const step: WorkflowStep = {
      agent,
      task,
      status: 'running',
    };
    this.result.steps.push(step);

    try {
      // 에이전트 시스템 프롬프트 + 작업
      const systemPrompt = agentDef?.prompt || '';
      const fullPrompt = `
${systemPrompt}

## 현재 작업
${task}

## 프로젝트 경로
${PROJECT_ROOT}

## 지시사항
1. 위 작업을 수행하세요.
2. 필요한 파일을 읽고 수정하세요.
3. 작업 완료 후 결과를 요약해주세요.
`;

      const response = await this.callClaude(fullPrompt);

      step.status = 'completed';
      step.result = {
        success: true,
        agent: agent,
        task: task,
        output: response,
      };

      // 결과 출력 (요약)
      console.log(`${colors.dim}─────────────────────────────────────────────${colors.reset}`);
      const summary = response.length > 500 ? response.substring(0, 500) + '...' : response;
      console.log(`${colors.dim}${summary}${colors.reset}`);
      console.log(`${colors.dim}─────────────────────────────────────────────${colors.reset}`);

      console.log(`\n${colors.green}✅ ${agent} 완료${colors.reset}`);

    } catch (error: any) {
      step.status = 'failed';
      step.result = {
        success: false,
        agent: agent,
        task: task,
        output: '',
        errors: [error.message],
      };

      console.log(`\n${colors.red}❌ ${agent} 실패: ${error.message}${colors.reset}`);
      this.result.errors = this.result.errors || [];
      this.result.errors.push(`[${agent}] ${error.message}`);
    }
  }

  /**
   * AI API 호출
   */
  private async callClaude(prompt: string): Promise<string> {
    const response = await this.client.messages.create({
      model: 'claude-sonnet-4-20250514',
      max_tokens: 4096,
      messages: [
        { role: 'user', content: prompt },
      ],
    });

    // 비용 계산 (대략적)
    const inputTokens = response.usage?.input_tokens || 0;
    const outputTokens = response.usage?.output_tokens || 0;
    const cost = (inputTokens * 0.003 + outputTokens * 0.015) / 1000;
    this.result.totalCost += cost;

    const textContent = response.content.find((c: { type: string }) => c.type === 'text') as { type: 'text'; text: string } | undefined;
    return textContent?.text || '';
  }

  /**
   * 에이전트 타입 검증
   */
  private validateAgent(agent: string): AgentType {
    const validAgents: AgentType[] = ['backend', 'frontend', 'layout', 'template', 'reviewer'];
    if (validAgents.includes(agent as AgentType)) {
      return agent as AgentType;
    }
    return 'backend'; // 기본값
  }

  /**
   * 최종 결과 출력
   */
  private printFinalResult(): void {
    const completedSteps = this.result.steps.filter(s => s.status === 'completed').length;
    const totalSteps = this.result.steps.length;

    this.result.success = completedSteps > 0 && !this.result.errors?.length;

    console.log(`\n${colors.bright}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${colors.reset}`);
    console.log(`${colors.bright}                    최종 결과                      ${colors.reset}`);
    console.log(`${colors.bright}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${colors.reset}\n`);

    if (this.result.success) {
      console.log(`${colors.green}✅ 작업 완료${colors.reset}\n`);
    } else {
      console.log(`${colors.red}❌ 작업 실패${colors.reset}\n`);
    }

    // 단계별 결과
    console.log(`${colors.bright}📊 실행 단계 (${completedSteps}/${totalSteps}):${colors.reset}\n`);

    this.result.steps.forEach((step, index) => {
      const agentColor = agentColors[step.agent] || colors.white;
      let statusIcon: string;
      let statusColor: string;

      switch (step.status) {
        case 'completed':
          statusIcon = '✅';
          statusColor = colors.green;
          break;
        case 'failed':
          statusIcon = '❌';
          statusColor = colors.red;
          break;
        case 'skipped':
          statusIcon = '⏭️';
          statusColor = colors.yellow;
          break;
        default:
          statusIcon = '⏳';
          statusColor = colors.dim;
      }

      console.log(`  ${statusIcon} ${agentColor}[${step.agent}]${colors.reset} ${statusColor}${step.task}${colors.reset}`);
    });

    // 오류
    if (this.result.errors?.length) {
      console.log(`\n${colors.red}⚠️ 오류:${colors.reset}`);
      this.result.errors.forEach(e => {
        console.log(`  ${colors.red}- ${e}${colors.reset}`);
      });
    }

    // 비용
    console.log(`\n${colors.cyan}💰 총 비용: $${this.result.totalCost.toFixed(4)}${colors.reset}`);
    console.log(`${colors.bright}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${colors.reset}`);
  }

  /**
   * 에이전트 목록 표시
   */
  private showAgents(): void {
    console.log(`\n${colors.bright}🤖 사용 가능한 에이전트:${colors.reset}\n`);

    Object.entries(agentDefinitions).forEach(([name, def]) => {
      const agentColor = agentColors[name] || colors.white;
      console.log(`  ${agentColor}${name}${colors.reset}`);
      console.log(`    ${colors.dim}${def.description}${colors.reset}`);
      console.log(`    ${colors.dim}도구: ${(def.tools || []).join(', ')}${colors.reset}`);
      console.log('');
    });
  }

  /**
   * 도움말 표시
   */
  private showHelp(): void {
    console.log(`
${colors.bright}📖 그누보드7 Multi-Agent Interactive System 도움말${colors.reset}

${colors.cyan}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${colors.reset}

${colors.bright}🔧 워크플로우 명령어 (단계별 승인):${colors.reset}

  ${colors.yellow}/feature${colors.reset} <설명>
    새로운 기능을 개발합니다.
    각 단계 실행 전 승인을 요청합니다.
    ${colors.dim}예: /feature 상품 할인 기능 추가${colors.reset}

  ${colors.yellow}/bugfix${colors.reset} <설명>
    버그를 수정합니다. 영향 영역을 분석하고 TDD 방식으로 진행합니다.
    ${colors.dim}예: /bugfix Form 저장 시 데이터가 사라지는 문제${colors.reset}

${colors.cyan}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${colors.reset}

${colors.bright}⚡ 빠른 실행:${colors.reset}

  ${colors.yellow}/quick${colors.reset} <요청>
    승인 없이 빠르게 실행합니다.
    ${colors.dim}예: /quick package.json 파일 분석해줘${colors.reset}

${colors.cyan}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${colors.reset}

${colors.bright}📚 자유 형식 요청:${colors.reset}

  명령어 없이 자유롭게 입력하면 요청을 분석하여
  워크플로우를 자동으로 구성하고 단계별 승인을 받습니다.

  ${colors.dim}예: "ProductService의 할인 로직을 구현해줘"${colors.reset}

${colors.cyan}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${colors.reset}

${colors.bright}⌨️ 승인 옵션:${colors.reset}

  ${colors.green}Y${colors.reset}/yes  - 실행
  ${colors.red}N${colors.reset}/no   - 건너뛰기
  ${colors.cyan}S${colors.reset}/skip - 이후 모든 단계 건너뛰기

${colors.cyan}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${colors.reset}

${colors.bright}⌨️ 기타 명령어:${colors.reset}

  ${colors.yellow}/agents${colors.reset}  - 에이전트 목록
  ${colors.yellow}/help${colors.reset}    - 이 도움말
  ${colors.yellow}/exit${colors.reset}    - 종료

${colors.cyan}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${colors.reset}
`);
  }
}

// 메인 실행
async function main() {
  // API 키 확인
  if (!process.env.ANTHROPIC_API_KEY) {
    console.error(`${colors.red}❌ ANTHROPIC_API_KEY가 설정되지 않았습니다.${colors.reset}`);
    console.error(`${colors.dim}.env 파일에 API 키를 설정해주세요.${colors.reset}`);
    process.exit(1);
  }

  const session = new InteractiveSession();
  await session.start();
}

main().catch((error) => {
  console.error(`${colors.red}❌ 오류: ${error.message}${colors.reset}`);
  process.exit(1);
});
