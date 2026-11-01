// e2e:allow 설치 마법사는 미설치 사이트에서만 열려 설치된 사이트를 대상으로 하는 Playwright 스위트 밖이다 — 실패 화면의 되돌아가기 확인 창은 빈 사본 브라우저 실측으로 확인한다
/**
 * 설치 마법사 Step 5 실패 화면 — 「Step 4 로 돌아가기」·「설정으로 돌아가기」 확인 창 문구를 잠근다.
 *
 * 회귀: 의존성 사전 검증 실패(데이터베이스를 건드리기 전)인데도 「Step 4 로 돌아가기」가 일반 「설정으로 돌아가기」와
 * 같은 확인 창(「설정 페이지로 이동하시겠습니까? … 데이터베이스에 생성된 테이블은 자동으로 삭제되지 않습니다 …」)을 띄웠다.
 *
 * 고정하는 계약:
 *  1. 사전 검증 실패 화면의 「Step 4 로 돌아가기」는 확장 선택 단계로 돌아간다는 확인 문구를 쓰고 DB 정리 안내가 없다.
 *  2. DB 작업 전 단계에서 멈춘 실패의 「설정으로 돌아가기」도 DB 정리 안내 없는 문구를 쓴다.
 *  3. DB 작업 뒤 실패(마이그레이션·확장 설치 등)의 「설정으로 돌아가기」는 종전대로 DB 정리 안내를 보인다.
 *  4. 확인하면 상태 초기화(reset)를 부르고, 취소하면 부르지 않는다.
 *
 * 화면은 설치 화면 뷰(5-installation.php)의 실패 버튼 마크업을 그대로 잘라 쓰고, installer.js 를 전역 스크립트로 평가한 뒤
 * 새로고침 복원 경로(상태 failed → 실패 화면)로 실패 화면을 띄운다.
 */
import { afterEach, describe, expect, it, vi } from 'vitest';
import fs from 'fs';
import path from 'path';

const root = path.resolve(__dirname, '../../../..');
const INSTALLER_SOURCE = fs.readFileSync(path.join(root, 'public/install/assets/js/installer.js'), 'utf8');
const VIEW_SOURCE = fs.readFileSync(path.join(root, 'public/install/views/5-installation.php'), 'utf8');

/** 설치기 ko 언어 파일에서 키 문구를 읽는다 (PHP 배열 리터럴의 한 줄 항목). */
function koLang(key: string): string {
    const src = fs.readFileSync(path.join(root, 'public/install/lang/ko.php'), 'utf8');
    const m = new RegExp(`'${key}' => (["'])((?:\\\\.|(?!\\1).)*)\\1,`).exec(src);
    if (!m) throw new Error(`ko.php 에 ${key} 가 없음`);
    return m[1] === '"' ? m[2].replace(/\\n/g, '\n').replace(/\\"/g, '"') : m[2].replace(/\\'/g, "'");
}

const LANG_KEYS = [
    'confirm_go_to_settings_simple',
    'confirm_go_to_settings_before_db',
    'confirm_back_to_extension_selection',
    'back_to_extension_selection',
    'back_to_settings',
    'retry_installation',
    'installation_failed',
    'dependency_precheck_failed',
];

/** 뷰의 실패 버튼 마크업(`$failureButtons`)을 잘라 lang() 호출을 문구로 바꾼다. */
function failureButtonsMarkup(lang: Record<string, string>): string {
    const m = /\$failureButtons = '([\s\S]*?)';\n/.exec(VIEW_SOURCE);
    if (!m) throw new Error('5-installation.php 에 $failureButtons 가 없음');
    return m[1].replace(/'\.htmlspecialchars\(lang\('([a-z_]+)'\)\)\.'/g, (_all, key) => lang[key] ?? key);
}

const DB_NOTICE = '데이터베이스에 생성된 테이블은 자동으로 삭제되지 않습니다';

/**
 * 실패 상태로 Step 5 를 띄운다.
 *
 * @param state state-management.php?action=get 응답
 */
async function bootFailureScreen(state: Record<string, unknown>) {
    const lang: Record<string, string> = {};
    for (const key of LANG_KEYS) {
        try {
            lang[key] = koLang(key);
        } catch {
            // 키가 아직 없는 경우(수정 전) — lang() 이 키 이름을 그대로 돌려준다
        }
    }

    document.body.innerHTML = `
        <div id="installation-start-section"></div>
        <div id="installation-card-body"></div>
        <div id="task-list"></div>
        <div id="log-container"></div>
        <div id="failure-section" class="result-section hidden">
            <h3 class="result-title">${lang.installation_failed ?? ''}</h3>
            <div class="result-message" id="failure-message"></div>
            <div class="result-button-group">${failureButtonsMarkup(lang)}</div>
        </div>`;

    const w = window as any;
    w.INSTALLER_BASE_URL = '/install';
    w.CURRENT_STEP = '5';
    w.INSTALLER_LANG = lang;
    w.INSTALLER_TASKS = [];
    w.INSTALLER_TASK_GROUPS = [];
    w.INSTALLER_CSRF = 'tok';

    const calls: string[] = [];
    w.fetch = vi.fn(async (url: string) => {
        calls.push(String(url));
        if (String(url).includes('action=reset')) {
            return { ok: true, json: async () => ({ success: true, target_step: 4 }) };
        }

        return { ok: true, json: async () => state, text: async () => JSON.stringify(state) };
    });

    // installer.js 는 DOMContentLoaded 에서 초기화한다 — 이 평가가 등록한 처리기만 골라 직접 부른다
    const handlers: Array<() => void> = [];
    const original = document.addEventListener.bind(document);
    const spy = vi.spyOn(document, 'addEventListener').mockImplementation(((type: string, cb: any, opts?: any) => {
        if (type === 'DOMContentLoaded') {
            handlers.push(cb);

            return;
        }
        original(type, cb, opts);
    }) as typeof document.addEventListener);
    new Function(INSTALLER_SOURCE)();
    spy.mockRestore();
    handlers.forEach((h) => h());

    await vi.waitFor(() => {
        expect(document.getElementById('failure-section')!.classList.contains('hidden')).toBe(false);
    });

    return { calls };
}

