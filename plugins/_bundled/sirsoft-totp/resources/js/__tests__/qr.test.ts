/**
 * QR SVG 렌더러 테스트
 *
 * @description
 * QR 을 우리가 직접 그리므로(라이브러리는 인코딩만 담당) 마크업 계약을 여기서 잠근다.
 * 스캐너가 읽지 못하는 QR 은 등록 화면 전체를 무용지물로 만들고, 그 실패는 사람이 휴대폰을
 * 들이대 보기 전까지 드러나지 않는다.
 *
 * @scenario-manifest plugins/_bundled/sirsoft-totp/tests/scenarios/bootstrap-qr-render.yaml
 */

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, it, expect } from 'vitest';

import { renderQrSvg } from '../qr';

const SAMPLE_URI = 'otpauth://totp/G7%20Test:member%40test.com?secret=JBSWY3DPEHPK3PXP&issuer=G7%20Test&algorithm=SHA1&digits=6&period=30';

describe('renderQrSvg', () => {
    /**
     * @scenario case=qr_markup
     * @effects qr_svg_markup_contract
     */
    it('SVG 루트를 돌려준다', () => {
        const svg = renderQrSvg(SAMPLE_URI, 'QR');

        expect(svg.startsWith('<svg')).toBe(true);
        expect(svg.endsWith('</svg>')).toBe(true);
        expect(svg).toContain('xmlns="http://www.w3.org/2000/svg"');
    });

    /**
     * @scenario case=qr_markup
     * @effects qr_svg_markup_contract
     */
    it('흰 배경 위에 검은 모듈을 그린다', () => {
        const svg = renderQrSvg(SAMPLE_URI, 'QR');

        // 반전된 코드(어두운 배경)는 읽지 못하는 스캐너가 많다 — 테마와 무관하게 고정한다.
        expect(svg).toContain('fill="#ffffff"');
        expect(svg).toContain('fill="#000000"');
    });

    /**
     * @scenario case=qr_markup
     * @effects qr_svg_markup_contract
     */
    it('여백(quiet zone)을 포함한 viewBox 를 쓴다', () => {
        const svg = renderQrSvg(SAMPLE_URI, 'QR');
        const match = svg.match(/viewBox="0 0 (\d+) (\d+)"/);

        expect(match).not.toBeNull();

        const [, width, height] = match as RegExpMatchArray;
        expect(width).toBe(height);
        // 모듈 격자 최소치(21) + 좌우 여백 4+4 보다는 커야 한다.
        expect(Number(width)).toBeGreaterThanOrEqual(29);
    });

    /**
     * @scenario case=qr_markup
     * @effects qr_svg_markup_contract
     */
    it('스크린리더용 라벨을 싣는다', () => {
        const svg = renderQrSvg(SAMPLE_URI, '인증 앱 QR 코드');

        expect(svg).toContain('role="img"');
        expect(svg).toContain('aria-label="인증 앱 QR 코드"');
    });

    /**
     * @scenario case=qr_markup
     * @effects qr_svg_markup_contract
     */
    it('라벨의 따옴표·꺾쇠를 이스케이프한다', () => {
        const svg = renderQrSvg(SAMPLE_URI, 'a"b<c>d&e');

        // 이스케이프하지 않으면 속성이 깨져 SVG 전체가 렌더되지 않는다.
        expect(svg).toContain('aria-label="a&quot;b&lt;c&gt;d&amp;e"');
    });

    /**
     * @scenario case=qr_markup
     * @effects qr_svg_markup_contract
     */
    it('내용이 다르면 다른 그림이 나온다', () => {
        const first = renderQrSvg(SAMPLE_URI, 'QR');
        const second = renderQrSvg(SAMPLE_URI.replace('JBSWY3DPEHPK3PXP', 'MZXW6YTBOIAAAAAA'), 'QR');

        expect(first).not.toBe(second);
    });

    /**
     * @scenario case=qr_markup
     * @effects qr_svg_markup_contract
     */
    it('같은 내용이면 같은 그림이 나온다', () => {
        expect(renderQrSvg(SAMPLE_URI, 'QR')).toBe(renderQrSvg(SAMPLE_URI, 'QR'));
    });

    /**
     * @scenario case=qr_markup
     * @effects qr_svg_has_no_style_attribute, qr_size_rule_in_css_file
     */
    it('SVG 에 style 속성이 없고 크기는 클래스로 건다', () => {
        // 스타일 보안 정책(style-src-attr 'none')은 HTML 문자열의 style 속성을 막는다 — QR 이 220px 로 줄지 않고 칸을 꽉 채웠다.
        const svg = renderQrSvg(SAMPLE_URI, 'QR');

        expect(svg).not.toMatch(/\sstyle\s*=/i);
        expect(svg).toContain('class="totp-qr-svg"');
    });

    /**
     * @scenario case=qr_markup
     * @effects qr_size_rule_in_css_file
     */
    it('QR 크기 규칙이 플러그인 CSS 파일에 있다', () => {
        // vitest 는 플러그인 디렉터리에서 실행한다
        const css = readFileSync(resolve(process.cwd(), 'resources/css/main.css'), 'utf8');

        expect(css).toMatch(/\.totp-qr-svg\s*\{[^}]*width: 100%;[^}]*max-width: 220px;[^}]*height: auto;[^}]*display: block;[^}]*margin: 0 auto;/);
    });

    /**
     * @scenario case=qr_markup
     * @effects qr_svg_markup_contract
     */
    it('긴 URI 도 그린다', () => {
        // issuer 가 긴 사이트명이어도 자동으로 더 큰 QR 버전이 선택되어야 한다.
        const longUri = SAMPLE_URI + '&foo=' + 'x'.repeat(200);

        expect(() => renderQrSvg(longUri, 'QR')).not.toThrow();
    });
});
