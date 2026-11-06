'use strict';
/**
 * 웹 설치 마법사 Step 5 — SSE 호환성 사전 탐지 단위 시험.
 *
 * 실행: node --test tests/installer/installer-sse-probe.test.cjs
 *
 * sse-probe.php 는 probe 이벤트 두 개를 2초 간격으로 보내고, 클라이언트는 도착 간격으로 스트리밍 여부를 판정한다
 * (버퍼 환경은 응답이 끝날 때 두 이벤트가 함께 도착). 첫 연결의 1차 이벤트만 늦게 도착하면(실측 0.94s·2.23s — 간격
 * 1.29s) 스트리밍 환경인데도 「SSE 를 쓸 수 없습니다 → 폴링?」 확인창이 떴다. 간격이 임계보다 짧지만 함께 도착한 것은
 * 아니면 한 번 더 잰다 — 버퍼 환경(간격 ≈0)은 다시 재지 않고 바로 비호환이다.
 *
 * installer.js 는 브라우저 전역 IIFE 라 함수 선언만 소스에서 잘라 vm 컨텍스트에서 돌린다.
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const ROOT = path.resolve(__dirname, '../..');
const SOURCE = fs.readFileSync(path.join(ROOT, 'public/install/assets/js/installer.js'), 'utf8');

/**
 * start 위치의 `{` 와 짝인 `}` 의 위치를 찾는다(문자열·템플릿 리터럴·주석 안의 괄호는 건너뜀).
 */
function matchBrace(src, start) {
    let depth = 0;
    for (let i = start; i < src.length; i++) {
        const ch = src[i];
        const next = src[i + 1];
        if (ch === '/' && next === '/') { i = src.indexOf('\n', i); if (i === -1) break; continue; }
        if (ch === '/' && next === '*') { i = src.indexOf('*/', i + 2) + 1; continue; }
        if (ch === '\'' || ch === '"' || ch === '`') {
            for (i = i + 1; i < src.length; i++) {
                if (src[i] === '\\') { i++; continue; }
                if (src[i] === ch) break;
            }
            continue;
        }
        if (ch === '{') depth++;
        if (ch === '}') { depth--; if (depth === 0) return i; }
    }
    throw new Error('짝이 맞는 } 를 찾지 못함 @' + start);
}

/** `function name(...) { ... }` 선언 전체를 잘라 낸다(없으면 빈 문자열). */
function extractFunction(name, required = true) {
    const re = new RegExp('\\n[ \\t]*(?:async\\s+)?function ' + name + '\\s*\\(');
    const m = re.exec(SOURCE);
    if (!m) {
        if (required) throw new Error('installer.js 에 function ' + name + ' 이 없음');
        return '';
    }
    const begin = m.index + 1;
    const open = SOURCE.indexOf('{', SOURCE.indexOf(')', begin));
    return SOURCE.slice(begin, matchBrace(SOURCE, open) + 1);
}

/**
 * 연결마다 정해 둔 시각에 probe 이벤트를 보내는 가짜 EventSource 로 탐지를 돌린다.
 *
 * @param {Array<Array<[number, number]>|'error'>} scripts 연결 순서대로 [[phase, 도착 ms], …] 또는 'error'
 * @returns {Promise<{ compatible: boolean, connections: number }>}
 */
async function runDetect(scripts) {
    let clock = 0;
    let connections = 0;
    class FakeEventSource {
        constructor() {
            const script = scripts[connections++];
            this.listeners = {};
            this.onerror = null;
            setImmediate(() => {
                if (script === 'error' || script === undefined) {
                    if (this.onerror) this.onerror(new Error('connection failed'));
                    return;
                }
                for (const [phase, at] of script) {
                    if (this.closed) return;
                    clock = at;
                    (this.listeners.probe || []).forEach((fn) => fn({ data: JSON.stringify({ phase, server_ts: at / 1000 }) }));
                }
            });
        }
        addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); }
        close() { this.closed = true; }
    }
    const sandbox = {
        window: { location: { origin: 'https://example.test' }, INSTALLER_BASE_URL: '/install' },
        EventSource: FakeEventSource,
        Date: { now: () => clock },
        setTimeout: () => 0, // 시간 초과 갈래는 쓰지 않는다
        JSON,
        Promise,
    };
    vm.createContext(sandbox);
    vm.runInContext(
        [extractFunction('probeSseStreamingOnce', false), extractFunction('detectSseCompatibility')].join('\n')
            + '\nthis.detectSseCompatibility = detectSseCompatibility;',
        sandbox,
    );
    const compatible = await sandbox.detectSseCompatibility(5000);
    return { compatible, connections };
}

test('간격 2초(스트리밍)면 한 번에 호환', async () => {
    const r = await runDetect([[[1, 100], [2, 2100]]]);
    assert.equal(r.compatible, true);
    assert.equal(r.connections, 1);
});

test('첫 연결의 1차 이벤트만 늦어 간격이 1.29초로 줄면 한 번 더 재서 호환으로 판정한다', async () => {
    const r = await runDetect([[[1, 940], [2, 2230]], [[1, 50], [2, 2060]]]);
    assert.equal(r.compatible, true, '스트리밍 환경인데 폴링 확인창이 뜨면 안 된다');
    assert.equal(r.connections, 2);
});

test('버퍼 환경(두 이벤트가 함께 도착)은 다시 재지 않고 바로 비호환', async () => {
    const r = await runDetect([[[1, 2050], [2, 2052]]]);
    assert.equal(r.compatible, false);
    assert.equal(r.connections, 1, '버퍼 환경에서 확인창을 늦추지 않는다');
});

test('두 번 모두 간격이 짧으면 비호환', async () => {
    const r = await runDetect([[[1, 940], [2, 2230]], [[1, 900], [2, 2100]]]);
    assert.equal(r.compatible, false);
    assert.equal(r.connections, 2);
});

test('연결 오류는 다시 재지 않고 비호환', async () => {
    const r = await runDetect(['error']);
    assert.equal(r.compatible, false);
    assert.equal(r.connections, 1);
});
