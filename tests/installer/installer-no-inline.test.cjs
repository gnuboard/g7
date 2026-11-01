'use strict';
/**
 * 설치 마법사·미설치 첫 화면에 실행형 인라인 코드가 없다 (#158 strict CSP)
 *
 * 실행: node --test tests/installer/installer-no-inline.test.cjs
 *
 * 설치기는 Laravel 밖이라 코어 CSP 헤더가 붙지 않지만, 운영자가 웹서버에 `script-src 'self'` 를 걸면
 * 그대로 영향을 받는다. 종전에는 뷰 인라인 `<script>`·on* 속성·`javascript:` 링크·alert 리다이렉트가 있어
 * 단계 로직과 버튼이 멈췄다. 모집단은 파일시스템에서 파생한다(새 뷰·스크립트도 자동 포함).
 *
 * @scenario surface=installer, csp=webserver_strict, failure=none
 * @effects installer_has_no_inline_executable
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.resolve(__dirname, '../..');

function walk(dir, out = []) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            if (entry.name === 'vendor' || entry.name === 'node_modules') continue; // 동봉 서드파티(font-awesome 등)
            walk(full, out);
        } else if (/\.(php|js)$/.test(entry.name)) {
            out.push(full);
        }
    }
    return out;
}

// 블록 주석·줄 주석·PHP 주석을 걷어낸 본문 (설명 문장 속 `<script>` 언급은 실행 코드가 아니다)
function code(source, file) {
    let text = source.replace(/\/\*[\s\S]*?\*\//g, '');
    if (file.endsWith('.js')) text = text.replace(/(^|[^:'"\\])\/\/[^\n]*/g, '$1');
    // PHP 출력 태그(`<?= … ?>`)는 값 자리 하나로 본다 — 속성 안의 `?>` 를 태그 끝으로 읽지 않게
    return text.replace(/<\?php\s*\/\*[\s\S]*?\*\/\s*\?>/g, '').replace(/<\?=[\s\S]*?\?>/g, 'X');
}

const FILES = [path.join(ROOT, 'public/index.php'), ...walk(path.join(ROOT, 'public/install'))];

test('모집단: 설치기 뷰·스크립트를 실제로 읽는다', () => {
    assert.ok(FILES.length > 20, `대상 파일이 너무 적다: ${FILES.length}`);
    assert.ok(FILES.some(f => f.endsWith(path.join('views', '3-configuration.php'))));
    assert.ok(FILES.some(f => f.endsWith(path.join('assets', 'js', 'installer.js'))));
});

test('실행형 인라인 <script> 본문이 없다 (JSON 데이터 블록·src 스크립트만)', () => {
    const offenders = [];
    for (const file of FILES) {
        const text = code(fs.readFileSync(file, 'utf8'), file);
        const re = /<script\b([^>]*)>([\s\S]*?)<\/script>/gi;
        let m;
        while ((m = re.exec(text)) !== null) {
            if (/type=["']application\/(?:ld\+)?json["']/.test(m[1])) continue;
            if (m[2].trim() !== '') offenders.push(`${path.relative(ROOT, file)}: ${m[2].trim().slice(0, 60)}`);
        }
        // echo '<script>' 처럼 문자열로 조립하는 형태
        if (/['"]<script>['"]/.test(text)) offenders.push(`${path.relative(ROOT, file)}: 문자열로 조립한 <script>`);
    }
    assert.deepEqual(offenders, []);
});

test('on* 인라인 핸들러·javascript: 링크가 없다 (서버 HTML·JS 문자열 템플릿 모두)', () => {
    const offenders = [];
    for (const file of FILES) {
        const text = code(fs.readFileSync(file, 'utf8'), file);
        const lines = text.split('\n');
        lines.forEach((line, i) => {
            if (/\son[a-z]+\s*=\s*["'\\]/i.test(line)) offenders.push(`${path.relative(ROOT, file)}:${i + 1} on*`);
            if (/href\s*=\s*["']\s*javascript:/i.test(line)) offenders.push(`${path.relative(ROOT, file)}:${i + 1} javascript:`);
        });
    }
    assert.deepEqual(offenders, []);
});

test('미설치 첫 화면은 스크립트 없이 302, 안내 후 이동 페이지는 meta refresh 로 보낸다', () => {
    const index = fs.readFileSync(path.join(ROOT, 'public/index.php'), 'utf8');
    assert.match(index, /header\('Location: '\.\$installUrl, true, 302\);/);
    assert.doesNotMatch(index, /alert\(/);

    const functions = fs.readFileSync(path.join(ROOT, 'public/install/includes/functions.php'), 'utf8');
    const body = functions.slice(functions.indexOf('function showAlertAndRedirect'));
    assert.match(body.slice(0, 2000), /<meta http-equiv="refresh" content="3;url=/);
    assert.doesNotMatch(body.slice(0, 2000), /alert\(/);
});

test('미설치 이동 주소는 Windows 루트 설치에서도 /install 이다 (dirname 역슬래시 정규화)', () => {
    // Windows 의 dirname('/index.php') 는 '\' — 종전 JS 문자열에서는 '\/' 가 '/' 였지만 Location 헤더의
    // '\/install' 은 브라우저가 '//install'(다른 호스트)로 읽는다(2026-10-31 실측).
    const index = fs.readFileSync(path.join(ROOT, 'public/index.php'), 'utf8');
    const expr = index.match(/\$installUrl = (.+);/)[1];
    assert.match(expr, /str_replace\('\\\\', '\/'/, '역슬래시를 슬래시로 바꿔야 한다');

    const { execFileSync } = require('node:child_process');
    for (const [scriptName, expected] of [['/index.php', '/install'], ['/sub/index.php', '/sub/install'], ['/a/b/index.php', '/a/b/install']]) {
        const code = `$_SERVER['SCRIPT_NAME'] = ${JSON.stringify(scriptName)}; echo ${expr};`;
        let out;
        try {
            out = execFileSync('php', ['-r', code], { encoding: 'utf8' });
        } catch {
            return; // php CLI 가 없는 환경은 위 정적 검사만으로 갈음한다
        }
        assert.equal(out, expected, scriptName);
    }
});
