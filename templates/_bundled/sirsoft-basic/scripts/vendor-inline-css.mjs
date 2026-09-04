/**
 * Font Awesome 웹폰트를 CSS 에 인라인해 단일 파일로 만드는 생성기
 *
 * 자산 URL 이 `?file=` 쿼리 형태가 되는 서버(확장자를 정적 location 이 가로채는 구성)에서는
 * CSS 내부의 상대 `url()` 이 해석되지 않는다. 정적 게시가 켜져 있으면 실경로가 나가 문제가
 * 없지만, 게시를 끈 조합이 남는다. 아이콘만 있는 버튼이 다수인 화면에서 아이콘 소실은
 * 곧 조작 불능이므로, 그 조합에서도 아이콘이 뜨도록 폰트를 data: URI 로 인라인한다.
 *
 * 다만 **현행 버전(Font Awesome 6) 패밀리만** 인라인한다. `all.min.css` 는 같은 폰트를
 * 구버전 호환 패밀리(`Font Awesome 5 …` · `FontAwesome`)에서 다시 참조하므로, 전부
 * 인라인하면 같은 폰트가 3벌 실려 렌더 차단 CSS 가 1.2MB 로 부푼다. 호환 패밀리는
 * 파일 참조를 유지한다 — 저장소 전수 조사에서 레거시 클래스(`fa fa-*`) 사용은 0건이고,
 * 그 조합에서 degrade 하는 것은 편집기 팔레트 샘플뿐이다.
 *
 * `ttf` 소스는 인라인 대상에서 제거한다 — 선언 하한(Chrome 111 / Safari 16.4 / Firefox 128)이
 * 모두 woff2 를 지원하므로 같은 폰트를 두 번 싣는 것은 배포 용량 낭비다.
 *
 * 입력은 설치 트리(`node_modules`)이고 출력은 `vendor/{lib}/{version}/` 이라, 설치본이
 * 선언과 어긋나면 다른 버전을 그 디렉토리에 써 넣게 된다. 생성기는 실행 전에 두 버전을
 * 대조하고, 어긋나거나 확인할 수 없으면 중단한다.
 *
 * 사용:
 *   node scripts/vendor-inline-css.mjs <입력 CSS> <웹폰트 디렉토리> <출력 CSS>
 *
 * 재생성이 저장소 안에서 재현 가능해야 하므로 입력/출력 경로와 해시를 로그로 남긴다.
 */

import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';

const [, , inputCss, webfontsDir, outputCss] = process.argv;

if (!inputCss || !webfontsDir || !outputCss) {
    console.error('사용법: node scripts/vendor-inline-css.mjs <입력 CSS> <웹폰트 디렉토리> <출력 CSS>');
    process.exit(1);
}

/** 인라인 대상 패밀리 (현행 버전) */
const INLINE_FAMILIES = ['Font Awesome 6 Free', 'Font Awesome 6 Brands'];

/**
 * 파일의 sha256 앞 16자를 돌려줍니다.
 *
 * @param {string} filePath 파일 경로
 * @returns {string} 해시 문자열
 */
function digest(filePath) {
    return crypto.createHash('sha256').update(fs.readFileSync(filePath)).digest('hex').slice(0, 16);
}

/**
 * 동봉 대상 버전과 입력 패키지의 실제 버전이 어긋나면 중단합니다.
 *
 * 출력 경로의 버전 디렉토리(`.../font-awesome/6.4.0/…`)는 "여기 든 것은 6.4.0 이다" 라는
 * 배포 선언이다. 그런데 입력은 `node_modules` 라 선언을 고정해 두어도 오래된 트리가
 * 남을 수 있고, 그대로 쓰면 7.x 를 `6.4.0/` 에 써 넣는 조용한 버전 거짓말이 된다 —
 * 오류도 로그도 없이 배포본의 라벨만 틀린다. 검증할 수 없으면 쓰지 않는다.
 *
 * @param {string} inputPath 입력 파일 또는 패키지 내부 경로
 * @param {string} outputPath 출력 파일 또는 디렉토리 경로
 * @returns {void}
 */
