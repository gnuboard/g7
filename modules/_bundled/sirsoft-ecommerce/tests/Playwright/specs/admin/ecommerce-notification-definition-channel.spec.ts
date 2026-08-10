/**
 * 커머스 알림 정의 탭 채널 계약 — 목록 요청이 화면 채널을 나르는지 (#518 / 공개 #76).
 *
 * 결함(2026-08-05 브라우저 실측): 목록 프루닝으로 서버가 `template_channel` 을 받은 요청에만
 *   `templates` 를 싣게 됐는데, 코어 설정 화면만 그 파라미터를 보내도록 고쳐지고 커머스 탭
 *   데이터소스는 빠졌다. 10건 전 행이 「이 채널에 대한 템플릿이 없습니다」로 표시되고
 *   제목·수신자·활성 토글·편집 모달 초기값이 통째로 비었다.
 *
 * 이 spec 이 브라우저 수준을 담당하는 이유: 구조 테스트(Vitest)는 레이아웃 JSON 에
 *   `template_channel` 키가 있는지만 본다. 그 키가 **실제로 요청에 실려 나가는지**와
 *   그 응답으로 행이 채워지는지는 렌더까지 가야 드러난다 — 결함 당시 응답은 200 이었고
 *   행 수도 정상이었다.
 *
 * 시드 의존: 커머스 모듈 알림 정의(최소 1건). 커스터마이즈 축은 화면에서 만들고 되돌린다.
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';
import type { Page } from '@playwright/test';

const SETTINGS_URL = '/admin/ecommerce/settings?tab=notification_definitions';
const DEFINITIONS_API = /\/api\/admin\/notification-definitions(\?|$)/;

/** 템플릿이 비었을 때 행에 표시되는 문구 */
const EMPTY_TEMPLATE_TEXT = '이 채널에 대한 템플릿이 없습니다';

/**
 * 알림 정의 목록 응답을 기다리며 해당 채널의 설정 탭으로 이동합니다.
 *
 * @param page Playwright 페이지
 * @param channel 조회할 알림 채널
 * @returns 요청 URL 과 응답 본문
 */
async function loadChannel(page: Page, channel: string) {
    const pending = page.waitForResponse(
        (res) => DEFINITIONS_API.test(res.url()) && res.request().method() === 'GET',
        { timeout: 30_000 },
    );

    await page.goto(`${SETTINGS_URL}&channel=${channel}`);

    const response = await pending;

    return { requestUrl: response.url(), payload: await response.json() };
}

/**
 * 기본 상태(커스터마이즈 없음) 축의 공통 단언을 수행합니다.
 *
 * @param page Playwright 페이지
 * @param channel 검증 대상 채널
 */
async function assertChannelIsCarried(page: Page, channel: string) {
    const { requestUrl, payload } = await loadChannel(page, channel);

    expect(
        requestUrl,
        '화면이 보는 채널을 서버에 넘기지 않으면 응답에 templates 키 자체가 없다',
    ).toContain(`template_channel=${channel}`);

    const rows = payload?.data?.data ?? [];

    test.skip(rows.length === 0, '커머스 알림 정의 시드가 없어 판정할 수 없다');

    // 서버가 요청한 채널만 실었는지 — 다른 채널이 섞이면 프루닝이 무의미하다.
    for (const row of rows) {
        expect(row, 'templates 키가 응답에 있어야 한다').toHaveProperty('templates');

        for (const template of row.templates ?? []) {
            expect(template.channel, '요청하지 않은 채널이 실렸다').toBe(channel);
        }
    }

    // 렌더까지 확인 — 응답에 실렸어도 화면이 다른 채널을 찾으면 빈 문구가 남는다.
    await expect(
        page.getByText(EMPTY_TEMPLATE_TEXT).first(),
        '템플릿이 실렸는데도 빈 문구가 보이면 화면과 요청의 채널이 어긋난 것이다',
    ).toBeHidden();
}

/**
 * 지정 채널의 첫 정의를 편집해 커스터마이즈 상태로 만든 뒤,
 * 「기본값 복원」이 채널을 가리지 않고 노출되는지 확인하고 원상 복구합니다.
 *
 * @param page Playwright 페이지
 * @param channel 커스터마이즈를 수행할 채널
 */
