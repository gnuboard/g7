/**
 * CKEditor5 본체 전역 확보 — 본체(UMD)는 한 화면에서 한 번만 실행되어야 한다
 *
 * UMD 는 실행 첫머리에 `window.CKEDITOR = {}` 를 대입한 뒤 내용을 채우고, 이미 실행된 적이 있으면
 * (`CKEDITOR_VERSION`) 중복 모듈 오류로 멈춘다. 그래서 두 번째 실행은 첫 번째가 채운 전역을 빈 객체로
 * 덮고 끝난다 — 그 뒤 `CKEDITOR.ClassicEditor.create` 가 undefined 로 실패해 편집기가 임시 입력창으로 떨어진다.
 *
 * 레이아웃 편집기 미리보기에서 실측: 편집기 컴포넌트가 스크립트 목록이 정해지기 전에 한 번
 * 마운트되어 이 모듈이 본체를 싣는 사이, 미리보기도 레이아웃 스크립트로 같은 본체를 실었다. 두 번의
 * 초기화 시도가 겹쳐도 같은 일이 생겼다.
 *
 * @scenario asset_class=vendored, outcome=loaded
 * @effects vendored_editor_umd_executes_once_across_loaders
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ensureCkeditorGlobal } from '../../handlers/ckeditorGlobal';
import { initEditorHandler } from '../../handlers/initEditor';

const UMD_EXT = '/api/plugins/assets/sirsoft-ckeditor5/dist/vendor/ckeditor5/43.3.1/ckeditor5.umd.js';
const UMD_QUERY = '/api/plugins/assets/sirsoft-ckeditor5?file=dist%2Fvendor%2Fckeditor5%2F43.3.1%2Fckeditor5.umd.js';

/** UMD 실행을 흉내 낸다 — 첫 실행만 전역을 채우고, 두 번째부터는 빈 객체로 덮고 멈춘다. */
function executeUmd(): void {
    const w = window as any;
    const executedBefore = typeof w.CKEDITOR_VERSION === 'string';
    w.CKEDITOR = {};
    if (executedBefore) return;
    w.CKEDITOR_VERSION = '43.3.1';
    w.CKEDITOR.ClassicEditor = class ClassicEditor {};
}

