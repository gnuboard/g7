/**
 * 설치 트리(`node_modules`)의 패키지 일부를 동봉 디렉토리(`dist/vendor/{lib}/{version}/`)로
 * 복사하는 생성기
 *
 * 하위 자산이 CSS 의 상대 `url()` 로 참조되는 라이브러리(flag-icons 등)는 인라인하지 않고
 * 파일을 그대로 동봉한다. 그 복사를 손으로 하면 어느 버전을 옮겼는지가 어디에도 남지 않아,
 * 설치 트리가 갱신된 뒤 다시 복사하는 순간 `7.2.3/` 디렉토리에 7.5.0 이 들어앉는다 —
 * 오류도 로그도 없이 배포본의 버전 라벨만 틀린다. 그래서 복사를 명령으로 고정하고,
 * 실행 전에 설치본 버전과 출력 경로의 선언 버전을 대조한다.
 *
 * 사용:
 *   node scripts/vendor-copy.mjs <패키지 루트> <출력 디렉토리> <복사 대상...>
 *
 * 복사 대상은 패키지 루트 기준 상대 경로이며 파일·디렉토리 모두 가능하다. 출력 디렉토리의
 * 해당 항목은 복사 전에 지워 설치 트리에 없는 파일이 남지 않게 한다.
 *
 * 패키지 안의 자리와 동봉 위치가 다르면 `<입력>=<출력>` 으로 적는다(예: monaco 의
 * `min/vs=vs`). 동봉 경로는 브라우저가 요청하는 URL 이 되므로 패키지 내부 배치를 그대로
 * 따를 수 없는 경우가 있고, 그 차이를 손으로 옮기면 이 생성기를 두는 의미가 사라진다.
 *
 * 재생성이 저장소 안에서 재현 가능해야 하므로 항목별 파일 수와 바이트를 로그로 남긴다.
 */

import fs from 'node:fs';
import path from 'node:path';

const [, , packageRoot, outputDir, ...entries] = process.argv;

if (!packageRoot || !outputDir || entries.length === 0) {
    console.error('사용법: node scripts/vendor-copy.mjs <패키지 루트> <출력 디렉토리> <복사 대상...>');
    process.exit(1);
}

/**
 * 동봉 대상 버전과 입력 패키지의 실제 버전이 어긋나면 중단합니다.
 *
 * 출력 경로의 버전 디렉토리(`.../flag-icons/7.2.3/…`)는 "여기 든 것은 7.2.3 이다" 라는
 * 배포 선언이다. 그런데 입력은 설치 트리라 선언을 고정해 두어도 오래된(또는 앞선) 트리가
 * 남을 수 있고, 그대로 쓰면 다른 버전을 그 디렉토리에 써 넣는 조용한 버전 거짓말이 된다.
 * 검증할 수 없으면 쓰지 않는다.
 *
 * @param {string} manifestPath 입력 패키지의 package.json 경로
 * @param {string} outputPath 출력 디렉토리 경로
 * @returns {void}
 */
function assertVendorVersion(manifestPath, outputPath) {
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

    if (!fs.existsSync(manifestPath)) {
        console.error(`입력 패키지의 package.json 이 없어 버전을 검증할 수 없습니다: ${manifestPath}`);
        console.error(`동봉 선언은 ${declared} 입니다. 검증 없이 쓰면 배포본의 버전 라벨이 틀어지므로 중단합니다.`);
        process.exit(1);
    }

    const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));

    if (manifest.version !== declared) {
        console.error(`버전 불일치: 설치본 ${manifest.version} ≠ 동봉 선언 ${declared}`);
        console.error(`  입력: ${packageRoot}`);
        console.error(`  출력: ${outputPath}`);
        console.error('`npm ci` 로 lock 에 선언된 버전을 설치한 뒤 다시 실행하세요.');
        process.exit(1);
    }

    console.log(`[vendor] 버전 확인: ${manifest.name || '(이름 없음)'} ${manifest.version} → ${declared}`);
}

