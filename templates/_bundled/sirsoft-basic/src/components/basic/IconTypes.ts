/**
 * Font Awesome 아이콘 스타일
 */
export type IconStyle = 'solid' | 'regular' | 'light' | 'duotone' | 'brands';

/**
 * Font Awesome 아이콘 크기
 */
export type IconSize = 'xs' | 'sm' | 'md' | 'lg' | 'xl' | '2x' | '3x' | '4x' | '5x';

/**
 * 자주 사용되는 Font Awesome 아이콘 이름
 *
 * @example
 * // 사용 예시
 * <Icon name="user" />
 * <Icon name="search" />
 * <Icon name="shopping-cart" />
 */
export enum IconName {
  // 사용자 및 계정
  User = 'fa-user',
  Users = 'fa-users',
  UserCircle = 'fa-user-circle',
  UserPlus = 'fa-user-plus',
  UserMinus = 'fa-user-minus',
  UserShield = 'fa-user-shield',

  // 액션 아이콘
  Search = 'fa-search',
  Edit = 'fa-edit',
  Pencil = 'fa-pencil-alt',
  Trash = 'fa-trash',
  Save = 'fa-save',
  Plus = 'fa-plus',
  Minus = 'fa-minus',
  Check = 'fa-check',
  Times = 'fa-times',
  Download = 'fa-download',
  Upload = 'fa-upload',
  Copy = 'fa-copy',
  ExternalLink = 'fa-external-link-alt',

  // 네비게이션
  Home = 'fa-home',
  ChevronLeft = 'fa-chevron-left',
  ChevronRight = 'fa-chevron-right',
  ChevronUp = 'fa-chevron-up',
  ChevronDown = 'fa-chevron-down',
  AngleLeft = 'fa-angle-left',
  AngleRight = 'fa-angle-right',
  AngleUp = 'fa-angle-up',
  AngleDown = 'fa-angle-down',
  ArrowUp = 'fa-arrow-up',
  ArrowDown = 'fa-arrow-down',
  ArrowLeft = 'fa-arrow-left',
  ArrowRight = 'fa-arrow-right',
  Bars = 'fa-bars',
  EllipsisVertical = 'fa-ellipsis-v',
  EllipsisHorizontal = 'fa-ellipsis-h',

  // 상태 아이콘
  CheckCircle = 'fa-check-circle',
  TimesCircle = 'fa-times-circle',
  ExclamationCircle = 'fa-exclamation-circle',
  ExclamationTriangle = 'fa-exclamation-triangle',
  InfoCircle = 'fa-info-circle',
  QuestionCircle = 'fa-question-circle',
  XCircle = 'fa-times-circle',

  // 이커머스
  ShoppingCart = 'fa-shopping-cart',
  ShoppingBag = 'fa-shopping-bag',
  CreditCard = 'fa-credit-card',
  Tag = 'fa-tag',
  Tags = 'fa-tags',

  // 파일 및 문서
  File = 'fa-file',
  FileAlt = 'fa-file-alt',
  Folder = 'fa-folder',
  FolderOpen = 'fa-folder-open',

  // 통신
  Envelope = 'fa-envelope',
  Phone = 'fa-phone',
  Comment = 'fa-comment',
  Comments = 'fa-comments',

  // 설정
  Cog = 'fa-cog',
  Cogs = 'fa-cogs',
  Settings = 'fa-cog',
  Wrench = 'fa-wrench',
  Sliders = 'fa-sliders-h',

  // 미디어
  Image = 'fa-image',
  Images = 'fa-images',
  Video = 'fa-video',
  Camera = 'fa-camera',

  // 소셜
  Heart = 'fa-heart',
  Star = 'fa-star',
  ThumbsUp = 'fa-thumbs-up',
  ThumbsDown = 'fa-thumbs-down',
  Share = 'fa-share',

  // 시간
  Clock = 'fa-clock',
  Calendar = 'fa-calendar',
  CalendarAlt = 'fa-calendar-alt',

  // 기타
  Lock = 'fa-lock',
  Unlock = 'fa-unlock',
  Eye = 'fa-eye',
  EyeSlash = 'fa-eye-slash',
  Bell = 'fa-bell',
  Flag = 'fa-flag',
  Map = 'fa-map',
  MapMarker = 'fa-map-marker-alt',
  Globe = 'fa-globe',
  Link = 'fa-link',
  Unlink = 'fa-unlink',
  Sun = 'fa-sun',
  Moon = 'fa-moon',

