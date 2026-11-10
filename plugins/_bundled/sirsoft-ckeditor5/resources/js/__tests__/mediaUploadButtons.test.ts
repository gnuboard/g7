/**
 * 본문 동영상·오디오 업로드 단추 (#883 4단계)
 *
 * 계약 정책이 켠 종류만 단추가 서고, 올리면 `<video controls src>`·`<audio controls src>` 를 넣는다(주소는 이스케이프).
 *
 * @scenario-manifest plugins/_bundled/sirsoft-ckeditor5/tests/scenarios/editor-content-media-contract.yaml
 */
import { afterEach, describe, expect, it, vi } from 'vitest';
import { enabledMediaButtons, insertUploadedMedia, mediaHtml } from '../handlers/mediaUploadButtons';

const target = (video: boolean, audio: boolean) => ({
    policy: {
        image: { enabled: true, extensions: ['png'], mimeTypes: ['image/png'], maxSizeMb: 1 },
        video: { enabled: video, extensions: ['mp4'], mimeTypes: ['video/mp4'], maxSizeMb: 1 },
        audio: { enabled: audio, extensions: ['mp3'], mimeTypes: ['audio/mpeg'], maxSizeMb: 1 },
        maxPerDocument: 3,
    },
});

afterEach(() => {
    delete (window as any).G7Core;
    vi.restoreAllMocks();
});

describe('본문 동영상·오디오 단추', () => {
    /**
     * @scenario case=buttons_by_policy
     * @effects buttons_follow_policy
     */
    it('정책이 켠 종류만 단추가 선다(계약이 없으면 없다)', () => {
        expect(enabledMediaButtons(null)).toEqual([]);
        expect(enabledMediaButtons(target(false, false))).toEqual([]);
        expect(enabledMediaButtons(target(true, false))).toEqual(['video']);
        expect(enabledMediaButtons(target(true, true))).toEqual(['video', 'audio']);
    });

    /**
     * @scenario case=insert_markup
     * @effects inserted_media_markup_escaped
     */
    it('넣는 마크업은 controls 와 이스케이프한 src 를 쓴다', () => {
        expect(mediaHtml('video', '/x?a=1&b="2"')).toBe('<video controls="controls" preload="metadata" src="/x?a=1&amp;b=&quot;2&quot;"></video>');
        expect(mediaHtml('audio', '/y')).toBe('<audio controls="controls" preload="metadata" src="/y"></audio>');
    });

    /**
     * @scenario case=upload_and_insert
     * @effects uploaded_media_inserted
     */
    it('검증 통과 → 업로드 → 본문에 넣는다, 종류가 다르면 넣지 않고 안내한다', async () => {
        const toastError = vi.fn();
        const upload = vi.fn(async () => ({ url: '/v.mp4', kind: 'video' }));
        const validateFile = vi.fn(() => ({ ok: true, kind: 'video' }));
        (window as any).G7Core = { t: (k: string) => k, toast: { error: toastError }, contentMedia: { upload, validateFile, isAtLimit: () => false } };
        const insertContent = vi.fn();
        const editor = {
            getData: () => '',
            data: { processor: { toView: (html: string) => ({ html }) }, toModel: (view: unknown) => ({ view }) },
            model: { insertContent },
        };

        await insertUploadedMedia(editor, target(true, false), 'video', new File(['x'], 'v.mp4'));
        expect(insertContent).toHaveBeenCalledWith({ view: { html: mediaHtml('video', '/v.mp4') } });

        validateFile.mockReturnValue({ ok: true, kind: 'image' });
        await insertUploadedMedia(editor, target(true, false), 'video', new File(['x'], 'a.png'));
        expect(insertContent).toHaveBeenCalledTimes(1);
        expect(toastError).toHaveBeenCalled();
    });
});