/**
 * 실패 화면 버튼을 누르고 확인 창 문구를 돌려준다.
 *
 * 버튼은 인라인 onclick 이 아니라 `data-action` 이름으로 위임 리스너(installer-boot.js)에 묶인다(#158 strict CSP) —
 * 뷰 마크업의 data-action 이 가리키는 함수 이름을 읽어 같은 전역 함수를 직접 부른다(버튼 ↔ 함수 연결도 함께 확인된다).
 */
async function clickAndCaptureConfirm(button: HTMLElement, accept: boolean) {
    const confirmSpy = vi.fn(() => accept);
    (window as any).confirm = confirmSpy;
    expect(button.getAttribute('onclick'), '인라인 onclick 이 남아 있다').toBeNull();
    const handler = /^(\w+)$/.exec(button.getAttribute('data-action') ?? '');
    expect(handler, `버튼 data-action 이 함수 이름이 아니다: ${button.getAttribute('data-action')}`).not.toBeNull();
    const fn = (window as any)[handler![1]];
    expect(typeof fn, `${handler![1]} 가 전역에 노출되지 않았다`).toBe('function');
    await fn();
    await new Promise((r) => setTimeout(r, 0));

    return confirmSpy.mock.calls.map((c) => String((c as unknown[])[0]));
}

const precheckFailedState = {
    status: 'failed',
    current_step: 5,
    failed_task: 'dependency_precheck',
    error_message_key: 'dependency_precheck_failed',
    error_detail: '확장 의존 관계가 순환합니다: modules:sirsoft-page → plugins:sirsoft-ckeditor5 → modules:sirsoft-page',
    completed_tasks: ['composer_check', 'composer_install', 'env_update', 'key_generate'],
    logs: [],
    log_total: 0,
};

describe('설치 실패 화면 — 되돌아가기 확인 창', () => {
    afterEach(() => {
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    /**
     * @effects installer_step5_back_to_extensions_confirm_has_no_db_notice, installer_step5_back_confirm_accept_resets_state
     */
    it('사전 검증 실패의 「Step 4 로 돌아가기」는 확장 선택 단계 확인 문구를 쓰고 DB 정리 안내가 없다', async () => {
        const { calls } = await bootFailureScreen(precheckFailedState);
        const back = document.getElementById('back-to-extensions-btn') as HTMLElement;
        expect(back.classList.contains('hidden')).toBe(false);

        const messages = await clickAndCaptureConfirm(back, true);

        expect(messages).toHaveLength(1);
        expect(messages[0]).toBe(koLang('confirm_back_to_extension_selection'));
        expect(messages[0]).not.toContain(DB_NOTICE);
        expect(messages[0]).not.toContain('phpMyAdmin');
        await vi.waitFor(() => expect(calls.some((u) => u.includes('action=reset'))).toBe(true));
    });

    /**
     * @effects installer_step5_back_confirm_cancel_keeps_state
     */
    it('확인 창에서 취소하면 상태를 초기화하지 않는다', async () => {
        const { calls } = await bootFailureScreen(precheckFailedState);
        const back = document.getElementById('back-to-extensions-btn') as HTMLElement;

        await clickAndCaptureConfirm(back, false);

        expect(calls.some((u) => u.includes('action=reset'))).toBe(false);
    });

    /**
     * @effects installer_step5_settings_confirm_has_no_db_notice_before_database
     */
    it.each([
        ['사전 검증 실패', precheckFailedState],
        ['환경 설정(composer) 실패', { ...precheckFailedState, failed_task: 'composer_install', error_message_key: 'error_composer_install_failed', completed_tasks: ['composer_check'] }],
    ])('DB 작업 전 실패(%s)의 「설정으로 돌아가기」도 DB 정리 안내가 없다', async (_label, state) => {
        await bootFailureScreen(state);
        const settings = Array.from(document.querySelectorAll('#failure-section button'))
            .find((b) => b.textContent === koLang('back_to_settings')) as HTMLElement;

        const messages = await clickAndCaptureConfirm(settings, false);

        expect(messages).toHaveLength(1);
        expect(messages[0]).toBe(koLang('confirm_go_to_settings_before_db'));
        expect(messages[0]).not.toContain(DB_NOTICE);
    });

    /**
     * @effects installer_step5_settings_confirm_keeps_db_notice_after_database
     */
    it.each([
        ['DB 마이그레이션 실패', 'db_migrate', 'error_migration_failed'],
        ['모듈 설치 실패', 'module_install:sirsoft-page', 'error_module_install_failed'],
    ])('DB 작업 뒤 실패(%s)의 「설정으로 돌아가기」는 종전대로 DB 정리 안내를 보인다', async (_label, failedTask, key) => {
        await bootFailureScreen({ ...precheckFailedState, failed_task: failedTask, error_message_key: key });
        expect((document.getElementById('back-to-extensions-btn') as HTMLElement).classList.contains('hidden')).toBe(true);
        const settings = Array.from(document.querySelectorAll('#failure-section button'))
            .find((b) => b.textContent === koLang('back_to_settings')) as HTMLElement;

        const messages = await clickAndCaptureConfirm(settings, false);

        expect(messages).toHaveLength(1);
        expect(messages[0]).toBe(koLang('confirm_go_to_settings_simple'));
        expect(messages[0]).toContain(DB_NOTICE);
    });
});
