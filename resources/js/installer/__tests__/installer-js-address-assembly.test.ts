/**
 * 인스톨러 화면 스크립트의 주소 조립을 고정한다 (7차 G3 라운드 B-7).
 *
 * 코어의 주소 축 계약 검사(`resources/js/core/support/__tests__/assetUrl.test.ts`)는
 * `resources/js` 를 훑는다. 그런데 **설치 화면이 실제로 싣는 스크립트**는
 * `public/install/assets/js/` 에 있어 그 walk 밖이다 — 즉 이 저장소의 브라우저 JS 중
 * 한 덩어리가 어느 주소 게이트에도 걸리지 않은 채였다.
 *
 * 인스톨러는 Laravel 부팅 전이라 `withBasePrefix()` 를 쓸 수 없고, 대신 자기 위치에서
 * 기준 주소를 계산해(`INSTALLER_BASE_URL` · `root`) 거기에 이어 붙인다. 그 규약이
 * 성립하는 한 하위 폴더·별칭 마운트에서도 동작한다. 깨지는 형태는 하나다 —
 * **루트 상대 리터럴을 그대로 요청하는 것**. 그러면 그 요청이 사이트 밖으로 나가고,
 * 증상은 오류가 아니라 설치 화면이 조용히 멈추는 것이다(문서 루트에 다른 사이트가
 * 있으면 그쪽 응답을 받는다).
 *
 * @scenario base_path=subpath, script_home=installer
 *
 * @effects installer_scripts_never_request_a_root_relative_literal
 */
import { readdirSync, readFileSync } from 'fs';
import path from 'path';
import { describe, expect, it } from 'vitest';

const SCRIPT_DIR = path.resolve(__dirname, '../../../../public/install/assets/js');

/** 주석·문자열 밖의 판정을 위해 줄 단위로 훑는다 (형제 게이트와 같은 단순 규칙). */
const ROOT_RELATIVE_REQUEST = [
    // fetch('/api/…') · fetch("/install/…")
    /\bfetch\s*\(\s*['"`]\//,
    // location.href = '/…' · location.assign('/…') · location.replace('/…')
    /location\s*\.\s*href\s*(?<![=!<>])=(?!=)\s*['"`]\//,
    /location\s*\.\s*(?:assign|replace)\s*\(\s*['"`]\//,
    // window.open('/…')
    /window\s*\.\s*open\s*\(\s*['"`]\//,
    // history.pushState/replaceState 의 세 번째 인자
    /history\s*\.\s*(?:push|replace)State\s*\([^)]*,\s*['"`]\//,
];

describe('인스톨러 스크립트는 루트 상대 주소를 직접 요청하지 않는다', () => {
    it('모집단 — 설치 화면 스크립트를 실제로 읽는다', () => {
        const files = readdirSync(SCRIPT_DIR).filter((name) => name.endsWith('.js'));

        // 하한이 없으면 경로가 어긋나 0건이 되어도 아래 비교가 초록이다.
        expect(files.length).toBeGreaterThan(1);
    });

    it('루트 상대 리터럴 요청·이동이 없다', () => {
        const offenders: string[] = [];

        for (const name of readdirSync(SCRIPT_DIR)) {
            if (!name.endsWith('.js')) continue;

            const lines = readFileSync(path.join(SCRIPT_DIR, name), 'utf8').split('\n');

            lines.forEach((line, index) => {
                const trimmed = line.trim();
                if (trimmed.startsWith('//') || trimmed.startsWith('*') || trimmed.startsWith('/*')) return;

                if (ROOT_RELATIVE_REQUEST.some((re) => re.test(line))) {
                    offenders.push(`${name}:${index + 1} → ${trimmed.slice(0, 90)}`);
                }
            });
        }

        expect(offenders, `루트 상대 주소를 직접 쓰는 지점:\n${offenders.join('\n')}`).toEqual([]);
    });

    it('판정식 자기검증 — 위반 형태를 실제로 잡는다', () => {
        // 음성 대조가 없으면 정규식이 사문이 되어도 위 비교가 초록이다.
        const positives = [
            "await fetch('/api/check-env.php');",
            'window.location.href = "/install/";',
            "location.replace('/admin');",
            "window.open('/install/api/x.php');",
            "history.pushState({}, '', '/install/step/2');",
        ];
        // 판정은 **줄 단위 문자열 대조**다 — 문자열 리터럴 안에 같은 형태가 들어 있으면
        // 오탐이 난다(현재 모집단에는 없다). 주석 줄은 위 스캔이 걸러 내지만 그 이상의
        // 구문 인식은 하지 않는다. 오탐이 생기면 그 자리를 고치는 편이 규칙을 무르게
        // 만드는 것보다 낫다 — 인스톨러 스크립트가 주소를 문자열로 설명할 일은 없다.
        const negatives = [
            'await fetch(`${root}${control.path}`);',
            "await fetch(INSTALLER_BASE_URL + '/api/scan-extensions.php');",
            "location.href = base + '/install/';",
            'window.open(resolvedUrl);',
        ];

        for (const line of positives) {
            expect(ROOT_RELATIVE_REQUEST.some((re) => re.test(line)), line).toBe(true);
        }
        for (const line of negatives) {
            expect(ROOT_RELATIVE_REQUEST.some((re) => re.test(line)), line).toBe(false);
        }
    });
});
