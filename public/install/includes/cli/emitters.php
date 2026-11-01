<?php

/**
 * 그누보드7 터미널 설치 - 진행 송출기
 *
 * 웹 설치 워커가 쓰는 `ProgressEmitter`(SseEmitter·NullEmitter)와 같은 인터페이스다. 설치 작업 실행 코어
 * `runInstallationTasks()` 는 송출기만 바꿔 끼우면 웹과 같은 순서·같은 이벤트로 돈다.
 *
 * - CliEmitter: 사람이 읽는 출력 (터미널일 때만 색)
 * - JsonLinesEmitter: 한 줄에 JSON 하나(NDJSON). 이벤트 이름·필드는 웹 SSE 이벤트와 같고 CLI 전용 step·summary 가 더해진다.
 *
 * 두 송출기 모두 log 이벤트를 installation.log 에 남기고(웹과 같음), 워커 잠금 심장박동을 갱신한다.
 * 중단 신호(Ctrl+C·SIGTERM)는 `isConnectionAborted()` 로 알려 기존 중단·롤백 경로를 탄다.
 */

if (! class_exists('CliEmitterBase')) {
    /**
     * CLI 송출기 공통 — 심장박동·중단 신호·log 기록.
     */
    abstract class CliEmitterBase implements ProgressEmitter
    {
        /** 워커 잠금 소유 ID */
        protected ?string $workerId;

        /** 잠금 상실 후 종료 처리 재진입 방지 */
        private bool $aborting = false;

        /**
         * @param  string|null  $workerId  워커 잠금 소유 ID (null 이면 잠금 무관)
         */
        public function __construct(?string $workerId = null)
        {
            $this->workerId = $workerId;
        }

        /**
         * 이벤트를 송출한다.
         *
         * @param  string  $event  이벤트 이름
         * @param  array<string, mixed>  $data  이벤트 데이터
         * @return void 반환값 없음
         */
        public function emit(string $event, array $data): void
        {
            if ($event === 'log' && isset($data['message'])) {
                addLog((string) $data['message']);
            }

            if ($event !== 'heartbeat') {
                $this->write($event, $data);
            }

            $this->exitIfNotOwner();
        }

        /**
         * 설치 작업 밖의 안내(단계 제목·입력 오류 등)를 출력만 한다 — installation.log·심장박동과 무관.
         *
         * @param  string  $event  이벤트 이름
         * @param  array<string, mixed>  $data  이벤트 데이터
         * @return void 반환값 없음
         */
        public function emitRaw(string $event, array $data): void
        {
            $this->write($event, $data);
        }

        /**
         * 중단 신호(Ctrl+C·SIGTERM)를 받았는지 — 받았으면 기존 중단·롤백 경로가 현재 작업을 되돌린다.
         *
         * @return bool 중단 요청 여부
         */
        public function isConnectionAborted(): bool
        {
            return g7_cli_abort_requested();
        }

        /**
         * 실제 출력.
         *
         * @param  string  $event  이벤트 이름
         * @param  array<string, mixed>  $data  이벤트 데이터
         * @return void 반환값 없음
         */
        abstract protected function write(string $event, array $data): void;

        /**
         * 다른 워커가 잠금을 넘겨받았으면 즉시 끝낸다 (두 워커 동시 실행 차단 — 웹 송출기와 같음).
         *
         * @return void 반환값 없음
         */
        private function exitIfNotOwner(): void
        {
            if ($this->workerId === null || $this->aborting) {
                return;
            }

            if (refreshWorkerHeartbeat($this->workerId)) {
                return;
            }

            $this->aborting = true;
            addLog('=== CLI Worker lock lost — another worker took over, aborting ===');
            $this->write('error', ['message' => lang('cli_worker_lock_lost'), 'message_key' => 'cli_worker_lock_lost']);
            exit(G7_CLI_EXIT_CONFLICT);
        }
    }
}

if (! class_exists('CliEmitter')) {
    /**
     * 사람이 읽는 터미널 출력.
     */
    class CliEmitter extends CliEmitterBase
    {
        /** 색 사용 여부 (출력이 터미널일 때만) */
        private bool $color;

        /**
         * @param  string|null  $workerId  워커 잠금 소유 ID
         * @param  bool|null  $color  색 사용 (null 이면 표준 출력이 터미널인지로 판정)
         */
        public function __construct(?string $workerId = null, ?bool $color = null)
        {
            parent::__construct($workerId);
            $this->color = $color ?? (function_exists('stream_isatty') && @stream_isatty(STDOUT));
        }

        /**
         * 이벤트별 한 줄 출력.
         *
         * @param  string  $event  이벤트 이름
         * @param  array<string, mixed>  $data  이벤트 데이터
         * @return void 반환값 없음
         */
        protected function write(string $event, array $data): void
        {
            $message = (string) ($data['message'] ?? '');

            $line = match ($event) {
                'task_start' => $this->paint('36', '▶ ').(string) ($data['name'] ?? $data['task'] ?? ''),
                'task_complete' => $this->paint('32', '  ✔ ').$message,
                'log' => '    '.$message,
                'error' => $this->paint('31', '✖ '.$message).(isset($data['error']) && $data['error'] !== '' ? "\n    ".str_replace("\n", "\n    ", (string) $data['error']) : ''),
                'rollback_failed' => $this->paint('31', '✖ '.$message).(isset($data['detail']) ? "\n    ".$data['detail'] : ''),
                'aborted' => $this->paint('33', '■ '.$message),
                'completed' => $this->paint('32', '✔ '.$message),
                'step' => "\n".$this->paint('1', '['.($data['step'] ?? '').'] '.($data['title'] ?? '')),
                'warning' => $this->paint('33', '! '.$message),
                'info' => '  '.$message,
                'summary', 'connected' => null,
                default => $message !== '' ? '  '.$message : null,
            };

            if ($line !== null) {
                fwrite(in_array($event, ['error', 'rollback_failed'], true) ? STDERR : STDOUT, $line."\n");
            }
        }

        /**
         * ANSI 색을 입힌다 (색을 쓰지 않으면 원문).
         *
         * @param  string  $code  SGR 코드
         * @param  string  $text  글자
         * @return string 출력 글자
         */
        private function paint(string $code, string $text): string
        {
            return $this->color ? "\033[{$code}m{$text}\033[0m" : $text;
        }
    }
}

if (! class_exists('JsonLinesEmitter')) {
    /**
     * 한 줄에 JSON 하나(NDJSON) — 자동화 도구용. 필드는 웹 SSE 이벤트 데이터와 같다.
     */
    class JsonLinesEmitter extends CliEmitterBase
    {
        /**
         * {"event": 이름, ...데이터} 한 줄.
         *
         * @param  string  $event  이벤트 이름
         * @param  array<string, mixed>  $data  이벤트 데이터
         * @return void 반환값 없음
         */
        protected function write(string $event, array $data): void
        {
            fwrite(STDOUT, installer_json_encode(['event' => $event] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        }
    }
}
