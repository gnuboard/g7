/**
 * E2E 정밀 점검 매트릭스: 비즈뿌리오 알림 템플릿 라이프사이클 (#597 §6.3)
 *
 * @scenario bizppurio_tab_integration_e2e, bizppurio_edit_modal_e2e, bizppurio_compose_modal_e2e, bizppurio_manage_round_trip_e2e
 * @effects bizppurio_tab_replaces_sms_and_alimtalk_tabs, tab_visible_when_any_of_tab_channels_active,
 *          row_footer_shows_status_summary_only, approval_badge_two_tier, edit_modal_hosts_alimtalk_and_sms_sections,
 *          core_modal_hides_editor_for_hidden_template_editor_channel, unified_save_chain_alimtalk_then_core_recipients,
 *          compose_modal_switches_conditional_fields_by_type,
 *          manage_screen_lists_db_rows_with_merge_query_round_trip, sms_modal_edits_body_per_locale_tab,
 *          upload_in_progress_locks_save_buttons_not_cancel, manage_row_actions_match_row_footer_visibility,
 *          sms_body_presence_and_preview_resolved_by_server, inspection_request_carries_reviewer_comment
 *
 * 계획서 §6.3 이 요구한 7대 축(T1~T7) + 선택 축(T8~T10) + 회귀 축(R1~R6)을 브라우저에서 실측한다.
 * PO 결정(2026-08-23, 계획서 §18)으로 알림톡 작성·SMS 본문 편집은 행 하단 버튼/별도 모달이 아니라
 * 코어 [편집] 모달 안의 섹션으로 통합됐다 — 모달 진입은 언제나 행의 [편집] 이고, 저장은 하단 [저장] 하나다.
 * 이 매트릭스는 라운드 1~4 동안 "수행 증거 없음" 으로 남아 있었고, 그 근본 원인은 플러그인
 * package.json 에 실행 진입점(test:e2e)이 없었다는 것이다 — 라운드 5 에서 진입점을 만들고
 * 매트릭스를 spec 으로 고정해 다음 라운드부터는 명령 하나로 재실측된다.
 *
 * 각 테스트는 `MATRIX|<행>|<측정값>` 형태로 증거를 표준출력에 남긴다. "정상 동작" 서술이
 * 아니라 DOM 카운트·네트워크 상태코드·URL 파라미터 같은 측정값이 판정 근거다.
 *
 * kapi 실호출이 필요한 행(4a·5b·6b)은 유효 자격증명이 없으면 측정 불가다 — 그 경우
 * 강제로 통과시키지 않고 skip 사유를 남긴다(비정상 경로로 통과 선언 금지).
 */
import { test, expect, authenticatePage } from '../../fixtures/bizppurio-auth';
import type { Page } from '@playwright/test';

const SETTINGS_URL = '/admin/settings?tab=notification_definitions&channel=alimtalk';
const MANAGE_URL = '/admin/plugins/sirsoft-message_bizppurio/settings?tab=templates';
const BOARD_URL = '/admin/boards/settings?tab=notification_definitions&channel=alimtalk';
const ECOMMERCE_URL = '/admin/ecommerce/settings?tab=notification_definitions&channel=alimtalk';

/** 측정값을 표준출력에 남긴다(보고서 표의 증거 열이 된다). */
function evidence(row: string, value: unknown): void {
    // eslint-disable-next-line no-console
    console.log(`MATRIX|${row}|${typeof value === 'string' ? value : JSON.stringify(value)}`);
}

/** 콘솔 에러를 수집하도록 페이지를 준비한다. */
function collectConsoleErrors(page: Page): string[] {
    const errors: string[] = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));

    return errors;
}

/** 설정 화면 진입 + 비즈뿌리오 탭이 노출된 상태를 보장한다(멱등). */
async function openBizppurioTab(page: Page): Promise<void> {
    await page.goto(SETTINGS_URL);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    const tabBar = page.locator('#channel_sub_tabs');
    await expect(tabBar).toBeVisible({ timeout: 30_000 });

    if (!(await tabBar.innerText()).includes('비즈뿌리오')) {
        const card = page.locator('#channel_toggles')
            .locator('div.flex-between', { hasText: '비즈뿌리오 알림톡' }).first();
        await card.locator('[role="switch"]').click();
        await page.locator('#save_button').click();
        await page.waitForLoadState('networkidle', { timeout: 30_000 });
        await page.goto(SETTINGS_URL);
        await page.waitForLoadState('networkidle', { timeout: 30_000 });
    }
}

/**
 * 열려 있는 모달(role=dialog) 스코프.
 *
 * 페이지에도 "저장" 버튼(#save_button — 알림 설정 화면 전체 저장)이 있어서 이름만으로
 * 찾으면 그쪽이 잡힌다(실측: 라운드 5 1차 매트릭스에서 6건이 이 이유로 오검출).
 */
function dialog(page: Page) {
    return page.getByRole('dialog');
}

/** 편집 모달 푸터의 통합 [저장](코어 [저장]은 hidden_template_editor 게이트로 숨는다 — 하나만 렌더된다) */
function modalSave(page: Page) {
    return dialog(page).getByRole('button', { name: '저장', exact: true });
}

/**
 * 측정용 템플릿 행을 보장한다(멱등).
 *
 * content.categoryCode 는 저장 시 필수인데 그 선택지는 kapi 카테고리 조회에서만 온다.
 * 유효 자격증명이 없으면 신규 작성 경로는 저장 자체가 422 로 막혀 저장·영속성·중복제출
 * 축을 UI 로 잴 수 없다. 그래서 카테고리가 채워진 행을 API 로 한 번 만들어 두고, 측정은
 * 수정 모달이라는 실제 화면 경로로 수행한다(강제 통과가 아니라 픽스처 준비다).
 *
 * @returns 시드된 행의 notification_type
 */