async function assertResetButtonSpansChannels(page: Page, channel: string) {
    // 편집→저장→재조회→복원까지 도는 다단계 플로우라 기본 30초를 넘는다.
    test.setTimeout(120_000);

    const { payload } = await loadChannel(page, channel);

    test.skip((payload?.data?.data ?? []).length === 0, '커머스 알림 정의 시드가 없어 판정할 수 없다');

    const resetButton = page.getByRole('button', { name: '기본값 복원' });
    const initialCount = await resetButton.count();

    await page.getByRole('button', { name: '편집' }).first().click();

    const dialog = page.getByRole('dialog');
    const subject = dialog.getByRole('textbox').first();

    await subject.fill(`${await subject.inputValue()} [E2E]`);
    await dialog.getByRole('button', { name: '저장' }).click();
    await expect(dialog).toBeHidden({ timeout: 15_000 });

    // 반대 채널로 옮겨도 복원 버튼이 보여야 한다. 화면이 templates 배열을 순회해 판정하면
    // 반대 채널 templates 에는 커스터마이즈가 없어 버튼이 사라진다.
    const otherChannel = channel === 'mail' ? 'database' : 'mail';

    try {
        await loadChannel(page, otherChannel);

        await expect(
            resetButton.first(),
            '채널을 가리지 않는 판정은 서버 집계(has_customized_templates)가 유일한 출처다',
        ).toBeVisible({ timeout: 15_000 });

        expect(
            await resetButton.count(),
            '커스터마이즈한 정의에만 복원 버튼이 붙어야 한다',
        ).toBeGreaterThan(initialCount);
    } finally {
        // 시드 상태로 되돌린다 — 남겨두면 다음 실행의 기준선이 달라진다.
        await loadChannel(page, otherChannel);

        if ((await resetButton.count()) > 0) {
            await resetButton.first().click();
            await page.getByRole('button', { name: '복원', exact: true }).click();
            await expect(resetButton).toHaveCount(initialCount, { timeout: 15_000 });
        }
    }
}

test.describe('커머스 알림 정의 탭 — 채널 계약 (#518)', () => {
    /**
     * @scenario channel=mail,template_state=default
     * @effects ecommerce_notification_list_requests_template_channel, ecommerce_notification_channel_reads_single_source
     */
    test('mail 채널: 목록 요청이 그 채널을 나르고 행에 템플릿이 채워진다', async ({
        page,
        settingsToken,
    }) => {
        await authenticatePage(page, settingsToken);
        await assertChannelIsCarried(page, 'mail');
    });

    /**
     * @scenario channel=database,template_state=default
     * @effects ecommerce_notification_list_requests_template_channel, ecommerce_notification_channel_reads_single_source
     */
    test('database 채널: 목록 요청이 그 채널을 나르고 행에 템플릿이 채워진다', async ({
        page,
        settingsToken,
    }) => {
        await authenticatePage(page, settingsToken);
        await assertChannelIsCarried(page, 'database');
    });

    test.describe('커스터마이즈 상태', () => {
        // 두 축 모두 같은 시드 정의를 편집·복원하므로 병렬로 돌면 서로의 기준선을 무너뜨린다.
        // 이 블록 안에서만 직렬 — 부모 describe 에 걸면 기본 상태 2건까지 한 건 실패로 중단된다.
        test.describe.configure({ mode: 'serial' });

        /**
         * @scenario channel=mail,template_state=customized
         * @effects ecommerce_notification_reset_button_uses_server_aggregate
         */
        test('mail 채널에서 커스터마이즈하면 database 화면에서도 기본값 복원이 보인다', async ({
            page,
            settingsToken,
        }) => {
            await authenticatePage(page, settingsToken);
            await assertResetButtonSpansChannels(page, 'mail');
        });

        /**
         * @scenario channel=database,template_state=customized
         * @effects ecommerce_notification_reset_button_uses_server_aggregate
         */
        test('database 채널에서 커스터마이즈하면 mail 화면에서도 기본값 복원이 보인다', async ({
            page,
            settingsToken,
        }) => {
            await authenticatePage(page, settingsToken);
            await assertResetButtonSpansChannels(page, 'database');
        });
    });
});
