/**
 * 구동 자산 로드 실패 안내 배너
 *
 * 실패를 표면화하지 않으면 사용자에게는 "빈 자리" 로만 나타나고, 자체 서버 로그에도
 * 흔적이 남지 않아 운영자가 원인을 특정할 수 없다.
 *
 * @scenario asset_class=vendored, outcome=failed
 * @effects failed_asset_shows_retry_notice, notice_renders_without_host_component_on_standalone_layout
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
    notifyAssetFailure,
    clearAssetFailure,
    clearAllAssetFailures,
    getAssetFailures,
    retryAssetFailures,
    drainExternalAssetFailures,
} from '../assets/AssetFailureNotice';

/** 배너 컨테이너를 돌려줍니다. */
const container = () => document.getElementById('g7-asset-failure-notice');

describe('AssetFailureNotice', () => {
    beforeEach(() => {
        clearAllAssetFailures();
        document.body.innerHTML = '';
        document.documentElement.classList.remove('dark');
    });

    afterEach(() => {
        clearAllAssetFailures();
        vi.restoreAllMocks();
    });

    // @scenario asset_class=vendored, outcome=failed
    // @effects notice_renders_without_host_component_on_standalone_layout, failed_asset_shows_retry_notice
    it('호스트 컴포넌트 없이 body 에 직접 배너를 세운다', () => {
        notifyAssetFailure({ id: 'editor', label: '편집기' });

        const el = container();

        expect(el).not.toBeNull();
        expect(el?.parentElement).toBe(document.body);
        expect(el?.getAttribute('role')).toBe('alert');
        expect(el?.getAttribute('aria-live')).toBe('polite');
    });

    // @scenario asset_class=vendored, outcome=failed
    // @effects layout_script_failure_label_is_human_readable
    it('번역이 없을 때 대체 문구도 괄호 조사 없이 코어 ko 문구와 같다 (7.1.0 O-R2-9)', () => {
        notifyAssetFailure({ id: 'postcode', label: '주소 검색' });

        const text = container()?.textContent ?? '';
        expect(text).toContain('주소 검색 불러오기에 실패했습니다.');
        expect(text).not.toMatch(/\((을|를|이|가|은|는|과|와)\)/);
    });

    // @scenario asset_class=vendored, outcome=failed
    // @effects failed_asset_shows_retry_notice
    it('caller 가 준 문구를 그대로 보여준다', () => {
        notifyAssetFailure({
            id: 'editor',
            label: '편집기',
            message: '편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다.',
        });

        expect(container()?.textContent).toContain('임시 입력창으로 전환했습니다');
    });

    // @scenario asset_class=vendored, outcome=failed
    // @effects failed_asset_shows_retry_notice
    it('같은 id 는 누적하지 않고 갱신한다', () => {
        notifyAssetFailure({ id: 'editor', label: '편집기' });
        notifyAssetFailure({ id: 'editor', label: '편집기(갱신)' });

        expect(getAssetFailures()).toHaveLength(1);
        expect(getAssetFailures()[0].label).toBe('편집기(갱신)');
    });

    // @scenario asset_class=vendored, outcome=failed
    // @effects failed_asset_shows_retry_notice
    it('여러 자산이 실패하면 건수로 합산하고 항목명을 나열한다', () => {
        notifyAssetFailure({ id: 'a', label: '편집기' });
        notifyAssetFailure({ id: 'b', label: '아이콘' });

        const text = container()?.textContent ?? '';

        expect(getAssetFailures()).toHaveLength(2);
        expect(text).toContain('2');
        expect(text).toContain('편집기');
        expect(text).toContain('아이콘');
    });

    // @scenario asset_class=vendored, outcome=failed
    // @effects failed_asset_shows_retry_notice
    it('retry 가 없으면 다시 시도 버튼을 렌더하지 않는다', () => {
        notifyAssetFailure({ id: 'a', label: '아이콘' });

        expect(container()?.querySelector('[data-action="retry"]')).toBeNull();
        expect(container()?.querySelector('[data-action="close"]')).not.toBeNull();
    });

    // @scenario asset_class=vendored, outcome=failed
    // @effects failed_asset_shows_retry_notice
    it('닫기 버튼은 배너를 제거한다 (자동 소멸하지 않고 사용자가 닫을 때까지 유지)', () => {
        notifyAssetFailure({ id: 'a', label: '아이콘' });

        (container()?.querySelector('[data-action="close"]') as HTMLButtonElement).click();

        expect(container()).toBeNull();
        expect(getAssetFailures()).toHaveLength(0);
    });

    // @scenario asset_class=vendored, outcome=failed
    // @effects failed_asset_shows_retry_notice
    it('재시도가 성공하면 그 항목만 해제된다', async () => {
        const retry = vi.fn().mockResolvedValue(undefined);
        notifyAssetFailure({ id: 'a', label: '편집기', retry });
        notifyAssetFailure({ id: 'b', label: '아이콘' });

        await retryAssetFailures();

        expect(retry).toHaveBeenCalledTimes(1);
        expect(getAssetFailures().map(f => f.id)).toEqual(['b']);
    });

    // @scenario asset_class=vendored, outcome=failed
    // @effects failed_asset_shows_retry_notice
    it('재시도가 실패하면 배너를 유지하고 재시도 실패를 알린다', async () => {
        const retry = vi.fn().mockRejectedValue(new Error('여전히 실패'));
        notifyAssetFailure({ id: 'a', label: '편집기', retry });

        await retryAssetFailures();

        expect(getAssetFailures()).toHaveLength(1);
        expect(container()?.textContent ?? '').toContain('다시 시도');
    });

    // @scenario asset_class=vendored, outcome=failed
    // @effects failed_asset_shows_retry_notice
    it('clearFailure 는 지정한 항목만 해제한다', () => {
        notifyAssetFailure({ id: 'a', label: '편집기' });
        notifyAssetFailure({ id: 'b', label: '아이콘' });

        clearAssetFailure('a');

        expect(getAssetFailures().map(f => f.id)).toEqual(['b']);
        expect(container()).not.toBeNull();
    });

    // @scenario asset_class=vendored, outcome=failed
    // @effects failed_asset_shows_retry_notice
    it('모든 항목이 해제되면 컨테이너를 제거한다', () => {
        notifyAssetFailure({ id: 'a', label: '편집기' });
        clearAssetFailure('a');

        expect(container()).toBeNull();
    });

    // @scenario asset_class=vendored, outcome=failed
    // @effects failed_asset_shows_retry_notice
    it('다크 모드에서 배경색이 달라진다', () => {
        // 색은 개별 프로퍼티로 설정되므로 일괄 문자열 파싱 여부와 무관하게 관측된다.
        notifyAssetFailure({ id: 'a', label: '편집기' });
        const light = (container()?.firstElementChild as HTMLElement).style.background;

        clearAllAssetFailures();
        document.documentElement.classList.add('dark');
        notifyAssetFailure({ id: 'a', label: '편집기' });
        const dark = (container()?.firstElementChild as HTMLElement).style.background;

        expect(light).not.toBe('');
        expect(dark).not.toBe('');
        expect(light).not.toBe(dark);
    });

    // @scenario asset_class=vendored, outcome=failed
    // @effects failed_asset_shows_retry_notice
    it('id 가 없는 호출은 무시한다', () => {
        notifyAssetFailure({ id: '', label: '편집기' });

        expect(getAssetFailures()).toHaveLength(0);
        expect(container()).toBeNull();
    });
});

