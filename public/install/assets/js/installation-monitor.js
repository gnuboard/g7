/**
 * G7 인스톨러 - 설치 진행 모니터 (SSE/폴링 듀얼 모드)
 *
 * 두 모드 공통 콜백 인터페이스를 통해 UI 업데이트 로직을 공유합니다.
 * - SseMonitor: EventSource 기반 실시간 스트리밍
 * - PollingMonitor: setInterval 기반 state-management.php 폴링
 *
 * 콜백:
 *   onConnected()                                — 연결 성공 (SSE 전용, 폴링은 첫 poll 시 즉시 호출)
 *   onTaskStart(taskId, name, target)            — 새 작업 시작
 *   onTaskComplete(taskId, message, target, skipped) — 작업 완료 (skipped: 건너뜀·best-effort 실패)
 *   onLog(message)                               — 로그 라인 추가
 *   onComplete(data)                             — 전체 설치 성공
 *   onError(errorData)                           — 실패 (data: {message, message_key, message_params, error, task, target, skippable, manual_commands})
 *                                                  message_params 는 자리표시자 치환값이다 —
 *                                                  키만 넘기면 화면이 `:errors` 같은 토큰을
 *                                                  그대로 그린다 (새로고침 복원 경로에는
 *                                                  치환된 문장이 없어 폴백도 없다).
 *                                                  skippable 은 태스크 실패 경로에서만 실린다 —
 *                                                  멈춤·네트워크·거부는 건너뛸 대상이 없다.
 *                                                  manual_commands 는 화면에 전용 UI 가 없고
 *                                                  진행 기록(로그)으로 노출된다 — 콜백에는
 *                                                  실어 보내되 소비처는 로그 경로다
 *   onAbort(state)                               — 사용자 중단
 *   onRollbackFailed(data)                       — 롤백 실패
 *   onHeartbeat({done, total})                   — 한 태스크 안의 부분 진행 (번들 추출 묶음 수)
 *                                                  진행 값이 실린 heartbeat 에서만 발화한다 —
 *                                                  값 없는 heartbeat 는 「살아 있다」의 신호일 뿐
 *                                                  진행이 아니므로 화면 표시를 0/0 으로 덮으면 안 된다
 *   onConnectionTimeout()                        — SSE 5초 내 연결 실패 (SSE 전용)
 */