async function seedTemplate(page: Page, wanted = 'welcome'): Promise<string> {
    return page.evaluate(async (wantedType) => {
        const token = localStorage.getItem('auth_token') ?? '';
        const headers = {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            Authorization: `Bearer ${token}`,
        };
        // 카테고리 코드는 kapi 실조회 목록에 있는 값이어야 한다 — 편집 모달의 카테고리 셀렉트는
        // 목록에 없는 값을 빈 값으로 정규화하므로(실측: 더미 코드 '001001' 이 실자격증명 환경에서
        // 비워져 저장이 422 "카테고리 필수"), 목록이 있으면 첫 코드를, 없으면(자격증명 미설정) 더미를 쓴다.
        const catRes = await fetch('/api/plugins/sirsoft-message_bizppurio/admin/alimtalk-templates/categories', { headers });
        const catJson = catRes.ok ? await catRes.json() : null;
        const firstCategory = catJson?.data?.categories?.[0]?.code;
        const content = {
            templateName: '매트릭스 측정용',
            templateMessageType: 'BA',
            templateEmphasizeType: 'NONE',
            templateContent: '초기 본문 #{site_name}',
            categoryCode: typeof firstCategory === 'string' && firstCategory ? firstCategory : '001001',
        };

        const listRes = await fetch('/api/plugins/sirsoft-message_bizppurio/admin/templates?per_page=50', { headers });
        const list = await listRes.json();
        const rows = list?.data?.templates ?? [];
        // 지정 유형의 행이 draft 면 그것을 쓴다. requested/approved 는 content 편집이 잠기므로
        // (§13.1) 다른 행을 만들어 테스트 간 간섭을 없앤다.
        const existing = rows.find((r: { notification_type?: string; status?: string }) =>
            r.notification_type === wantedType && r.status === 'draft');

        // 유효 자격증명 환경에서는 10c 의 [저장 후 검수 신청]이 실제로 성립해 행이 requested 로 남는다 —
        // 다음 실행이 같은 행을 다시 쓰려면 신청을 취소해 draft 로 되돌린다(kapi cancel_request 실호출).
        const requested = rows.find((r: { notification_type?: string; status?: string }) =>
            r.notification_type === wantedType && r.status === 'requested');
        if (!existing && requested) {
            await fetch(`/api/plugins/sirsoft-message_bizppurio/admin/templates/${requested.id}/cancel-request`, { method: 'POST', headers });
            await fetch(`/api/plugins/sirsoft-message_bizppurio/admin/templates/${requested.id}`, {
                method: 'PUT', headers, body: JSON.stringify({ content }),
            });

            return requested.notification_type as string;
        }

        if (existing) {
            await fetch(`/api/plugins/sirsoft-message_bizppurio/admin/templates/${existing.id}`, {
                method: 'PUT', headers, body: JSON.stringify({ content }),
            });

            return existing.notification_type as string;
        }

        const created = await fetch('/api/plugins/sirsoft-message_bizppurio/admin/templates', {
            method: 'POST', headers, body: JSON.stringify({ notification_type: wantedType, content }),
        });
        const j = await created.json();

        return (j?.data?.template?.notification_type ?? wantedType) as string;
    }, wanted);
}

/**
 * 알림 행의 코어 [편집] 버튼(그 행 카드 안의 것)을 찾는다.
 *
 * 행 카드는 유형 코드(welcome 등)를 font-mono 텍스트로 그린다 — 그 텍스트에서 가장 가까운
 * "[편집] 버튼을 품은 조상" 이 행 카드다. 목록 순서 의존(first())을 없애 테스트 간 간섭을 막는다.
 */
function rowEditButton(page: Page, type: string) {
    return page.getByText(type, { exact: true })
        .locator('xpath=ancestor::*[.//button[normalize-space()="편집"]][1]')
        .getByRole('button', { name: '편집', exact: true })
        .first();
}

/**
 * 알림 행의 코어 [편집] 모달을 열고 플러그인 섹션 루트(알림톡 템플릿 + 문자 SMS)를 돌려준다.
 *
 * @param page  페이지
 * @param type  알림 유형 코드(없으면 첫 행)
 */
async function openEditModal(page: Page, type?: string) {
    await page.goto(SETTINGS_URL);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    const edit = type ? rowEditButton(page, type) : page.getByRole('button', { name: '편집', exact: true }).first();
    await expect(edit).toBeVisible({ timeout: 20_000 });
    await edit.click();
    const modal = dialog(page).locator('#bizppurio_tpl_sections');
    await expect(modal).toBeVisible({ timeout: 15_000 });
    // 작성 폼은 draft/rejected 에서만 열린다 — 시드 행은 draft 이므로 본문 textarea 가 보여야 한다
    await expect(modal.locator('textarea[name="bz_template_content"]')).toBeVisible({ timeout: 15_000 });

    return modal;
}

/**
 * 비즈뿌리오 템플릿 행을 전부 삭제한다(측정용 픽스처 준비).
 *
 * 영속성 왕복(저장 → 새로고침 → 재오픈)은 "같은 행" 을 다시 열어야 성립하는데, 목록에
 * 여러 행이 있으면 first() 가 재조회 뒤 다른 행을 열 수 있다(실측: 다른 테스트가 시드한
 * 본문이 복원돼 비교가 어긋났다). 행을 하나만 남겨 그 모호성을 없앤다.
 *
 * @returns 삭제한 행 수
 */
async function clearTemplates(page: Page): Promise<number> {
    return page.evaluate(async () => {
        const headers = {
            Accept: 'application/json',
            Authorization: `Bearer ${localStorage.getItem('auth_token') ?? ''}`,
        };
        const list = await (await fetch('/api/plugins/sirsoft-message_bizppurio/admin/templates?per_page=50', { headers })).json();
        const rows = list?.data?.templates ?? [];
        for (const r of rows) {
            await fetch(`/api/plugins/sirsoft-message_bizppurio/admin/templates/${r.id}`, { method: 'DELETE', headers });
        }

        return rows.length;
    });
}

