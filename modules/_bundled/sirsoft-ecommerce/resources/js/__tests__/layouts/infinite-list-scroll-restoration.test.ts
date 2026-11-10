// e2e:allow 굽기 산출(JSON)의 스크롤 복원 선언을 소스에서 기계 도출한 모집단과 대조하는 정적 시험 — 복원 동작 자체는 코어 TemplateApp.scrollRestoration·E2E scroll-restoration 이 잰다
/**
 * @file infinite-list-scroll-restoration.test.ts
 * @description 무한 스크롤(더보기) 목록 화면의 뒤로 가기 스크롤 복원 선언 (코어 엔진 engine-v1.85.0)
 *
 * 엔진은 더보기로 늘어난 목록을 뒤로 가기 때 스냅샷으로 되살린다. 그런데 이 화면들은 다음 쪽 번호를 공유 키
 * `_global.infiniteScroll` 에 두고, 다른 화면(배송정책 폼 등)이 그 키를 1로 되돌린다 — 쪽 번호를 함께 되돌리지 않으면
 * 되살린 여러 쪽 목록에서 다음 더보기가 이미 받은 쪽을 다시 받아 같은 행이 두 번 붙는다. 또 목록은 창이 아니라
 * 목록 영역 안에서 스크롤되므로 그 영역을 `containers` 로 함께 기억해야 위치가 돌아온다.
 *
 * 모집단은 손으로 적지 않는다 — 레이아웃 소스(.g7.html)에서 「append-data-source + _global.infiniteScroll」 을 쓰는 화면을
 * 찾아(조각이면 조각 폴더 이름 = 화면 레이아웃) 그 굽기 산출의 `meta.scroll_restoration` 을 검사한다. 덧붙이는 데이터소스가
 * 모두 열 때 받는 소스(`auto_fetch: false` — 모달 목록 등)인 화면은 엔진이 스냅샷을 심지 않으므로 대상에서 뺀다.
 *
 * @scenario-manifest tests/scenarios/client-router-scroll-restoration.yaml
 */

import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

/** 레이아웃 디렉터리 */
const LAYOUTS = path.resolve(__dirname, '../../../layouts/admin');

/**
 * 디렉터리 아래 .g7.html 파일을 모두 찾는다
 *
 * @param dir 디렉터리
 * @returns 파일 경로 목록
 */
function walk(dir: string): string[] {
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            return walk(full);
        }
        return entry.name.endsWith('.g7.html') ? [full] : [];
    });
}

/** 더보기 + 공유 쪽 번호를 쓰는 화면 → 목록 영역 id · 덧붙이는 데이터소스 id */
function infiniteListScreens(): Map<string, { containers: string[]; sources: string[] }> {
    const screens = new Map<string, { containers: string[]; sources: string[] }>();
    for (const file of walk(LAYOUTS)) {
        const src = fs.readFileSync(file, 'utf8');
        if (!src.includes('<append-data-source') || !src.includes('_global.infiniteScroll')) {
            continue;
        }
        const rel = path.relative(LAYOUTS, file).split(path.sep);
        const screen = rel[0] === 'partials' ? rel[1] : rel[rel.length - 1].replace(/\.g7\.html$/, '');
        const containers = [...src.matchAll(/scrollContainer="(#[\w-]+)"/g)].map((m) => m[1]);
        const sources = [...src.matchAll(/<append-data-source[^>]*dataSourceId="([\w-]+)"/g)].map((m) => m[1]);
        const prev = screens.get(screen) ?? { containers: [], sources: [] };
        screens.set(screen, { containers: [...prev.containers, ...containers], sources: [...prev.sources, ...sources] });
    }

    return screens;
}

describe('무한 스크롤 목록 화면 — 뒤로 가기 스크롤 복원 선언', () => {
    const screens = infiniteListScreens();
    const layoutOf = (screen: string): any => JSON.parse(fs.readFileSync(path.join(LAYOUTS, `${screen}.json`), 'utf8'));
    /** 덧붙이는 소스 중 화면 진입 때 자동으로 받는 것이 있는 화면만 — 엔진이 스냅샷을 심는 대상 */
    const autoFetched = [...screens].filter(([screen, info]) => {
        const sources: any[] = layoutOf(screen).data_sources ?? [];
        return info.sources.some((id) => sources.find((s) => s.id === id)?.auto_fetch !== false);
    });

    // @scenario entry=popstate, content_timing=grows_late, config_layer=layout_meta
    // @effects snapshot_state_restored_after_init
    it('모집단이 비지 않는다 (스캐너가 죽으면 붉어진다)', () => {
        expect(screens.size).toBeGreaterThanOrEqual(3);
        expect(autoFetched.length).toBeGreaterThanOrEqual(2);
    });

    // @scenario entry=popstate, content_timing=grows_late, config_layer=layout_meta
    // @effects snapshot_state_restored_after_init
    it('열 때 받는 소스만 덧붙이는 화면(모달 목록)은 대상에서 빠진다 — 엔진이 auto_fetch:false 소스에 스냅샷을 심지 않는다', () => {
        const excluded = [...screens.keys()].filter((screen) => !autoFetched.some(([s]) => s === screen));
        expect(excluded).toContain('admin_ecommerce_shipping_policy_form');
    });

    // @scenario entry=popstate, content_timing=grows_late, config_layer=layout_meta
    // @effects snapshot_state_restored_after_init
    it('화면마다 진행 상태(_global.infiniteScroll)와 목록 영역·본문 영역을 선언한다', () => {
        for (const [screen, { containers: listContainers }] of autoFetched) {
            const config = layoutOf(screen).meta?.scroll_restoration;

            expect(config, `${screen}: meta.scroll_restoration 이 없다`).toBeTruthy();
            expect(config.snapshot?.state, `${screen}: snapshot.state`).toContain('_global.infiniteScroll');
            // containers 는 통째로 교체되므로 템플릿 기본값(본문 영역)도 함께 적어야 한다
            expect(config.containers, `${screen}: containers`).toContain('#right_content_area');
            for (const id of new Set(listContainers)) {
                expect(config.containers, `${screen}: 목록 영역 ${id}`).toContain(id);
            }
        }
    });
});
