/**
 * 그누보드7 에이전트 시스템 로거
 */
import pino from 'pino';

const level = process.env.LOG_LEVEL || 'info';
const isMcpStdio = process.env.MCP_STDIO === '1';

// 로그는 항상 stderr(fd 2)로 보낸다.
// stdio MCP 서버에서 stdout 은 JSON-RPC 전용 채널이며, 로그 한 줄이라도 섞이면
// 클라이언트가 프레임을 파싱하지 못해 서버 접속 자체가 실패한다.
// CLI 실행에서도 진단 로그의 올바른 스트림은 stderr 이므로 분기 없이 통일한다.
export const logger = isMcpStdio
  ? pino({ level }, pino.destination(2))
  : pino({
      level,
      transport: {
        target: 'pino-pretty',
        options: {
          destination: 2,
          colorize: true,
          translateTime: 'SYS:standard',
          ignore: 'pid,hostname',
        },
      },
    },
  );

// 에이전트별 로거 생성
export function createAgentLogger(agentName: string) {
  return logger.child({ agent: agentName });
}

// 워크플로우 로거 생성
export function createWorkflowLogger(workflowName: string) {
  return logger.child({ workflow: workflowName });
}

export default logger;
