/**
 * @file productSeoSyncFlags.test.tsx
 * @description A26 — SEO 동기화 플래그 영속화 회귀 (구조 검증)
 *
 * 프론트 역추론식(ui.seoSync* == null ? !meta_title : ...) 이 제거되고
 * _local.form.seo_sync_* 단일 참조로 교체되었는지, 저장 sequence 의 SEO 덮어쓰기 setState 가
 * 제거되었는지, 신규 폼 form 초기값에 seo_sync_* 가 시드되었는지 검증한다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import seoPartial from '../../../layouts/admin/partials/admin_ecommerce_product_form/_partial_seo_settings.json';
import productForm from '../../../layouts/admin/admin_ecommerce_product_form.json';

const flat = (obj: unknown) => JSON.stringify(obj);

describe('A26 — SEO 동기화 플래그 영속화', () => {
    it('SEO partial 에서 역추론식(ui.seoSync*)이 0회 등장', () => {
        const s = flat(seoPartial);
        expect(s).not.toContain('seoSyncTitle');
        expect(s).not.toContain('seoSyncDescription');
        expect(s).not.toContain('ui.seoSync');
    });

    it('synced/custom if 가 form.seo_sync_* 단일 참조를 사용', () => {
        const s = flat(seoPartial);
        expect(s).toContain('{{_local.form.seo_sync_title}}');
        expect(s).toContain('{{!_local.form.seo_sync_title}}');
        expect(s).toContain('{{_local.form.seo_sync_description}}');
        expect(s).toContain('{{!_local.form.seo_sync_description}}');
    });

    it('체크박스 토글이 form.seo_sync_* 를 직접 토글', () => {
        const s = flat(seoPartial);
        expect(s).toContain('"form.seo_sync_title":"{{!_local.form.seo_sync_title}}"');
        expect(s).toContain('"form.seo_sync_description":"{{!_local.form.seo_sync_description}}"');
    });

    it('루트 폼 저장 sequence 에 SEO 덮어쓰기 setState 가 제거됨', () => {
        const s = flat(productForm);
        // 역추론 condition 으로 meta_title/meta_description 을 덮어쓰던 저장 시점 setState 제거
        expect(s).not.toContain('_local.ui.seoSyncTitle == null');
        expect(s).not.toContain('_local.ui.seoSyncDescription == null');
        // ui.seoSync 초기화도 제거
        expect(s).not.toContain('seoSyncTitle');
        expect(s).not.toContain('seoSyncDescription');
    });

    it('신규 폼 form 초기값에 seo_sync_* 가 true 로 시드됨', () => {
        const s = flat(productForm);
        expect(s).toContain('"seo_sync_title":true');
        expect(s).toContain('"seo_sync_description":true');
    });
});
