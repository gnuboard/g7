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
// AddressSearch는 별도 컴포넌트가 아닌 sirsoft-daum_postcode 플러그인의 extension_point 방식 사용

// 오프셋 테마 메인 페이지 (home-aict) 전용
export { default as HeroCarousel } from './HeroCarousel';
export type { HeroCarouselProps, HeroSlide, HeroCtaCard } from './HeroCarousel';
export { default as PopupSlider } from './PopupSlider';
export type { PopupSliderProps, PopupSlideItem } from './PopupSlider';
export { default as NoticeTab } from './NoticeTab';
export type { NoticeTabProps, NoticeListItem, NoticeTabDef } from './NoticeTab';
export { default as OverlayHeader } from './OverlayHeader';
export type { OverlayHeaderProps, OverlayMenuItem } from './OverlayHeader';
export { default as RevealOnScroll } from './RevealOnScroll';
export type { RevealOnScrollProps } from './RevealOnScroll';
export { default as RevealScrollManager } from './RevealScrollManager';
export type { RevealScrollManagerProps } from './RevealScrollManager';
export { default as CountUp } from './CountUp';
export type { CountUpProps } from './CountUp';
// 정적 섹션 컴포넌트
export { default as QuickSection } from './QuickSection';
export { default as ThemesSection } from './ThemesSection';
export { default as NoticeArea } from './NoticeArea';
export { default as ResearchSection } from './ResearchSection';
export { default as GlanceSection } from './GlanceSection';
export { default as SnsSection } from './SnsSection';
export { default as AictFooter } from './AictFooter';

// 테마 게시판 (slug=theme) 전용
export { default as ThemeListSection } from './ThemeListSection';
export type { ThemeListSectionProps, ThemeCardItem, ThemeCategoryDef } from './ThemeListSection';
export { default as ThemeGalleryViewer } from './ThemeGalleryViewer';
export type { ThemeGalleryViewerProps } from './ThemeGalleryViewer';
export { default as BuyCardSticky } from './BuyCardSticky';
export type { BuyCardStickyProps, SpecRow } from './BuyCardSticky';
export { default as ChangelogTimeline } from './ChangelogTimeline';
export type { ChangelogTimelineProps, ChangelogEntry } from './ChangelogTimeline';
export { default as ThemeDetailContent } from './ThemeDetailContent';
export type { ThemeDetailContentProps } from './ThemeDetailContent';

// 제작의뢰 (sirsoft-inquiry) 전용
export { default as InquiryStatusBar } from './InquiryStatusBar';
export type { InquiryStatusBarProps } from './InquiryStatusBar';
export { default as InquiryCard } from './InquiryCard';
export type { InquiryCardProps } from './InquiryCard';
export { default as InquiryMessageThread } from './InquiryMessageThread';
export type { InquiryMessageThreadProps, InquiryMessage } from './InquiryMessageThread';

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

  // 오프셋 테마 메인 페이지 전용 (home-aict)
  HeroCarousel: () => import('./HeroCarousel'),
  PopupSlider: () => import('./PopupSlider'),
  NoticeTab: () => import('./NoticeTab'),
  OverlayHeader: () => import('./OverlayHeader'),
  RevealOnScroll: () => import('./RevealOnScroll'),
  RevealScrollManager: () => import('./RevealScrollManager'),
  CountUp: () => import('./CountUp'),
  QuickSection: () => import('./QuickSection'),
  ThemesSection: () => import('./ThemesSection'),
  NoticeArea: () => import('./NoticeArea'),
  ResearchSection: () => import('./ResearchSection'),
  GlanceSection: () => import('./GlanceSection'),
  SnsSection: () => import('./SnsSection'),
  AictFooter: () => import('./AictFooter'),

  // 테마 게시판 (slug=theme) 전용
  ThemeListSection: () => import('./ThemeListSection'),
  ThemeGalleryViewer: () => import('./ThemeGalleryViewer'),
  BuyCardSticky: () => import('./BuyCardSticky'),
  ChangelogTimeline: () => import('./ChangelogTimeline'),
  ThemeDetailContent: () => import('./ThemeDetailContent'),

  // 제작의뢰 (sirsoft-inquiry) 전용
  InquiryStatusBar: () => import('./InquiryStatusBar'),
  InquiryCard: () => import('./InquiryCard'),
  InquiryMessageThread: () => import('./InquiryMessageThread'),
};

/**
 * 컴포넌트 타입 정의 (TypeScript 자동완성용)
 */
export type CompositeComponentName = keyof typeof compositeComponents;
