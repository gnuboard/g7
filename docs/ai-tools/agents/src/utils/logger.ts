/**
 * 그누보드7 에이전트 시스템 로거
 */
import pino from 'pino';

const level = process.env.LOG_LEVEL || 'info';

export const logger = pino({
  level,
  transport: {
    target: 'pino-pretty',
    options: {
      colorize: true,
      translateTime: 'SYS:standard',
      ignore: 'pid,hostname',
    },
  },
});

// 에이전트별 로거 생성
export function createAgentLogger(agentName: string) {
  return logger.child({ agent: agentName });
}

// 워크플로우 로거 생성
export function createWorkflowLogger(workflowName: string) {
  return logger.child({ workflow: workflowName });
}

export default logger;
