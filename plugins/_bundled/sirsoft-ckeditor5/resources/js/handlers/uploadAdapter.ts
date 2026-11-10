/**
 * CKEditor5 이미지 업로드 어댑터 — 코어 API 클라이언트(`G7Core.api`)로 보낸다
 *
 * 종전에는 CKEditor 의 SimpleUploadAdapter 가 직접 요청을 보내고, 이 플러그인이 브라우저 저장소의
 * 로그인 토큰을 꺼내 Authorization 헤더를 만들어 넘겼다. 그 경로는 코어 클라이언트를 우회하므로 토큰
 * 저장 방식이 바뀌거나 토큰이 갱신되면 조용히 깨지고, 401 처리(재로그인 유도)도 받지 못했다.
 * 이제 Bearer 부착과 401 처리는 `G7Core.api` 가 맡고, 이 어댑터는 파일을 담아 보내기만 한다.
 *
 * 서버 응답 규격은 그대로다: 성공 `{ url }`, 실패 `{ error: { message } }`.
 *
 * 화면이 본문 미디어 업로드 계약(`mediaUpload`, 코어 `G7Core.contentMedia`)을 넘기면 그 계약으로 올린다 — 업로드 주소·
 * 정책·응답 경로를 화면(확장)이 정하고, 이 어댑터는 계약을 읽기만 한다(#883 4단계). 계약이 없으면 종전 경로(이 플러그인의
 * 관리자 전용 업로드)다.
 */

/** CKEditor FileLoader 중 이 어댑터가 쓰는 부분 */
export interface UploadLoader {
    /** 업로드할 파일 (CKEditor 가 읽어 둔다) */
    file: Promise<File | null>;
    /** 전체 바이트 수 (진행률 표시용) */
    uploadTotal?: number | null;
    /** 보낸 바이트 수 (진행률 표시용) */
    uploaded?: number;
}

/** 업로드 폼 필드 이름 — 서버 검증(`ImageUploadRequest`)과 같은 이름 */
const UPLOAD_FIELD = 'upload';

/**
 * 편집기가 받는 이미지 형식 — 서버 검증(`ImageUploadRequest` 의 `mimes:jpeg,jpg,png,gif,webp`)과 같은 목록.
 *
 * CKEditor 의 기본값(`image.upload.types`)은 bmp·tiff 까지 받아, 파일 선택 창이 서버가 거절할 형식을
 * 고르게 했다(고르면 「이미지 파일만 업로드할 수 있습니다」로 실패, 7.1.0 실측 M1-O9). 파일 선택 창의
 * accept 와 끌어 놓기·붙여 넣기 필터가 모두 이 목록을 쓴다.
 */
export const UPLOAD_IMAGE_TYPES: readonly string[] = ['jpeg', 'png', 'gif', 'webp'];

/**
 * 실패 응답에서 사람이 읽을 문구를 꺼냅니다.
 *
 * @param error API 클라이언트가 던진 오류
 * @param fallback 문구를 찾지 못했을 때의 문구
 * @return 오류 문구
 */
function extractUploadErrorMessage(error: unknown, fallback: string): string {
    const data = (error as any)?.response?.data;
    const message = data?.error?.message ?? data?.message;

    return typeof message === 'string' && message !== '' ? message : fallback;
}

/**
 * CKEditor 업로드 어댑터.
 */
/**
 * 업로드 실패 문구 — 화면 언어로 보여 준다(번역 엔진이 없으면 CKEditor 기본 문구와 같은 영어).
 *
 * @param fileName 올리려던 파일 이름
 * @return 실패 문구
 */
function uploadFailedMessage(fileName: string): string {
    return translateOr('sirsoft-ckeditor5.editor.upload_failed', "Couldn't upload file: {{name}}.").replace('{{name}}', fileName);
}

/**
 * 비회원이 업로드하려다 401 을 받았을 때의 문구 — 로그인 화면으로 옮기지 않으므로 쓰던 글이 남아 있음을 함께 알린다.
 *
 * @return 로그인 필요 문구
 */
