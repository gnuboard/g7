// e2e:allow 편집기 ComponentRegistry 등록맵에 SlotContainer 추가(편집기 IIFE 번들 포함 — 헤더 통화 슬롯 렌더). 편집기 캔버스 렌더는 Chrome MCP 라이브 실측 + Vitest 로 검증. 사용자 사이트 런타임 무변경.
/**
 * sirsoft-basic 템플릿 Composite 컴포넌트 등록
 *
 * 이 파일에서 모든 Composite 컴포넌트를 export합니다.
 * G7Core에서 자동으로 로드하여 레이아웃 JSON에서 사용할 수 있습니다.
 */

// 레이아웃 컴포넌트
export { default as Header } from './Header';
export { default as Footer } from './Footer';
export { default as MobileNav } from './MobileNav';
export { NotificationCenter } from './NotificationCenter';
export type { NotificationCenterProps, NotificationItem } from './NotificationCenter';

// 상품 관련 컴포넌트
export { default as ProductCard } from './ProductCard';
export { default as ImageGallery } from './ImageGallery';
export { default as ProductImageViewer } from './ProductImageViewer';
export { default as QuantitySelector } from './QuantitySelector';

// 게시판 관련 컴포넌트
export { default as PostReactions } from './PostReactions';
export { default as RichTextEditor } from './RichTextEditor';
export { HtmlContent } from './HtmlContent';
export { HtmlEditor } from './HtmlEditor';

// 콘텐츠 유틸리티
export { ExpandableContent } from './ExpandableContent';

// 공통 컴포넌트
export { default as FileUploader } from './FileUploader';
export { ConfirmDialog } from './ConfirmDialog';
export { default as SocialLoginButtons } from './SocialLoginButtons';
export { default as Toast } from './Toast';
export { default as PageTransitionIndicator } from './PageTransitionIndicator';
export { default as PageTransitionBlur } from './PageTransitionBlur';
export { default as PageSkeleton } from './PageSkeleton';
export { default as PageLoading } from './PageLoading';
export { default as ThemeToggle } from './ThemeToggle';
export { Pagination } from './Pagination';
export { SearchBar } from './SearchBar';
export { Avatar } from './Avatar';
export { AvatarUploader } from './AvatarUploader';
export { UserInfo } from './UserInfo';
export { Modal } from './Modal';
export { TabNavigation } from './TabNavigation';
export { SlotContainer } from './SlotContainer';
// AddressSearch는 별도 컴포넌트가 아닌 sirsoft-daum_postcode 플러그인의 extension_point 방식 사용

/**
 * 컴포넌트 등록 맵
 *
 * G7Core 템플릿 엔진에서 레이아웃 JSON의 컴포넌트 이름을
 * 실제 컴포넌트로 매핑할 때 사용합니다.
 *
 * @example
 * // 레이아웃 JSON에서 사용
 * {
 *   "type": "composite",
 *   "name": "Header",
 *   "props": { ... }
 * }
 */
export const compositeComponents = {
  // 레이아웃
  Header: () => import('./Header'),
  Footer: () => import('./Footer'),
  MobileNav: () => import('./MobileNav'),
  // 슬롯 컨테이너 — 모듈 주입 UI(헤더 통화 셀렉터 등)를 떙겨 렌더. 등록맵 누락 시
  // 편집기 캔버스 IIFE 번들에 포함되지 않아 "Component not found in bundle" 로 렌더 실패한다
  // (운영 사이트는 별도 등록 경로라 정상이나 편집기 미리보기에서만 슬롯이 통째로 비는 회귀).
  SlotContainer: () => import('./SlotContainer'),
  NotificationCenter: () => import('./NotificationCenter'),

  // 상품
  ProductCard: () => import('./ProductCard'),
  ImageGallery: () => import('./ImageGallery'),
  ProductImageViewer: () => import('./ProductImageViewer'),
  QuantitySelector: () => import('./QuantitySelector'),

  // 게시판
  PostReactions: () => import('./PostReactions'),
  RichTextEditor: () => import('./RichTextEditor'),
  HtmlContent: () => import('./HtmlContent'),
  HtmlEditor: () => import('./HtmlEditor'),

  // 콘텐츠 유틸리티
  ExpandableContent: () => import('./ExpandableContent'),

  // 공통
  FileUploader: () => import('./FileUploader'),
  ConfirmDialog: () => import('./ConfirmDialog'),
  SocialLoginButtons: () => import('./SocialLoginButtons'),
  Toast: () => import('./Toast'),
  PageTransitionIndicator: () => import('./PageTransitionIndicator'),
  PageTransitionBlur: () => import('./PageTransitionBlur'),
  PageSkeleton: () => import('./PageSkeleton'),
  PageLoading: () => import('./PageLoading'),
  ThemeToggle: () => import('./ThemeToggle'),
  Pagination: () => import('./Pagination'),
  SearchBar: () => import('./SearchBar'),
  Avatar: () => import('./Avatar'),
  AvatarUploader: () => import('./AvatarUploader'),
  UserInfo: () => import('./UserInfo'),
  Modal: () => import('./Modal'),
  TabNavigation: () => import('./TabNavigation'),
  // AddressSearch는 sirsoft-daum_postcode 플러그인의 extension_point 방식 사용
};

/**
 * 컴포넌트 타입 정의 (TypeScript 자동완성용)
 */
export type CompositeComponentName = keyof typeof compositeComponents;
