/**
 * 연간 통지 설정·시험 발송·이력·새로고침. 변경 설정은 finally에서 복구합니다.
 * @scenario flow=browser
 * @effects settings_validated, self_test_only, history_paginated
 */
import { test, expect, authenticatePage } from '../../fixtures/gdpr-auth';
import type { Page } from '@playwright/test';

test.describe.configure({ mode: 'serial' });

async function settingsApi(page: Page, annual?: unknown) {
    return page.evaluate(async (value) => {
        const response = await fetch('/api/plugins/sirsoft-gdpr/admin/settings', {
            method: value === undefined ? 'GET' : 'PUT',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${localStorage.getItem('auth_token') ?? ''}` },
            ...(value === undefined ? {} : { body: JSON.stringify({ annual_notice: value }) }),
        });
        return { status: response.status, json: await response.json() };
    }, annual);
}

// @scenario flow=browser
// @effects settings_validated, self_test_only, history_paginated
test('통지 상한 저장·재조회·경계값 거부·본인 시험 발송과 이력 복원', async ({ page, privacyManageToken }) => {
    await authenticatePage(page, privacyManageToken);
    await page.goto('/admin/plugins/sirsoft-gdpr/settings');
    await expect(page.locator('#card_annual_notice')).toBeAttached();
    const saved = await settingsApi(page);
    const original = saved.json.data.settings.annual_notice;
    try {
        // 상한을 화면에서 고치려면 자동 통지가 켜져 있어야 한다 — 꺼진 상태의 입력은 비활성이다(아래 케이스가 그 계약을 잠근다).
        expect((await settingsApi(page, { enabled: true, channels: ['database'], batch_size: 500, introduction: {} })).status).toBe(200);
        await page.reload();
        const batch = page.locator('input[name="annual_notice.batch_size"]');
        await batch.scrollIntoViewIfNeeded();
        await expect(batch).toHaveValue('500');
        for (const value of ['1', '500']) {
            await batch.fill(value);
            const response = page.waitForResponse(r => r.url().includes('/sirsoft-gdpr/admin/settings') && r.request().method() === 'PUT');
            await page.locator('#footer_save_button').click();
            expect((await response).status()).toBe(200);
            await page.reload();
            await expect(batch).toHaveValue(value);
        }
        expect((await settingsApi(page, { enabled: true, channels: ['database'], batch_size: 1001 })).status).toBe(422);
        expect((await settingsApi(page, { enabled: true, channels: [], batch_size: 500 })).status).toBe(422);
        const testResponse = page.waitForResponse(r => r.url().includes('/annual-notice/test'));
        await page.locator('#annual_notice_test').click();
        expect((await testResponse).status()).toBe(200);
        await page.goto('/admin/plugins/sirsoft-gdpr/notice-log');
        await expect(page.locator('#gdpr_notice_log_grid')).toBeVisible();
        await expect(page.locator('#gdpr_notice_log_grid')).toContainText('database');
        await page.reload();
        await expect(page.locator('#gdpr_notice_log_grid')).toContainText('database');
    } finally {
        expect((await settingsApi(page, original)).status).toBe(200);
    }
});

// @scenario flow=browser
// @effects disabled_toggle_releases_dependent_fields
test('자동 통지를 끄면 하위 입력이 비활성이고 저장이 막히지 않는다', async ({ page, privacyManageToken }) => {
    await authenticatePage(page, privacyManageToken);
    await page.goto('/admin/plugins/sirsoft-gdpr/settings');
    const saved = await settingsApi(page);
    const original = saved.json.data.settings.annual_notice;
    try {
        // 켠 상태에서는 입력이 살아 있다.
        expect((await settingsApi(page, { enabled: true, channels: ['database'], batch_size: 500, introduction: {} })).status).toBe(200);
        await page.reload();
        const batch = page.locator('input[name="annual_notice.batch_size"]');
        await batch.scrollIntoViewIfNeeded();
        await expect(batch).toBeEnabled();

        // 끄면 하위 입력이 비활성이 되고, 그 상태로 저장해도 422 가 아니다.
        expect((await settingsApi(page, { enabled: false, channels: ['database'], batch_size: 500, introduction: {} })).status).toBe(200);
        await page.reload();
        await batch.scrollIntoViewIfNeeded();
        await expect(batch).toBeDisabled();

        // 상한을 비운 채 저장해도(화면이 비활성이라 빈 값이 올라올 수 있다) 저장값은 지켜진다.
        expect((await settingsApi(page, { enabled: false, channels: [], batch_size: null, introduction: {} })).status).toBe(200);
        const after = await settingsApi(page);
        expect(after.json.data.settings.annual_notice.batch_size).toBe(500);
        expect(after.json.data.settings.annual_notice.channels).toEqual(['database']);
    } finally {
        expect((await settingsApi(page, original)).status).toBe(200);
    }
});

// @scenario flow=browser
// @effects notification_templates_are_editable_in_plugin_settings
test('알림 템플릿 카드가 이 화면에서 자기 정의를 보여 준다', async ({ page, privacyManageToken }) => {
    await authenticatePage(page, privacyManageToken);
    const definitions = page.waitForResponse(r => r.url().includes('/api/admin/notification-definitions') && r.url().includes('extension_identifier=sirsoft-gdpr'));
    await page.goto('/admin/plugins/sirsoft-gdpr/settings');
    expect((await definitions).status()).toBe(200);
    const card = page.locator('#card_gdpr_notifications');
    await card.scrollIntoViewIfNeeded();
    await expect(card).toBeVisible();
    // 코어 「알림 정의」 화면은 코어 정의만 받으므로 이 카드가 확장 정의의 유일한 진입점이다.
    await expect(card).toContainText('개인정보');

    // 템플릿 행과 편집 버튼 — 정의 목록을 template_channel=all 로 받아야 그려진다.
    const editButton = card.getByRole('button', { name: '템플릿 편집' }).first();
    await expect(editButton).toBeVisible();

    // 편집 창: 제목·본문이 저장값으로 채워지고 미리보기가 서버 치환 결과를 돌려준다(형제 확장과 같은 수준).
    await editButton.click();
    const modal = page.getByRole('dialog');
    await expect(modal).toBeVisible({ timeout: 10_000 });
    const subject = modal.locator('#gdpr_template_subject_input input').first();
    await expect(subject).toBeVisible();
    await expect(subject).not.toHaveValue('');
    // 창 안에는 본문 편집기의 미리보기와 하단 미리보기가 있다(형제 확장과 같은 구성).
    // 서버 치환을 거치는 것은 하단 버튼이다.
    const preview = page.waitForResponse(r => r.url().includes('/api/admin/notification-templates/preview'));
    await modal.getByRole('button', { name: '미리보기' }).last().click();
    expect((await preview).status()).toBe(200);
    // 저장 경로도 살아 있어야 한다 — 실제 저장은 하지 않고 버튼이 열려 있는지만 본다.
    await expect(modal.getByRole('button', { name: '저장' })).toBeEnabled();
});