(function (global) {
    'use strict';

    /**
     * 「살아 있다」의 증거로 세는 SSE 이벤트 이름.
     *
     * 서버가 내보내는 이벤트 이름 전수(task-runner.php 의 sendSSEEvent/emit)에서 **종료
     * 이벤트만** 뺀 집합이다. 목록에서 이름이 빠지면 그 이벤트만 오는 구간이 통째로 침묵으로
     * 세어져, 서버가 정상 동작 중인데도 정지 화면이 뜬다 — 번들 추출 구간이 `heartbeat` 만
     * 내보내는 것이 바로 그 경우였다 (12차 #B27). 소비 리스너가 따로 없는 이름도 여기에는
     * 반드시 남는다.
     *
     * 제외 대상과 근거:
     *  - `completed` · `aborted` — 도착 즉시 stop() 한다. 다음 침묵이 없다.
     *  - `error` — EventSource 가 **전송 오류**에도 같은 이름을 쓴다. 여기에 넣으면 연결이
     *    끊길 때마다 침묵 시계가 되감겨 정지 판정이 임계만큼 미뤄진다.
     *
     * @type {string[]}
     */
    const SSE_LIVENESS_EVENTS = [
        'connected',
        'task_start',
        'task_complete',
        'log',
        'heartbeat',
        'rollback_failed',
    ];

    /**
     * SSE 이벤트 본문을 안전하게 파싱한다.
     *
     * 스트림은 프록시·버퍼 경계에서 잘릴 수 있다. 한 이벤트의 파싱 실패가 예외로 새면
     * 그 이벤트의 콜백이 발화하지 않는데, 그것이 `completed` 라면 **성공한 설치가 잠시 뒤
     * 「응답이 없습니다」로 뒤집힌다**. 보호는 전 이벤트에 대칭으로 둔다.
     *
     * @param {MessageEvent} e
     * @returns {Object} 파싱 결과 (실패 시 빈 객체)
     */
    function safeParseEventData(e) {
        try {
            return JSON.parse(e && e.data ? e.data : '{}') || {};
        } catch (_) {
            return {};
        }
    }

    /**
     * 실패 상태를 `onError` 페이로드로 바꾼다 — 폴백 사슬의 단일 지점 (13차 F12).
     *
     * 사유는 세 자리에서 온다. 태스크가 실패하면 상태 파일에 기록되어 `state` 로 오고
     * (`error_detail` 은 상세, `error` 는 요약), **잡히지 않은 예외**로 끝난 경우(500 봉투)는
     * 상태 파일에 기록될 겨를이 없어 응답 최상위(`message`·`error_detail`)로만 온다.
     *
     * 종전에는 경로마다 사슬이 달랐다 — 새로고침 복원은 `state.error`, 단계별 실행은
     * `error_detail → body.message`, 폴링은 `error_detail` 만 봤다. 그래서 같은 실패가
     * 경로에 따라 사유가 있기도 없기도 했다. 사본을 세 곳에 두면 그중 하나가 반드시
     * 뒤처지므로 여기 한 곳이 소유한다.
     *
     * @param {Object|null} state 서버 상태 요약 (installerClientFailureFields)
     * @param {Object|null} body  응답 봉투 (단계별 실행 전용, 없으면 생략)
     * @returns {Object} onError 페이로드
     */
    function failurePayload(state, body) {
        const st = state || {};
        const res = body || {};

        return {
            // 제목 자리에는 **사람이 읽을 문구**를 먼저 둔다 (§12.0 W3 — 개발자 표기는 제목이
            // 아니라 진단 상세에만). 종전에는 상세(`error_detail`, 대개 예외 원문)가 앞에 있어,
            // `message_key` 가 없는 상태(레거시 state · 키를 못 실은 태스크)에서 예외 원문이
            // 실패 배너 본문으로 승격되고 접기 영역에도 같은 문자열이 중복됐다.
            message: res.message || st.error || st.error_detail || null,
            message_key: st.error_message_key || res.message_key || null,
            message_params: st.error_message_params || null,
            error: st.error_detail || res.error_detail || st.error || null,
            task: st.failed_task || null,
            target: null,
            // 서버가 실어 보내는 건너뛰기 가능 여부를 그대로 넘긴다 — 빠뜨리면 그 모드에서만
            // [건너뛰고 계속] 이 사라지고, 새로고침하면 화면이 state 를 직접 읽어 버튼이
            // 나타나 같은 상태가 두 번 다르게 보인다.
            skippable: !!st.failed_task_skippable,
            manual_commands: st.manual_commands || null,
        };
    }

    /**
     * 기본 모니터 클래스 (공통 인터페이스)
     */
    class InstallationMonitor {
        constructor(callbacks, options = {}) {
            this.callbacks = callbacks || {};
            this.options = options;
            this.stopped = false;
            // 정지 감지는 **페이로드와 무관한 시간 기준**이다 (기본 90초). 응답 내용으로만
            // 판정하면 서버가 계속 4xx/5xx 를 주거나 응답 자체가 오지 않는 동안에는 판정
            // 지점에 도달하지 못해 화면이 마지막 진행률에서 영구 정지한다 — 오류도 안내도 없다.
            // 세 모니터가 같은 기준을 공유해야 한 모드에만 있는 사각이 생기지 않는다.
            this.stuckThresholdMs = options.stuckThresholdMs || 90000;
            this.stuckTimer = null;
            this.stuckEmitted = false;
        }

        start() {
            throw new Error('start() must be implemented by subclass');
        }

        stop() {
            this.stopped = true;
            if (this.stuckTimer) {
                clearTimeout(this.stuckTimer);
                this.stuckTimer = null;
            }
        }

        /**
         * 침묵 타이머를 다시 건다 — 「살아 있다」는 증거가 하나 도착할 때마다 호출한다.
         *
         * @param {string|null} taskId 정지 시점에 돌고 있던 태스크(있으면)
         */
        _armStuckTimer(taskId = null) {
            if (this.stuckEmitted || this.stopped) {
                return;
            }
            if (this.stuckTimer) {
                clearTimeout(this.stuckTimer);
            }
            this.stuckTimer = setTimeout(() => {
                if (this.stopped || this.stuckEmitted) {
                    return;
                }
                this.stuckEmitted = true;
                this.stop();
                this._invoke('onError', {
                    message: null,
                    message_key: 'error_installation_stuck',
                    message_params: { seconds: Math.floor(this.stuckThresholdMs / 1000) },
                    error: null,
                    task: taskId,
                    target: null,
                    manual_commands: null,
                    stuck: true,
                });
            }, this.stuckThresholdMs);
        }

        _invoke(name, ...args) {
            const cb = this.callbacks[name];
            if (typeof cb === 'function') {
                try {
                    cb(...args);
                } catch (e) {
                    console.error(`[InstallationMonitor] callback ${name} failed:`, e);
                }
            }
        }
    }

    /**
     * SSE 기반 모니터 (EventSource)
     */
    class SseMonitor extends InstallationMonitor {
        constructor(callbacks, options = {}) {
            super(callbacks, options);
            this.workerUrl = options.workerUrl;
            // 승자 요청에게 진행을 넘겨받을 때 갈아탈 주소 (13차 E5). 없으면 갈아탈 곳이
            // 없으므로 종전 동작(EventSource 재연결)을 그대로 둔다.
            this.stateUrl = options.stateUrl || null;
            this.connectionTimeoutMs = options.connectionTimeoutMs || 5000;
            this.eventSource = null;
            this.connected = false;
            this.connectionTimer = null;
            // 이어받기 뒤 진행을 나르는 폴링 모니터 — stop() 이 함께 멈춰야 한다.
            this.successor = null;
            this.supersededHandled = false;
            // 연결 뒤 침묵 감지 — 폴링 모니터와 같은 기준(기본 90초). 이것이 없으면 워커가
            // 죽거나 프록시가 스트림을 끊었을 때 EventSource 가 조용히 재연결만 시도하고
            // (그 재연결은 워커 lock 에 막혀 아무것도 못 한다) 화면은 마지막 진행률에서
            // 영구 정지한다 — 오류도 안내도 없다. onerror 는 `connected === false` 일 때만
            // 발화하므로 「연결 전 실패」만 덮고 「연결 후 정지」는 세 모니터 중 여기만 사각이었다.
            // 정지 감지 필드·메서드는 베이스 클래스가 소유한다 (세 모니터 공용)
        }

        start() {
            this.eventSource = new EventSource(this.workerUrl);

            this.connectionTimer = setTimeout(() => {
                if (!this.connected && !this.stopped) {
                    this.stop();
                    this._invoke('onConnectionTimeout');
                }
            }, this.connectionTimeoutMs);

            // 어떤 이벤트가 와도 「살아 있다」의 증거다 — EventSource 는 이벤트 이름별 리스너만
            // 부르므로 침묵 타이머는 여기 한 곳(캡처 단계)에서 갱신한다.
            //
            // 목록은 서버가 내보내는 이벤트 이름 전수와 같아야 한다 (task-runner.php 의
            // sendSSEEvent/emit). 하나라도 빠지면 그 이벤트만 오는 구간이 통째로 「침묵」으로
            // 세어진다 — 번들 추출 구간은 `heartbeat` 말고는 아무것도 내보내지 않으므로
            // 추출이 임계(기본 90초)를 넘기면 정상 설치가 정지 화면으로 뒤집힌다 (12차 #B27).
            // 이름 있는 이벤트는 `message` 리스너로 오지 않아 위의 캡처도 이를 대신하지 못한다.
            this.eventSource.addEventListener('message', () => this._armStuckTimer());
            SSE_LIVENESS_EVENTS.forEach((name) => {
                this.eventSource.addEventListener(name, () => this._armStuckTimer());
            });

            this.eventSource.addEventListener('connected', () => {
                clearTimeout(this.connectionTimer);
                this.connected = true;
                this._invoke('onConnected');
            });

            this.eventSource.addEventListener('task_start', (e) => {
                const data = safeParseEventData(e);
                this._invoke('onTaskStart', data.task, data.name, data.target || null);
            });

            this.eventSource.addEventListener('task_complete', (e) => {
                const data = safeParseEventData(e);
                // skipped: 운영자가 건너뛴 작업 + best_effort 실패로 지나간 작업 — 세 진행 모드가
                // 같은 runSingleInstallationTask() 를 쓰므로 이 플래그는 SSE 에도 실려 온다.
                // 떨어뜨리면 실패한 best-effort 단계가 초록 "완료" 로 보인다.
                this._invoke('onTaskComplete', data.task, data.message, data.target || null, !!data.skipped);
            });

            this.eventSource.addEventListener('log', (e) => {
                const data = safeParseEventData(e);
                if (data.message) {
                    this._invoke('onLog', data.message);
                }
            });

            this.eventSource.addEventListener('heartbeat', (e) => {
                // 한 태스크 **안**의 부분 진행이다 (번들 추출 묶음 수). 태스크 단위 진행률은
                // 이 값으로 움직이지 않는다 — 최장 태스크가 도는 동안 화면이 정지한 스피너로만
                // 남지 않도록 현재 작업 줄에 덧붙이는 용도다 (12차 #B35).
                // 진행 값이 없는 heartbeat 는 「살아 있다」의 신호일 뿐이므로 표시를 덮지 않는다.
                const data = safeParseEventData(e);
                if (typeof data.done === 'number' && typeof data.total === 'number' && data.total > 0) {
                    this._invoke('onHeartbeat', { done: data.done, total: data.total });
                }
            });

            this.eventSource.addEventListener('completed', (e) => {
                // 스트림이 프록시에 잘려 본문이 깨져도 「완료했다」는 사실은 유효하다 —
                // 예외를 그대로 흘리면 onComplete 가 발화하지 않아, 성공한 설치가 잠시 뒤
                // 「응답이 없습니다」 로 뒤집힌다.
                const data = safeParseEventData(e);
                this.stop();
                this._invoke('onComplete', data);
            });

            this.eventSource.addEventListener('aborted', (e) => {
                const data = safeParseEventData(e);

                // `busy` 는 중단이 아니라 **이어받기 실패**다 (19차 J1).
                //
                // 스트림이 연결된 **뒤** 끊기면(프록시·LB 의 idle 타임아웃) EventSource 가
                // 스스로 재연결하는데, 그 재연결은 살아 있는 워커의 lock 에 막혀 이 이벤트를
                // `reason:'busy'` 로 받는다. 종전에는 `reason` 을 보지 않고 곧장 중단 화면을
                // 그려서, **돌고 있는 설치 위에** 「설치가 중단되었습니다」가 떴다.
                //
                // 바로 아래 `_handOffToPolling()` 의 docblock 이 이 상황을 이미 정확히 적어
                // 두었고(`worker_superseded` 를 위해 만들어진 기계다), 성질도 같다 —
                // 다른 요청이 진행을 쥐고 있으니 조회로 갈아타면 된다.
                if (data && data.reason === 'busy') {
                    this._handOffToPolling(data.message || null);

                    return;
                }

                this.stop();
                this._invoke('onAbort', data);
            });

            this.eventSource.addEventListener('rollback_failed', (e) => {
                this._invoke('onRollbackFailed', safeParseEventData(e));
            });

            // 다른 요청이 워커 lock 을 가져갔다 — 이 워커는 물러나기 전에 그 사실을 한 프레임
            // 보내고 끝낸다 (서버: progress-emitter.php::exitIfNotOwner). 이어받기는 실패가
            // 아니므로 onError 로 올리지 않는다.
            this.eventSource.addEventListener('worker_superseded', (e) => {
                const data = safeParseEventData(e);
                this._handOffToPolling(data.message || null);
            });

            this.eventSource.addEventListener('error', (e) => {
                // 서버에서 보낸 명시적 error 이벤트 (e.data 있음)
                if (!e.data) {
                    return;
                }
                let data = safeParseEventData(e);
                if (Object.keys(data).length === 0) {
                    // 본문이 깨졌으니 사유를 알 수 없다 — 키로 넘겨 화면이 번역하게 한다
                    // (여기서 영문 리터럴을 만들면 한국어 인스톨러 실패 화면에 영어가 남는다).
                    data = { message_key: 'unknown_error_occurred' };
                }
                this.stop();
                this._invoke('onError', data);
            });

            // 연결 오류 (네이티브 onerror) — e.data 없음
            this.eventSource.onerror = () => {
                clearTimeout(this.connectionTimer);
                if (!this.connected && !this.stopped) {
                    this.stop();
                    this._invoke('onConnectionTimeout');
                }
            };
        }

        /**
         * 승자 요청에게 진행을 넘긴다 — 재연결이 아니라 진행 조회로 갈아탄다 (13차 E5).
         *
         * 아무것도 하지 않으면 EventSource 가 스트림 종료를 장애로 읽고 재연결하는데, 그
         * 재연결은 승자 요청이 쥔 lock 에 막혀 `aborted{busy}` 를 받고 「중단」 화면을 그린다.
         * 설치는 멀쩡히 진행 중인데 화면만 실패하는 상태다. 단계별 실행은 같은 상황을
         * busy → 자동 재시도로 넘기므로, 그 계약을 SSE 로 옮긴 것이다.
         *
         * @param {string|null} message 서버가 보낸 안내 문구 (진행 기록에 남긴다)
         */
        _handOffToPolling(message) {
            if (this.stopped || this.supersededHandled) {
                return;
            }

            if (message) {
                this._invoke('onLog', message);
            }

            // 갈아탈 주소가 없으면 종전 동작(재연결)을 그대로 둔다 — 여기서 스트림만 닫으면
            // 화면이 아무 안내 없이 마지막 진행률에 영구 정지한다.
            if (!this.stateUrl) {
                return;
            }

            this.supersededHandled = true;
            this.stop();

            this.successor = new PollingMonitor(this.callbacks, {
                stateUrl: this.stateUrl,
                intervalMs: this.options.intervalMs || 1000,
                stuckThresholdMs: this.options.stuckThresholdMs,
            });
            this.successor.start();
        }

        stop() {
            super.stop();
            if (this.connectionTimer) {
                clearTimeout(this.connectionTimer);
                this.connectionTimer = null;
            }
            if (this.stuckTimer) {
                clearTimeout(this.stuckTimer);
                this.stuckTimer = null;
            }
            if (this.eventSource) {
                this.eventSource.close();
                this.eventSource = null;
            }
            // 이어받은 폴링도 함께 멈춘다 — [중단] 은 이 객체 하나만 붙잡고 있다.
            if (this.successor) {
                this.successor.stop();
            }
        }
    }

    /**
     * 폴링 기반 모니터 (state-management.php?action=get)
     *
     * 1초 간격으로 state를 조회하고 diff 발생 시 콜백을 호출합니다.
     */
    class PollingMonitor extends InstallationMonitor {
        /**
         * 응답 파싱 연속 실패 허용 횟수.
         *
         * 일시적 오류로 사용자를 놀라게 하지 않을 만큼 크고,
         * 서버가 계속 빈 본문을 돌려주는 상황에서 사용자를 무한정 기다리게 하지 않을 만큼 작다.
         */
        static MAX_PARSE_FAILURES = 5;

        constructor(callbacks, options = {}) {
            super(callbacks, options);
            this.stateUrl = options.stateUrl;
            this.intervalMs = options.intervalMs || 1000;
            // stuck 판정 임계값(기본 90초)은 베이스 클래스가 소유한다 — last_updated 가 그
            // 시간 이상 갱신되지 않거나, 응답 자체가 오지 않아도 같은 기준으로 끝난다.
            this.timer = null;
            this.lastLogOffset = 0;
            this.lastCompletedTasks = [];
            this.lastBestEffortFailedTasks = [];
            this.lastCurrentTask = null;
            this.lastStatus = null;
            this.terminated = false;
            // 조회가 날아가 있는 동안 다음 tick 이 또 발사하지 않게 한다 (19차 J2)
            this.inFlight = false;
            // stuck 감지용 — 마지막으로 last_updated 가 변한 시각(client clock 기준 ms)
            this.lastUpdatedSeen = null;
            this.lastUpdatedClientMs = Date.now();
            this.stuckEmitted = false;
            // 응답 파싱 연속 실패 횟수 — 성공 시 0 으로 리셋한다.
            // 서버가 빈 본문/비 JSON 을 계속 돌려주면 콘솔 경고만 무한히 쌓이고
            // 화면에는 아무 일도 일어나지 않는다 (gnuboard/g7#62). 임계치에서 사용자에게 알린다.
            this.parseFailures = 0;
        }

        start() {
            this._invoke('onConnected');
            // 페이로드와 무관하게 시간으로도 판정한다 — 응답이 계속 4xx/5xx 이거나 깨진 JSON
            // 이면 아래 diff 기반 판정에는 영원히 도달하지 못한다 (그때가 정확히 정지 상태다).
            this._armStuckTimer();
            this._poll();
            this.timer = setInterval(() => this._poll(), this.intervalMs);
        }

        stop() {
            super.stop();
            this.terminated = true;
            if (this.timer) {
                clearInterval(this.timer);
                this.timer = null;
            }
        }

        async _poll() {
            if (this.terminated) return;

            // 겹침 방지 (19차 J2). `setInterval` 은 응답과 무관하게 매 tick 발사한다 —
            // 상태 조회가 1초를 넘기면(로그가 커진 후반부·느린 디스크) 요청이 브라우저에
            // 쌓이고, 늦게 도착한 **옛 응답**이 `lastLogOffset`·`lastCompletedTasks`·
            // `lastCurrentTask` 를 통째로 되감는다. 그러면 다음 tick 에서 서버가 되감긴
            // offset 부터 다시 보내 같은 로그 줄이 두 번 그려지고, 이미 지나간 단계에
            // `onTaskStart` 가 다시 발화한다. 종단 상태만 아래 재확인으로 보호되고 진행
            // 상태는 무보호였다.
            if (this.inFlight) return;
            this.inFlight = true;

            try {
                const url = `${this.stateUrl}${this.stateUrl.includes('?') ? '&' : '?'}log_offset=${this.lastLogOffset}`;
                const res = await fetch(url, { cache: 'no-store' });
                if (!res.ok) {
                    // 4xx 는 재시도로 낫지 않는다 (11차 #B21). 종전에는 401·403·409·410 이
                    // 5xx·네트워크와 함께 삼켜져, 입구가 닫힌(410) 뒤나 토큰 거부(403) 뒤에도
                    // 화면이 90초 동안 마지막 진행률에 멈춰 있다가 「응답이 없습니다」라는
                    // **틀린 사유**로 끝났다. 단계별 실행은 같은 상황에서 즉시 사유를 낸다.
                    if (res.status >= 400 && res.status < 500) {
                        let body = null;
                        try {
                            body = await res.json();
                        } catch (e) {
                            body = null;
                        }
                        if (this.terminated || this.stopped) return;
                        this.stop();
                        this._invoke('onError', {
                            message: (body && body.message) || null,
                            // 폴링 모드의 폴백은 폴링 문구여야 한다 — 종전에는 단계별 실행 문구를
                            // 써서, 이 모드에 없는 동작([재시도] 로 중단된 단계부터 이어감)을
                            // 안내했다. 서버가 message_key 를 실으면 그것이 이긴다.
                            message_key: (body && body.message_key) || 'error_polling_request_failed',
                            error: null,
                            task: null,
                            target: null,
                            manual_commands: null,
                        });
                        return;
                    }

                    return; // 5xx·일시적 네트워크 오류는 다음 tick 에서 재시도 (계속되면 침묵 타이머가 끝낸다)
                }
                const text = await res.text();
                // await 뒤 취소 재확인 — 진입부의 한 번만으로는 부족하다. [중단] 은 이 요청이
                // 날아가 있는 동안에도 눌리므로, 남은 폴이 그대로 emit 하면 중단 화면 위에
                // 완료·진행이 덧그려진다. 단계별 실행이 이미 쓰는 규칙과 같다 (10차).
                if (this.terminated || this.stopped) return;

                let state;
                try {
                    state = JSON.parse(text);
                } catch (parseError) {
                    this.parseFailures += 1;
                    console.warn('[PollingMonitor] poll response was not JSON:', this.parseFailures, parseError);

                    if (this.parseFailures >= PollingMonitor.MAX_PARSE_FAILURES) {
                        this.stop();
                        this._invoke('onError', {
                            message: null,
                            message_key: 'error_polling_response_invalid',
                            error: null,
                            task: null,
                            target: null,
                            manual_commands: null,
                            parse_failures: this.parseFailures,
                        });
                    }
                    return;
                }

                this.parseFailures = 0;
                this._diffAndEmit(state);
            } catch (e) {
                console.warn('[PollingMonitor] poll failed:', e);
            } finally {
                this.inFlight = false;
            }
        }

        _diffAndEmit(state) {
            // 0. 서버 상태 요약 — 단계별 실행과 같은 계약이다 (13차 F11). 아래 diff 가 같은
            //    일을 자체 보정으로도 하지만, 계약이 한쪽에만 있으면 onState 소비자가 늘어날
            //    때마다 그 소비자가 폴링에서만 조용히 발화하지 않는다. 소비자는 멱등이다.
            this._invoke('onState', state);

            // 1. 새 로그 송출
            if (Array.isArray(state.logs) && state.logs.length > 0) {
                for (const log of state.logs) {
                    const msg = (typeof log === 'object' && log.message) ? log.message : String(log);
                    this._invoke('onLog', msg);
                }
            }
            if (typeof state.log_total === 'number') {
                this.lastLogOffset = state.log_total;
            }

            // 2. current_task 변화 감지 → onTaskStart
            const currentTask = state.current_task || null;
            if (currentTask && currentTask !== this.lastCurrentTask) {
                // target 분리: "taskId:target" 형식
                let taskId = currentTask;
                let target = null;
                const colonIdx = currentTask.indexOf(':');
                if (colonIdx !== -1) {
                    taskId = currentTask.substring(0, colonIdx);
                    target = currentTask.substring(colonIdx + 1);
                }
                this._invoke('onTaskStart', taskId, null, target);
                this.lastCurrentTask = currentTask;
            }

            // 3. completed_tasks diff → onTaskComplete
            //    운영자가 건너뛴 작업은 completed 에 들어가되 skipped_tasks 로도 표시된다 — 그 구분을
            //    넘겨야 경고 아이콘으로 그려진다.
            const skippedTasks = Array.isArray(state.skipped_tasks) ? state.skipped_tasks : [];
            const completedTasks = Array.isArray(state.completed_tasks) ? state.completed_tasks : [];
            for (const key of completedTasks) {
                if (!this.lastCompletedTasks.includes(key)) {
                    this._emitTaskComplete(key, skippedTasks.includes(key));
                }
            }
            this.lastCompletedTasks = completedTasks.slice();

            // 3-1. best_effort_failed_tasks diff → onTaskComplete(skipped)
            //      실패한 best-effort 작업은 completed 에 들어가지 않는다(재시도 시 다시 돌려야 하므로).
            //      여기서 따로 흘리지 않으면 폴링 화면은 그 단계를 영원히 "대기" 로 남긴다.
            const bestEffortFailed = Array.isArray(state.best_effort_failed_tasks) ? state.best_effort_failed_tasks : [];
            for (const key of bestEffortFailed) {
                if (!this.lastBestEffortFailedTasks.includes(key)) {
                    this._emitTaskComplete(key, true);
                }
            }
            this.lastBestEffortFailedTasks = bestEffortFailed.slice();

            // 4. rollback_failure 저장 (에러/중단 처리 시 UI에 표시)
            //    state 에는 다국어 **키**만 저장된다(비밀·로케일 비의존). 번역은 화면이 하므로
            //    키를 그대로 넘긴다 — 종전처럼 detail_key 를 detail 자리에 넣으면 번역되지 않은
            //    키 문자열이 그대로 실패 화면에 노출된다(SSE 는 서버가 번역해 보내 경로마다 달랐다).
            if (state.rollback_failure && this.lastStatus !== state.status) {
                this._invoke('onRollbackFailed', {
                    message: state.rollback_failure.message || null,
                    detail: state.rollback_failure.detail || null,
                    message_key: state.rollback_failure.message_key || null,
                    detail_key: state.rollback_failure.detail_key || null,
                });
            }

            // 4-1. stuck 감지 — running 상태에서 last_updated 가 stuckThresholdMs 이상 갱신 안 되면 에러 처리
            //      서버 측 워커가 exec() 중 deadlock 되거나 PHP 프로세스가 silent crash 된 케이스를 client 가 알림.
            if (state.status === 'running' && !this.stuckEmitted) {
                if (state.last_updated !== this.lastUpdatedSeen) {
                    this.lastUpdatedSeen = state.last_updated;
                    this.lastUpdatedClientMs = Date.now();
                    // 진행이 있었다는 증거 — 시간 기준 타이머도 함께 다시 건다
                    this._armStuckTimer(state.current_task || null);
                } else if (this.lastUpdatedSeen !== null) {
                    const idleMs = Date.now() - this.lastUpdatedClientMs;
                    if (idleMs >= this.stuckThresholdMs) {
                        this.stuckEmitted = true;
                        this.stop();
                        // 침묵 시간은 자리표시자 치환값으로 나른다 — 종전 `idle_seconds` 는
                        // onError 소비자가 읽지 않는 필드라 화면에 도달하지 못했다
                        // (7차가 세운 규약: 키를 내보내는 지점이 치환값도 함께 낸다).
                        this._invoke('onError', {
                            message: null,
                            message_key: 'error_installation_stuck',
                            message_params: { seconds: Math.floor(idleMs / 1000) },
                            error: null,
                            task: state.current_task || null,
                            target: null,
                            manual_commands: null,
                            stuck: true,
                        });
                        return;
                    }
                }
            }

            // 5. status 전환 감지
            const status = state.status;
            if (status && status !== this.lastStatus) {
                switch (status) {
                    case 'completed':
                        this.stop();
                        this._invoke('onComplete', { message: state.error_detail || null, redirect: '/install/' });
                        break;
                    case 'failed':
                        this.stop();
                        // 폴백 사슬은 failurePayload() 가 소유한다 (13차 F12).
                        this._invoke('onError', failurePayload(state));
                        break;
                    case 'aborted':
                        this.stop();
                        this._invoke('onAbort', state);
                        break;
                }
            }
            this.lastStatus = status;
        }

        /**
         * "taskId" 또는 "taskId:target" 키를 분해해 완료 콜백으로 흘린다.
         *
         * @param {string} key      완료된 작업 키
         * @param {boolean} skipped 건너뜀(운영자 선택) 또는 best-effort 실패
         * @returns {void}
         */
        _emitTaskComplete(key, skipped) {
            let taskId = key;
            let target = null;
            const colonIdx = key.indexOf(':');
            if (colonIdx !== -1) {
                taskId = key.substring(0, colonIdx);
                target = key.substring(colonIdx + 1);
            }
            this._invoke('onTaskComplete', taskId, null, target, !!skipped);
        }
    }

    /**
     * 단계별 실행 모니터 (run-task.php 순차 호출)
     *
     * 요청 하나가 태스크 하나를 돌리고, 그 동안 서버가 모아 둔 이벤트(task_start/log/
     * task_complete/error/…)를 응답에 실어 준다. 모니터는 받은 이벤트를 SSE 와 같은
     * 순서·형태로 재생하므로 UI 콜백은 세 모드가 같다.
     *
     * 서버가 다음 태스크를 정한다 — 클라이언트는 인덱스를 보내지 않는다. 그래서
     * 새로고침 뒤에도 같은 호출로 이어서 진행된다.
     *
     * 추가 콜백 (선택):
     *   onBusy(message)           — 다른 요청이 태스크를 돌리는 중 (잠시 후 재시도). message 는 서버가 실은 사유
     *   onRetry(attempt, max)     — 응답을 받지 못해 재시도
     *   onState(state)            — 응답마다 서버 상태 요약(완료·건너뜀·현재 태스크) 전달.
     *                               새로고침 시점에 돌고 있던 태스크의 완료 이벤트는 끊긴
     *                               연결로 가 버리므로, 화면은 이 요약으로 목록을 맞춘다.
     *
     *                               **상태 스냅샷을 받는 모드만 발화한다** — 단계별 실행은
     *                               응답 본문의 `state`, 폴링은 조회 응답 자체가 스냅샷이다.
     *                               SSE 는 스트림으로 이벤트만 받아 스냅샷이 없으므로 발화할
     *                               것이 없다(억지로 만들면 그 모드에만 별도 상태 폴링이
     *                               생긴다). 대신 SSE 가 워커를 뺏겨 폴링으로 갈아타면 그때
     *                               후계 모니터가 발화한다. 스냅샷을 받는 모드가 이 콜백을
     *                               빠뜨리지 않도록 InstallerMonitorContractTest 가 대조한다.
     */
    class StepwiseMonitor extends InstallationMonitor {
        constructor(callbacks, options = {}) {
            super(callbacks, options);
            this.runTaskUrl = options.runTaskUrl;
            this.token = options.token || '';
            this.busyRetryMs = options.busyRetryMs || 2000;
            // 이 창이 이미 화면에 그린 진행 기록 줄 수. busy 로 기다리는 동안 **다른 요청**이
            // 쓴 줄은 자기 events 에 오지 않아 그대로 사라졌다 — 서버가 그 꿬리만 실어 주도록
            // 기준을 함께 보낸다. 폴링이 이미 쓰는 log_offset 과 같은 방식이다.
            this.logOffset = Number.isFinite(options.logOffset) ? Math.max(0, options.logOffset) : 0;
            this.networkRetryMs = options.networkRetryMs || 3000;
            this.maxNetworkRetries = options.maxNetworkRetries || 3;
            this.networkFailures = 0;
            this.terminalEventSeen = false;
            // 진행 중인 요청을 끊을 수 있게 인스턴스에 둔다 (11차 #B20). 지역 변수로 두면
            // SSE 는 close(), 폴링은 clearInterval 로 즉시 끊는데 단계별만 못 끊어 [중단] 을
            // 눌러도 최대 11분짜리 요청과 그 타임아웃 타이머가 살아 있었다.
            this.activeController = null;
            this.activeTimeoutHandle = null;
            // 대기(busy·재시도) 타이머도 같은 이유로 인스턴스에 둔다 (19차 J8)
            this.activeSleepHandle = null;
            // 단계별 실행의 한 요청은 태스크 하나만큼 산다 — 서버가 스스로에게 주는 상한은
            // 180초(stepwise) 이고 서브프로세스 스폰 구간은 600초까지 올라간다. 그래서 이 모드의
            // 판정 창은 폴링·SSE 의 90초가 아니라 그 상한보다 조금 큰 값이어야 한다. 90초로 두면
            // 정상적인 긴 마이그레이션을 「정지」로 오판해 설치를 중단시킨다.
            this.stuckThresholdMs = options.stuckThresholdMs || 660000;
            // 응답이 오지 않는 요청(프록시 hold·태스크 hang)은 fetch 가 스스로 끝나지 않는다 —
            // 그러면 networkFailures 도 오르지 않아 루프가 await 에서 영구 정지한다.
            this.requestTimeoutMs = options.requestTimeoutMs || this.stuckThresholdMs;
        }

        start() {
            this._invoke('onConnected');
            // busy 무한 반복·응답 없음도 정지다 — 시간 기준 타이머가 그 상태를 끝낸다
            this._armStuckTimer();
            this._loop();
        }

        /**
         * 대기 타이머는 핸들을 보관한다 (19차 J8) — `stop()` 이 끊을 수 있어야 한다.
         *
         * 보관하지 않으면 busy 대기(2초)·재시도 대기(3초) 중의 [중단] 이 최대 3초짜리 고아
         * 타이머를 남긴다. SSE 의 `close()`·폴링의 `clearInterval` 과 달리 「전부 대칭 해제」
         * 규칙에서 유일하게 빠져 있던 자리다.
         */
        _sleep(ms) {
            return new Promise((resolve) => {
                this.activeSleepHandle = setTimeout(() => {
                    this.activeSleepHandle = null;
                    resolve();
                }, ms);
            });
        }

        stop() {
            super.stop();
            if (this.activeSleepHandle) {
                clearTimeout(this.activeSleepHandle);
                this.activeSleepHandle = null;
            }
            // 진행 중인 요청과 그 타임아웃 타이머를 함께 끊는다 (11차 #B20) — SSE 의
            // close()·폴링의 clearInterval 과 대칭이다.
            if (this.activeTimeoutHandle) {
                clearTimeout(this.activeTimeoutHandle);
                this.activeTimeoutHandle = null;
            }
            if (this.activeController) {
                try {
                    this.activeController.abort();
                } catch (e) {
                    // 이미 끝난 요청의 abort 는 무시한다
                }
                this.activeController = null;
            }
        }

        async _loop() {
            while (!this.stopped) {
                let body = null;
                let httpStatus = 0;

                const controller = (typeof AbortController === 'function') ? new AbortController() : null;
                const timeoutHandle = controller
                    ? setTimeout(() => controller.abort(), this.requestTimeoutMs)
                    : null;
                this.activeController = controller;
                this.activeTimeoutHandle = timeoutHandle;

                try {
                    const res = await fetch(this.runTaskUrl, {
                        method: 'POST',
                        cache: 'no-store',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Installer-Token': this.token,
                        },
                        body: JSON.stringify({ token: this.token, log_offset: this.logOffset }),
                        signal: controller ? controller.signal : undefined,
                    });
                    httpStatus = res.status;
                    body = await res.json();
                } catch (e) {
                    // 취소는 실패가 아니다 (19차 J5). `stop()` 이 부른 `AbortController.abort()`
                    // 가 바로 이 catch 로 되돌아온다. 그것을 네트워크 실패로 세면 두 가지가
                    // 어긋난다 — ① 침묵 타이머가 먼저 발화해 `stop()` 한 경우, 정확한 사유
                    // (「응답이 없습니다 — 정지」)가 **틀린 사유**(「요청 실패」)로 덮인다
                    // ② [중단] 시점에 실패 횟수가 이미 임계였으면 중단 화면 위에 실패 화면이
                    // 덧그려진다. 형제 지점(폴링의 두 재확인 · 이 루프의 성공 경로)은 이미
                    // 같은 가드를 갖고 있었다.
                    if (this.stopped || this.terminated) return;

                    // 응답 없음/비-JSON — 서버 프로세스가 중간에 죽었거나 네트워크 문제.
                    // 상태 파일은 완료된 태스크만 기록하므로 재호출하면 같은 태스크부터 이어진다.
                    this.networkFailures += 1;
                    if (this.networkFailures > this.maxNetworkRetries) {
                        this.stop();
                        this._invoke('onError', {
                            message: null,
                            message_key: 'error_stepwise_request_failed',
                            error: e && e.message ? e.message : null,
                            task: null,
                            target: null,
                            manual_commands: null,
                        });
                        return;
                    }
                    this._invoke('onRetry', this.networkFailures, this.maxNetworkRetries);
                    await this._sleep(this.networkRetryMs);
                    continue;
                } finally {
                    if (timeoutHandle) {
                        clearTimeout(timeoutHandle);
                    }
                    this.activeController = null;
                    this.activeTimeoutHandle = null;
                }

                this.networkFailures = 0;
                // 응답이 하나 도착했다 = 살아 있다. busy 만 계속 오는 경우에는 다시 걸지 않아
                // 침묵 타이머가 결국 그 상태를 끝낸다.

                if (this.stopped) return;

                this.terminalEventSeen = false;

                // busy 로 기다리는 동안 다른 요청이 쓴 진행 기록을 먼저 메운다 — 그 줄들은 이
                // 창의 events 에 오지 않아, 싣지 않으면 「단계는 초록인데 그 단계의 기록이 없다」가
                // 된다. 이벤트 재생보다 먼저 넣어야 시간 순서가 맞는다.
                if (Array.isArray(body && body.logs)) {
                    for (const log of body.logs) {
                        const msg = (typeof log === 'object' && log && log.message) ? log.message : String(log);
                        this._invoke('onLog', msg);
                    }
                }

                this._replay(Array.isArray(body && body.events) ? body.events : []);

                // 서버가 알려 준 총 줄 수를 기준으로 다시 잡는다 — 이 창이 그린 수를 스스로
                // 세면 재시도·재개에서 쉽게 어긋난다.
                if (Number.isFinite(body && body.log_total)) {
                    this.logOffset = Math.max(0, body.log_total);
                }

                // 이벤트 재생 뒤 서버 상태로 보정 — 이 요청이 돌리지 않은 태스크(끊긴 이전 요청이
                // 마저 끝낸 것)의 완료는 이벤트로 오지 않고 상태 요약에만 있다
                if (body && body.state && typeof body.state === 'object') {
                    this._invoke('onState', body.state);
                }

                const outcome = body ? body.outcome : null;

                switch (outcome) {
                    case 'task_done':
                        this._armStuckTimer();
                        continue;

                    case 'busy':
                        // 서버가 실은 사유(대기 중 / lock 을 다른 요청에 넘김)를 그대로 넘긴다 —
                        // 버리면 두 경우가 화면에서 같은 문구로 보인다
                        this._invoke('onBusy', (body && body.message) || null);
                        await this._sleep(this.busyRetryMs);
                        continue;

                    case 'completed':
                        this.stop();
                        if (!this.terminalEventSeen) {
                            this._invoke('onComplete', { message: null, redirect: '/install/' });
                        }
                        return;

                    case 'failed':
                        this.stop();
                        if (!this.terminalEventSeen) {
                            // 사유는 두 통로로 온다(상태 파일 / 500 봉투). 폴백 사슬은
                            // failurePayload() 가 소유한다 — 사본을 두면 그 사본이 뒤처져
                            // 같은 실패가 경로에 따라 다르게 보인다 (10차 #M4, 13차 F12).
                            this._invoke('onError', failurePayload(body && body.state, body));
                        }
                        return;

                    case 'aborted':
                        this.stop();
                        if (!this.terminalEventSeen) {
                            this._invoke('onAbort', (body && body.state) || {});
                        }
                        return;

                    default:
                        // rejected (토큰·모드 불일치·상태 불일치) 또는 알 수 없는 응답
                        this.stop();
                        // 서버가 번역된 문구를 줬으면 그것을, 없으면 화면이 번역 키로 그린다 —
                        // HTTP 코드 같은 개발자 표기는 제목이 아니라 진단 상세에만 싣는다 (§12.0 W3)
                        this._invoke('onError', {
                            message: (body && body.message) || null,
                            message_key: (body && body.message) ? null : 'error_stepwise_unexpected_response',
                            error: (body && body.message) ? null : `HTTP ${httpStatus}`,
                            task: null,
                            target: null,
                            manual_commands: null,
                        });
                        return;
                }
            }
        }

        /**
         * 서버가 모아 보낸 이벤트를 SSE 와 같은 콜백으로 재생한다.
         */
        _replay(events) {
            for (const entry of events) {
                const event = entry && entry.event;
                const data = (entry && entry.data) || {};
                switch (event) {
                    case 'task_start':
                        this._invoke('onTaskStart', data.task, data.name || null, data.target || null);
                        break;
                    case 'task_complete':
                        this._invoke('onTaskComplete', data.task, data.message || null, data.target || null, !!data.skipped);
                        break;
                    case 'log':
                        this._invoke('onLog', data.message);
                        break;
                    case 'rollback_failed':
                        this._invoke('onRollbackFailed', data);
                        break;
                    case 'error':
                        this.terminalEventSeen = true;
                        this._invoke('onError', data);
                        break;
                    case 'aborted':
                        this.terminalEventSeen = true;
                        this._invoke('onAbort', data);
                        break;
                    case 'completed':
                        this.terminalEventSeen = true;
                        this._invoke('onComplete', data);
                        break;
                    case 'heartbeat':
                        // SSE 와 같은 규약 — 진행 값이 실린 heartbeat 만 화면으로 나른다 (#B35).
                        if (typeof data.done === 'number' && typeof data.total === 'number' && data.total > 0) {
                            this._invoke('onHeartbeat', { done: data.done, total: data.total });
                        }
                        break;
                    default:
                        // connected — 화면 변화 없음
                        break;
                }
            }
        }
    }

    // 전역 노출
    global.G7InstallationMonitor = {
        InstallationMonitor,
        SseMonitor,
        PollingMonitor,
        StepwiseMonitor,
        // 새로고침 복원 경로(installer.js)도 같은 사슬을 써야 한다 — 그래서 함께 노출한다.
        failurePayload,
    };
})(window);
