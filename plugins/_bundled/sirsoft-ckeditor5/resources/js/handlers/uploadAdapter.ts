/**
 * CKEditor5 이미지 업로드 어댑터 — 코어 API 클라이언트(`G7Core.api`)로 보낸다
 *
 * 종전에는 CKEditor 의 SimpleUploadAdapter 가 직접 요청을 보내고, 이 플러그인이 브라우저 저장소의
 * 로그인 토큰을 꺼내 Authorization 헤더를 만들어 넘겼다. 그 경로는 코어 클라이언트를 우회하므로 토큰
 * 저장 방식이 바뀌거나 토큰이 갱신되면 조용히 깨지고, 401 처리(재로그인 유도)도 받지 못했다.
 * 이제 Bearer 부착과 401 처리는 `G7Core.api` 가 맡고, 이 어댑터는 파일을 담아 보내기만 한다.
 *
 * 서버 응답 규격은 그대로다: 성공 `{ url }`, 실패 `{ error: { message } }`.
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
    const key = 'sirsoft-ckeditor5.editor.upload_failed';
    const translate = (window as any)?.G7Core?.t;
    const translated = typeof translate === 'function' ? translate(key) : null;
    const template = typeof translated === 'string' && translated !== key ? translated : "Couldn't upload file: {{name}}.";

    return template.replace('{{name}}', fileName);
}

export class G7UploadAdapter {
    private readonly controller = new AbortController();

    /**
     * @param loader CKEditor FileLoader
     * @param uploadUrl 업로드 엔드포인트
     */
    constructor(private readonly loader: UploadLoader, private readonly uploadUrl: string) {}

    /**
     * 파일을 올리고 CKEditor 가 이미지에 넣을 주소를 돌려줍니다.
     *
     * @return `{ default: 이미지 주소 }`
     */
    async upload(): Promise<{ default: string }> {
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
            response = await api.post(this.uploadUrl, formData, {
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
 * 생성기를 이 어댑터로 바꾼다.
 *
 * @param uploadUrl 업로드 엔드포인트
 * @return CKEditor 플러그인 함수
 */
export function createG7UploadAdapterPlugin(uploadUrl: string): (editor: any) => void {
    return function G7UploadAdapterPlugin(editor: any): void {
        editor.plugins.get('FileRepository').createUploadAdapter = (loader: UploadLoader) => new G7UploadAdapter(loader, uploadUrl);
    };
}