  // 차트 및 통계
  ChartBar = 'fa-chart-bar',
  ChartLine = 'fa-chart-line',
  ChartPie = 'fa-chart-pie',
  ChartArea = 'fa-chart-area',

  // 스피너 (로딩)
  Spinner = 'fa-spinner',
  CircleNotch = 'fa-circle-notch',

  // 배지 아이콘
  CheckBadge = 'fa-badge-check',

  // 알림 관련
  BellSlash = 'fa-bell-slash',

  // 문서/책 관련
  BookOpen = 'fa-book-open',

  // 건물/주소 관련
  Building = 'fa-building',

  // 파일 관련
  FileCircleCheck = 'fa-file-circle-check',

  // 마법 관련
  WandMagicSparkles = 'fa-wand-magic-sparkles',

  // 사용자 관련
  UserXmark = 'fa-user-xmark',

  // 확장 시스템 (모듈/플러그인)
  Cube = 'fa-cube',
  Cubes = 'fa-cubes',
  Plug = 'fa-plug',
  PuzzlePiece = 'fa-puzzle-piece',

  // 추가 아이콘 (Lucide 호환)
  Fire = 'fa-fire',
  CommentDots = 'fa-comment-dots',
  DollarSign = 'fa-dollar-sign',
  Bars2 = 'fa-bars',
  X = 'fa-times',
  List = 'fa-list',
  ThLarge = 'fa-th-large',
  Inbox = 'fa-inbox',
  Box = 'fa-box',
  PaperPlane = 'fa-paper-plane',
  FileText = 'fa-file-alt',
  Paperclip = 'fa-paperclip',
  Truck = 'fa-truck',
  Sync = 'fa-sync',
  RotateLeft = 'fa-rotate-left',
  MapPin = 'fa-map-marker-alt',
  HelpCircle = 'fa-question-circle',
}

/**
 * 문자열 아이콘 이름을 IconName enum으로 매핑합니다.
 * JSON 레이아웃에서 문자열로 아이콘 이름을 지정할 때 사용합니다.
 */
