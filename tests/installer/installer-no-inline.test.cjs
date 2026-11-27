'use strict';
/**
 * 설치 마법사·미설치 첫 화면에 실행형 인라인 코드가 없다 (공개 #158 strict CSP)
 *
 * 실행: node --test tests/installer/installer-no-inline.test.cjs
 *
 * 설치기는 Laravel 밖이라 코어 CSP 헤더가 붙지 않지만, 운영자가 웹서버에 `script-src 'self'` 를 걸면
 * 그대로 영향을 받는다. 종전에는 뷰 인라인 `<script>`·on* 속성·`javascript:` 링크·alert 리다이렉트가 있어
 * 단계 로직과 버튼이 멈췄다. 모집단은 파일시스템에서 파생한다(새 뷰·스크립트도 자동 포함).
 *
 * 스타일도 같다(공개 #163). `style-src 'self'; style-src-attr 'none'` 에서는 `<style>` 요소·마크업의 style 속성·
 * `setAttribute('style', …)` 가 막혀 숨겨 둔 영역이 드러나고 배치가 무너진다.
 *
 * 마킹은 각 test 바로 앞에 있다(파일 머리말의 마킹은 어느 시험이 재는지 가리키지 않는다).
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

/**
 * @scenario surface=installer, csp=webserver_strict, failure=none
 * @effects installer_has_no_inline_executable
 */
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

/**
 * @scenario surface=installer, csp=webserver_strict, failure=none
 * @effects installer_has_no_inline_executable
 */
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

// ── 인라인 스타일 (공개 #163 strict style CSP) ──────────────────────────────
// 웹서버가 `style-src 'self'; style-src-attr 'none'` 을 걸면 막히는 것은 세 가지다:
// <style> 요소 / HTML 문자열(서버 HTML·innerHTML)의 style 속성 / setAttribute('style', …).
// `el.style.x = …` 같은 CSSOM 대입은 막히지 않으므로 검사하지 않는다.

/**
 * @scenario surface=installer, csp=webserver_strict, failure=none
 * @effects installer_has_no_inline_style
 */
test('<style> 요소가 없다 (서버 HTML·JS 문자열 템플릿 모두 — 스타일은 CSS 파일로 싣는다)', () => {
    const offenders = [];
    for (const file of FILES) {
        const text = code(fs.readFileSync(file, 'utf8'), file);
        text.split('\n').forEach((line, i) => {
            if (/<style[\s>]/i.test(line)) offenders.push(`${path.relative(ROOT, file)}:${i + 1} <style>`);
        });
    }
    assert.deepEqual(offenders, []);
});

/**
 * @scenario surface=installer, csp=webserver_strict, failure=none
 * @effects installer_has_no_inline_style
 */