function uploadLoginRequiredMessage(): string {
    return translateOr('sirsoft-ckeditor5.editor.upload_login_required', 'Sign in to upload images. Your draft is kept.');
}

/**
 * 번역 키를 화면 언어 문구로 바꿉니다. 번역 엔진이 없거나 키가 그대로 돌아오면 대체 문구를 씁니다.
 *
 * @param key 번역 키
 * @param fallback 대체 문구
 * @return 문구
 */
function translateOr(key: string, fallback: string): string {
    const translate = (window as any)?.G7Core?.t;
    const translated = typeof translate === 'function' ? translate(key) : null;

    return typeof translated === 'string' && translated !== key ? translated : fallback;
}

/** 어댑터 선택 — 계약이 있으면 계약 경로, 없으면 종전 경로 */
export interface G7UploadAdapterOptions {
    /** 정규화한 본문 미디어 업로드 계약 (`G7Core.contentMedia.normalizeTarget` 결과) */
    target?: any | null;
    /** 종전 업로드 주소 (계약이 없을 때) */
    legacyUrl: string;
    /** 문서당 개수 사전 차단에 쓸 현재 본문 (편집기 getData) */
    getContent?: () => string;
}

/**
 * 계약 판정 문구를 화면 언어로 만듭니다.
 *
 * @param key 번역 키
 * @param params 치환 값
 * @param fallback 대체 문구
 * @return 문구
 */
function translateWith(key: string, params: Record<string, string | number> | undefined, fallback: string): string {
    const translate = (window as any)?.G7Core?.t;
    const translated = typeof translate === 'function' ? translate(key, params) : null;

    return typeof translated === 'string' && translated !== key ? translated : fallback;
}

export class G7UploadAdapter {
    private readonly controller = new AbortController();

    private readonly options: G7UploadAdapterOptions;

    /**
     * @param loader CKEditor FileLoader
     * @param options 업로드 주소(종전) 또는 계약 옵션
     */
    constructor(private readonly loader: UploadLoader, options: string | G7UploadAdapterOptions) {
        this.options = typeof options === 'string' ? { legacyUrl: options } : options;
    }

    /**
     * 파일을 올리고 CKEditor 가 이미지에 넣을 주소를 돌려줍니다.
     *
     * @return `{ default: 이미지 주소 }`
     */
    async upload(): Promise<{ default: string }> {
        const target = this.options.target ?? null;
        const contentMedia = (window as any).G7Core?.contentMedia;
        if (target && contentMedia && typeof contentMedia.upload === 'function') {
            return this.uploadByContract(target, contentMedia);
        }

        return this.uploadLegacy();
    }

    /**
     * 본문 미디어 계약으로 올립니다 — 정책 판정 → 문서당 개수 사전 차단 → 코어 어댑터 업로드.
     *
     * @param target 정규화한 계약
     * @param contentMedia `G7Core.contentMedia`
     * @return `{ default: 미디어 주소 }`
     */
    private async uploadByContract(target: any, contentMedia: any): Promise<{ default: string }> {
        const file = await this.loader.file;
        const fallback = uploadFailedMessage(file?.name ?? '');
        if (!file) {
            throw fallback;
        }

        const verdict = contentMedia.validateFile(target, file);
        if (!verdict?.ok) {
            throw translateWith(verdict?.messageKey ?? '', verdict?.params, fallback);
        }

        const html = this.options.getContent?.() ?? '';
        if (contentMedia.isAtLimit?.(html, target) === true) {
            throw translateWith('core.content_media.client.limit_reached', { max: target.policy?.maxPerDocument ?? 0 }, fallback);
        }

        try {
            const result = await contentMedia.upload(target, file, {
                signal: this.controller.signal,
                onProgress: (loaded: number, total: number) => {
                    this.loader.uploadTotal = total;
                    this.loader.uploaded = loaded;
                },
            });

            if (typeof result?.url !== 'string' || result.url === '') {
                throw fallback;
            }

            return { default: result.url };
        } catch (error) {
            if (typeof error === 'string') {
                throw error;
            }
            // 토큰 없이 보낸 업로드의 401 — 코어가 로그인 화면으로 옮기지 않고 돌려준다(engine-v1.88.2)
            if ((window as any).G7Core?.api?.isAnonymousUnauthorized?.(error) === true) {
                throw uploadLoginRequiredMessage();
            }

            throw extractUploadErrorMessage(error, translateWith('core.content_media.client.upload_failed', undefined, fallback));
        }
    }