/**
 * 복사된 텍스트 자산 말미의 소스맵 지시자를 제거합니다.
 *
 * 동봉물은 배포 산출물이라 `.map` 을 싣지 않는다(gitignore 대상). 지시자만 남으면 개발자
 * 도구를 연 방문자에게 404 가 뜨고, 커밋 dist 의 지시자 잔존을 막는 정적 검사에도 걸린다.
 * 손으로 지우면 재생성할 때마다 되살아나므로 생성기가 맡는다.
 *
 * 파일 **말미**의 지시자만 지운다 — monaco 의 `ts.worker` 는 TypeScript 컴파일러의 emitter
 * 코드 문자열로 같은 문구를 품고 있어(§13-5), 본문 전체를 훑으면 그 코드를 망가뜨린다.
 *
 * @param {string} target 파일 또는 디렉토리 경로
 * @returns {string[]} 지시자를 제거한 파일 경로 목록
 */
function stripSourceMapDirectives(target) {
    const stat = fs.statSync(target);

    if (stat.isDirectory()) {
        return fs
            .readdirSync(target)
            .flatMap((entry) => stripSourceMapDirectives(path.join(target, entry)));
    }

    if (!/\.(js|mjs|cjs|css)$/i.test(target)) {
        return [];
    }

    const original = fs.readFileSync(target, 'utf8');
    // 지시자 줄만 걷어내고 앞뒤 공백은 원본 그대로 둔다 — 재생성 결과가 종전 배포본과
    // 바이트 동일해야 "다시 만들 수 있다" 가 성립한다.
    const stripped = original.replace(
        /(?:\/\/#|\/\*#)[ \t]*sourceMappingURL=[^\r\n]*?(?:\*\/)?[ \t]*\r?\n?$/,
        ''
    );

    if (stripped === original) {
        return [];
    }

    fs.writeFileSync(target, stripped);

    return [target];
}

/**
 * 경로 아래의 파일 수와 총 바이트를 셉니다.
 *
 * @param {string} target 파일 또는 디렉토리 경로
 * @returns {{files: number, bytes: number}} 집계 결과
 */
function measure(target) {
    const stat = fs.statSync(target);

    if (stat.isFile()) {
        return { files: 1, bytes: stat.size };
    }

    let files = 0;
    let bytes = 0;

    for (const entry of fs.readdirSync(target)) {
        const child = measure(path.join(target, entry));

        files += child.files;
        bytes += child.bytes;
    }

    return { files, bytes };
}

assertVendorVersion(path.join(packageRoot, 'package.json'), outputDir);

let totalFiles = 0;
let totalBytes = 0;

for (const entry of entries) {
    const separator = entry.indexOf('=');
    const sourceEntry = separator === -1 ? entry : entry.slice(0, separator);
    const destinationEntry = separator === -1 ? entry : entry.slice(separator + 1);

    if (!sourceEntry || !destinationEntry) {
        console.error(`복사 대상 표기가 올바르지 않습니다: ${entry}`);
        console.error('`<입력>` 또는 `<입력>=<출력>` 형태여야 합니다.');
        process.exit(1);
    }

    const source = path.join(packageRoot, sourceEntry);
    const destination = path.join(outputDir, destinationEntry);

    if (!fs.existsSync(source)) {
        console.error(`복사 대상을 찾을 수 없습니다: ${source}`);
        process.exit(1);
    }

    fs.rmSync(destination, { recursive: true, force: true });
    fs.mkdirSync(path.dirname(destination), { recursive: true });
    fs.cpSync(source, destination, { recursive: true });

    for (const stripped of stripSourceMapDirectives(destination)) {
        console.log(`[vendor-copy] 소스맵 지시자 제거: ${path.relative(outputDir, stripped)}`);
    }

    const { files, bytes } = measure(destination);

    totalFiles += files;
    totalBytes += bytes;

    console.log(`[vendor-copy] ${entry}  ${files}개 파일  ${bytes} bytes`);
}

console.log(`[vendor-copy] 출력 : ${outputDir} (총 ${totalFiles}개 파일, ${totalBytes} bytes)`);
