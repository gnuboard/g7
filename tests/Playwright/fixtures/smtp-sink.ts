/**
 * 최소 SMTP 싱크 (테스트 전용).
 *
 * 2단계 인증 실측은 **인증번호 발송이 성공해야** 코드 입력 단계까지 갈 수 있다. 발송이
 * 실패하면 서버가 로그인을 503 으로 끊기 때문이다(그것도 정당한 동작이라 PHPUnit 이 따로 잰다).
 *
 * 그래서 실제 메일을 보내는 대신 받아서 버리는 SMTP 서버를 잠깐 띄우고 사이트의 메일 설정을
 * 그쪽으로 돌린다. `log` 메일러를 쓰지 않는 이유는 그것이 이 제품의 설정 스키마에 없어서다 —
 * 등록된 메일 드라이버(smtp·mailgun·ses) 밖의 값은 저장 검증에서 거부되고, 파일을 직접 고쳐도
 * 드라이버 해석 단계에서 smtp 로 되돌아간다.
 *
 * 의존성을 늘리지 않으려고 Node 기본 `net` 만 쓴다. 받은 메일은 어디에도 남기지 않는다.
 */
import { createServer, type Server, type Socket } from 'node:net';

/** 띄운 싱크의 핸들 */
export type SmtpSink = {
  port: number;
  /** 받은 메시지 수 (진단용) */
  received(): number;
  close(): Promise<void>;
};

/**
 * 한 연결의 SMTP 대화를 처리한다.
 *
 * 인증도 TLS 도 요구하지 않는다 — 테스트 전용이며 127.0.0.1 에만 바인딩한다.
 *
 * @param socket 클라이언트 소켓
 * @param onMessage 메시지 1건 수신 시 호출
 */
function handleConnection(socket: Socket, onMessage: () => void): void {
  let inData = false;
  let buffer = '';

  socket.setEncoding('utf-8');
  socket.write('220 g7-test-sink ESMTP\r\n');

  socket.on('data', (chunk: string) => {
    buffer += chunk;

    // 본문 수신 중에는 종료 표식(<CRLF>.<CRLF>)만 본다.
    if (inData) {
      const terminator = buffer.indexOf('\r\n.\r\n');
      if (terminator === -1) return;

      buffer = buffer.slice(terminator + 5);
      inData = false;
      onMessage();
      socket.write('250 2.0.0 Ok: queued\r\n');
    }

    let newline = buffer.indexOf('\r\n');
    while (! inData && newline !== -1) {
      const line = buffer.slice(0, newline);
      buffer = buffer.slice(newline + 2);

      const verb = line.split(' ')[0].toUpperCase();

      if (verb === 'EHLO' || verb === 'HELO') {
        // 마지막 줄만 하이픈 없이 — 그래야 클라이언트가 목록의 끝을 안다.
        socket.write('250-g7-test-sink\r\n250 SIZE 10485760\r\n');
      } else if (verb === 'DATA') {
        socket.write('354 End data with <CR><LF>.<CR><LF>\r\n');
        inData = true;
      } else if (verb === 'QUIT') {
        socket.write('221 2.0.0 Bye\r\n');
        socket.end();
        return;
      } else {
        // MAIL FROM / RCPT TO / RSET / NOOP 등 — 전부 수락한다.
        socket.write('250 2.0.0 Ok\r\n');
      }

      newline = buffer.indexOf('\r\n');
    }
  });

  socket.on('error', () => {
    // 클라이언트가 먼저 끊는 것은 정상이다 — 테스트를 실패시키지 않는다.
  });
}

/**
 * SMTP 싱크를 띄운다.
 *
 * @param preferredPort 우선 시도할 포트 (사용 중이면 다음 포트로)
 * @returns 싱크 핸들
 */
export async function startSmtpSink(preferredPort = 2525): Promise<SmtpSink> {
  let count = 0;

  const server: Server = createServer((socket) => {
    handleConnection(socket, () => {
      count += 1;
    });
  });

  const port = await new Promise<number>((resolvePort, rejectPort) => {
    let attempt = 0;

    const tryListen = (candidate: number): void => {
      server.once('error', (error: NodeJS.ErrnoException) => {
        if (error.code === 'EADDRINUSE' && attempt < 20) {
          attempt += 1;
          tryListen(candidate + 1);
          return;
        }
        rejectPort(error);
      });

      server.listen(candidate, '127.0.0.1', () => {
        const address = server.address();
        resolvePort(typeof address === 'object' && address ? address.port : candidate);
      });
    };

    tryListen(preferredPort);
  });

  return {
    port,
    received: () => count,
    close: () =>
      new Promise<void>((resolveClose) => {
        server.close(() => resolveClose());
        // 열려 있는 연결이 남아도 테스트 종료를 막지 않는다.
        server.unref();
      }),
  };
}
