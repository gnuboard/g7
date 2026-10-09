/**
 * @file editorClaimAttachmentSample.test.ts
 * @description 레이아웃 편집기에서 클레임 상세의 첨부 상한 안내가 표본으로 채워지는지
 *
 * 편집기에서 「취소/반품/교환 상세」 를 열면 첨부 안내가 「최대 0장 · 1장당 -MB · {{types}}」 로 보였다
 * (이커머스 전수 실측 S5, 2026-10-07). 안내와 업로더는 서버 봉투 `attachment_settings`
 * (`ClaimController` — `ClaimAttachmentService::publicLimits()`)를 읽는데, 편집기 표본 `claim` 응답에
 * 그 축이 없었다. 표본은 서버와 같은 꼴(max_images·image_max_size_kb·image_max_size_mb·allowed_extensions·accept)을
 * 기본 설정값으로 갖고 있어야 한다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

const MODULE_ROOT = resolve(__dirname, '../../../..');

const spec = JSON.parse(readFileSync(join(MODULE_ROOT, 'editor-spec.json'), 'utf8'));
const markup = readFileSync(join(MODULE_ROOT, 'resources/layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_reason.g7.html'), 'utf8');

describe('편집기 클레임 상세 표본 — 첨부 상한(attachment_settings)', () => {
    const settings = spec.sampleData?.byDataSourceId?.claim?.data?.attachment_settings;

    /**
     * @scenario case=c01
     * @effects claim_attachment_sample_has_settings
     */
    it('서버 봉투와 같은 꼴의 첨부 상한이 있다', () => {
        expect(settings).toBeTruthy();
        expect(typeof settings.max_images).toBe('number');
        expect(settings.max_images).toBeGreaterThan(0);
        expect(typeof settings.image_max_size_kb).toBe('number');
        expect(settings.image_max_size_mb).toBe(Math.round((settings.image_max_size_kb / 1024) * 1000) / 1000);
        expect(Array.isArray(settings.allowed_extensions)).toBe(true);
        expect(settings.allowed_extensions.length).toBeGreaterThan(0);
        expect(settings.accept).toBe(settings.allowed_extensions.map((e: string) => `.${e}`).join(','));
    });

    /**
     * @scenario case=c02
     * @effects claim_attachment_sample_has_settings
     */
    it('허용 확장자가 서버 상수(ClaimAttachmentService::ALLOWED_EXTENSIONS)와 같다', () => {
        const service = readFileSync(join(MODULE_ROOT, 'src/Services/ClaimAttachmentService.php'), 'utf8');
        const declared = service.match(/ALLOWED_EXTENSIONS = \[([^\]]+)\]/)?.[1] ?? '';
        const exts = [...declared.matchAll(/'([a-z0-9]+)'/g)].map((m) => m[1]);
        expect(settings.allowed_extensions).toEqual(exts);
    });

    /**
     * @scenario case=c03
     * @effects claim_attachment_sample_has_settings
     */
    it('첨부 안내 문구의 인자가 표본으로 빈 값 없이 계산된다', () => {
        // 영어 단수·복수 선택용 `|count={{…}}` 인자가 앞에 올 수 있다(값은 max 와 같다)
        const hint = markup.match(/images_hint(?:\|count=\{\{.*?\}\})?\|max=\{\{(.*?)\}\}&amp;size=\{\{(.*?)\}\}&amp;types=\{\{(.*?)\}\}/);
        expect(hint).toBeTruthy();
        const claim = spec.sampleData.byDataSourceId.claim;
        // eslint-disable-next-line no-new-func
        const evaluate = (expr: string) => new Function('claim', `return (${expr});`)(claim);
        expect(evaluate(hint![1])).toBeGreaterThan(0);
        expect(String(evaluate(hint![2]))).not.toBe('-MB');
        expect(String(evaluate(hint![3])).trim()).not.toBe('');
    });
});
