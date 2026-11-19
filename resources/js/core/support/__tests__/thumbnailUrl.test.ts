/**
 * 이미지 썸네일 주소 유틸 (support/thumbnailUrl) 단위 테스트.
 *
 * 서버 `App\Thumbnails\ThumbnailUrl` 과 같은 판정이어야 한다 — 기대값은 서버 시험
 * (`tests/Unit/Thumbnails/ThumbnailUrlSrcsetTest.php`)과 같은 규격표로 맞춘다.
 */

import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
    contentSrcset,
    contentSrcsetWithin,
    getContentPreset,
    getThumbnailPresets,
    isThumbnailableUrl,
    stripThumbnailSize,
    thumbnailSrcset,
    withThumbnailSize,
} from '../thumbnailUrl';

/** 고정 슬롯 기본값(D33) + og */
const PRESETS = [
    { name: 'xs', width: 64, height: 64, fit: 'cover', numeric: false, format: 'original' },
    { name: 'sm', width: 160, height: 160, fit: 'cover', numeric: false, format: 'original' },
    { name: 'md', width: 640, height: null, fit: 'width', numeric: true, format: 'original' },
    { name: 'lg', width: 1280, height: null, fit: 'width', numeric: true, format: 'original' },
    { name: 'xl', width: 1920, height: null, fit: 'width', numeric: true, format: 'original' },
    { name: 'og', width: 1200, height: 630, fit: 'cover', numeric: false, format: 'original' },
];

const PATTERNS = [
    '/api/attachment/*',
    '/api/templates/*/layout-attachments/*/file',
    '/api/modules/sirsoft-board/boards/*/attachment/*/preview',
];

function setConfig(upload: Record<string, unknown> | undefined, basePath?: string): void {
    (window as any).G7Config = { basePath, settings: upload === undefined ? {} : { upload } };
}

