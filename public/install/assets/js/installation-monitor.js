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
 *   onTaskComplete(taskId, message, target)      — 작업 완료
 *   onLog(message)                               — 로그 라인 추가
 *   onComplete(data)                             — 전체 설치 성공
 *   onError(errorData)                           — 실패 (data: {message, message_key, error, task, target, manual_commands})
 *   onAbort(state)                               — 사용자 중단
 *   onRollbackFailed(data)                       — 롤백 실패
 *   onConnectionTimeout()                        — SSE 5초 내 연결 실패 (SSE 전용)
 *
 * SSE 모드에서 연결 수립 뒤 스트림이 끊기면 서버 상태를 조회해, 워커 없이 설치가 남았으면
 * 새 워커로 다시 연결해 이어 받고, 아니면 onError / onAbort / onComplete 중 하나로 결말을 알린다
 * — 화면이 「진행 중」에 멈추지 않는다.
 */
(function (global) {
    'use strict';

    /**
     * 기본 모니터 클래스 (공통 인터페이스)
     */
    class InstallationMonitor {
        constructor(callbacks, options = {}) {
            this.callbacks = callbacks || {};
            this.options = options;
            this.stopped = false;
        }

        start() {
            throw new Error('start() must be implemented by subclass');
        }

        stop() {
            this.stopped = true;
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
     *
     * 연결 수립 뒤 스트림이 끊기면 브라우저 자동 재연결에 맡기지 않고(EventSource 를 닫는다)
     * 서버 상태(state-management.php)로 다음을 정한다.
     *  - failed / aborted / completed: 해당 콜백으로 결말을 알린다.
     *  - running 이고 워커 잠금이 비어 있음(worker_active=false): 앞 워커가 끝났는데 설치가 남았다.
     *    새 워커로 다시 연결해 남은 작업을 이어 받는다(끝난 작업은 서버가 건너뛴다).
     *  - running 이고 잠금이 남아 있음: 앞 워커가 아직 작업을 돌리고 있을 수 있다. 같은 작업을 두 워커가
     *    동시에 돌리지 않도록 잠금이 풀릴 때까지 기다린다.
     *  - running 인 채 last_updated 가 stuckThresholdMs 동안 그대로면(조회 실패 포함) error_installation_stuck.
     * 다시 연결은 진척(새로 끝난 작업) 없이 maxReconnects 번까지만 한다.
     */
    class SseMonitor extends InstallationMonitor {
        /**
         * 진척 없이 다시 연결할 수 있는 횟수.
         *
         * 한 번 끊긴 스트림을 이어 받기에는 충분하고, 연결할 때마다 바로 끊기는 서버에서
         * 워커 요청을 끝없이 보내지 않을 만큼 작다.
         */
        static MAX_RECONNECTS_WITHOUT_PROGRESS = 3;

        constructor(callbacks, options = {}) {
            super(callbacks, options);
            this.workerUrl = options.workerUrl;
            this.connectionTimeoutMs = options.connectionTimeoutMs || 5000;
            this.eventSource = null;
            this.connected = false;
            this.connectionTimer = null;
            // 연결 수립 뒤 스트림이 끊겼을 때 결말을 판정할 상태 조회 주소와 주기·임계값.
            this.stateUrl = options.stateUrl || null;
            this.watchIntervalMs = options.watchIntervalMs || 2000;
            // 폴링 모드의 stuck 임계값과 같은 기본 90 초 — 워커가 작업을 진행하면 last_updated 가
            // 바뀌므로, 이 시간 동안 그대로면 이어 받을 워커가 없는 것으로 본다.
            this.stuckThresholdMs = options.stuckThresholdMs || 90000;
            this.maxReconnects = Number.isInteger(options.maxReconnects)
                ? options.maxReconnects
                : SseMonitor.MAX_RECONNECTS_WITHOUT_PROGRESS;
            this.watchTimer = null;
            this.watchFinished = false;
            this.watchLastUpdated = undefined;
            this.watchLastChangeMs = 0;
            this.watchLastTask = null;
            this.reconnectAttempts = 0;
            // 진척 판정용 — 이 화면이 끝났다고 받은 작업 키(task 또는 task:target)
            this.completedSeen = new Set();
        }

        start() {
            this._openStream(false);
        }

        /**
         * 워커 스트림을 연다.
         *
         * @param {boolean} isReconnect 끊긴 뒤 이어 받기 위한 연결이면 true
         */
        _openStream(isReconnect) {
            const es = new EventSource(this.workerUrl);
            this.eventSource = es;
            let streamConnected = false;
            const isCurrent = () => !this.stopped && this.eventSource === es;

            this.connectionTimer = setTimeout(() => {
                if (!isCurrent() || streamConnected) {
                    return;
                }
                if (isReconnect) {
                    // 이어 받기 연결이 수립되지 않았다 — 다시 상태를 보고 정한다
                    this._handleDisconnect(false);
                    return;
                }
                this.stop();
                this._invoke('onConnectionTimeout');
            }, this.connectionTimeoutMs);

            es.addEventListener('connected', () => {
                if (!isCurrent()) return;
                clearTimeout(this.connectionTimer);
                this.connectionTimer = null;
                streamConnected = true;
                if (!this.connected) {
                    this.connected = true;
                    this._invoke('onConnected');
                }
            });

            es.addEventListener('task_start', (e) => {
                if (!isCurrent()) return;
                const data = JSON.parse(e.data);
                this._invoke('onTaskStart', data.task, data.name, data.target || null);
            });

            es.addEventListener('task_complete', (e) => {
                if (!isCurrent()) return;
                const data = JSON.parse(e.data);
                const key = data.target ? `${data.task}:${data.target}` : data.task;
                if (!this.completedSeen.has(key)) {
                    // 새로 끝난 작업 — 이어 받기가 진척을 냈다
                    this.completedSeen.add(key);
                    this.reconnectAttempts = 0;
                }
                this._invoke('onTaskComplete', data.task, data.message, data.target || null);
            });

            es.addEventListener('log', (e) => {
                if (!isCurrent()) return;
                const data = JSON.parse(e.data);
                this._invoke('onLog', data.message);
            });

            es.addEventListener('completed', (e) => {
                if (!isCurrent()) return;
                const data = JSON.parse(e.data);
                this.stop();
                this._invoke('onComplete', data);
            });

            es.addEventListener('aborted', (e) => {
                if (!isCurrent()) return;
                let data = {};
                try {
                    data = JSON.parse(e.data);
                } catch (_) {}
                if (isReconnect && data && data.reason === 'busy') {
                    // 이어 받기 요청이 「다른 워커 진행 중」 으로 거절됐다 — 설치 중단이 아니다.
                    // 앞 워커가 잠금을 풀 때까지 상태를 보며 기다린다.
                    this._handleDisconnect(false);
                    return;
                }
                this.stop();
                this._invoke('onAbort', data);
            });

            es.addEventListener('rollback_failed', (e) => {
                if (!isCurrent()) return;
                let data = {};
                try {
                    data = JSON.parse(e.data);
                } catch (_) {}
                this._invoke('onRollbackFailed', data);
            });

            es.addEventListener('error', (e) => {
                // 서버에서 보낸 명시적 error 이벤트 (e.data 있음)
                if (!e.data || !isCurrent()) {
                    return;
                }
                let data = {};
                try {
                    data = JSON.parse(e.data);
                } catch (_) {
                    data = { message: 'Unknown error' };
                }
                this.stop();
                this._invoke('onError', data);
            });

            // 연결 오류 (네이티브 onerror) — e.data 없음
            es.onerror = () => {
                if (!isCurrent()) {
                    return;
                }
                clearTimeout(this.connectionTimer);
                this.connectionTimer = null;
                if (!isReconnect && !this.connected) {
                    this.stop();
                    this._invoke('onConnectionTimeout');
                    return;
                }
                // 연결 수립 뒤 끊김 — 워커가 끝났거나(연결 끊김을 감지한 PHP 가 스크립트를 끝냄,
                // 실행 시간 제한, 프로세스 종료) 망이 끊겼다. 브라우저 자동 재연결은 실패해도 아무 콜백이
                // 불리지 않고, 앞 워커가 살아 있을 때 새 워커를 띄울 수도 있다 — 닫고 상태로 정한다.
                this._handleDisconnect(streamConnected);
            };
        }

        /**
         * 스트림을 닫고 상태 감시로 넘어간다.
         *
         * @param {boolean} streamWasConnected 닫힌 스트림이 연결까지 됐었는지 — 그랬다면 멈춤 판정 기준
         *   시각을 지금으로 다시 잡는다. 연결되지 못한 이어 받기 시도는 기준 시각을 그대로 둬,
         *   거듭 실패해도 임계 시간 안에 결말이 난다.
         */
        _handleDisconnect(streamWasConnected) {
            if (this.stopped) return;
            if (this.connectionTimer) {
                clearTimeout(this.connectionTimer);
                this.connectionTimer = null;
            }
            this._closeEventSource();
            if (streamWasConnected || this.watchLastChangeMs === 0) {
                this.watchLastChangeMs = Date.now();
                this.watchLastUpdated = undefined;
            }
            this._watchStateAfterDisconnect();
        }

        /**
         * 스트림이 끊긴 뒤 state-management.php 로 다음 할 일을 정한다(클래스 설명 참고).
         */
        _watchStateAfterDisconnect() {
            if (this.watchTimer || this.watchFinished) {
                return;
            }

            if (!this.stateUrl) {
                this._finishWatch('onError', this._stuckErrorData());
                return;
            }

            this._pollStateOnce();
            this.watchTimer = setInterval(() => this._pollStateOnce(), this.watchIntervalMs);
        }

        async _pollStateOnce() {
            if (this.watchFinished || this.stopped || this.eventSource) return;

            let state = null;
            try {
                // 로그는 필요 없다 — 큰 offset 으로 본문을 줄인다.
                const url = `${this.stateUrl}${this.stateUrl.includes('?') ? '&' : '?'}log_offset=2147483647`;
                const res = await fetch(url, { cache: 'no-store' });
                if (res && res.ok) {
                    state = JSON.parse(await res.text());
                }
            } catch (_) {
                state = null;
            }
            // 기다리는 사이 판정이 끝났거나 이어 받기 스트림이 열렸으면 이 응답은 버린다
            if (this.watchFinished || this.stopped || this.eventSource) return;

            if (state && typeof state === 'object') {
                if (state.current_task) {
                    this.watchLastTask = state.current_task;
                }
                switch (state.status) {
                    case 'failed': {
                        const failedKey = state.failed_task || null;
                        const colonIdx = failedKey ? failedKey.indexOf(':') : -1;
                        this._finishWatch('onError', {
                            message: state.error_detail || null,
                            message_key: state.error_message_key || null,
                            error: state.error_detail || null,
                            task: colonIdx !== -1 ? failedKey.substring(0, colonIdx) : failedKey,
                            target: colonIdx !== -1 ? failedKey.substring(colonIdx + 1) : null,
                            manual_commands: state.manual_commands || null,
                        });
                        return;
                    }
                    case 'aborted':
                        this._finishWatch('onAbort', state);
                        return;
                    case 'completed':
                        this._finishWatch('onComplete', { message: null, redirect: '/install/' });
                        return;
                    default:
                        if (state.last_updated !== this.watchLastUpdated) {
                            // 첫 응답은 기준값만 잡는다 (끊긴 시각부터 잰다).
                            if (this.watchLastUpdated !== undefined) {
                                this.watchLastChangeMs = Date.now();
                            }
                            this.watchLastUpdated = state.last_updated;
                        }
                        // 설치는 남았는데 워커 잠금이 비었다 — 새 워커로 이어 받는다.
                        // 잠금이 남아 있으면(worker_active=true) 앞 워커가 끝날 때까지 기다린다.
                        if (
                            state.status === 'running' &&
                            state.worker_active === false &&
                            this.reconnectAttempts < this.maxReconnects
                        ) {
                            this._reconnect();
                            return;
                        }
                }
            }

            if (Date.now() - this.watchLastChangeMs >= this.stuckThresholdMs) {
                this._finishWatch('onError', this._stuckErrorData());
            }
        }

        _reconnect() {
            if (this.stopped || this.eventSource) return;
            this.reconnectAttempts += 1;
            this._stopWatchTimer();
            this._openStream(true);
        }

        _stuckErrorData() {
            return {
                message: null,
                message_key: 'error_installation_stuck',
                error: null,
                task: this.watchLastTask,
                target: null,
                manual_commands: null,
                stuck: true,
                idle_seconds: Math.floor((Date.now() - this.watchLastChangeMs) / 1000),
            };
        }

        _finishWatch(callbackName, data) {
            if (this.watchFinished || this.stopped) return;
            this.watchFinished = true;
            this.stop();
            this._invoke(callbackName, data);
        }

        _stopWatchTimer() {
            if (this.watchTimer) {
                clearInterval(this.watchTimer);
                this.watchTimer = null;
            }
        }

        _closeEventSource() {
            if (this.eventSource) {
                this.eventSource.close();
                this.eventSource = null;
            }
        }

        stop() {
            super.stop();
            if (this.connectionTimer) {
                clearTimeout(this.connectionTimer);
                this.connectionTimer = null;
            }
            this._stopWatchTimer();
            this._closeEventSource();
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
            // stuck 판정 임계값 (기본 90 초). last_updated 가 이 시간 이상 갱신되지 않으면
            // 워커가 명령 실행 중 deadlock/silent-crash 됐다고 판정해 onError 발화.
            this.stuckThresholdMs = options.stuckThresholdMs || 90000;
            this.timer = null;
            this.lastLogOffset = 0;
            this.lastCompletedTasks = [];
            this.lastCurrentTask = null;
            this.lastStatus = null;
            this.terminated = false;
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

            try {
                const url = `${this.stateUrl}${this.stateUrl.includes('?') ? '&' : '?'}log_offset=${this.lastLogOffset}`;
                const res = await fetch(url, { cache: 'no-store' });
                if (!res.ok) {
                    return; // 일시적인 네트워크 오류는 무시 (다음 tick에서 재시도)
                }
                const text = await res.text();

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
            }
        }

        _diffAndEmit(state) {
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
            const completedTasks = Array.isArray(state.completed_tasks) ? state.completed_tasks : [];
            for (const key of completedTasks) {
                if (!this.lastCompletedTasks.includes(key)) {
                    let taskId = key;
                    let target = null;
                    const colonIdx = key.indexOf(':');
                    if (colonIdx !== -1) {
                        taskId = key.substring(0, colonIdx);
                        target = key.substring(colonIdx + 1);
                    }
                    this._invoke('onTaskComplete', taskId, null, target);
                }
            }
            this.lastCompletedTasks = completedTasks.slice();

            // 4. rollback_failure 저장 (에러/중단 처리 시 UI에 표시)
            if (state.rollback_failure && this.lastStatus !== state.status) {
                this._invoke('onRollbackFailed', {
                    message: state.rollback_failure.message || null,
                    detail: state.rollback_failure.detail_key || null,
                });
            }

            // 4-1. stuck 감지 — running 상태에서 last_updated 가 stuckThresholdMs 이상 갱신 안 되면 에러 처리
            //      서버 측 워커가 exec() 중 deadlock 되거나 PHP 프로세스가 silent crash 된 케이스를 client 가 알림.
            if (state.status === 'running' && !this.stuckEmitted) {
                if (state.last_updated !== this.lastUpdatedSeen) {
                    this.lastUpdatedSeen = state.last_updated;
                    this.lastUpdatedClientMs = Date.now();
                } else if (this.lastUpdatedSeen !== null) {
                    const idleMs = Date.now() - this.lastUpdatedClientMs;
                    if (idleMs >= this.stuckThresholdMs) {
                        this.stuckEmitted = true;
                        this.stop();
                        this._invoke('onError', {
                            message: null,
                            message_key: 'error_installation_stuck',
                            error: null,
                            task: state.current_task || null,
                            target: null,
                            manual_commands: null,
                            stuck: true,
                            idle_seconds: Math.floor(idleMs / 1000),
                        });
                        return;
                    }
                }
            }

            // 4-2. 완료 표식(complete_flag) 뒤 마무리 작업(설정·라우트 캐시 굽기·정적 게시)이 남아 있으면 기다린다.
            //      먼저 완료 처리하면 finalize-env 가 상태 파일을 지워 워커가 lock lost 로 끝나고,
            //      설정 캐시에 확정 전 값(installer_completed=false)이 남는다. 상태 갱신이 임계 시간 넘게 멈추면
            //      설치 자체는 끝났으므로 완료 처리한다. tasks_finished 가 없는 응답은 종전처럼 바로 완료.
            if (state.status === 'completed' && state.tasks_finished === false) {
                if (state.last_updated !== this.lastUpdatedSeen) {
                    this.lastUpdatedSeen = state.last_updated;
                    this.lastUpdatedClientMs = Date.now();
                    return;
                }
                if (Date.now() - this.lastUpdatedClientMs < this.stuckThresholdMs) {
                    return;
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
                        this._invoke('onError', {
                            message: state.error_detail || null,
                            message_key: state.error_message_key || null,
                            error: state.error_detail || null,
                            task: state.failed_task || null,
                            target: null,
                            manual_commands: state.manual_commands || null,
                        });
                        break;
                    case 'aborted':
                        this.stop();
                        this._invoke('onAbort', state);
                        break;
                }
            }
            this.lastStatus = status;
        }
    }

    // 전역 노출
    global.G7InstallationMonitor = {
        InstallationMonitor,
        SseMonitor,
        PollingMonitor,
    };
})(window);
