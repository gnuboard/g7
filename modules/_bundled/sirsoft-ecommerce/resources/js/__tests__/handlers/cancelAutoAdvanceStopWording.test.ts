/**
 * 「환불까지 한 번에 처리」 정지 안내 — 실제로 멈춘 단계를 말한다 (S3-D2, 2026-10-07 실측)
 *
 * @description
 * 결제수단이 환불 자동 승인 목록 밖(무통장·가상계좌 등)이면 서버는 환불신청서를 **접수 단계에서**
 * 멈춘다(운영자가 승인한 뒤 [환불하기]). 그런데 취소 직후 토스트는 「승인까지 처리했습니다」,
 * 체크박스 설명은 「승인에서 멈추고」라고 안내해, 운영자가 승인된 줄 알고 신청서에 가면 [환불하기]가
 * 꺼져 있었다(실측: 무통장 주문 전액 취소 → 신청서 approved_at=null, [환불하기] disabled).
 * 같은 창의 주문별 안내(`auto_advance_refund_blocked_hint`)는 「환불신청 상태에서 멈춥니다」로 맞다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

/**
 * 언어 파일에서 키 값을 읽는다($partial 해석 포함).
 *
 * @param locale 로케일
 * @param key `admin.` 으로 시작하는 점 경로
 * @returns 문자열 값
 */
function langValue(locale: 'ko' | 'en' | 'ja', key: string): string {
    const moduleRoot = path.resolve(__dirname, '../../../..');
    const file = locale === 'ja'
        ? path.resolve(moduleRoot, '../../../lang-packs/_bundled/g7-module-sirsoft-ecommerce-ja/frontend/ja.json')
        : path.join(moduleRoot, 'resources/lang', `${locale}.json`);
    const resolve = (node: any, dir: string): any => {
        if (node && typeof node === 'object' && !Array.isArray(node)) {
            if (typeof node.$partial === 'string') {
                const target = path.resolve(dir, node.$partial);

                return resolve(JSON.parse(fs.readFileSync(target, 'utf8')), path.dirname(target));
            }
            const out: Record<string, any> = {};
            for (const [k, v] of Object.entries(node)) out[k] = resolve(v, dir);

            return out;
        }

        return node;
    };
    const root = resolve(JSON.parse(fs.readFileSync(file, 'utf8')), path.dirname(file));

    return String(key.split('.').reduce((o: any, k) => (o == null ? o : o[k]), root));
}

const STOPPED = 'admin.order.detail.modal.cancel.refund_request_auto_stopped';
const HINT = 'admin.order.detail.modal.cancel.auto_advance_refund_hint';

describe('자동 처리 정지 안내 문구', () => {
    /**
     * @scenario surface=toast
     *
     * @effects auto_advance_stop_wording_matches_server
     */
    it('정지 토스트는 승인까지 처리했다고 말하지 않는다', () => {
        expect(langValue('ko', STOPPED)).not.toMatch(/승인까지 처리/);
        expect(langValue('en', STOPPED)).not.toMatch(/was approved/i);
        expect(langValue('ja', STOPPED)).not.toMatch(/承認まで処理/);
    });

    /**
     * @scenario surface=toast
     *
     * @effects auto_advance_stop_wording_matches_server
     */
    it('정지 토스트는 운영자가 승인부터 밟아야 한다고 안내한다', () => {
        expect(langValue('ko', STOPPED)).toMatch(/승인/);
        expect(langValue('en', STOPPED)).toMatch(/approve/i);
        expect(langValue('ja', STOPPED)).toMatch(/承認/);
    });

    /**
     * @scenario surface=checkbox_hint
     *
     * @effects auto_advance_stop_wording_matches_server
     */
    it('체크박스 설명은 송금 수단이 환불신청에서 멈춘다고 말한다', () => {
        expect(langValue('ko', HINT)).not.toMatch(/승인에서 멈추/);
        expect(langValue('ko', HINT)).toMatch(/환불신청/);
        expect(langValue('en', HINT)).not.toMatch(/stop at approval/i);
        expect(langValue('ja', HINT)).not.toMatch(/承認で止まり/);
    });
});
