/**
 * 에이전트 정의
 * 각 에이전트의 역할, 시스템 프롬프트, 도구 권한 정의
 */
import type { AgentDefinition } from '@anthropic-ai/claude-agent-sdk';
import {
  BACKEND_PROMPT,
  FRONTEND_PROMPT,
  LAYOUT_PROMPT,
  TEMPLATE_PROMPT,
  REVIEWER_PROMPT,
} from '../prompts/index.js';

/**
 * 백엔드 개발자 에이전트
 * Service-Repository 패턴, FormRequest, 훅 시스템 담당
 */
export const backendAgent: AgentDefinition = {
  description: '백엔드 개발: Service-Repository 패턴, FormRequest, Controller, 훅 시스템, Migration',
  prompt: BACKEND_PROMPT,
  tools: ['Read', 'Write', 'Edit', 'Glob', 'Grep', 'Bash'],
  model: 'sonnet',
};

/**
 * 프론트엔드 개발자 에이전트
 * TSX 컴포넌트, 상태 관리, G7Core API 담당
 */
export const frontendAgent: AgentDefinition = {
  description: '프론트엔드 개발: TSX 컴포넌트, 상태 관리, G7Core API, 다크 모드',
  prompt: FRONTEND_PROMPT,
  tools: ['Read', 'Write', 'Edit', 'Glob', 'Grep', 'Bash'],
  model: 'sonnet',
};

/**
 * 레이아웃 개발자 에이전트
 * 레이아웃 JSON, 데이터 바인딩, 반응형 담당
 */
export const layoutAgent: AgentDefinition = {
  description: '레이아웃 개발: 레이아웃 JSON 스키마, 데이터 바인딩, 반응형, 상속/슬롯',
  prompt: LAYOUT_PROMPT,
  tools: ['Read', 'Write', 'Edit', 'Glob', 'Grep'],
  model: 'sonnet',
};

/**
 * 템플릿 개발자 에이전트
 * 템플릿 구조, 빌드, 컴포넌트 등록 담당
 */
export const templateAgent: AgentDefinition = {
  description: '템플릿 개발: 템플릿 구조, Vite 빌드, 컴포넌트 등록, 다국어 파일',
  prompt: TEMPLATE_PROMPT,
  tools: ['Read', 'Write', 'Edit', 'Glob', 'Grep', 'Bash'],
  model: 'sonnet',
};

/**
 * 검수자 에이전트
 * 규정 준수, 테스트, 문서화 검증 담당
 */
export const reviewerAgent: AgentDefinition = {
  description: '코드 검수: 규정 준수 확인, 테스트 통과, 문서화 검증',
  prompt: REVIEWER_PROMPT,
  tools: ['Read', 'Glob', 'Grep', 'Bash'],
  model: 'sonnet',
};

/**
 * 모든 에이전트 정의
 */
export const agentDefinitions: Record<string, AgentDefinition> = {
  backend: backendAgent,
  frontend: frontendAgent,
  layout: layoutAgent,
  template: templateAgent,
  reviewer: reviewerAgent,
};

export default agentDefinitions;