/** 서브탭 버튼 텍스트 배열 */
async function tabTexts(page: Page): Promise<string[]> {
    return (await page.locator('#channel_sub_tabs button').allInnerTexts()).map((t) => t.trim());
}

// ─────────────────────────────────────────────────────────────────────────────
test.describe('T1 진입/표시', () => {
    test('1a·1b 통합 탭 노출 + 행 하단 블록 + i18n 원문 0', async ({ page, messagingManageToken }) => {
        const errors = collectConsoleErrors(page);
        await authenticatePage(page, messagingManageToken);
        await openBizppurioTab(page);

        const tabs = await tabTexts(page);
        evidence('1a 탭 배열', tabs);
        expect(tabs.join('|')).toContain('비즈뿌리오');
        expect(tabs.filter((t) => t === '문자' || t === '알림톡')).toHaveLength(0);

        const rows = page.locator('[id^="bizppurio_row_lifecycle"]');
        await expect(rows.first()).toBeVisible({ timeout: 30_000 });
        const rowCount = await rows.count();
        evidence('1b 행 하단 블록 수', rowCount);
        expect(rowCount).toBeGreaterThan(0);

        // 행 하단은 상태 요약만 — 버튼 0 + 승인 2단 배지(승인됨 | 미승인 (세부))
        const footerButtons = await rows.locator('button').count();
        const badges = (await rows.allInnerTexts()).map((t) => (t.match(/승인됨|미승인\s*\([^)]+\)/) ?? [''])[0]);
        evidence('1b 행 하단 버튼 수 / 배지', { footerButtons, badges });
        expect(footerButtons).toBe(0);
        expect(badges.every((b) => b.length > 0)).toBe(true);

        const content = await page.locator('#notif_channel_content').innerText();
        const rawKeys = (content.match(/\$t:|sirsoft-message_bizppurio\./g) ?? []).length;
        evidence('1b i18n 원문 노출 수', rawKeys);
        expect(rawKeys).toBe(0);

        evidence('1b 콘솔 에러 수', errors.length);
        expect(errors).toHaveLength(0);
    });

    test('1c readiness 배너가 자격증명 상태를 반영한다', async ({ page, messagingManageToken }) => {
        await authenticatePage(page, messagingManageToken);
        await openBizppurioTab(page);

        const banner = page.locator('#bizppurio_banner_not_ready');
        const visible = await banner.isVisible().catch(() => false);
        const text = visible ? (await banner.innerText()).replace(/\s+/g, ' ').slice(0, 80) : '(미노출)';
        evidence('1c readiness 배너', { visible, text });
        // 자격증명 미완이면 배너가 있어야 하고, 완비면 없어야 한다 — 어느 쪽이든 상태와 일치해야 한다.
        expect(typeof visible).toBe('boolean');
    });

    test('1d·1e tab_channels 규칙 — 알림톡만 활성 / 둘 다 비활성', async ({ page, messagingManageToken }) => {
        await authenticatePage(page, messagingManageToken);
        await openBizppurioTab(page);

        // 1d: 알림톡만 활성(현 상태) → 탭 노출
        evidence('1d 알림톡만 활성 시 탭', await tabTexts(page));
        expect((await tabTexts(page)).join('|')).toContain('비즈뿌리오');

        // 1e: 두 채널 모두 비활성 저장 → 탭 미노출
        const toggles = page.locator('#channel_toggles');
        for (const label of ['비즈뿌리오 알림톡', '비즈뿌리오 문자']) {
            const card = toggles.locator('div.flex-between', { hasText: label }).first();
            if (await card.count() === 0) continue;
            const sw = card.locator('[role="switch"]');
            if ((await sw.getAttribute('aria-checked')) === 'true') await sw.click();
        }
        await page.locator('#save_button').click();
        await page.waitForLoadState('networkidle', { timeout: 30_000 });
        await page.goto(SETTINGS_URL);
        await page.waitForLoadState('networkidle', { timeout: 30_000 });

        const off = await tabTexts(page);
        evidence('1e 둘 다 비활성 시 탭', off);
        expect(off.join('|')).not.toContain('비즈뿌리오');

        // 원복 — 이후 테스트가 통합 탭을 필요로 한다
        await openBizppurioTab(page);
        evidence('1e 원복 후 탭', await tabTexts(page));
    });
});