function assertVendorVersion(inputPath, outputPath) {
    const declared = path
        .resolve(outputPath)
        .split(/[\\/]+/)
        .filter((segment) => /^\d+\.\d+\.\d+$/.test(segment))
        .pop();

    if (!declared) {
        console.error(`출력 경로에 버전 디렉토리가 없습니다: ${outputPath}`);
        console.error('동봉 자산은 `vendor/{lib}/{version}/` 규약을 따라야 합니다.');
        process.exit(1);
    }

    let dir = path.resolve(path.dirname(inputPath));
    let manifest = null;

    for (let depth = 0; depth < 8; depth++) {
        const candidate = path.join(dir, 'package.json');

        if (fs.existsSync(candidate)) {
            manifest = JSON.parse(fs.readFileSync(candidate, 'utf8'));
            break;
        }

        const parent = path.dirname(dir);

        if (parent === dir) {
            break;
        }

        dir = parent;
    }

    if (!manifest || !manifest.version) {
        console.error(`입력 패키지의 package.json 을 찾지 못해 버전을 검증할 수 없습니다: ${inputPath}`);
        console.error(`동봉 선언은 ${declared} 입니다. 검증 없이 쓰면 배포본의 버전 라벨이 틀어지므로 중단합니다.`);
        process.exit(1);
    }

    if (manifest.version !== declared) {
        console.error(`버전 불일치: 설치본 ${manifest.version} ≠ 동봉 선언 ${declared}`);
        console.error(`  입력: ${inputPath}`);
        console.error(`  출력: ${outputPath}`);
        console.error('`npm ci` 로 lock 에 선언된 버전을 설치한 뒤 다시 실행하세요.');
        process.exit(1);
    }

    console.log(`[vendor] 버전 확인: ${manifest.name || '(이름 없음)'} ${manifest.version} → ${declared}`);
}

assertVendorVersion(inputCss, outputCss);

const source = fs.readFileSync(inputCss, 'utf8');
const embedded = new Map();
let inlinedBlocks = 0;
let keptBlocks = 0;

/**
 * `@font-face` 블록 하나를 변환합니다.
 *
 * @param {string} block 블록 원문
 * @returns {string} 변환된 블록
 */
function transformFontFace(block) {
    const family = block.match(/font-family:\s*"([^"]+)"/)?.[1] ?? '';

    if (!INLINE_FAMILIES.includes(family)) {
        keptBlocks++;

        return block;
    }

    inlinedBlocks++;

    let transformed = block.replace(
        /url\(\.\.\/webfonts\/([A-Za-z0-9._-]+)\.woff2\)/g,
        (match, name) => {
            const fontPath = path.join(webfontsDir, `${name}.woff2`);

            if (!fs.existsSync(fontPath)) {
                console.error(`웹폰트를 찾을 수 없습니다: ${fontPath}`);
                process.exit(1);
            }

            if (!embedded.has(name)) {
                embedded.set(name, {
                    bytes: fs.statSync(fontPath).size,
                    sha256: digest(fontPath),
                });
            }

            return `url(data:font/woff2;base64,${fs.readFileSync(fontPath).toString('base64')})`;
        }
    );

    // woff2 를 인라인한 뒤 남는 ttf 소스 선언 제거
    transformed = transformed.replace(
        /,\s*url\(\.\.\/webfonts\/[A-Za-z0-9._-]+\.ttf\)\s*format\("truetype"\)/g,
        ''
    );

    if (/url\(\.\.\//.test(transformed)) {
        console.error(`인라인되지 않은 상대 경로가 남았습니다 (family: ${family}). 생성기를 갱신해야 합니다.`);
        process.exit(1);
    }

    return transformed;
}

const output = source.replace(/@font-face\{[^}]*\}/g, transformFontFace);

if (inlinedBlocks === 0) {
    console.error('인라인 대상 @font-face 를 찾지 못했습니다. 입력 CSS 또는 패밀리 목록을 확인하세요.');
    process.exit(1);
}

fs.mkdirSync(path.dirname(outputCss), { recursive: true });
fs.writeFileSync(outputCss, output);

console.log('[vendor-inline-css] 입력 :', inputCss, `(${fs.statSync(inputCss).size} bytes, sha256:${digest(inputCss)})`);
console.log('[vendor-inline-css] 폰트 :', webfontsDir);
for (const [name, info] of embedded) {
    console.log(`  - ${name}.woff2  ${info.bytes} bytes  sha256:${info.sha256}`);
}
console.log(`[vendor-inline-css] 인라인 @font-face ${inlinedBlocks}개 / 파일 참조 유지 ${keptBlocks}개 (구버전 호환 패밀리)`);
console.log('[vendor-inline-css] 출력 :', outputCss, `(${fs.statSync(outputCss).size} bytes, sha256:${digest(outputCss)})`);