export const iconNameMap: Record<string, IconName> = {
  // 사용자 및 계정
  'user': IconName.User,
  'users': IconName.Users,
  'user-circle': IconName.UserCircle,
  'user-plus': IconName.UserPlus,
  'user-minus': IconName.UserMinus,
  'user-shield': IconName.UserShield,

  // 액션 아이콘
  'search': IconName.Search,
  'edit': IconName.Edit,
  'pencil': IconName.Pencil,
  'trash': IconName.Trash,
  'save': IconName.Save,
  'plus': IconName.Plus,
  'minus': IconName.Minus,
  'check': IconName.Check,
  'times': IconName.Times,
  'download': IconName.Download,
  'upload': IconName.Upload,
  'copy': IconName.Copy,
  'external-link': IconName.ExternalLink,

  // 네비게이션
  'home': IconName.Home,
  'chevron-left': IconName.ChevronLeft,
  'chevron-right': IconName.ChevronRight,
  'chevron-up': IconName.ChevronUp,
  'chevron-down': IconName.ChevronDown,
  'angle-left': IconName.AngleLeft,
  'angle-right': IconName.AngleRight,
  'angle-up': IconName.AngleUp,
  'angle-down': IconName.AngleDown,
  'arrow-up': IconName.ArrowUp,
  'arrow-down': IconName.ArrowDown,
  'arrow-left': IconName.ArrowLeft,
  'arrow-right': IconName.ArrowRight,
  'bars': IconName.Bars,
  'ellipsis-vertical': IconName.EllipsisVertical,
  'ellipsis-v': IconName.EllipsisVertical,
  'ellipsis-horizontal': IconName.EllipsisHorizontal,
  'ellipsis-h': IconName.EllipsisHorizontal,

  // 상태 아이콘
  'check-circle': IconName.CheckCircle,
  'times-circle': IconName.TimesCircle,
  'x-circle': IconName.XCircle,
  'exclamation-circle': IconName.ExclamationCircle,
  'exclamation-triangle': IconName.ExclamationTriangle,
  'info-circle': IconName.InfoCircle,
  'question-circle': IconName.QuestionCircle,

  // 이커머스
  'shopping-cart': IconName.ShoppingCart,
  'shopping-bag': IconName.ShoppingBag,
  'credit-card': IconName.CreditCard,
  'tag': IconName.Tag,
  'tags': IconName.Tags,

  // 파일 및 문서
  'file': IconName.File,
  'file-alt': IconName.FileAlt,
  'folder': IconName.Folder,
  'folder-open': IconName.FolderOpen,

  // 통신
  'envelope': IconName.Envelope,
  'phone': IconName.Phone,
  'comment': IconName.Comment,
  'comments': IconName.Comments,

  // 설정
  'cog': IconName.Cog,
  'cogs': IconName.Cogs,
  'settings': IconName.Settings,
  'wrench': IconName.Wrench,
  'sliders': IconName.Sliders,

  // 미디어
  'image': IconName.Image,
  'images': IconName.Images,
  'video': IconName.Video,
  'camera': IconName.Camera,

  // 소셜
  'heart': IconName.Heart,
  'star': IconName.Star,
  'thumbs-up': IconName.ThumbsUp,
  'thumbs-down': IconName.ThumbsDown,
  'share': IconName.Share,

  // 시간
  'clock': IconName.Clock,
  'calendar': IconName.Calendar,
  'calendar-alt': IconName.CalendarAlt,

  // 기타
  'lock': IconName.Lock,
  'unlock': IconName.Unlock,
  'eye': IconName.Eye,
  'eye-slash': IconName.EyeSlash,
  'bell': IconName.Bell,
  'flag': IconName.Flag,
  'map': IconName.Map,
  'map-marker': IconName.MapMarker,
  'globe': IconName.Globe,
  'link': IconName.Link,
  'unlink': IconName.Unlink,
  'sun': IconName.Sun,
  'moon': IconName.Moon,

  // 차트 및 통계
  'chart-bar': IconName.ChartBar,
  'chart-line': IconName.ChartLine,
  'chart-pie': IconName.ChartPie,
  'chart-area': IconName.ChartArea,

  // 스피너 (로딩)
  'spinner': IconName.Spinner,
  'circle-notch': IconName.CircleNotch,

  // 배지 아이콘
  'check-badge': IconName.CheckBadge,
  'badge-check': IconName.CheckBadge,

  // 확장 시스템 (모듈/플러그인)
  'cube': IconName.Cube,
  'cubes': IconName.Cubes,
  'plug': IconName.Plug,
  'puzzle-piece': IconName.PuzzlePiece,

  // Lucide 호환 아이콘 매핑
  'flame': IconName.Fire,
  'fire': IconName.Fire,
  'message-square': IconName.CommentDots,
  'dollar-sign': IconName.DollarSign,
  'menu': IconName.Bars,
  'x': IconName.X,
  'list': IconName.List,
  'layout-grid': IconName.ThLarge,
  'th-large': IconName.ThLarge,
  'grid': IconName.ThLarge,
  'inbox': IconName.Inbox,
  'package': IconName.Box,
  'box': IconName.Box,
  'send': IconName.PaperPlane,
  'paper-plane': IconName.PaperPlane,
  'file-text': IconName.FileText,
  'paperclip': IconName.Paperclip,
  'truck': IconName.Truck,
  'refresh-cw': IconName.Sync,
  'sync': IconName.Sync,
  'undo-2': IconName.RotateLeft,
  'rotate-left': IconName.RotateLeft,
  'map-pin': IconName.MapPin,
  'help-circle': IconName.HelpCircle,

  // 추가 Lucide 호환 아이콘 매핑
  'alert-triangle': IconName.ExclamationTriangle,
  'bell-off': IconName.BellSlash,
  'book-open': IconName.BookOpen,
  'building': IconName.Building,
  'edit-2': IconName.Edit,
  'edit-3': IconName.Edit,
  'file-check': IconName.FileCircleCheck,
  'mail': IconName.Envelope,
  'sparkles': IconName.WandMagicSparkles,
  'trash-2': IconName.Trash,
  'user-x': IconName.UserXmark,
};
