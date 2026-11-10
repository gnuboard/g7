/**
 * 본문 동영상·오디오 업로드 툴바 단추 (#883 4·5단계)
 *
 * 화면이 넘긴 본문 미디어 계약(`mediaUpload`)의 정책이 동영상·오디오를 켰을 때만(코어 「본문 콘텐츠」 업로드 후보가 꺼져 있으면
 * 서버가 이미 `enabled=false` 로 내려준다) 툴바에 `g7VideoUpload`·`g7AudioUpload` 를 더한다. 파일을 고르면 코어
 * `G7Core.contentMedia` 로 검증·올린 뒤 `<video controls src>`·`<audio controls src>` 를 넣는다. 편집기는 계약을 읽기만 한다.
 */

/** 단추 종류 */
export type MediaButtonKind = 'video' | 'audio';

/** 단추 이름 (툴바 항목) */
export const MEDIA_BUTTON_NAMES: Readonly<Record<MediaButtonKind, string>> = Object.freeze({
    video: 'g7VideoUpload',
    audio: 'g7AudioUpload',
});

/**
 * 계약 정책으로 보일 단추를 고릅니다.
 *
 * @param target 정규화한 계약 (없으면 단추 없음)
 * @return 보일 단추 종류
 */
export function enabledMediaButtons(target: any | null | undefined): MediaButtonKind[] {
    if (!target?.policy) {
        return [];
    }

    return (['video', 'audio'] as MediaButtonKind[]).filter((kind) => target.policy[kind]?.enabled === true);
}

/**
 * 넣을 HTML 을 만듭니다(주소는 속성 값으로 이스케이프).
 *
 * @param kind 종류
 * @param url 서빙 주소
 * @return HTML
 */
export function mediaHtml(kind: MediaButtonKind, url: string): string {
    const safe = url.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');

    return kind === 'video'
        ? `<video controls="controls" preload="metadata" src="${safe}"></video>`
        : `<audio controls="controls" preload="metadata" src="${safe}"></audio>`;
}

/**
 * 번역 키를 화면 언어 문구로 바꿉니다(없으면 대체 문구).
 *
 * @param key 번역 키
 * @param params 치환 값
 * @param fallback 대체 문구
 * @return 문구
 */
function t(key: string, params: Record<string, string | number> | undefined, fallback: string): string {
    const translate = (window as any)?.G7Core?.t;
    const translated = typeof translate === 'function' ? translate(key, params) : null;

    return typeof translated === 'string' && translated !== key ? translated : fallback;
}

/**
 * 고른 파일을 계약으로 올리고 본문에 넣습니다.
 *
 * @param editor CKEditor 인스턴스
 * @param target 계약
 * @param kind 종류
 * @param file 파일
 * @return 끝나면 resolve
 */
export async function insertUploadedMedia(editor: any, target: any, kind: MediaButtonKind, file: File): Promise<void> {
    const contentMedia = (window as any)?.G7Core?.contentMedia;
    const toastError = (window as any)?.G7Core?.toast?.error ?? (() => {});
    if (!contentMedia) {
        return;
    }

    const verdict = contentMedia.validateFile(target, file);
    if (!verdict?.ok || verdict.kind !== kind) {
        toastError(t(verdict?.messageKey ?? 'core.content_media.client.kind_not_allowed', verdict?.params, 'Not allowed.'));

        return;
    }

    const html = typeof editor.getData === 'function' ? editor.getData() : '';
    if (contentMedia.isAtLimit?.(html, target) === true) {
        toastError(t('core.content_media.client.limit_reached', { max: target.policy?.maxPerDocument ?? 0 }, 'Too many media.'));

        return;
    }

    try {
        const result = await contentMedia.upload(target, file, {});
        const viewFragment = editor.data.processor.toView(mediaHtml(kind, result.url));
        const modelFragment = editor.data.toModel(viewFragment);
        editor.model.insertContent(modelFragment);
    } catch (error) {
        if ((window as any)?.G7Core?.api?.isAnonymousUnauthorized?.(error) === true) {
            toastError(t('sirsoft-ckeditor5.editor.upload_login_required', undefined, 'Sign in to upload. Your draft is kept.'));

            return;
        }
        const message = (error as any)?.response?.data?.message;
        toastError(typeof message === 'string' && message !== '' ? message : t('core.content_media.client.upload_failed', undefined, 'Upload failed.'));
    }
}

/**
 * 동영상·오디오 업로드 단추를 등록하는 CKEditor 플러그인 함수를 만듭니다.
 *
 * @param target 정규화한 계약
 * @param kinds 보일 단추 종류
 * @return CKEditor 플러그인 함수 (단추가 없으면 아무것도 하지 않음)
 */
export function createMediaUploadButtonsPlugin(target: any, kinds: MediaButtonKind[]): (editor: any) => void {
    return function G7MediaUploadButtons(editor: any): void {
        const CKEDITOR = (window as any).CKEDITOR;
        if (!CKEDITOR?.FileDialogButtonView || kinds.length === 0) {
            return;
        }

        for (const kind of kinds) {
            const extensions: string[] = target.policy?.[kind]?.extensions ?? [];
            const mimeTypes: string[] = target.policy?.[kind]?.mimeTypes ?? [];
            const label = kind === 'video'
                ? t('core.content_media.client.video_button', undefined, 'Insert video')
                : t('core.content_media.client.audio_button', undefined, 'Insert audio');

            editor.ui.componentFactory.add(MEDIA_BUTTON_NAMES[kind], (locale: any) => {
                const view = new CKEDITOR.FileDialogButtonView(locale);
                view.set({
                    acceptedType: [...extensions.map((e) => `.${e}`), ...mimeTypes].join(','),
                    allowMultipleFiles: false,
                });
                view.buttonView.set({ label, withText: true, tooltip: true });
                view.on('done', (_evt: unknown, files: FileList | File[]) => {
                    const file = Array.from(files ?? [])[0];
                    if (file) {
                        void insertUploadedMedia(editor, target, kind, file);
                    }
                });

                return view;
            });
        }
    };
}