describe('[case:wysiwyg-6] ensureCkeditorGlobal — 본체 한 번만 실행', () => {
    let loadScript: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        loadScript = vi.fn(async () => {
            await Promise.resolve();
            executeUmd();
        });
        (window as any).G7Core = {
            asset: {
                plugin: (_id: string, path: string) => `/api/plugins/assets/sirsoft-ckeditor5?file=${encodeURIComponent(path)}`,
                loadScript,
            },
        };
    });

    afterEach(() => {
        delete (window as any).G7Core;
        delete (window as any).CKEDITOR;
        delete (window as any).CKEDITOR_VERSION;
        document.querySelectorAll('script').forEach((el) => el.remove());
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('전역이 이미 쓸 수 있으면 싣지 않는다', async () => {
        executeUmd();

        await expect(ensureCkeditorGlobal()).resolves.toBe(true);
        expect(loadScript).not.toHaveBeenCalled();
    });

    it('동시에 들어온 두 초기화 요청은 본체를 한 번만 싣는다', async () => {
        const [a, b] = await Promise.all([ensureCkeditorGlobal(), ensureCkeditorGlobal()]);

        expect(a).toBe(true);
        expect(b).toBe(true);
        expect(loadScript).toHaveBeenCalledTimes(1);
        expect(typeof (window as any).CKEDITOR.ClassicEditor).toBe('function');
    });

    it('다른 로더(레이아웃 스크립트·편집기 미리보기)가 실는 중인 본체가 있으면 새로 싣지 않고 그 실행을 기다린다', async () => {
        const pending = document.createElement('script');
        pending.id = 'ckeditor5_script';
        pending.setAttribute('src', UMD_QUERY);
        document.head.appendChild(pending);

        const result = ensureCkeditorGlobal();
        await Promise.resolve();
        executeUmd();
        pending.dispatchEvent(new Event('load'));

        await expect(result).resolves.toBe(true);
        expect(loadScript).not.toHaveBeenCalled();
    });

    it('확장자 형태로 실는 중인 본체도 같은 본체로 알아본다', async () => {
        const pending = document.createElement('script');
        pending.setAttribute('src', UMD_EXT);
        document.head.appendChild(pending);

        const result = ensureCkeditorGlobal();
        await Promise.resolve();
        executeUmd();
        pending.dispatchEvent(new Event('load'));

        await expect(result).resolves.toBe(true);
        expect(loadScript).not.toHaveBeenCalled();
    });

    it('기다리던 본체가 실패하고 재시도 태그도 오지 않으면 그때 직접 한 번 싣는다', async () => {
        vi.useFakeTimers();
        const pending = document.createElement('script');
        pending.setAttribute('src', UMD_QUERY);
        document.head.appendChild(pending);

        const result = ensureCkeditorGlobal();
        await Promise.resolve();
        pending.dispatchEvent(new Event('error'));
        pending.remove();
        await vi.advanceTimersByTimeAsync(3000);

        await expect(result).resolves.toBe(true);
        expect(loadScript).toHaveBeenCalledTimes(1);
    });

    it('코어 로더가 실패한 태그를 지우고 다시 싣는 동안에는 직접 싣지 않고 재시도 태그를 기다린다', async () => {
        vi.useFakeTimers();
        const first = document.createElement('script');
        first.id = 'ckeditor5_script';
        first.setAttribute('src', UMD_QUERY);
        document.head.appendChild(first);

        const result = ensureCkeditorGlobal();
        await Promise.resolve();
        // 코어 loadScriptWithRetry: 실패한 태그를 지우고 백오프(최대 2초) 뒤 새 태그로 다시 싣는다
        first.dispatchEvent(new Event('error'));
        first.remove();
        await vi.advanceTimersByTimeAsync(600);
        const retry = document.createElement('script');
        retry.id = 'ckeditor5_script';
        retry.setAttribute('src', UMD_QUERY);
        document.head.appendChild(retry);
        await vi.advanceTimersByTimeAsync(100);
        executeUmd();
        retry.dispatchEvent(new Event('load'));
        await vi.advanceTimersByTimeAsync(100);

        await expect(result).resolves.toBe(true);
        expect(loadScript).not.toHaveBeenCalled();
    });

    it('기다리는 시간이 지나도 다른 로더가 아직 싣는 중이면 다시 싣지 않는다(두 번 실행 방지)', async () => {
        vi.useFakeTimers();
        const pending = document.createElement('script');
        pending.setAttribute('src', UMD_QUERY);
        document.head.appendChild(pending);

        const result = ensureCkeditorGlobal();
        await vi.advanceTimersByTimeAsync(16000);

        await expect(result).resolves.toBe(false);
        expect(loadScript).not.toHaveBeenCalled();
    });

    it('이미 실행됐는데 전역이 비었으면(중복 실행으로 덮임) 다시 싣지 않고 실패로 알린다', async () => {
        executeUmd();
        executeUmd();

        await expect(ensureCkeditorGlobal()).resolves.toBe(false);
        expect(loadScript).not.toHaveBeenCalled();
    });

    it('번역 파일 태그는 본체로 보지 않는다', async () => {
        const translation = document.createElement('script');
        translation.setAttribute('src', '/api/plugins/assets/sirsoft-ckeditor5?file=dist%2Fvendor%2Fckeditor5%2F43.3.1%2Ftranslations%2Fko.umd.js');
        document.head.appendChild(translation);

        await expect(ensureCkeditorGlobal()).resolves.toBe(true);
        expect(loadScript).toHaveBeenCalledTimes(1);
    });
});

describe('[case:wysiwyg-6] initEditorHandler — 본체를 기다리는 사이 해제된 컨테이너', () => {
    let create: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        create = vi.fn(async () => ({ destroy: async () => {} }));
        (window as any).G7Core = {
            locale: { current: () => 'en' },
            state: { get: () => ({}), getLocal: () => ({ form: {} }), setLocal: vi.fn() },
            asset: {
                plugin: (_id: string, path: string) => `/api/plugins/assets/sirsoft-ckeditor5?file=${encodeURIComponent(path)}`,
                loadStylesheet: vi.fn(async () => {}),
                // 본체를 싣는 사이 컴포넌트가 해제된다(레이아웃 편집기 미리보기가 스크립트 목록을 다시 정하며 다시 마운트)
                loadScript: vi.fn(async () => {
                    document.getElementById('ckeditor5-content')?.remove();
                    (window as any).CKEDITOR_VERSION = '43.3.1';
                    (window as any).CKEDITOR = { ClassicEditor: Object.assign(class {}, { create }) };
                }),
            },
        };
    });

    afterEach(() => {
        delete (window as any).G7Core;
        delete (window as any).CKEDITOR;
        delete (window as any).CKEDITOR_VERSION;
        document.querySelectorAll('script, link, style').forEach((el) => el.remove());
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    it('떨어져 나간 옛 컨테이너에는 편집기도 임시 입력창도 세우지 않는다 — 다시 마운트된 쪽이 초기화한다', async () => {
        const container = document.createElement('div');
        container.id = 'ckeditor5-content';
        document.body.appendChild(container);

        await initEditorHandler({ params: { name: 'content' } }, {});

        expect(create).not.toHaveBeenCalled();
        expect(container.querySelector('textarea')).toBeNull();
    });
});
