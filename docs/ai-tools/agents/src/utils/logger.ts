/**
 * G7 DevTools MCP 서버 로거
 *
 * stdio MCP 전송에서는 stdout 이 프로토콜 채널이므로 항상 stderr 로만 쓴다.
 * 외부 로깅 의존성(pino) 없이 동작하도록 최소 구현으로 유지한다.
 */
type Level = 'debug' | 'info' | 'warn' | 'error';

const ORDER: Record<Level, number> = { debug: 10, info: 20, warn: 30, error: 40 };
const configured = (process.env.LOG_LEVEL || 'info') as Level;
const threshold = ORDER[configured] ?? ORDER.info;

// 로그는 항상 stderr(fd 2)로 보낸다. stdio MCP 서버에서 stdout 은 JSON-RPC 전용 채널이며,
// 로그 한 줄이라도 섞이면 클라이언트가 프레임을 파싱하지 못해 서버 접속 자체가 실패한다.
// CLI 실행에서도 진단 로그의 올바른 스트림은 stderr 이므로 분기 없이 통일한다.
function write(level: Level, bindings: Record<string, unknown>, args: unknown[]): void {
  if (ORDER[level] < threshold) return;
  const time = new Date().toISOString();
  const context = Object.keys(bindings).length ? ` ${JSON.stringify(bindings)}` : '';
  const message = args
    .map((a) => (a instanceof Error ? `${a.name}: ${a.message}` : typeof a === 'string' ? a : JSON.stringify(a)))
    .join(' ');
  process.stderr.write(`[${time}] ${level.toUpperCase()}${context} ${message}\n`);
}

export interface Logger {
  debug: (...args: unknown[]) => void;
  info: (...args: unknown[]) => void;
  warn: (...args: unknown[]) => void;
  error: (...args: unknown[]) => void;
  child: (bindings: Record<string, unknown>) => Logger;
}

function create(bindings: Record<string, unknown> = {}): Logger {
  return {
    debug: (...args) => write('debug', bindings, args),
    info: (...args) => write('info', bindings, args),
    warn: (...args) => write('warn', bindings, args),
    error: (...args) => write('error', bindings, args),
    child: (more) => create({ ...bindings, ...more }),
  };
}

export const logger: Logger = create();

export default logger;
