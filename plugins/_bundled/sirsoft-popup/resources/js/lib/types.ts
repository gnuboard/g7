import type { Overlays, PublicOverlayBox } from './overlays';

/**
 * 공개 API(`GET /api/plugins/sirsoft-popup/popups`) 응답 모양.
 *
 * 문구(alt·본문)는 서버가 요청 언어로 골라 둔 값이다. 노출 페이지·기기·「보지 않기」는 브라우저가 거른다.
 */

/** 이미지 한 장 */
export interface PopupImage {
  /** 공개 이미지 주소 */
  url: string;

  /** 가로 픽셀 */
  width?: number | null;

  /** 세로 픽셀 */
  height?: number | null;
}

/** 팝업 한 건 */
export interface PublicPopup {
  /** 팝업 ID */
  id: number;

  /** 내용 유형 */
  content_type: 'image' | 'html';

  /** 노출 페이지 범위 */
  page_scope: 'all' | 'routes';

  /** 정규화된 노출 경로 (라우트 지정일 때) */
  routes?: string[];

  /** 노출 기기 */
  device: 'all' | 'pc' | 'mobile';

  /** 이미지형 이미지 (모바일이 없으면 PC 를 쓴다) */
  image?: { pc: PopupImage | null; mobile: PopupImage | null } | null;

  /** 이미지 대체 텍스트 (서버가 관리용 제목까지 대체해 둔다) */
  image_alt?: string;

  /** 이미지를 누르면 이동할 주소 */
  link_url?: string | null;

  /** 새 창에서 열기 */
  link_new_window?: boolean;

  /** 이미지 위 글 상자 (글은 서버가 요청 언어로 고른 값, 모바일 배치는 모바일 이미지가 있을 때만) */
  overlays?: Overlays<PublicOverlayBox> | null;

  /** 직접 작성형 본문 (서버가 저장 때 정화한 HTML) */
  body_html?: string;
}

/** 표시 방식 — 모든 팝업에 똑같이 적용된다 */
export interface PopupDisplaySettings {
  /** PC 위치 */
  pc_position: 'center' | 'coords';

  /** 왼쪽에서(px) — 좌표일 때 */
  pc_left: number;

  /** 위에서(px) — 좌표일 때 */
  pc_top: number;

  /** 내용 너비(px) */
  pc_width: number;

  /** 내용 높이(px) */
  pc_height: number;

  /** 모바일 형태 */
  mobile_style: 'center' | 'sheet';

  /** 배경 어둡게 */
  dim_background: boolean;

  /** 배경을 누르면 닫기 */
  close_on_backdrop: boolean;

  /** 여러 개일 때 자동 넘김 */
  autoplay: boolean;

  /** 넘김 간격(초) */
  autoplay_interval: number;

  /** 닫기 옵션 */
  dismiss_mode: 'today' | 'days' | 'close';

  /** 보지 않을 일수 (days 일 때) */
  dismiss_days: number;
}

/** 표시 방식 기본값 — 응답에 값이 빠졌을 때 */
export const DEFAULT_SETTINGS: PopupDisplaySettings = {
  pc_position: 'center',
  pc_left: 80,
  pc_top: 120,
  pc_width: 480,
  pc_height: 520,
  mobile_style: 'sheet',
  dim_background: true,
  close_on_backdrop: false,
  autoplay: true,
  autoplay_interval: 6,
  dismiss_mode: 'today',
  dismiss_days: 7,
};
