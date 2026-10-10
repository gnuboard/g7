/**
 * 게시글·답변 첨부를 로그인 토큰을 실어 내려받는 핸들러.
 *
 * 관리자 게시글 상세의 게시글 카드·답변 카드 첨부 목록이 쓴다. 첨부 내려받기 주소
 * (`download_url`)는 로그인하지 않아도 열리는 라우트라, `<a href>` 로 열면 Authorization 헤더가
 * 실리지 않아 서버가 요청을 비회원으로 처리한다 — 내려받기 활동 이력의 행위자가 비고, 내려받기
 * 권한도 비회원 기준으로 판정된다. 코어 ApiClient(`G7Core.api.get`)로 blob 을 받아 저장한다.
 *
 * 이 모듈이 직접 소유한다 — 관리자 레이아웃은 어느 관리자 템플릿으로 그려지고 편집되든 같은
 * 동작을 해야 하므로 특정 템플릿이 등록한 핸들러에 기대지 않는다. 코어에는 내려받기 핸들러가 없다.
 *
 * 실패하면 예외를 던진다 — 안내 문구는 레이아웃이 `onError`(`<fail>`)에서 띄운다.
 */

/** ActionDispatcher 가 넘기는 액션 객체 중 이 핸들러가 읽는 부분 */
interface DownloadAttachmentAction {
    params?: {
        url?: unknown;
        filename?: unknown;
    };
}

/**
 * 첨부를 토큰 동반 요청으로 받아 원본 파일명으로 저장한다.
 *
 * @param action `url`(내려받기 주소)·`filename`(저장할 파일명) 파라미터를 실은 액션 객체
 * @param _context 액션 컨텍스트 (미사용)
 * @returns 저장을 시작했으면 true, 주소가 없어 건너뛰었으면 false
 * @throws {Error} 코어 ApiClient 가 없거나 요청이 실패했을 때
 */
export async function downloadAttachmentHandler(
    action?: DownloadAttachmentAction,
    _context?: unknown
): Promise<boolean> {
    const url = typeof action?.params?.url === 'string' ? action.params.url : '';
    const filename = typeof action?.params?.filename === 'string' ? action.params.filename : '';

    if (url === '') {
        return false;
    }

    const api = (window as any).G7Core?.api;

    if (typeof api?.get !== 'function') {
        throw new Error('G7Core.api is not available');
    }

    // 코어 ApiClient 경로 → Authorization(Bearer) 헤더가 자동으로 실린다.
    const blob: Blob | undefined = await api.get(url, { responseType: 'blob' });

    if (!blob) {
        throw new Error('Empty download response');
    }

    const objectUrl = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = objectUrl;
    link.download = filename;
    document.body.appendChild(link);

    try {
        link.click();
    } finally {
        link.remove();
        URL.revokeObjectURL(objectUrl);
    }

    return true;
}
