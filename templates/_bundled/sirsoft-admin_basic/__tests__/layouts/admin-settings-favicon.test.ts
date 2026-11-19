/**
 * @file admin-settings-favicon.test.ts
 * @description 환경설정 일반 탭 파비콘 칸과 저장 후 전역 갱신을 잠근다 (#434).
 *
 * - 파비콘 업로더: 파비콘 전용 형식(ico·svg 포함)·1개·1MB·지연 업로드·이미지 압축 없음(ico·svg 변환 방지)
 * - 완료 시 폼 값을 **교체**(1개짜리), 제거 시 걸러 냄
 * - 우선순위 안내 2줄은 `_meta.favicon.{user,admin}.source === 'template'` 일 때만, 「템플릿 설정으로」는 설정 화면 경로가 있을 때만
 * - 저장 버튼이 `upload:site_favicon` 신호를 보낸다
 * - 저장 성공 뒤 전역 설정은 응답의 공개 설정(`frontend_settings`)으로 바꾼다 — 관리자 형태로 바꾸면 계산 필드(site_logo_url)가 없어
 *   상단 로고가 새로고침 전까지 사라졌다(S-3). 같은 갱신을 하는 알림 채널 확인 창도 같다.
 *
 * 시나리오: surface=admin_settings
 *
 * 효과: site_favicon_saved_and_replaced
 * 효과: save_response_carries_frontend_settings
 * 효과: priority_meta_described
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/**
 * 템플릿 루트(template.json 기준)를 위로 훑어 찾는다.
 *
 * @returns 템플릿 루트 절대경로
 */
function templateRoot(): string {
    let current = path.dirname(fileURLToPath(import.meta.url));

    for (let depth = 0; depth < 10; depth++) {
        if (fs.existsSync(path.join(current, 'template.json'))) {
            return current;
        }
        current = path.dirname(current);
    }

    throw new Error('template.json 을 가진 템플릿 루트를 찾지 못했습니다.');
}

/**
 * 레이아웃 트리를 깊이 우선으로 훑는다.
 *
 * @param node 탐색 시작 노드
 * @param visit 노드마다 부르는 함수
 */
function walk(node: unknown, visit: (n: Record<string, any>) => void): void {
    if (Array.isArray(node)) {
        node.forEach((child) => walk(child, visit));
        return;
    }
    if (!node || typeof node !== 'object') return;

    visit(node as Record<string, any>);
    Object.values(node as Record<string, unknown>).forEach((value) => walk(value, visit));
}

/**
 * 조건을 만족하는 첫 노드를 찾는다.
 *
 * @param tree 레이아웃 트리
 * @param predicate 조건
 * @returns 노드 (없으면 null)
 */
function find(tree: unknown, predicate: (n: Record<string, any>) => boolean): Record<string, any> | null {
    let found: Record<string, any> | null = null;
    walk(tree, (n) => {
        if (!found && predicate(n)) found = n;
    });

    return found;
}

const root = templateRoot();
const read = (rel: string) => JSON.parse(fs.readFileSync(path.join(root, rel), 'utf-8'));
const general = read('layouts/partials/admin_settings/_tab_general.json');
const settings = read('layouts/admin_settings.json');
const channelOff = read('layouts/partials/admin_settings/_modal_notification_channel_off_confirm.json');