describe('thumbnailUrl', () => {
    beforeEach(() => {
        setConfig({ thumbnail_presets_public: PRESETS, thumbnail_url_patterns: PATTERNS, thumbnail_content_preset: 'lg' });
    });

    afterEach(() => {
        delete (window as any).G7Config;
        delete (window as any).__templateApp;
    });

    describe('isThumbnailableUrl', () => {
        // @scenario surface=url_util
        // @effects url_util_adds_size_only_to_registered_same_origin_paths
        it('등록된 서빙 경로의 루트 상대·같은 출처 전체 주소만 대상이다', () => {
            expect(isThumbnailableUrl('/api/attachment/abc')).toBe(true);
            expect(isThumbnailableUrl(`${window.location.origin}/api/attachment/abc`)).toBe(true);
            expect(isThumbnailableUrl('/api/templates/sirsoft-basic/layout-attachments/9/file')).toBe(true);
            expect(isThumbnailableUrl('/api/modules/sirsoft-board/boards/free/attachment/h1/preview?expires=1&signature=x')).toBe(true);
        });

        // @scenario surface=url_util
        // @effects url_util_wildcard_matches_one_segment_exactly
        it('와일드카드는 / 없는 한 조각이고 패턴 끝까지 정확히 맞아야 한다', () => {
            expect(isThumbnailableUrl('/api/attachment/a/b')).toBe(false);
            expect(isThumbnailableUrl('/api/templates/x/layout-attachments/9/file/extra')).toBe(false);
            expect(isThumbnailableUrl('/api/attachment/')).toBe(false);
        });

        // @scenario surface=url_util
        // @effects url_util_adds_size_only_to_registered_same_origin_paths
        it('외부·정적·data·blob·protocol-relative·상대 경로는 대상이 아니다', () => {
            for (const url of [
                'https://cdn.example.com/api/attachment/abc',
                '//cdn.example.com/api/attachment/abc',
                '/\\cdn.example.com/api/attachment/abc',
                '/storage/attachments/avatars/a.png',
                'data:image/png;base64,AAAA',
                `blob:${window.location.origin}/0d6c`,
                'api/attachment/abc',
                '',
            ]) {
                expect(isThumbnailableUrl(url)).toBe(false);
            }
            expect(isThumbnailableUrl(null)).toBe(false);
            expect(isThumbnailableUrl(123)).toBe(false);
        });

        // @scenario surface=url_util
        // @effects url_util_disabled_feature_targets_nothing
        it('기능이 꺼져 패턴이 비면 어떤 주소도 대상이 아니다', () => {
            setConfig({ thumbnail_presets_public: PRESETS, thumbnail_url_patterns: [] });
            expect(isThumbnailableUrl('/api/attachment/abc')).toBe(false);
            setConfig(undefined);
            expect(isThumbnailableUrl('/api/attachment/abc')).toBe(false);
        });

        // @scenario surface=url_util
        // @effects url_util_subfolder_prefix_stripped_before_match
        it('하위 폴더 설치는 접두를 벗겨 대조한다', () => {
            setConfig({ thumbnail_presets_public: PRESETS, thumbnail_url_patterns: PATTERNS }, '/g7');
            expect(isThumbnailableUrl('/g7/api/attachment/abc')).toBe(true);
            expect(withThumbnailSize('/g7/api/attachment/abc', 'sm')).toBe('/g7/api/attachment/abc?size=sm');
        });

        // @scenario surface=url_util
        // @effects url_util_drops_non_api_patterns
        it('/api/ 로 시작하지 않는 패턴은 버린다 (서버와 같은 거름)', () => {
            setConfig({ thumbnail_presets_public: PRESETS, thumbnail_url_patterns: ['/storage/*', '*'] });
            expect(isThumbnailableUrl('/storage/a.png')).toBe(false);
        });
    });

    describe('withThumbnailSize', () => {
        // @scenario surface=url_util
        // @effects url_util_adds_size_only_to_registered_same_origin_paths
        it('쿼리가 없으면 ?size= 를 붙인다', () => {
            expect(withThumbnailSize('/api/attachment/abc', 'sm')).toBe('/api/attachment/abc?size=sm');
        });

        // @scenario surface=url_util
        // @effects url_util_preserves_query_and_fragment
        it('서명 쿼리는 원문(순서·인코딩) 그대로 두고 끝에 덧붙인다', () => {
            const signed = '/api/modules/sirsoft-board/boards/free/attachment/h1/preview?expires=1700000000&signature=a%2Fb%3D%3D';
            expect(withThumbnailSize(signed, 'sm')).toBe(`${signed}&size=sm`);
        });

        // @scenario surface=url_util
        // @effects url_util_replaces_existing_size_and_w
        it('기존 size·w 는 걷어내고 새 규격을 붙인다', () => {
            expect(withThumbnailSize('/api/attachment/abc?size=xs&v=2', 'md')).toBe('/api/attachment/abc?v=2&size=md');
            expect(withThumbnailSize('/api/attachment/abc?w=300', 'lg')).toBe('/api/attachment/abc?size=lg');
        });

        // @scenario surface=url_util
        // @effects url_util_strips_array_and_encoded_thumbnail_keys
        it('배열·인코딩 키(size[]·size%5B%5D·w[]·+ 공백)도 서버 판정처럼 걷어낸다', () => {
            expect(withThumbnailSize('/api/attachment/abc?size[]=xs&v=2', 'md')).toBe('/api/attachment/abc?v=2&size=md');
            expect(withThumbnailSize('/api/attachment/abc?size%5B%5D=xs&size%5B0%5D=sm', 'md')).toBe('/api/attachment/abc?size=md');
            expect(withThumbnailSize('/api/attachment/abc?w[]=300&w%5Bx%5D=1&v=1', 'lg')).toBe('/api/attachment/abc?v=1&size=lg');
            expect(withThumbnailSize('/api/attachment/abc?%73ize=xs', 'lg')).toBe('/api/attachment/abc?size=lg');
            // 서버는 + 를 공백으로 읽는다 — `size+` 는 `size ` 이라 썸네일 키가 아니다(서버와 같게 남긴다)
            expect(withThumbnailSize('/api/attachment/abc?size+=a', 'lg')).toBe('/api/attachment/abc?size+=a&size=lg');
            // 이름이 size 로 시작할 뿐인 키는 남긴다
            expect(withThumbnailSize('/api/attachment/abc?sizes=a&width=3', 'lg')).toBe('/api/attachment/abc?sizes=a&width=3&size=lg');
            // 잘못된 퍼센트 인코딩 키도 판정을 멈추지 않는다
            expect(withThumbnailSize('/api/attachment/abc?size%5B%ZZ=1&v=%E0%A4%A', 'lg')).toBe('/api/attachment/abc?v=%E0%A4%A&size=lg');
            expect(stripThumbnailSize('/api/x?size[]=xs&size%5B%5D=sm&w[]=1&expires=1')).toBe('/api/x?expires=1');
        });

        // @scenario surface=url_util
        // @effects url_util_preserves_query_and_fragment
        it('# 조각 앞에 넣는다', () => {
            expect(withThumbnailSize('/api/attachment/abc?v=1#top', 'sm')).toBe('/api/attachment/abc?v=1&size=sm#top');
            expect(withThumbnailSize('/api/attachment/abc#top', 'sm')).toBe('/api/attachment/abc?size=sm#top');
        });

        // @scenario surface=url_util
        // @effects url_util_adds_size_only_to_registered_same_origin_paths
        it('대상이 아니거나 규격이 비면 그대로다', () => {
            expect(withThumbnailSize('https://cdn.example.com/a.png', 'sm')).toBe('https://cdn.example.com/a.png');
            expect(withThumbnailSize('/storage/a.png', 'sm')).toBe('/storage/a.png');
            expect(withThumbnailSize('/api/attachment/abc', '')).toBe('/api/attachment/abc');
            expect(withThumbnailSize(null, 'sm')).toBeNull();
            expect(withThumbnailSize(undefined, 'sm')).toBeUndefined();
        });
    });

    describe('stripThumbnailSize', () => {
        // @scenario surface=url_util
        // @effects url_util_replaces_existing_size_and_w, url_util_preserves_query_and_fragment
        it('size·w 만 걷어내고 나머지 쌍·조각은 그대로다', () => {
            expect(stripThumbnailSize('/api/attachment/abc?size=lg')).toBe('/api/attachment/abc');
            expect(stripThumbnailSize('/api/x?expires=1&size=lg&signature=s#f')).toBe('/api/x?expires=1&signature=s#f');
            expect(stripThumbnailSize('/api/x')).toBe('/api/x');
            expect(stripThumbnailSize(null)).toBeNull();
        });
    });

    describe('thumbnailSrcset', () => {
        // @scenario surface=url_util
        // @effects srcset_2x_is_smallest_same_shape_preset_at_one_and_half_times
        it('같은 맞춤·같은 비율 중 1.5배 이상 최소 규격을 2x 로 고른다', () => {
            expect(thumbnailSrcset('/api/attachment/a', 'xs')).toBe('/api/attachment/a?size=xs 1x, /api/attachment/a?size=sm 2x');
            expect(thumbnailSrcset('/api/attachment/a', 'md')).toBe('/api/attachment/a?size=md 1x, /api/attachment/a?size=lg 2x');
            expect(thumbnailSrcset('/api/attachment/a', 'lg')).toBe('/api/attachment/a?size=lg 1x, /api/attachment/a?size=xl 2x');
        });

        // @scenario surface=url_util
        // @effects srcset_unknown_preset_is_one_x_only
        it('후보가 없으면 1x 만 — sm 정사각에 비가 다른 og 는 2x 가 아니다', () => {
            expect(thumbnailSrcset('/api/attachment/a', 'sm')).toBe('/api/attachment/a?size=sm 1x');
            expect(thumbnailSrcset('/api/attachment/a', 'xl')).toBe('/api/attachment/a?size=xl 1x');
            expect(thumbnailSrcset('/api/attachment/a', 'unknown')).toBe('/api/attachment/a?size=unknown 1x');
        });

        // @scenario surface=url_util
        // @effects srcset_empty_for_non_target_or_disabled
        it('대상이 아니면 빈 문자열', () => {
            expect(thumbnailSrcset('https://cdn.example.com/a.png', 'md')).toBe('');
            expect(thumbnailSrcset('/api/attachment/a', '')).toBe('');
        });
    });

    describe('contentSrcset', () => {
        // @scenario surface=url_util
        // @effects content_srcset_lists_numeric_presets_by_width
        it('숫자 요청 대상 규격 전부를 가로 기술자로 (가로 오름차순)', () => {
            expect(contentSrcset('/api/attachment/a')).toBe(
                '/api/attachment/a?size=md 640w, /api/attachment/a?size=lg 1280w, /api/attachment/a?size=xl 1920w',
            );
        });

        // @scenario surface=url_util
        // @effects srcset_encodes_spaces_in_candidates
        it('주소 안 공백은 %20 으로 (srcset 후보 구분자)', () => {
            expect(contentSrcset('/api/attachment/a b')).toContain('/api/attachment/a%20b?size=md 640w');
        });

        // @scenario surface=url_util
        // @effects srcset_empty_for_non_target_or_disabled
        it('대상이 아니면 빈 문자열', () => {
            expect(contentSrcset('/storage/a.png')).toBe('');
        });
    });

    describe('contentSrcsetWithin', () => {
        // @scenario surface=url_util
        // @effects content_srcset_within_keeps_candidates_up_to_source_width
        it('원본 가로 이하 후보만 남긴다 — 봇 화면 HtmlSanitizer 본문 이미지와 같은 입력·같은 기대값', () => {
            expect(contentSrcsetWithin('/api/attachment/w1', 2000)).toBe(
                '/api/attachment/w1?size=md 640w, /api/attachment/w1?size=lg 1280w, /api/attachment/w1?size=xl 1920w',
            );
            // 원본과 같은 가로의 후보는 남는다(본문은 원본을 따로 덧붙이지 않는다)
            expect(contentSrcsetWithin('/api/attachment/w2', 1280)).toBe('/api/attachment/w2?size=md 640w, /api/attachment/w2?size=lg 1280w');
            expect(contentSrcsetWithin('/api/attachment/w6', 700)).toBe('/api/attachment/w6?size=md 640w');
            expect(contentSrcsetWithin('/api/attachment/w3', 500)).toBe('');
        });

        // @scenario surface=url_util
        // @effects content_srcset_within_appends_original_at_source_width
        it('includeOriginal 이면 원본보다 작은 후보 + 원본(제 가로) — 원본과 같은 가로의 후보는 기술자가 겹쳐 뺀다', () => {
            const url = '/api/attachment/pc';
            expect(contentSrcsetWithin(url, 1920, { includeOriginal: true })).toBe(`${url}?size=md 640w, ${url}?size=lg 1280w, ${url} 1920w`);
            expect(contentSrcsetWithin(url, 750, { includeOriginal: true })).toBe(`${url}?size=md 640w, ${url} 750w`);
            expect(contentSrcsetWithin(url, 2560, { includeOriginal: true })).toBe(
                `${url}?size=md 640w, ${url}?size=lg 1280w, ${url}?size=xl 1920w, ${url} 2560w`,
            );
            // 원본만 남으면 srcset 을 만들지 않는다(원본 한 장이면 src 로 충분하다)
            expect(contentSrcsetWithin(url, 640, { includeOriginal: true })).toBe('');
            expect(contentSrcsetWithin(url, 600, { includeOriginal: true })).toBe('');
            // 원본 주소 안 공백도 후보처럼 인코딩한다
            expect(contentSrcsetWithin('/api/attachment/a b', 960, { includeOriginal: true })).toBe(
                '/api/attachment/a%20b?size=md 640w, /api/attachment/a%20b 960w',
            );
        });

        // @scenario surface=url_util
        // @effects content_srcset_within_floors_fractional_width
        it('가로가 정수가 아니면 내림한 정수로 본다 — 원본 기술자와 후보 기술자가 겹치지 않고, 원본보다 큰 후보가 들어오지 않는다', () => {
            const url = '/api/attachment/pc';
            // 640.4 → 640: 원본보다 작은 후보가 없다 (종전에는 `md 640w` 와 원본 `640w` 가 겹쳤다)
            expect(contentSrcsetWithin(url, 640.4, { includeOriginal: true })).toBe('');
            // 1920.7 → 1920: xl 1920 은 원본과 같은 가로라 빠지고 원본은 1920w
            expect(contentSrcsetWithin(url, 1920.7, { includeOriginal: true })).toBe(`${url}?size=md 640w, ${url}?size=lg 1280w, ${url} 1920w`);
            // 이하 규칙도 같은 정수로 — 1279.9 는 1279 라 lg 1280 을 넣지 않는다
            expect(contentSrcsetWithin(url, 1279.9)).toBe(`${url}?size=md 640w`);
            expect(contentSrcsetWithin(url, 1280.9)).toBe(`${url}?size=md 640w, ${url}?size=lg 1280w`);
            // 1 보다 작으면 가로를 모르는 것과 같다
            expect(contentSrcsetWithin(url, 0.5)).toBe('');
            expect(contentSrcsetWithin(url, 0.5, { includeOriginal: true })).toBe('');
        });

        // @scenario surface=url_util
        // @effects content_srcset_within_empty_for_unknown_width_or_non_target
        it('가로를 모르거나(0·음수·NaN·문자열·null) 대상 주소가 아니면 빈 문자열', () => {
            for (const width of [0, -1, Number.NaN, Number.POSITIVE_INFINITY, '1280', null, undefined]) {
                expect(contentSrcsetWithin('/api/attachment/a', width)).toBe('');
                expect(contentSrcsetWithin('/api/attachment/a', width, { includeOriginal: true })).toBe('');
            }
            expect(contentSrcsetWithin('/storage/a.png', 1920)).toBe('');
            expect(contentSrcsetWithin('https://cdn.example.com/a.jpg', 1920, { includeOriginal: true })).toBe('');
            expect(contentSrcsetWithin(null, 1920)).toBe('');
        });
    });

    describe('설정 출처', () => {
        // @scenario surface=url_util
        // @effects config_read_from_render_environment_before_g7config
        it('G7Config 가 없으면 TemplateApp 전역 상태(편집 캔버스 sampleGlobal) 를 읽는다', () => {
            delete (window as any).G7Config;
            (window as any).__templateApp = {
                getGlobalState: () => ({ settings: { upload: { thumbnail_presets_public: PRESETS.slice(0, 2), thumbnail_url_patterns: PATTERNS, thumbnail_content_preset: 'md' } } }),
            };
            expect(getThumbnailPresets().map((p) => p.name)).toEqual(['xs', 'sm']);
            expect(getContentPreset()).toBe('md');
            expect(withThumbnailSize('/api/attachment/abc', 'xs')).toBe('/api/attachment/abc?size=xs');
        });

        // @scenario surface=url_util
        // @effects config_read_from_render_environment_before_g7config
        it('렌더 환경 전역 상태가 G7Config 보다 먼저다 (편집 캔버스 시드 — 위젯과 같은 순서)', () => {
            (window as any).__templateApp = {
                getGlobalState: () => ({ settings: { upload: { thumbnail_content_preset: 'md' } } }),
            };
            expect(getContentPreset()).toBe('md');
            // 전역 상태에 없는 키는 G7Config 에서 읽는다
            expect(getThumbnailPresets().map((p) => p.name)).toContain('xl');
        });

        // @scenario surface=url_util
        // @effects content_preset_defaults_to_lg
        it('본문 규격 기본값은 lg', () => {
            setConfig({ thumbnail_url_patterns: PATTERNS });
            expect(getContentPreset()).toBe('lg');
        });
    });
});