test('마크업의 style 속성이 없다 (서버 HTML·JS 문자열 템플릿 모두)', () => {
    const offenders = [];
    for (const file of FILES) {
        const text = code(fs.readFileSync(file, 'utf8'), file);
        text.split('\n').forEach((line, i) => {
            // 속성 자리의 style= 만 본다 — `el.style.x =` 대입은 앞이 공백이 아니라 걸리지 않는다
            if (/[\s"']style\s*=\s*["'\\`$]/i.test(line)) offenders.push(`${path.relative(ROOT, file)}:${i + 1} style=`);
        });
    }
    assert.deepEqual(offenders, []);
});

/**
 * @scenario surface=installer, csp=webserver_strict, failure=none
 * @effects installer_has_no_inline_style
 */
test("setAttribute('style', …) 가 없다", () => {
    const offenders = [];
    for (const file of FILES) {
        const text = code(fs.readFileSync(file, 'utf8'), file);
        text.split('\n').forEach((line, i) => {
            if (/setAttribute\(\s*["'`]style["'`]/.test(line)) offenders.push(`${path.relative(ROOT, file)}:${i + 1} setAttribute('style')`);
        });
    }
    assert.deepEqual(offenders, []);
});

/**
 * @scenario surface=installer, csp=webserver_strict, failure=none
 * @effects installer_standalone_pages_link_css
 */
test('독립 화면(안내 후 이동·403)은 스타일 파일을 <link> 로 싣는다', () => {
    assert.ok(fs.existsSync(path.join(ROOT, 'public/install/assets/css/installer-standalone.css')));

    const functions = fs.readFileSync(path.join(ROOT, 'public/install/includes/functions.php'), 'utf8');
    const body = functions.slice(functions.indexOf('function showAlertAndRedirect'));
    assert.match(body.slice(0, 2000), /<link rel="stylesheet" href="'\.htmlspecialchars\(installerStandaloneCssUrl\(\)\)/);
    assert.match(functions, /installer-standalone\.css/);

    const forbidden = fs.readFileSync(path.join(ROOT, 'public/install/views/403-forbidden.php'), 'utf8');
    assert.match(forbidden, /<link rel="stylesheet" href="<\?= htmlspecialchars\(installerStandaloneCssUrl\(\)\) \?>">/);
});

// 초기 숨김을 `hidden` 클래스로 옮긴 요소 — `.hidden` 이 `display:none !important` 라 `el.style.display = ''` 로는
// 보이지 않는다. 보이기는 반드시 installerShow()(클래스를 뗀다)로 해야 한다. 하나라도 빠지면 그 영역이 영영 안 보인다.
const INITIALLY_HIDDEN = {
    'session-cookie-blocked-banner': 'views/0-welcome.php',
    'core-pending-check-wrapper': 'views/3-configuration.php',
    'cli-status-summary': 'views/3-configuration.php',
    'composer-install-guide': 'views/3-configuration.php',
    'file-setup-relative-hint': 'views/5-installation.php',
    'file-setup-relative-box': 'views/5-installation.php',
    'abort-installation-btn': 'views/5-installation.php',
    'installation-start-section': 'views/5-installation.php',
    'install-warning': 'views/5-installation.php',
    'installation-progress-card': 'views/5-installation.php',
};

/**
 * @scenario surface=installer, csp=webserver_strict, failure=none
 * @effects installer_hidden_areas_shown_by_helper
 */
test('초기 숨김 요소 10개는 hidden 클래스로 숨기고, 보이기는 전부 installerShow 로 한다', () => {
    const css = fs.readFileSync(path.join(ROOT, 'public/install/assets/css/installer.css'), 'utf8');
    assert.match(css, /\.hidden\s*\{\s*display:\s*none\s*!important;?\s*\}/);

    const boot = fs.readFileSync(path.join(ROOT, 'public/install/assets/js/installer-boot.js'), 'utf8');
    assert.match(boot, /function installerShow\(el, display\) \{[\s\S]{0,200}?classList\.remove\('hidden'\)/);
    assert.match(boot, /window\.installerShow = installerShow;/);
    assert.match(boot, /window\.installerHide = installerHide;/);

    const scripts = FILES.filter(f => f.endsWith('.js')).map(f => [path.relative(ROOT, f), code(fs.readFileSync(f, 'utf8'), f)]);

    for (const [id, view] of Object.entries(INITIALLY_HIDDEN)) {
        // 1) 뷰의 그 요소 여는 태그에 hidden 클래스가 있다
        const html = code(fs.readFileSync(path.join(ROOT, 'public/install', view), 'utf8'), view);
        const tag = html.match(new RegExp(`<[a-z]+\\b[^>]*\\bid="${id}"[^>]*>`));
        assert.ok(tag, `${view}: #${id} 요소가 없다`);
        assert.match(tag[0], /class="[^"]*\bhidden\b[^"]*"/, `${view}: #${id} 에 hidden 클래스가 없다`);

        // 2) 그 요소를 잡은 변수에 보이는 값을 직접 대입하지 않고, installerShow 를 한 번 이상 부른다
        let shown = 0;
        for (const [rel, text] of scripts) {
            const names = new Set();
            const decl = new RegExp(`(?:const|let|var)\\s+(\\w+)\\s*=\\s*document\\.getElementById\\('${id}'\\)`, 'g');
            let m;
            while ((m = decl.exec(text)) !== null) names.add(m[1]);

            for (const name of names) {
                shown += (text.match(new RegExp(`installerShow\\(${name}\\b`, 'g')) || []).length;
                const direct = text.match(new RegExp(`\\b${name}\\.style\\.display\\s*=\\s*(?!'none')[^;]*;`, 'g')) || [];
                assert.deepEqual(direct, [], `${rel}: #${id}(${name}) 를 style.display 로 직접 보이게 한다 — installerShow 를 써야 한다`);
            }
        }
        assert.ok(shown > 0, `#${id} 를 보이게 하는 installerShow 호출이 없다`);
    }
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

test('상태 아이콘은 종전 style 속성과 같은 크기(20px·24px)를 크기 클래스로 지킨다', () => {
    // 종전 svg 의 style="width:20px;height:20px" 는 그룹 안 16px 규칙(.task-group-items .task-icon svg)보다 우선했다.
    // 속성(width/height)만으로는 그 규칙에 져서 설치 진행 화면의 그룹 아이콘이 16px 로 줄어든다.
    const js = fs.readFileSync(path.join(ROOT, 'public/install/assets/js/installer.js'), 'utf8');
    const fn = js.slice(js.indexOf('function getIconSvg('));
    const body = fn.slice(0, fn.indexOf('return svgs[type]'));
    assert.match(body, /const sizeClass = px === 24 \? 'icon-px-24' : 'icon-px-20';/);
    const svgTags = body.match(/<svg [^>]*>/g) || [];
    // 아이콘 6종(성공·실패·진행 + 7.2.0 의 안내·건너뜀·대기) 모두 크기 클래스를 쓴다
    assert.equal(svgTags.length, 6);
    for (const tag of svgTags) {
        assert.match(tag, /class="\$\{sizeClass\} /, tag);
    }

    const css = fs.readFileSync(path.join(ROOT, 'public/install/assets/css/installer.css'), 'utf8');
    assert.match(css, /\.icon-px-20\s*\{\s*width:\s*20px\s*!important;\s*height:\s*20px\s*!important;\s*\}/);
    assert.match(css, /\.icon-px-24\s*\{\s*width:\s*24px\s*!important;\s*height:\s*24px\s*!important;\s*\}/);
});
