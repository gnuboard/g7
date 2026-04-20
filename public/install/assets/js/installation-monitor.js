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
     */
    class SseMonitor extends InstallationMonitor {
        constructor(callbacks, options = {}) {
            super(callbacks, options);
            this.workerUrl = options.workerUrl;
            this.connectionTimeoutMs = options.connectionTimeoutMs || 5000;
            this.eventSource = null;
            this.connected = false;
            this.connectionTimer = null;
        }

        start() {
            this.eventSource = new EventSource(this.workerUrl);

            this.connectionTimer = setTimeout(() => {
                if (!this.connected && !this.stopped) {
                    this.stop();
                    this._invoke('onConnectionTimeout');
                }
            }, this.connectionTimeoutMs);

            this.eventSource.addEventListener('connected', () => {
                clearTimeout(this.connectionTimer);
                this.connected = true;
                this._invoke('onConnected');
            });

            this.eventSource.addEventListener('task_start', (e) => {
                const data = JSON.parse(e.data);
                this._invoke('onTaskStart', data.task, data.name, data.target || null);
            });

            this.eventSource.addEventListener('task_complete', (e) => {
                const data = JSON.parse(e.data);
                this._invoke('onTaskComplete', data.task, data.message, data.target || null);
            });

            this.eventSource.addEventListener('log', (e) => {
                const data = JSON.parse(e.data);
                this._invoke('onLog', data.message);
            });

            this.eventSource.addEventListener('completed', (e) => {
                const data = JSON.parse(e.data);
                this.stop();
                this._invoke('onComplete', data);
            });

            this.eventSource.addEventListener('aborted', (e) => {
                let data = {};
                try {
                    data = JSON.parse(e.data);
                } catch (_) {}
                this.stop();
                this._invoke('onAbort', data);
            });

            this.eventSource.addEventListener('rollback_failed', (e) => {
                let data = {};
                try {
                    data = JSON.parse(e.data);
                } catch (_) {}
                this._invoke('onRollbackFailed', data);
            });

            this.eventSource.addEventListener('error', (e) => {
                // 서버에서 보낸 명시적 error 이벤트 (e.data 있음)
                if (!e.data) {
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
            this.eventSource.onerror = () => {
                clearTimeout(this.connectionTimer);
                if (!this.connected && !this.stopped) {
                    this.stop();
                    this._invoke('onConnectionTimeout');
                }
            };
        }

        stop() {
            super.stop();
            if (this.connectionTimer) {
                clearTimeout(this.connectionTimer);
                this.connectionTimer = null;
            }
            if (this.eventSource) {
                this.eventSource.close();
                this.eventSource = null;
            }
        }
    }

    /**
     * 폴링 기반 모니터 (state-management.php?action=get)
     *
     * 1초 간격으로 state를 조회하고 diff 발생 시 콜백을 호출합니다.
     */
    class PollingMonitor extends InstallationMonitor {
        constructor(callbacks, options = {}) {
            super(callbacks, options);
            this.stateUrl = options.stateUrl;
            this.intervalMs = options.intervalMs || 1000;
            this.timer = null;
            this.lastLogOffset = 0;
            this.lastCompletedTasks = [];
            this.lastCurrentTask = null;
            this.lastStatus = null;
            this.terminated = false;
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
                const state = await res.json();
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
