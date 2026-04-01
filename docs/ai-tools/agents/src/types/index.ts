/**
 * 그누보드7 멀티에이전트 시스템 타입 정의
 */

// 에이전트 타입
export type AgentType = 'coordinator' | 'backend' | 'frontend' | 'layout' | 'template' | 'reviewer';

// 에이전트 설정
export interface AgentConfig {
  name: string;
  description: string;
  systemPrompt: string;
  tools: string[];
  model: 'opus' | 'sonnet' | 'haiku';
}

// 작업 컨텍스트
export interface TaskContext {
  description: string;
  requirements?: string[];
  files?: string[];
  dependencies?: string[];
  context?: Record<string, unknown>;
}

// 에이전트 결과
export interface AgentResult {
  success: boolean;
  agent: string;
  task: string;
  output: string;
  cost?: number;
  errors?: string[];
  filesModified?: string[];
}

// 워크플로우 단계
export interface WorkflowStep {
  agent: AgentType;
  task: string;
  status: 'pending' | 'running' | 'completed' | 'failed' | 'skipped';
  result?: AgentResult;
}

// 워크플로우 결과
export interface WorkflowResult {
  success: boolean;
  steps: WorkflowStep[];
  totalCost: number;
  output?: string;
  errors?: string[];
}

// 코디네이터 설정
export interface CoordinatorConfig {
  cwd?: string;
  model?: 'opus' | 'sonnet';
}

// 워크플로우 타입
export type WorkflowType = 'feature' | 'bugfix' | 'pr-review';

// MCP 도구 결과
export interface ToolResult {
  success: boolean;
  message: string;
  data?: unknown;
  issues?: string[];
}

// 검증 결과
export interface ValidationResult {
  valid: boolean;
  issues: ValidationIssue[];
}

export interface ValidationIssue {
  file: string;
  line?: number;
  rule: string;
  message: string;
  severity: 'error' | 'warning' | 'info';
}

// CLI 옵션
export interface CliOptions {
  mode: 'interactive' | 'single';
  prompt?: string;
  workflow?: WorkflowType;
  verbose?: boolean;
}