    /**
     * 종전 경로(이 플러그인의 관리자 전용 업로드)로 올립니다.
     *
     * @return `{ default: 이미지 주소 }`
     */
    private async uploadLegacy(): Promise<{ default: string }> {
        const file = await this.loader.file;
        const fallback = uploadFailedMessage(file?.name ?? '');
        const api = (window as any).G7Core?.api;

        if (!file || typeof api?.post !== 'function') {
            throw fallback;
        }

        const formData = new FormData();
        formData.append(UPLOAD_FIELD, file);

        let response: any;
        try {
            response = await api.post(this.options.legacyUrl, formData, {
                // 기본 Content-Type(JSON) 이 FormData 를 JSON 으로 바꾸지 않게 한다 — 경계값은 브라우저가 채운다
                headers: { 'Content-Type': 'multipart/form-data' },
                signal: this.controller.signal,
                onUploadProgress: (event: { loaded: number; total?: number }) => {
                    if (typeof event.total === 'number') {
                        this.loader.uploadTotal = event.total;
                    }
                    this.loader.uploaded = event.loaded;
                },
            });
        } catch (error) {
            // 토큰 없이 보낸 업로드의 401 — 코어가 로그인 화면으로 옮기지 않고 돌려준다(engine-v1.88.2).
            // 서버 문구(「인증이 필요합니다.」) 대신 쓰던 글이 남아 있다는 안내를 보여 준다.
            if (api.isAnonymousUnauthorized?.(error) === true) {
                throw uploadLoginRequiredMessage();
            }

            // CKEditor 는 거부 값(문자열)을 그대로 알림 문구로 보여 준다
            throw extractUploadErrorMessage(error, fallback);
        }

        const url = response?.url ?? response?.urls?.default;
        if (typeof url !== 'string' || url === '') {
            throw extractUploadErrorMessage({ response: { data: response } }, fallback);
        }

        return { default: url };
    }

    /**
     * 업로드를 중단합니다 (편집기가 업로드 중 이미지를 지울 때).
     */
    abort(): void {
        this.controller.abort();
    }
}

/**
 * 편집기에 업로드 어댑터를 붙이는 CKEditor 플러그인 함수를 만듭니다.
 *
 * CKEditor 는 함수도 플러그인으로 받는다. `FileRepository`(ImageUpload 가 함께 싣는다)의 어댑터
 * 생성기를 이 어댑터로 바꾼다. 계약(`target`)을 주면 계약 경로로, 문자열만 주면 종전 경로로 올린다.
 *
 * @param options 종전 업로드 주소 또는 `{ target, legacyUrl }`
 * @return CKEditor 플러그인 함수
 */
export function createG7UploadAdapterPlugin(options: string | Omit<G7UploadAdapterOptions, 'getContent'>): (editor: any) => void {
    return function G7UploadAdapterPlugin(editor: any): void {
        const resolved: G7UploadAdapterOptions = typeof options === 'string'
            ? { legacyUrl: options }
            : { ...options, getContent: () => (typeof editor.getData === 'function' ? editor.getData() : '') };
        editor.plugins.get('FileRepository').createUploadAdapter = (loader: UploadLoader) => new G7UploadAdapter(loader, resolved);
    };
}
