/**
 * sirsoft-basic 템플릿 Composite 컴포넌트 등록
 *
 * 이 파일에서 모든 Composite 컴포넌트를 export합니다.
 * G7Core에서 자동으로 로드하여 레이아웃 JSON에서 사용할 수 있습니다.
 */
export { default as Header } from './Header';
export { default as Footer } from './Footer';
export { default as MobileNav } from './MobileNav';
export { NotificationCenter } from './NotificationCenter';
export type { NotificationCenterProps, NotificationItem } from './NotificationCenter';
export { default as ProductCard } from './ProductCard';
export { default as ImageGallery } from './ImageGallery';
export { default as ProductImageViewer } from './ProductImageViewer';
export { default as QuantitySelector } from './QuantitySelector';
export { default as PostReactions } from './PostReactions';
export { default as RichTextEditor } from './RichTextEditor';
export { HtmlContent } from './HtmlContent';
export { HtmlEditor } from './HtmlEditor';
export { ExpandableContent } from './ExpandableContent';
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
export { default as QuickSection } from './QuickSection';
export { default as ThemesSection } from './ThemesSection';
export { default as NoticeArea } from './NoticeArea';
export { default as ResearchSection } from './ResearchSection';
export { default as GlanceSection } from './GlanceSection';
export { default as SnsSection } from './SnsSection';
export { default as AictFooter } from './AictFooter';
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
export { default as InquiryStatusBar } from './InquiryStatusBar';
export type { InquiryStatusBarProps } from './InquiryStatusBar';
export { default as InquiryCard } from './InquiryCard';
export type { InquiryCardProps } from './InquiryCard';
export { default as InquiryMessageThread } from './InquiryMessageThread';
export type { InquiryMessageThreadProps, InquiryMessage } from './InquiryMessageThread';
export { default as QuoteCard } from './QuoteCard';
export type { QuoteCardProps, QuoteItem } from './QuoteCard';
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
export declare const compositeComponents: {
    Header: () => Promise<typeof import("./Header")>;
    Footer: () => Promise<typeof import("./Footer")>;
    MobileNav: () => Promise<typeof import("./MobileNav")>;
    NotificationCenter: () => Promise<typeof import("./NotificationCenter")>;
    ProductCard: () => Promise<typeof import("./ProductCard")>;
    ImageGallery: () => Promise<typeof import("./ImageGallery")>;
    ProductImageViewer: () => Promise<typeof import("./ProductImageViewer")>;
    QuantitySelector: () => Promise<typeof import("./QuantitySelector")>;
    PostReactions: () => Promise<typeof import("./PostReactions")>;
    RichTextEditor: () => Promise<typeof import("./RichTextEditor")>;
    HtmlContent: () => Promise<typeof import("./HtmlContent")>;
    HtmlEditor: () => Promise<typeof import("./HtmlEditor")>;
    ExpandableContent: () => Promise<typeof import("./ExpandableContent")>;
    FileUploader: () => Promise<typeof import("./FileUploader")>;
    ConfirmDialog: () => Promise<typeof import("./ConfirmDialog")>;
    SocialLoginButtons: () => Promise<typeof import("./SocialLoginButtons")>;
    Toast: () => Promise<typeof import("./Toast")>;
    PageTransitionIndicator: () => Promise<typeof import("./PageTransitionIndicator")>;
    PageTransitionBlur: () => Promise<typeof import("./PageTransitionBlur")>;
    PageSkeleton: () => Promise<typeof import("./PageSkeleton")>;
    PageLoading: () => Promise<typeof import("./PageLoading")>;
    ThemeToggle: () => Promise<typeof import("./ThemeToggle")>;
    Pagination: () => Promise<typeof import("./Pagination")>;
    SearchBar: () => Promise<typeof import("./SearchBar")>;
    Avatar: () => Promise<typeof import("./Avatar")>;
    AvatarUploader: () => Promise<typeof import("./AvatarUploader")>;
    UserInfo: () => Promise<typeof import("./UserInfo")>;
    Modal: () => Promise<typeof import("./Modal")>;
    TabNavigation: () => Promise<typeof import("./TabNavigation")>;
    HeroCarousel: () => Promise<typeof import("./HeroCarousel")>;
    PopupSlider: () => Promise<typeof import("./PopupSlider")>;
    NoticeTab: () => Promise<typeof import("./NoticeTab")>;
    OverlayHeader: () => Promise<typeof import("./OverlayHeader")>;
    RevealOnScroll: () => Promise<typeof import("./RevealOnScroll")>;
    RevealScrollManager: () => Promise<typeof import("./RevealScrollManager")>;
    CountUp: () => Promise<typeof import("./CountUp")>;
    QuickSection: () => Promise<typeof import("./QuickSection")>;
    ThemesSection: () => Promise<typeof import("./ThemesSection")>;
    NoticeArea: () => Promise<typeof import("./NoticeArea")>;
    ResearchSection: () => Promise<typeof import("./ResearchSection")>;
    GlanceSection: () => Promise<typeof import("./GlanceSection")>;
    SnsSection: () => Promise<typeof import("./SnsSection")>;
    AictFooter: () => Promise<typeof import("./AictFooter")>;
    ThemeListSection: () => Promise<typeof import("./ThemeListSection")>;
    ThemeGalleryViewer: () => Promise<typeof import("./ThemeGalleryViewer")>;
    BuyCardSticky: () => Promise<typeof import("./BuyCardSticky")>;
    ChangelogTimeline: () => Promise<typeof import("./ChangelogTimeline")>;
    ThemeDetailContent: () => Promise<typeof import("./ThemeDetailContent")>;
    InquiryStatusBar: () => Promise<typeof import("./InquiryStatusBar")>;
    InquiryCard: () => Promise<typeof import("./InquiryCard")>;
    InquiryMessageThread: () => Promise<typeof import("./InquiryMessageThread")>;
    QuoteCard: () => Promise<typeof import("./QuoteCard")>;
};
/**
 * 컴포넌트 타입 정의 (TypeScript 자동완성용)
 */
export type CompositeComponentName = keyof typeof compositeComponents;
