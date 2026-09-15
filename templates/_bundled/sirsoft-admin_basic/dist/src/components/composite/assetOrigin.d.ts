/**
 * 자산 URL 출처 판정 유틸리티
 *
 * 공개 자산 스토리지(S3/CDN)를 켜면 첨부·이미지의 `download_url` 이 외부 origin
 * 절대 URL 이 된다. 이런 URL 은 인증이 필요 없는 공개 자산이므로 XHR(Blob) 로
 * 가져오면 안 된다 — 교차 출처 XHR 은 CDN 이 CORS 헤더를 주지 않는 한 응답을
 * 읽지 못해 이미지가 통째로 실패하고, 응답을 읽을 수 있는 CDN 이라면 이번에는
 * 세션 토큰이 제3자 origin 으로 나간다. 교차 출처 URL 은 `<img src>` / 링크로
 * 직접 사용한다.
 *
 * @module composite/assetOrigin
 */
/**
 * URL 이 현재 문서와 다른 출처인지 판정합니다.
 *
 * 상대 경로는 항상 동일 출처입니다. 절대 URL(`https://…`)과 프로토콜 상대
 * URL(`//host/…`)만 출처를 비교하며, 파싱 불가하거나 브라우저 밖이면
 * 동일 출처로 간주합니다(기존 인증 경로 유지).
 *
 * @param url 판정할 URL
 * @returns 교차 출처 여부
 */
export declare const isCrossOriginAssetUrl: (url?: string | null) => boolean;