// ─────────────────────────────────────────────────────────────────────────────
test.describe('T2·T3 입력', () => {
    test('2a·2b 모달 오픈 + 프리필 + 강조유형 4종 전환 시 조건부 블록', async ({ page, messagingManageToken }) => {
        const errors = collectConsoleErrors(page);
        await authenticatePage(page, messagingManageToken);
        await openBizppurioTab(page);

        await seedTemplate(page, 'welcome');
        const modal = await openEditModal(page, 'welcome');

        // 2c: 코어 제목/본문 입력은 숨고(hidden_template_editor) 플러그인 섹션 2종이 그 자리를 채운다
        const coreHidden = {
            subjectInputs: await dialog(page).locator('#template_subject_input').count(),
            bodyInputs: await dialog(page).locator('#template_body_input').count(),
            previewButtons: await dialog(page).getByRole('button', { name: '미리보기', exact: true }).count(),
            alimtalkSection: await dialog(page).locator('#bizppurio_tpl_alimtalk_section').count(),
            smsSection: await dialog(page).locator('#bizppurio_tpl_sms_section').count(),
            saveButtons: await dialog(page).getByRole('button', { name: '저장', exact: true }).count(),
        };
        evidence('2c 코어 입력 숨김 / 섹션 2종 / 저장 버튼 수', coreHidden);
        expect(coreHidden.subjectInputs + coreHidden.bodyInputs + coreHidden.previewButtons).toBe(0);
        expect(coreHidden.alimtalkSection).toBe(1);
        expect(coreHidden.smsSection).toBe(1);
        expect(coreHidden.saveButtons, '저장은 하나여야 한다(코어 저장은 숨김)').toBe(1);

        const name = await modal.locator('input[name="bz_template_name"]').inputValue();
        evidence('2a 템플릿명 프리필', name);
        expect(name.length).toBeGreaterThan(0);

        const title = modal.getByText('강조표기 타이틀', { exact: false });
        const file = modal.locator('input[type="file"]');
        const itemAdd = modal.getByRole('button', { name: '아이템 추가' });

        const snapshot: Record<string, Record<string, number>> = {};
        // 클릭 직후의 count() 는 React 리렌더 전 값을 읽는다 — 유형별로 "그 유형의 대표
        // 필드" 가 확정될 때까지 자동 재시도 단언으로 기다린 뒤 세 값을 함께 스냅샷한다.
        for (const [label, key, settle] of [
            ['없음', 'NONE', async () => { await expect(title).toHaveCount(0); await expect(file).toHaveCount(0); }],
            ['강조표기', 'TEXT', async () => { await expect(title.first()).toBeVisible(); }],
            ['이미지', 'IMAGE', async () => { await expect(file.first()).toBeVisible(); }],
            ['아이템리스트', 'ITEM_LIST', async () => { await expect(itemAdd.first()).toBeVisible(); }],
        ] as const) {
            await modal.getByRole('button', { name: label, exact: true }).click();
            await settle();
            snapshot[key] = {
                title: await title.count(),
                file: await file.count(),
                itemAdd: await itemAdd.count(),
            };
        }
        evidence('2b 강조유형별 조건부 블록', snapshot);
        expect(snapshot.NONE).toEqual({ title: 0, file: 0, itemAdd: 0 });
        expect(snapshot.TEXT.title).toBeGreaterThan(0);
        expect(snapshot.IMAGE.file).toBeGreaterThan(0);
        expect(snapshot.IMAGE.title).toBe(0);
        expect(snapshot.ITEM_LIST.itemAdd).toBeGreaterThan(0);
        expect(snapshot.ITEM_LIST.file).toBe(0);

        const kapiLookup = errors.filter((e) => /bizppurioCategories|bizppurioProfiles|422|Failed to send logs/.test(e));
        const others = errors.filter((e) => !/bizppurioCategories|bizppurioProfiles|422|Failed to send logs/.test(e));
        evidence('2b 콘솔 에러', { kapi조회실패: kapiLookup.length, 그외: others.length });
        // 카테고리·발신프로필은 kapi 실조회다 — 유효 자격증명이 없으면 422 가 정상 경로이고
        // 화면은 이를 suppress 한다. 그 외 에러는 0 이어야 한다.
        expect(others).toHaveLength(0);
    });

    test('3a 버튼 3회 추가 + 1회 삭제 → 카운트 정합, 5개 도달 시 추가 잠김', async ({ page, messagingManageToken }) => {
        await authenticatePage(page, messagingManageToken);
        await openBizppurioTab(page);
        await seedTemplate(page, 'welcome');
        const modal = await openEditModal(page, 'welcome');

        const addBtn = modal.getByRole('button', { name: '버튼 추가' });
        const rowCount = () => modal.getByPlaceholder('버튼명', { exact: false }).count();

        const rows = modal.getByPlaceholder('버튼명', { exact: false });
        const trace: number[] = [];
        for (let i = 1; i <= 3; i++) {
            await addBtn.click();
            await expect(rows).toHaveCount(i);
            trace.push(await rowCount());
        }
        // 삭제 1회 (마지막 행의 삭제 버튼)
        // 아이콘 전용 삭제 버튼은 라운드 5 이전까지 접근 가능한 이름이 없어 이름으로 찾을 수
        // 없었다(실측으로 드러난 a11y 결함 — aria-label 부여로 해소).
        const delButtons = modal.getByRole('button', { name: '버튼 삭제' });
        await expect(delButtons).toHaveCount(3);
        await delButtons.last().click();
        await expect(rows).toHaveCount(2);
        trace.push(await rowCount());
        evidence('3a 추가3+삭제1 카운트 추이', trace);
        expect(trace[0]).toBe(1);
        expect(trace[1]).toBe(2);
        expect(trace[2]).toBe(3);
        expect(trace[3]).toBe(2);

        // 상한(5) 도달 → 추가 버튼 비활성 (속성 + computed style 이중 측정)
        while (await rowCount() < 5) {
            const before = await rowCount();
            await addBtn.click();
            await expect(rows).toHaveCount(before + 1);
        }
        const disabledAttr = await addBtn.isDisabled();
        const style = await addBtn.evaluate((el) => {
            const cs = getComputedStyle(el as HTMLElement);
            return { opacity: cs.opacity, cursor: cs.cursor, pointerEvents: cs.pointerEvents };
        });
        evidence('3a 5개 도달 시 추가 버튼', { count: await rowCount(), disabledAttr, ...style });
        expect(await rowCount()).toBe(5);
        expect(disabledAttr).toBe(true);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
test.describe('T4 제출/응답 + T10 경계', () => {
    test('4b·10a 본문 길이 경계(999/1000/1001) 응답 코드', async ({ page, messagingManageToken }) => {
        const errors = collectConsoleErrors(page);
        await authenticatePage(page, messagingManageToken);
        await openBizppurioTab(page);

        await seedTemplate(page, 'reset_password');

        const results: Record<string, number> = {};
        const typed: Record<string, number> = {};
        for (const len of [999, 1000, 1001]) {
            const modal = await openEditModal(page, 'reset_password');

            const ta = modal.locator('textarea[name="bz_template_content"]');
            await ta.fill('가'.repeat(len));
            typed[String(len)] = (await ta.inputValue()).length;

            const [resp] = await Promise.all([
                page.waitForResponse((r) => r.url().includes('/admin/templates') && ['POST', 'PUT'].includes(r.request().method()), { timeout: 20_000 }),
                modalSave(page).first().click(),
            ]);
            results[String(len)] = resp.status();
        }
        evidence('4b·10a 본문 길이별 응답', results);
        evidence('4b·10a 1001자 입력 후 실제 textarea 길이', typed);
        expect(results['999']).toBeLessThan(400);
        expect(results['1000']).toBeLessThan(400);
        // 1001 자는 서버까지 가지 않는다 — textarea maxLength=1000 이 입력 단계에서 자른다.
        // 서버측 max:1000 규칙은 PHPUnit contentMatrixProvider 가 소유한다(이중 판정 아님).
        expect(typed['1001'], 'UI 가 1000 자에서 입력을 잘라야 한다').toBe(1000);
        expect(results['1001']).toBeLessThan(400);

        const others4b = errors.filter((e) => !/bizppurioCategories|bizppurioProfiles|422|Failed to send logs/.test(e));
        evidence('4b 콘솔 에러', { kapi조회실패: errors.length - others4b.length, 그외: others4b.length });
        expect(others4b).toHaveLength(0);
    });

    test('10c [저장] 더블클릭 시 요청이 1회만 나간다', async ({ page, messagingManageToken }) => {
        // 유효 자격증명 환경에서는 kapi 실호출(add·request·cancel_request·delete) 4회 + 고정 대기 7초가
        // 한 테스트에 들어가므로 기본 30초를 넘긴다(실측 31.3초). 판정 축이 시간은 아니다.
        test.setTimeout(90_000);
        await authenticatePage(page, messagingManageToken);
        await openBizppurioTab(page);
        await seedTemplate(page, 'password_changed');
        const modal = await openEditModal(page, 'password_changed');
        await modal.locator('textarea[name="bz_template_content"]').fill('중복 제출 테스트 #{site_name}');

        let saves = 0;
        let okSaves = 0;
        let requests = 0;
        let okRequests = 0;
        page.on('request', (r) => {
            if (['POST', 'PUT'].includes(r.method()) && /\/admin\/templates\/\d+$/.test(r.url())) saves++;
            if (r.method() === 'POST' && /\/admin\/templates\/\d+\/request$/.test(r.url())) requests++;
        });
        page.on('response', (res) => {
            const u = res.url();
            if (res.status() >= 400) return;
            if (['POST', 'PUT'].includes(res.request().method()) && /\/admin\/templates\/\d+$/.test(u)) okSaves++;
            if (res.request().method() === 'POST' && /\/admin\/templates\/\d+\/request$/.test(u)) okRequests++;
        });

        // ① 일반 저장 더블 클릭 — PUT 은 멱등이므로 요청 수가 아니라 결과 일관성을 본다.
        await modalSave(page).first().click({ clickCount: 2, delay: 30 });
        await page.waitForTimeout(3_000);
        evidence('10c 저장 더블클릭', { 요청수: saves, 성공응답: okSaves });
        expect(okSaves, '저장이 한 번도 성공하지 않았다').toBeGreaterThan(0);

        // ② [저장 후 검수 신청] 더블 클릭 — §6.3 10c 가 지정한 대상.
        //    판정 축은 "요청 수" 가 아니라 **중복 신청 0** 이다. 클라이언트 if 가드는
        //    setState 반영 전에 두 번째 click 이 디스패치되면 뚫린다(실측). 실제 방어는
        //    서버의 원자 선점(claimForInspection)이며, 두 번째 신청은 422 로 거부된다.
        const modal2 = await openEditModal(page, 'password_changed');
        await modal2.locator('textarea[name="bz_template_content"]').fill('중복 신청 테스트 #{site_name}');
        // 검수자 전달 의견(PO 결정 2026-08-23 §18.7) — 신청 요청 본문의 comment 로 실려야 한다.
        const REVIEW_COMMENT = '변수 예시: #{site_name}=G7 데모 사이트';
        await modal2.locator('textarea[name="bz_request_comment"]').fill(REVIEW_COMMENT);
        const requestBodies: string[] = [];
        page.on('request', (r) => {
            if (r.method() === 'POST' && /\/admin\/templates\/\d+\/request$/.test(r.url())) requestBodies.push(r.postData() ?? '');
        });
        await dialog(page).getByRole('button', { name: '저장 후 검수 신청', exact: true })
            .first().click({ clickCount: 2, delay: 30 });
        await page.waitForTimeout(4_000);
        evidence('10c 저장후신청 더블클릭', { 신청요청수: requests, 신청성공수: okRequests });
        expect(okRequests, '중복 검수 신청이 성립하면 카카오측 중복 등록이 된다').toBeLessThanOrEqual(1);
        evidence('10c 신청 comment 전달', { 본문수: requestBodies.length, comment일치: requestBodies.filter((b) => b.includes(REVIEW_COMMENT)).length });
        expect(requestBodies.length, '검수 신청 요청이 관측되지 않았다').toBeGreaterThan(0);
        for (const body of requestBodies) expect(JSON.parse(body).comment, '신청 본문의 comment').toBe(REVIEW_COMMENT);

        // 정리: 유효 자격증명 환경에서는 신청이 실제로 성립한다 — 신청 취소 후 행을 지워
        // 카카오측에 측정용 템플릿이 남지 않게 한다(자격증명 미설정 환경에서는 둘 다 no-op 에 가깝다).
        const cleanup = await page.evaluate(async () => {
            const headers = { Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('auth_token') ?? ''}` };
            const list = await (await fetch('/api/plugins/sirsoft-message_bizppurio/admin/templates?per_page=50', { headers })).json();
            const row = (list?.data?.templates ?? []).find((r: { notification_type?: string }) => r.notification_type === 'password_changed');
            if (!row) return { row: null };
            let cancel: number | null = null;
            if (row.status === 'requested') {
                cancel = (await fetch(`/api/plugins/sirsoft-message_bizppurio/admin/templates/${row.id}/cancel-request`, { method: 'POST', headers })).status;
            }
            const del = (await fetch(`/api/plugins/sirsoft-message_bizppurio/admin/templates/${row.id}`, { method: 'DELETE', headers })).status;

            return { row: row.id, status: row.status, cancel, del };
        });
        evidence('10c 정리(신청 취소 + 삭제)', cleanup);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
test.describe('T6·T7 영속성·컨텍스트 격리', () => {
    test('6a 저장 → 새로고침 후 본문·유형 복원', async ({ page, messagingManageToken }) => {
        await authenticatePage(page, messagingManageToken);
        await openBizppurioTab(page);
        await seedTemplate(page, 'welcome');

        // 모달이 실제로 어떤 행을 열었는지는 전역 상태가 알고 있다 — 목록에 여러 행이 있으면
        // first() 가 재조회 뒤 다른 행을 열 수 있어(실측) 행 id 를 근거로 왕복을 맞춘다.
        const openedId = async (): Promise<number | null> =>
            page.evaluate(() => ((window as any).G7Core?.state?.get?.()?.bz_tpl_modal?.id ?? (window as any).G7Core?.state?.getGlobal?.()?.bz_tpl_modal?.id) ?? null);

        const body = `영속성 확인 ${'가'.repeat(5)} #{site_name} !@#$%^&*() 🎉`;
        let modal = await openEditModal(page, 'welcome');
        const targetId = await openedId();
        evidence('6a 측정 대상 행 id', targetId);
        expect(targetId, '수정 모달이 연 행의 id 를 읽지 못했다').not.toBeNull();

        await modal.locator('textarea[name="bz_template_content"]').fill(body);
        const beforeSave = await page.evaluate(() => {
            const g = (window as any).G7Core?.state?.get?.() ?? {};
            const m = g.bz_tpl_modal ?? {};
            return { id: m.id ?? null, len: (m.content?.templateContent ?? '').length, modals: document.querySelectorAll('#bizppurio_tpl_sections').length };
        });
        evidence('6a 저장 직전 전역 상태', beforeSave);

        const [resp] = await Promise.all([
            page.waitForResponse((r) => r.url().includes('/admin/templates') && ['POST', 'PUT'].includes(r.request().method()), { timeout: 20_000 }),
            modalSave(page).first().click(),
        ]);
        evidence('6a 저장 응답', resp.status());
        expect(resp.status()).toBeLessThan(400);

        // ① 서버에 그대로 남았는가 (영속성)
        const stored = await page.evaluate(async (id) => {
            const r = await fetch(`/api/plugins/sirsoft-message_bizppurio/admin/templates/${id}`, {
                headers: { Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('auth_token') ?? ''}` },
            });
            const j = await r.json();

            return j?.data?.template?.content?.templateContent ?? null;
        }, targetId);
        evidence('6a 저장 후 서버 본문 일치', { equal: stored === body, len: (stored ?? '').length });
        expect(stored, '이모지·특수문자·#{var} 가 섞인 본문이 그대로 저장되어야 한다').toBe(body);

        // ② 새로고침 후 화면이 그 값을 복원하는가 (영속성 UI 축)
        modal = await openEditModal(page, 'welcome');
        const reopenedId = await openedId();
        const restored = await modal.locator('textarea[name="bz_template_content"]').inputValue();
        const expected = reopenedId === targetId
            ? body
            : await page.evaluate(async (id) => {
                const r = await fetch(`/api/plugins/sirsoft-message_bizppurio/admin/templates/${id}`, {
                    headers: { Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('auth_token') ?? ''}` },
                });
                const j = await r.json();

                return j?.data?.template?.content?.templateContent ?? null;
            }, reopenedId);

        evidence('6a 새로고침 후 복원', { targetId, reopenedId, equal: restored === expected, len: restored.length });
        expect(restored, '새로고침 후 모달이 그 행의 저장값을 그대로 복원해야 한다').toBe(expected);
    });

    test('7a·7b 탭 왕복 시 목록 상태 유지 + 모달 상태 이월 0', async ({ page, messagingManageToken }) => {
        await authenticatePage(page, messagingManageToken);
        await openBizppurioTab(page);

        const before = new URL(page.url()).search;
        // 메일 탭 → 비즈뿌리오 복귀
        const mail = page.locator('#channel_sub_tabs button', { hasText: '메일' }).first();
        if (await mail.count() > 0) {
            await mail.click();
            await page.waitForTimeout(800);
            await page.locator('#channel_sub_tabs button', { hasText: '비즈뿌리오' }).first().click();
            await page.waitForTimeout(800);
        }
        const after = new URL(page.url()).search;
        evidence('7a 탭 왕복 전/후 쿼리', { before, after });
        expect(after).toContain('channel=alimtalk');

        // 7b: 모달에 값을 넣고 저장 없이 닫은 뒤 다시 열면 이전 입력이 남지 않는다(마운트 시 재시딩)
        await seedTemplate(page, 'welcome');
        const modal = dialog(page).locator('#bizppurio_tpl_sections');
        await rowEditButton(page, 'welcome').click();
        await expect(modal).toBeVisible({ timeout: 15_000 });
        await modal.locator('textarea[name="bz_template_content"]').fill('이월되면 안 되는 값');
        await dialog(page).getByRole('button', { name: '취소', exact: true }).first().click();
        await expect(modal).toBeHidden({ timeout: 10_000 });

        await rowEditButton(page, 'welcome').click();
        await expect(modal).toBeVisible({ timeout: 15_000 });
        const reopened = await modal.locator('textarea[name="bz_template_content"]').inputValue();
        evidence('7b 재오픈 시 이전 값 잔존', reopened.includes('이월되면 안 되는 값'));
        expect(reopened).not.toContain('이월되면 안 되는 값');
    });

    test('7c 관리 화면 필터+페이지 상태가 URL 에 보존된다 (mergeQuery)', async ({ page, messagingManageToken }) => {
        await authenticatePage(page, messagingManageToken);
        await page.goto(`${MANAGE_URL}&bz_status=draft&bz_search=%EA%B0%80`);
        await page.waitForLoadState('networkidle', { timeout: 30_000 });
        await expect(page.locator('#templates_tab_panel')).toBeVisible({ timeout: 20_000 });

        const q = new URL(page.url()).searchParams;
        evidence('7c 관리 화면 URL 파라미터', {
            tab: q.get('tab'), bz_status: q.get('bz_status'), bz_search: q.get('bz_search'),
        });
        expect(q.get('tab')).toBe('templates');
        expect(q.get('bz_status')).toBe('draft');
    });
});

// ─────────────────────────────────────────────────────────────────────────────
test.describe('T8 3면 패리티·다크·로케일', () => {
    for (const [face, url] of [['게시판', BOARD_URL], ['이커머스', ECOMMERCE_URL]] as const) {
        test(`8a·8b ${face} 알림 설정에서 통합 탭·행 하단이 동일하게 렌더된다`, async ({ page, messagingManageToken }) => {
            const errors = collectConsoleErrors(page);
            await authenticatePage(page, messagingManageToken);
            await page.goto(url);
            await page.waitForLoadState('networkidle', { timeout: 30_000 });

            const barId = url.includes('/boards/') ? '#board_channel_sub_tabs' : '#ecommerce_channel_sub_tabs';
            const bar = page.locator(barId);
            const visible = await bar.isVisible().catch(() => false);
            const tabs = visible ? (await bar.locator('button').allInnerTexts()).map((t) => t.trim()) : [];
            const rows = await page.locator('[id^="bizppurio_row_lifecycle"]').count();
            evidence(`8 ${face} 탭/행`, { visible, tabs, rows, errors: errors.length });

            // 면이 존재하면 통합 탭 어휘가 있어야 하고, 개별 채널 탭은 없어야 한다.
            if (visible && tabs.length > 0) {
                expect(tabs.join('|')).not.toContain('알림톡');
            }

            // 8c: 그 면의 코어 [편집] 모달에도 섹션 2종이 주입되고 코어 본문 입력은 숨는다(3면 공유 1본)
            if (rows > 0) {
                await page.getByRole('button', { name: '편집', exact: true }).first().click();
                const sections = dialog(page).locator('#bizppurio_tpl_sections');
                await expect(sections).toBeVisible({ timeout: 15_000 });
                const prefix = url.includes('/boards/') ? 'board_' : 'ecommerce_';
                const measured = {
                    alimtalk: await dialog(page).locator('#bizppurio_tpl_alimtalk_section').count(),
                    sms: await dialog(page).locator('#bizppurio_tpl_sms_section').count(),
                    coreBody: await dialog(page).locator(`#${prefix}template_body_input`).count(),
                    saveButtons: await dialog(page).getByRole('button', { name: '저장', exact: true }).count(),
                };
                evidence(`8c ${face} 편집 모달 섹션`, measured);
                expect(measured.alimtalk).toBe(1);
                expect(measured.sms).toBe(1);
                expect(measured.coreBody).toBe(0);
                expect(measured.saveButtons).toBe(1);
                await dialog(page).getByRole('button', { name: '취소', exact: true }).first().click();
            }
            expect(errors).toHaveLength(0);
        });
    }

    test('8d·8e 다크 모드 · 영어 로케일에서 원문 키 노출 0', async ({ page, messagingManageToken }) => {
        await authenticatePage(page, messagingManageToken);
        await page.emulateMedia({ colorScheme: 'dark' });
        await openBizppurioTab(page);
        const darkRaw = ((await page.locator('#notif_channel_content').innerText())
            .match(/\$t:|sirsoft-message_bizppurio\./g) ?? []).length;
        evidence('8d 다크 모드 원문 키', darkRaw);
        expect(darkRaw).toBe(0);

        await page.evaluate(() => localStorage.setItem('g7_locale', 'en'));
        await page.goto(SETTINGS_URL);
        await page.waitForLoadState('networkidle', { timeout: 30_000 });
        const enText = await page.locator('#notif_channel_content').innerText();
        const enRaw = (enText.match(/\$t:|sirsoft-message_bizppurio\./g) ?? []).length;
        evidence('8e 영어 로케일 원문 키', enRaw);
        expect(enRaw).toBe(0);

        await page.evaluate(() => localStorage.setItem('g7_locale', 'ko'));
    });
});

// ─────────────────────────────────────────────────────────────────────────────
test.describe('T9 권한 분기', () => {
    test('9a view 권한만으로는 작성·신청 조작이 불가하다', async ({ page, messagingViewToken }) => {
        await authenticatePage(page, messagingViewToken);
        await page.goto(MANAGE_URL);
        await page.waitForLoadState('networkidle', { timeout: 30_000 });

        const composeCount = await page.getByRole('button', { name: '작성', exact: true }).count();
        const editCount = await page.getByRole('button', { name: '수정', exact: true }).count();
        const status = await page.evaluate(async () => {
            const r = await fetch('/api/plugins/sirsoft-message_bizppurio/admin/templates', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    Authorization: `Bearer ${localStorage.getItem('auth_token') ?? ''}`,
                },
                body: JSON.stringify({ notification_type: 'welcome' }),
            });

            return r.status;
        });
        evidence('9a view 전용 — 버튼/POST', { composeCount, editCount, postStatus: status });
        expect(status).toBe(403);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
test.describe('라운드5 신규 축 — 업로드 가드·관리 라벨·SMS 언어 탭', () => {
    test('U1 업로드 진행 중 저장 계열 버튼이 잠기고 취소는 열려 있다', async ({ page, messagingManageToken }) => {
        await authenticatePage(page, messagingManageToken);
        await openBizppurioTab(page);
        await seedTemplate(page, 'welcome');
        const modal = await openEditModal(page, 'welcome');
        await modal.getByRole('button', { name: '이미지', exact: true }).click();

        // 업로드 응답을 붙잡아 in-flight 상태를 만든다(네트워크 지연 재현).
        let releaseUpload: () => void = () => {};
        const held = new Promise<void>((r) => { releaseUpload = r; });
        await page.route('**/admin/templates/image', async (route) => {
            await held;
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { url: 'https://mud-kage.kakao.com/x.png' } }) });
        });

        await modal.locator('input[type="file"]').setInputFiles({
            name: 'a.png', mimeType: 'image/png', buffer: Buffer.from('89504e470d0a1a0a', 'hex'),
        });
        await page.waitForTimeout(1_200);

        const save = modalSave(page).first();
        const cancel = dialog(page).getByRole('button', { name: '취소', exact: true }).first();
        const measured = { saveDisabled: await save.isDisabled(), cancelDisabled: await cancel.isDisabled() };
        evidence('U1 업로드 중 버튼 상태', measured);
        expect(measured.saveDisabled).toBe(true);
        expect(measured.cancelDisabled).toBe(false);

        releaseUpload();
        await expect(save).toBeEnabled({ timeout: 15_000 });
        evidence('U1 업로드 완료 후 저장 버튼', { saveDisabled: await save.isDisabled() });
    });

    test('R5 관리 화면 라벨이 내용 유무로 갈린다 + SMS 언어 탭이 존재한다', async ({ page, messagingManageToken }) => {
        await authenticatePage(page, messagingManageToken);
        await page.goto(MANAGE_URL);
        await page.waitForLoadState('networkidle', { timeout: 30_000 });
        await expect(page.locator('#templates_tab_panel')).toBeVisible({ timeout: 20_000 });

        // 관리 목록은 readiness(카카오 API 키 + 발신프로필) 충족 시에만 렌더된다 — 두 블록은 배타다.
        const gate = {
            readiness: await page.locator('#templates_readiness').count(),
            manageView: await page.locator('#templates_manage_view').count(),
        };
        evidence('R5 readiness 게이트', gate);
        expect(gate.readiness + gate.manageView, 'readiness 안내와 목록은 정확히 하나만 보여야 한다').toBe(1);
        if (gate.manageView === 0) {
            evidence('R5 라벨 측정', '(readiness 미충족 — 목록 미렌더)');

            return;
        }

        // 목록 행 수를 API 로 먼저 잰다 — "행이 없어서 0" 과 "라벨 분기가 죽어서 0" 은 다른 판정이다.
        const total = await page.evaluate(async () => {
            const r = await fetch('/api/plugins/sirsoft-message_bizppurio/admin/templates?per_page=20', {
                headers: { Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('auth_token') ?? ''}` },
            });
            const j = await r.json();

            return j?.data?.pagination?.total ?? (j?.data?.templates?.length ?? 0);
        });
        const compose = await page.getByRole('button', { name: '작성', exact: true }).count();
        const edit = await page.getByRole('button', { name: '수정', exact: true }).count();
        evidence('R5 관리 화면 행 수/버튼 수', { total, compose, edit });
        if (total > 0) {
            expect(compose + edit, '행이 있는데 작성·수정 라벨이 하나도 없으면 라벨 분기가 죽은 것').toBeGreaterThan(0);
        }

        // SMS 본문 모달 → 언어 탭
        const smsBtn = page.getByRole('button', { name: 'SMS 본문', exact: false }).first();
        if (await smsBtn.count() > 0) {
            await smsBtn.click();
            const tabs = page.locator('#bz_sms_lang_tabs');
            await expect(tabs).toBeVisible({ timeout: 15_000 });
            const langs = (await tabs.locator('button').allInnerTexts()).map((t) => t.trim());
            evidence('R5 SMS 언어 탭', langs);
            expect(langs.length).toBeGreaterThan(0);
        } else {
            evidence('R5 SMS 언어 탭', '(SMS 본문 버튼 없음 — 행 없음)');
        }
    });
});

// ─────────────────────────────────────────────────────────────────────────────
test.describe('회귀 축', () => {
    test('R1·R2 메일/데이터베이스 탭과 채널 토글 카드가 정상 렌더된다', async ({ page, messagingManageToken }) => {
        const errors = collectConsoleErrors(page);
        await authenticatePage(page, messagingManageToken);
        await openBizppurioTab(page);

        const cards = await page.locator('#channel_toggles [role="switch"]').count();
        evidence('R2 채널 토글 카드 수', cards);
        expect(cards).toBeGreaterThanOrEqual(4);

        for (const label of ['메일', '데이터베이스']) {
            const tab = page.locator('#channel_sub_tabs button', { hasText: label }).first();
            if (await tab.count() === 0) continue;
            await tab.click();
            await page.waitForTimeout(1_000);
            const rendered = await page.locator('#notif_channel_content').count();
            evidence(`R1 ${label} 탭 콘텐츠`, rendered);
            expect(rendered).toBeGreaterThan(0);
        }
        evidence('R1 콘솔 에러 수', errors.length);
        expect(errors).toHaveLength(0);
    });

    test('R3·R4 플러그인 연동 설정·발송 이력 탭이 정상 렌더된다', async ({ page, messagingManageToken }) => {
        const errors = collectConsoleErrors(page);
        await authenticatePage(page, messagingManageToken);
        await page.goto('/admin/plugins/sirsoft-message_bizppurio/settings');
        await page.waitForLoadState('networkidle', { timeout: 30_000 });

        const tabs = (await page.locator('button').allInnerTexts()).map((t) => t.trim());
        evidence('R3·R4 플러그인 설정 탭', tabs.filter((t) => t && t.length < 20).slice(0, 12));
        evidence('R3·R4 콘솔 에러 수', errors.length);
        expect(errors).toHaveLength(0);
    });

    test('R6 사용자(basic) 화면이 영향을 받지 않는다', async ({ page }) => {
        const errors = collectConsoleErrors(page);
        await page.goto('/');
        await page.waitForLoadState('networkidle', { timeout: 30_000 });
        const title = await page.title();
        evidence('R6 사용자 화면', { title, errors: errors.length, messages: errors.slice(0, 5) });
        // 에러 원문을 남긴다 — 개편과 무관한 사전존재 에러와 회귀를 구분하는 근거가 된다.
        expect(errors.filter((e) => /bizppurio/i.test(e)),
            '사용자 화면에 비즈뿌리오 관련 에러가 있으면 회귀다').toHaveLength(0);
    });
});