describe('환경설정 일반 탭 파비콘 칸 (#434)', () => {
    const uploader = find(general, (n) => n.id === 'site_favicon_uploader');

    // @scenario surface=admin_settings
    // @effects site_favicon_saved_and_replaced
    it('파비콘 전용 형식·1개·1MB·지연 업로드이고 이미지 압축이 없다', () => {
        expect(uploader).not.toBeNull();
        expect(uploader!.props.collection).toBe('site_favicon');
        expect(uploader!.props.accept).toBe('.ico,.png,.svg,.gif,.jpg,.jpeg,.webp');
        expect(uploader!.props.maxFiles).toBe(1);
        expect(uploader!.props.maxSize).toBe(1);
        expect(uploader!.props.autoUpload).toBe(false);
        expect(uploader!.props.uploadTriggerEvent).toBe('upload:site_favicon');
        expect(uploader!.props.imageCompression).toBeUndefined();
        expect(uploader!.props.initialFiles).toContain('general?.site_favicon');
    });

    // @scenario surface=admin_settings
    // @effects site_favicon_saved_and_replaced
    it('보기 권한만 있는 관리자에게는 업로더가 잠긴다 — 다른 일반 탭 칸과 같은 읽기 전용 판정', () => {
        expect(uploader!.props.disabled).toBe('{{_computed.isReadOnly}}');

        const logo = find(general, (n) => n.props?.collection === 'site_logo');
        expect(uploader!.props.disabled).toBe(logo!.props.disabled);
    });

    // @scenario surface=admin_settings
    // @effects site_favicon_saved_and_replaced
    it('업로드 완료는 폼 값을 교체하고 제거는 걸러 낸다', () => {
        const complete = (uploader!.actions as any[]).find((a) => a.type === 'change' && a.event === 'onUploadComplete');
        expect(complete.params['form.general.site_favicon']).toBe('{{$args[0]}}');

        const remove = (uploader!.actions as any[]).find((a) => a.type === 'change' && a.event === 'onRemove');
        expect(remove.params['form.general.site_favicon']).toContain('.filter(');
    });

    // @scenario surface=admin_settings
    // @effects priority_meta_described
    it('우선순위 안내는 템플릿 계층일 때만, 이동 링크는 설정 화면 경로가 있을 때만 보인다', () => {
        for (const target of ['user', 'admin']) {
            const notice = find(general, (n) => n.id === `favicon_override_${target}`);
            expect(notice).not.toBeNull();
            expect(notice!.if).toBe(`{{settings?.data?._meta?.favicon?.${target}?.source === 'template'}}`);

            // 다른 화면 이동은 실제 주소를 가진 링크(#757 WP-L)
            const link = find(notice, (n) => n.name === 'A');
            expect(link!.if).toContain(`favicon?.${target}?.settings_route`);
            expect(link!.props.href).toContain(`favicon?.${target}?.settings_route`);
            const navigate = find(link, (n) => n.handler === 'navigate');
            expect(navigate!.params.path).toContain(`favicon?.${target}?.settings_route`);
        }
    });
});

describe('저장 후 전역 설정 갱신 (#434 S-3)', () => {
    // @scenario surface=admin_settings
    // @effects site_favicon_saved_and_replaced
    it('저장 버튼이 파비콘 업로드 신호를 로고 다음에 보낸다', () => {
        const save = find(settings, (n) => n.id === 'save_button');
        const emitted: string[] = [];
        walk(save, (n) => {
            if (n.handler === 'emitEvent' && typeof n.params?.event === 'string') emitted.push(n.params.event);
        });

        expect(emitted).toContain('upload:site_favicon');
        expect(emitted.indexOf('upload:site_favicon')).toBe(emitted.indexOf('upload:site_logo') + 1);
    });

    // @scenario surface=admin_settings
    // @effects save_response_carries_frontend_settings
    it('두 저장 경로 모두 전역 settings 를 frontend_settings 로 바꾼다', () => {
        for (const layout of [settings, channelOff]) {
            const setGlobal: Record<string, any>[] = [];
            walk(layout, (n) => {
                if (n.handler === 'setState' && n.params?.target === 'global' && 'settings' in (n.params ?? {})) setGlobal.push(n);
            });

            expect(setGlobal.length).toBeGreaterThan(0);
            for (const action of setGlobal) {
                expect(action.params.settings).toBe('{{result.data.frontend_settings ?? result.data.settings}}');
                expect(action.params.uploadSettings).toBe('{{(result.data.frontend_settings ?? result.data.settings).upload}}');
            }
        }
    });
});
