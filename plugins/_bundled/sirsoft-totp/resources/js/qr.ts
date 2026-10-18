import qrcode from 'qrcode-generator';

/**
 * otpauth URI 를 QR 코드 SVG 로 그린다.
 *
 * 인코딩(문자열 → 흑백 모듈 격자)만 `qrcode-generator`(MIT, 의존성 0)에 맡기고, 화면에
 * 들어갈 마크업은 여기서 만든다. 라이브러리가 제공하는 `createImgTag()`·`createTableTag()`
 * 는 쓰지 않는다 — 우리 레이아웃 계약(dark 모드 대비, 크기 제어, 접근성 라벨)을 따르지
 * 않고, base64 `<img>` 는 확대 시 흐려진다.
 *
 * SVG 를 고른 이유는 어느 배율에서도 또렷하고, 문서 흐름 안에서 크기를 CSS 로 정할 수
 * 있기 때문이다.
 */

/** 오류 정정 수준 — otpauth URI 는 길지 않으므로 여유 있는 M(15%)을 쓴다. */
const ERROR_CORRECTION = 'M' as const;

/** 코드 주위 여백(모듈 수). QR 규격 권장치는 4다 — 좁히면 인식률이 떨어진다. */
const QUIET_ZONE = 4;

/**
 * otpauth URI 로 QR SVG 문자열을 만든다.
 *
 * 색은 `currentColor` 와 흰 배경을 쓴다 — 어두운 테마에서도 카드가 흰 바탕을 깔아 주므로
 * 코드 자체는 항상 충분한 대비를 갖는다(QR 스캐너는 반전된 코드를 읽지 못하는 것이 많다).
 *
 * @param uri otpauth URI
 * @param label 스크린리더가 읽을 대체 텍스트
 * @returns SVG 마크업 문자열
 */
export function renderQrSvg(uri: string, label: string): string {
    // typeNumber 0 = 내용 길이에 맞춰 자동 선택.
    const qr = qrcode(0, ERROR_CORRECTION);
    qr.addData(uri);
    qr.make();

    const count = qr.getModuleCount();
    const size = count + QUIET_ZONE * 2;

    // 어두운 모듈만 path 로 모아 그린다 — 사각형을 개별 요소로 두면 노드가 수백 개가 된다.
    let path = '';
    for (let row = 0; row < count; row++) {
        for (let col = 0; col < count; col++) {
            if (qr.isDark(row, col)) {
                path += `M${col + QUIET_ZONE} ${row + QUIET_ZONE}h1v1h-1z`;
            }
        }
    }

    return [
        `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${size} ${size}"`,
        ` role="img" aria-label="${escapeAttribute(label)}"`,
        ' style="width:100%;max-width:220px;height:auto;display:block;margin:0 auto">',
        `<rect width="${size}" height="${size}" fill="#ffffff"/>`,
        `<path d="${path}" fill="#000000"/>`,
        '</svg>',
    ].join('');
}

/**
 * 속성값에 들어갈 문자열을 이스케이프한다.
 *
 * 라벨은 다국어 문구라 따옴표·꺾쇠가 들어올 수 있다.
 *
 * @param value 원본 문자열
 * @returns 이스케이프된 문자열
 */
function escapeAttribute(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}