describe('서버가 심은 템플릿 externals 의 실패', () => {
    beforeEach(() => {
        clearAllAssetFailures();
        document.body.innerHTML = '';
        delete (window as any).__g7ExternalAssetFailures;
        delete (window as any).__g7ExternalAssetSink;
    });

    afterEach(() => {
        clearAllAssetFailures();
        delete (window as any).__g7ExternalAssetFailures;
        delete (window as any).__g7ExternalAssetSink;
    });

    // @scenario asset_class=vendored, outcome=failed
    // @effects failed_asset_shows_retry_notice
    it('엔진보다 먼저 쌓인 대기열을 배너로 흘려보낸다', () => {
        // 이 태그들은 엔진 번들보다 먼저 평가되므로, 실패가 대기열에 쌓인 채 도착한다.
        // 여기서 비우지 않으면 아이콘 폰트 실패가 화면에 영영 드러나지 않는다.
        (window as any).__g7ExternalAssetFailures = [
            { id: 'template-external:https://x/fa.css', label: 'fontawesome', retry: () => Promise.resolve() },
        ];

        drainExternalAssetFailures();

        expect(getAssetFailures().map(f => f.label)).toEqual(['fontawesome']);
        expect(container()).not.toBeNull();
        expect(container()!.textContent).toContain('fontawesome');
    });

    // @scenario asset_class=vendored, outcome=failed
    // @effects failed_asset_shows_retry_notice
    it('엔진이 뜬 뒤에 생긴 실패도 곧바로 배너로 간다', () => {
        drainExternalAssetFailures();

        expect(typeof (window as any).__g7ExternalAssetSink).toBe('function');

        (window as any).__g7ExternalAssetSink({
            id: 'template-external:https://x/pretendard.css',
            label: 'pretendard',
        });

        expect(getAssetFailures().map(f => f.label)).toEqual(['pretendard']);
    });

    // @scenario asset_class=vendored, outcome=failed
    // @effects failed_asset_shows_retry_notice
    it('id 가 없는 항목은 배너에 싣지 않는다 (식별 불가 항목이 누적되지 않도록)', () => {
        (window as any).__g7ExternalAssetFailures = [{ label: '이름만 있는 항목' }];

        drainExternalAssetFailures();

        expect(getAssetFailures()).toEqual([]);
    });

    // @scenario asset_class=vendored, outcome=failed
    // @effects failed_asset_shows_retry_notice
    it('대기열이 비어 있어도 sink 는 걸린다 (정상 부팅에서 배너를 만들지 않는다)', () => {
        drainExternalAssetFailures();

        expect(getAssetFailures()).toEqual([]);
        expect(container()).toBeNull();
        expect(typeof (window as any).__g7ExternalAssetSink).toBe('function');
    });
});
