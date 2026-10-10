/**
 * 편집기의 이미지 파일 선택 창이 서버가 받는 형식만 고르게 한다
 *
 * CKEditor 기본값(`image.upload.types`)은 jpeg·png·gif·bmp·webp·tiff 라, 파일 선택 창 accept 에 bmp·tiff 가
 * 들어 있었다. 서버(`ImageUploadRequest`)는 jpeg·png·gif·webp 만 받으므로 bmp·tiff 를 고르면 업로드가
 * 「이미지 파일만 업로드할 수 있습니다」로 실패했다(7.1.0 실측 M1-O9). 편집기 설정이 서버 검증과 같은 목록을 쓰는지 본다.
 *
 * @effects editor_image_picker_matches_server_allowed_types
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import fs from 'fs';
import path from 'path';
import { initEditorHandler } from '../handlers/initEditor';
import { UPLOAD_IMAGE_TYPES } from '../handlers/uploadAdapter';

const REQUEST = path.resolve(__dirname, '../../../src/Http/Requests/ImageUploadRequest.php');

/**
 * 서버 검증의 mimes 목록을 읽어 CKEditor 형식 이름(jpg → jpeg)으로 맞춘다.
 *
 * @returns 정렬된 형식 목록
 */
function serverTypes(): string[] {
    const source = fs.readFileSync(REQUEST, 'utf8');
    const match = source.match(/'mimes:([a-z,]+)'/);
    if (!match) throw new Error('ImageUploadRequest 에서 mimes 규칙을 찾지 못했다');
    return [...new Set(match[1].split(',').map((t) => (t === 'jpg' ? 'jpeg' : t)))].sort();
}

describe('편집기 이미지 형식 — 서버 허용 형식과 같다', () => {
    let create: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        create = vi.fn(async () => ({
            destroy: async () => {},
            model: { document: { on: () => {} } },
            editing: { view: { document: { on: () => {} } } },
            ui: { view: { editable: { element: document.createElement('div') } } },
            getData: () => '',
            setData: () => {},
            on: () => {},
            enableReadOnlyMode: () => {},
        }));
        (window as any).G7Core = {
            locale: { current: () => 'ko' },
            state: { get: () => ({}), getLocal: () => ({ form: {} }), setLocal: vi.fn() },
            asset: {
                plugin: (_id: string, p: string) => `/api/plugins/assets/sirsoft-ckeditor5?file=${encodeURIComponent(p)}`,
                loadStylesheet: vi.fn(async () => {}),
                loadScript: vi.fn(async () => {
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

    /**
     * @scenario image_type=server_rejected
     * @effects editor_image_picker_matches_server_allowed_types
     */
    it('업로드 형식 상수는 서버 검증 mimes 와 같다(bmp·tiff 없음)', () => {
        expect([...UPLOAD_IMAGE_TYPES].sort()).toEqual(serverTypes());
        expect(UPLOAD_IMAGE_TYPES).not.toContain('bmp');
        expect(UPLOAD_IMAGE_TYPES).not.toContain('tiff');
    });

    /**
     * @scenario image_type=server_allowed
     * @effects editor_image_picker_matches_server_allowed_types
     */
    it('편집기 생성 설정의 image.upload.types 가 그 목록이다', async () => {
        const container = document.createElement('div');
        container.id = 'ckeditor5-content';
        document.body.appendChild(container);

        await initEditorHandler({ params: { name: 'content', imageUpload: true } }, {});

        expect(create).toHaveBeenCalled();
        const config = create.mock.calls[0][1];
        expect([...config.image.upload.types].sort()).toEqual(serverTypes());
    });
});
