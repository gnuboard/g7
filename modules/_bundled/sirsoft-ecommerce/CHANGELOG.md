# Changelog

이 프로젝트의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [1.0.0-beta.2] - 2026-04-20

### Added

- **알림 시스템 강화**
  - 7종 알림(주문확인/배송시작/구매확정/주문취소/신규주문/문의접수/문의답변) 실제 발송 구현
  - 알림 목록에 채널별 수신자 뱃지 표시
  - 알림 편집 모달에 수신자 설정 섹션 추가 — 타입 선택 및 규칙 관리
  - 알림 클릭 시 이동 URL 기본값 설정
  - 알림 정의 일괄 초기화 기능 — 확인 모달 + 진행 스피너 UX 포함
  - 이커머스 알림 정의를 코어 리셋 로직에 자동 기여하여 기본값 복원 가능
  - 알림 탭은 코어 알림 권한 기준으로 편집 가능 여부를 판단하도록 개선 (모듈 설정 권한과 분리)
- **SEO 환경설정 강화**
  - 페이지 타입별 SEO 변수 메타데이터 선언 (상품/카테고리/검색/쇼핑몰 메인)
  - 쇼핑몰 메인 페이지 메타태그 설정 추가
  - 캐시 삭제 확인 모달 및 캐시 용량 동적 표시
- **배송유형 관리 기능**
  - 환경설정 > 배송설정에서 배송유형 추가/수정/삭제/활성화 가능
  - 기본 11종(국내 7종 + 해외 2종 + 기타 2종) 시딩
  - 배송유형/배송사 테이블 드래그앤드롭 순서 변경
  - 배송방법 "직접입력" 선택 시 다국어 배송방법명 입력 지원
- 클레임 사유(ClaimReason) 사용자 수정 보존 지원
- 주소 중복 등록 시 전용 도메인 예외 처리

### Changed

- 코어 최소 요구 버전을 7.0.0-beta.2 로 상향
- 알림 정의 시더를 인라인 방식으로 전환 — 사용자 수정 자동 보존 패턴 적용
- 레거시 메일 템플릿 테이블을 통합 알림 시스템으로 이관
- 알림 수신자를 채널별 독립 관리 방식으로 변경
- 이전 업그레이드 스텝에 클래스 부재 시 안전 스킵 처리 추가
- 상품 설명/공통정보 에디터를 확장 포인트(extension_point)로 변환
- SEO 캐시 무효화를 코어 공통 캐시 인터페이스로 전환
- 상품정보제공고시, 공통정보, 알림 템플릿 편집의 다국어 탭을 동적 생성으로 전환
- 배송유형을 Enum에서 DB 테이블로 변경하여 관리자 화면에서 동적 관리 가능하도록 변경
- 주문 필터, 배송정책 폼의 하드코딩 옵션을 DB 기반 동적 렌더링으로 변경
- 주문/배송정책에서 직접입력 배송방법명 해석 지원 (스냅샷 기반)

### Removed

- 레거시 메일 템플릿 시더 및 관련 다국어 키 제거
- ShippingTypeEnum, ShippingMethodEnum 제거 — DB 관리 방식으로 이관
- 환경설정의 도서산간 배송비 기본설정 제거 — 배송정책별 설정으로 완전 이관

### Fixed

- 마이페이지 배송지 관리에서 수정 버튼에 권한 체크가 누락되어 있던 문제 수정
- 사용자 화면에서 상품 설명 HTML이 텍스트로 노출되던 문제 수정
- 상품 설명/공통정보 에디터에 HTML 모드 전환 옵션 누락 수정
- 공통정보 패널 폼 초기화 및 모달 전환 관련 버그 수정
- 주문목록 검색필터 배송방법 선택 시 검증 에러 수정
- 환경설정/상품옵션 테이블의 Select 드롭다운이 가려지는 문제 수정
- 재설치·재시드 시 주문/상품 시퀀스 counter 가 0으로 리셋되어 주문번호 중복 위험이 있던 문제 수정 — 기존 counter 보존
- 재설치·재시드 시 관리자가 수정·추가한 택배사 정보가 기본 목록으로 초기화되던 문제 수정 — 사용자 수정분 자동 보존

## [1.0.0-beta.1] - 2026-04-01

### Changed

- 오픈 베타 릴리즈

## [0.20.0] - 2026-03-31

### Added

- 기본 클레임 사유(환불/취소) 자동 등록 — 모듈 설치 시 7건 기본 사유 시딩 (고객 귀책 4건 + 판매자 귀책 3건)
- `ClaimReasonSeeder` 시더 추가 (`getSeeders()` 및 `DatabaseSeeder` 등록)
- 업그레이드 스텝 `Upgrade_0_20_0` — 기존 설치 환경에 기본 클레임 사유 삽입

### Fixed

- 장바구니 API 서버 에러 수정 — 삭제된 배송정책을 참조하는 상품의 배송비 계산 시 null TypeError 방지, 배송비 0원 처리 (OrderCalculationService)
- 사용자 주문 취소 완료 후 모달이 닫히지 않는 문제 수정 — `showCancelModal: false` 상태값 방식을 `G7Core.modal.close('modal_cancel_order')` 호출 방식으로 교체 (userCancelOrderHandlers)
- 사용자 주문 취소 모달 취소 사유 selectbox 다크모드 미적용 수정 — 다크모드 색상 클래스 추가 (_modal_cancel.json)
- 사용자 주문 취소 모달 취소 사유 selectbox 하단 여백 누락 수정 — `mb-4` 추가 (_modal_cancel.json)

## [0.19.6] - 2026-03-30

### Fixed

- 상품옵션/고시정보 다국어 입력 stale closure 수정 — productOptionHandlers/productNoticeHandlers에서 디바운스 병합 시 이전 키 변경 유실 방지 (`_partial_product_options.json`, `_partial_product_notices.json`)
- 주문상세 배송방법 `$t:` 번역 토큰 원문 노출 수정 — 배송방법 표시 바인딩에 `raw:` 마커 적용 (_partial_order_info.json)
- FileUploader onRemove 시 form 데이터 동기화 누락 수정 — remove 핸들러에 setState 추가하여 폼 데이터에서 삭제된 파일 즉시 반영 (_partial_image_upload.json)

## [0.19.5] - 2026-03-30

### Fixed

- 주문 일괄 변경 활동 로그 `:count` 플레이스홀더 미치환 수정 — bulk 핸들러 4개(bulkUpdate, bulkStatusUpdate, bulkShippingUpdate, bulkOptionStatusChange)의 `description_params`에 `count` 키 추가
- 주문 상태 변경 활동 로그에서 enum 값(payment_complete, shipping 등)이 번역되지 않고 원시값 노출 수정 — Order/OrderOption 모델 `$activityLogFields`의 status 필드를 `type: 'enum'`으로 변경 + `OrderStatusEnum::labelKey()` 메서드 추가

## [0.19.4] - 2026-03-30

### Changed

- `window.G7Config` 설정 노출 최소화 — frontend_schema 필드별 `expose: false` 처리 (basic_info: route_path, no_route만 노출, language_currency: default_currency, currencies만 노출, order_settings/shipping/seo/review_settings 전체 미노출)

## [0.19.3] - 2026-03-30

### Fixed

- 관리자 다크모드 레이아웃 전수 수정 — 101개 파일에서 누락된 `dark:` variant 약 415건 추가 (text-gray, bg-white/gray, focus:ring, text-red/blue/green/yellow, border-gray, hover 등)
- 상품 일괄 변경 모달 라벨 미정의 CSS 클래스 수정 — `className: "label"` → `"form-label"` (다크모드 텍스트 가독성 복원)
- 상품 목록 판매가/재고 일괄 변경 버튼 다크모드 텍스트 색상 누락 수정 — `text-gray-700 dark:text-gray-300` 추가
- 레이아웃 JSON 내 Select 컴포넌트 다크모드 배경색 수정 — `dark:bg-gray-800` → `dark:bg-gray-700` (카드 배경과 구분)

## [0.19.2] - 2026-03-30

### Added

- 검색/필터/정렬 성능 향상을 위한 일반 인덱스 추가 — ecommerce_products(selling_price, list_price, stock_quantity, shipping_policy_id, barcode, tax_status, updated_at), ecommerce_orders(total_amount, order_device, confirmed_at, [user_id+ordered_at]), ecommerce_order_addresses(orderer_phone, recipient_phone)

### Changed

- Repository 7개의 직접 `MATCH...AGAINST` 호출을 Laravel Scout `Model::search()` 파이프라인으로 전환 — DBMS별 검색 분기를 엔진 내부에서 처리 (ProductRepository, CategoryRepository, BrandRepository, CouponRepository, ProductCommonInfoRepository)
- ReportRepository의 관계 검색을 `DatabaseFulltextEngine::whereFulltext()` 정적 헬퍼로 전환 — 다중 DBMS 호환
- Category, Brand, PromotionCoupon, ProductCommonInfo 모델에 `FulltextSearchable` 인터페이스 + `Searchable` 트레이트 추가
- `searchIndexShouldBeUpdated()` 훅 기반 전환 — `HookManager::applyFilters()` 사용으로 검색 플러그인이 모델별 인덱스 업데이트 제어 가능
- 마이그레이션 FULLTEXT 인덱스 생성을 `DatabaseFulltextEngine::addFulltextIndex()` 정적 헬퍼로 전환 — DBMS별 조건부 처리

### Fixed

- 인덱스명 약어 수정 — `idx_ecom_*` → `idx_ecommerce_*`, `stock_qty` → `stock_quantity` (네이밍 규칙 준수)

## [0.19.1] - 2026-03-30

### Added

- 상품 옵션 재생성 시 기존 옵션 초기화 확인 모달 연결 — 기존 옵션이 존재할 때 generateOptions 호출 시 확인 모달 표시, 확인 후 skipConfirm 플래그로 실제 생성 진행 (optionHandlers, _modal_confirm_regenerate.json)

## [0.19.0] - 2026-03-30

### Added

- FULLTEXT 인덱스(ngram) 추가 — ecommerce_products(name, description), ecommerce_categories(name, description), ecommerce_brands(name), ecommerce_promotion_coupons(name, description), ecommerce_product_common_infos(name, content) 총 9개
- Product 모델에 Laravel Scout `Searchable` 트레이트 + `FulltextSearchable` 인터페이스 적용

### Changed

- 상품/카테고리/브랜드/쿠폰/공통정보 관리자 검색 쿼리를 `LIKE '%keyword%'`에서 `MATCH...AGAINST IN BOOLEAN MODE`로 전환 — JSON TEXT 컬럼 검색 성능 향상
- 공개 상품 검색(통합검색) 쿼리를 로케일 루프 `JSON_EXTRACT + LIKE`에서 `MATCH...AGAINST`로 전환
- 관리자 상품 목록 검색 필드에 '상품설명', 'SKU' 옵션 추가 — description FULLTEXT 검색 지원

## [0.18.4] - 2026-03-30

### Fixed

- 상품 수정 시 이미지 순서 유실 수정 — syncImages에서 배열 인덱스를 sort_order SSoT로 사용, stale sort_order 값 무시 (ProductService)
- 상품 수정 시 대표이미지 hash 기반 설정 — syncImages에 thumbnail_hash 파라미터 추가, hash 기준 is_thumbnail 갱신 (ProductService)
- 상품 복사 시 원본 이미지 파일 복사 — copyFromSource() 메서드 추가, hash 기반 원본 조회 및 스토리지 파일 복사 (ProductImageService)
- 상품 복사 시 createImages에서 hash 기반 이미지 복사 분기 — hash 존재 시 copyFromSource 호출, 미존재 시 기존 직접 생성 (ProductService)
- 상품 이미지 임시 파일 연결 시 sort_order 재배치 — linkTempImages에서 기존 이미지 최대 sort_order 뒤에 배치, is_thumbnail 해제 (ProductImageService)
- 상품 수정 폼 이미지 메타데이터 확장 — download_url, size, size_formatted, mime_type, is_image, width, height, thumbnail_hash 추가 (ProductService)
- 상품 이미지 업로드 레이아웃 전면 수정 — event 기반 콜백, hash 기반 대표이미지, 편집/복사 모드별 API 엔드포인트 분기, onReorder/onRemove 핸들러 추가 (_partial_image_upload.json)
- ProductResource에 thumbnail_hash 필드 추가 — 대표이미지 hash 반환 (ProductResource)
- StoreProductRequest에 thumbnail_hash 검증 추가, description maxLength 65535 적용 (StoreProductRequest)
- ProductController 에러 응답에 상세 에러 정보 추가 — ValidationException errors 전달, debug 모드 예외 메시지 포함 (ProductController)

## [0.18.3] - 2026-03-30

### Fixed

- 카테고리 삭제 모달 영문 다국어 ICU MessageFormat 오류 수정 — `{count, plural, ...}` 형식을 단순 `{count}` 치환 형식으로 변경 (category.json)

## [0.18.2] - 2026-03-29

### Fixed

- 주문 일괄 배송중 변경 시 운송장/택배사 정보가 저장되지 않는 버그 수정 — `bulkUpdateShipping()`에서 shipping 레코드 미존재 시 옵션별 신규 생성 로직 추가 (OrderRepository)

### Changed

- order_shippings 테이블에서 `carrier_name` 컬럼 삭제 — 택배사명은 carrier_id 관계를 통해 사용자 로케일에 맞게 동적 조회로 전환 (OrderShipping, OrderOptionResource, OrderListResource, OrderShippingResource)

## [0.18.1] - 2026-03-29

### Fixed

- 배송완료 상태 변경 시 운송장 입력 강제 해제 — `shippingInfoRequiredStatuses()`에서 DELIVERED 제거 (OrderStatusEnum)
- 주문 목록 일괄 배송완료 시 상태가 "배송중"으로 표시되는 버그 수정 — `bulkUpdateShipping()`에서 order_status를 SHIPPING으로 덮어쓰는 코드 삭제 (OrderRepository)
- 주문상세 일괄변경 시 carrier vs carrier_id 키 불일치로 422 에러 발생 수정 — FE `body.carrier` → `body.carrier_id`, BE `$validated['carrier']` → `$validated['carrier_id']` (orderDetailHandlers, OrderController)
- 주문상세 옵션 일괄 상태 변경 후 부모 주문 상태 미동기화 수정 — `syncParentOrderStatus()` 메서드 추가, 모든 활성 옵션 동일 상태면 주문도 해당 상태로, 혼합 시 최저 진행 단계로 설정 (OrderOptionService)
- ActivityLog 일괄 상태 변경 로그 미기록 수정 — Service results 키를 `order_option_id`로 변경하여 Listener pluck와 일치, 분할/병합 케이스별 loggable 대상 정확히 지정 (OrderOptionService, OrderActivityLogListener)
- 프론트엔드 주문 목록 배송완료 선택 시 운송장 필수 경고/비활성화 해제 — shippingStatuses 배열 및 modal 조건에서 delivered 제거 (orderHandlers, _modal_bulk_confirm.json)

### Changed

- OrderOption 일괄 상태 변경 results 구조 확장 — `option_id` → `order_option_id`, `split_order_option_id`, `merged_into_order_option_id`, `is_full_quantity` 추가 (OrderOptionService)

## [0.18.0] - 2026-03-29

### Added

- 검색/필터/정렬 성능 향상을 위한 누락 인덱스 일괄 추가 — ecommerce_products 7개(selling_price, list_price, stock_quantity, shipping_policy_id, barcode, tax_status, updated_at), ecommerce_orders 4개(total_amount, order_device, confirmed_at, [user_id+ordered_at] 복합), ecommerce_order_addresses 2개(orderer_phone, recipient_phone)
- 상품 복사 모드 이미지 파일 복사 기능 — copyFromSource() 메서드로 원본 이미지 파일과 메타데이터를 새 상품에 복제 (ProductImageService)
- 상품 등록 시 에러 상세 로깅 — ValidationException/Exception 분리 처리 및 debug 모드 시 exception 메시지 포함 응답 (ProductController)

### Fixed

- 상품 등록 시 상세설명 255자 제한 버그 수정 — TranslatableField maxLength 65535 명시 (StoreProductRequest)
- 상품 복사/수정 화면에서 FileUploader 렌더링 에러 수정 — getDetailForForm() 이미지 매핑에 누락 필드(mime_type, is_image, download_url, size, order 등) 추가 (ProductService)
- 상품 복사 컨트롤러에서 중복 download_url 추가 로직 제거 — getDetailForForm()에서 이미 포함 (ProductController)
- 상품 복사 등록 시 500 에러 수정 — createImages()에서 hash 기반 복사 모드 이미지를 copyFromSource()로 파일 복사 처리 (ProductService)
- 인덱스 마이그레이션 날짜 순서 수정 — 테이블 생성 마이그레이션 이후로 재배치 + 인덱스 중복 방어 코드 추가
- 상품 수정/복사 화면에서 대표이미지 표시 안됨 수정 — id 기반 → hash 기반 대표이미지 식별로 전환, 복사 모드 이미지 삭제 시 원본 보호 (ProductResource, ProductService, _partial_image_upload.json)
- 상품 복사 등록 시 임시 이미지 정렬/썸네일 버그 수정 — linkTempImages()에서 sort_order 미설정(기존 이미지와 충돌) 및 is_thumbnail 미해제(이중 썸네일) 문제 해결 (ProductImageService)
- 상품 이미지 filesChange 이벤트가 form.images를 pendingFiles로 덮어쓰는 버그 수정 — 복사 이미지 데이터 유실 방지, uploadComplete도 form.images 미수정으로 createImages/linkTempImages 이중 처리 해소 (_partial_image_upload.json)

## [0.17.2] - 2026-03-29

### Fixed

- 주문상세 일괄변경 시 toast에 번역 키(`no_items_selected`)가 원문 그대로 표시되는 버그 수정 — 누락된 다국어 키 추가 (ko/en)
- 주문상세 일괄변경에서 "선택 없음"과 "취소 가능 상품 없음" 메시지를 동일 키로 사용하던 것을 `no_items_selected` / `no_cancellable_items`로 분리 (orderDetailHandlers)

## [0.17.1] - 2026-03-28

### Fixed

- 공통정보 추가 시 "ko 언어의 값은 필수입니다" 검증 오류 수정 — MultilingualInput의 setState에서 `$event` 전체 대신 `$event.target.value`로 다국어 값 객체만 저장
- 공통정보 뷰 패널 "기본값으로 설정" 버튼 미동작 수정 — 모달 ID 불일치(`set_default_confirm_modal` → `modal_set_default_confirm`) 및 확인 모달에 기본값 설정 API 호출 추가

## [0.17.0] - 2026-03-30

### Added

- 상품 상세 페이지에 1:1 문의 탭 추가
  - 문의 게시판이 설정된 경우에만 탭 노출
  - 비회원 포함 누구나 문의 목록 조회 가능, 로그인 사용자가 문의 작성/수정/삭제 가능
  - 비밀글 토글 필터 (전체 / 비밀글 제외) 제공
  - 관리자 권한 보유자가 문의에 답변 등록/수정/삭제 가능
  - 작성자명 마스킹, 비밀글 잠금 처리
- 마이페이지에 내 문의내역 조회 API 추가 (답변 내용 포함)
- 이커머스 환경설정에 1:1 문의게시판 설정 섹션 추가
  - 게시판 모듈과 연동하여 사용할 게시판을 지정할 수 있음
  - 게시판 모듈 미설치 시에도 이커머스 단독 운영 가능
- 문의 접수/답변 완료 메일 템플릿 2종 추가 (`inquiry_received`, `inquiry_replied`)
- 문의 작성 시 상품명 다국어 스냅샷 저장 (상품 삭제 후에도 문의 내역에서 상품명 표시 유지)
- 기존 설치 환경을 위한 업그레이드 스텝 추가 (`Upgrade_0_9_0`)

### Changed

- 상품 복사 기능을 POST API 방식에서 navigate 패턴으로 전환 — 복사 옵션 선택 → 상품 등록 페이지로 이동하여 사전 입력 (역할 복사와 동일 UX)
- 복사 옵션 11개로 확장: 이미지, 옵션, 카테고리, 판매정보, 상품설명, 상품정보제공고시, 공통정보, 기타정보, 배송정책, SEO, 식별코드
- 수정/목록 페이지 복사/삭제 버튼을 setState 방식에서 openModal/closeModal 패턴으로 변경

### Fixed

- 문의 삭제 시 Action 훅이 트랜잭션 내부에서 실행되던 문제 수정 (롤백 시 부작용 방지)
- 훅명 표준화: 엔티티 부분의 하이픈(`-`)을 언더스코어(`_`)로 변경
- 마이페이지 문의 목록 조회 시 타입 불일치로 발생할 수 있는 오류 수정
- 문의 가능 여부(`inquiry_available`) 판단 기준을 게시판 slug 존재 여부로 통일
- 목록 페이지 복사 모달 제출 시 "POST method not supported" 에러 수정 — GET 라우트에 POST 요청 불일치 해결
- 수정 페이지 복사/삭제 버튼 미동작 수정 — modals 섹션에서 setState 플래그 대신 openModal 핸들러 사용
- 배송정책 목록 페이지에서 데이터 0건일 때 DataGrid와 Empty State가 동시 표시되는 버그 수정 — DataGrid, bulk_actions, table_header_bar, pagination에 조건부 렌더링 추가
- 배송정책 복사 시 원본 데이터가 폼에 로드되지 않는 버그 수정 — copy_source data_source 추가 및 initShippingPolicyForm 핸들러에 isCopy 모드 처리
- 배송정책 신규 등록/수정 시 is_default=true 설정 시 기존 기본 정책이 해제되지 않는 버그 수정 — create()/update()에 clearDefault() 호출 추가
- 배송정책 목록 DataGrid subRow에서 `$t:` 번역 토큰이 원문 그대로 표시되는 버그 수정 — `{{}}` 표현식 내 문자열 리터럴에서 `$t()` 함수 호출로 변경
- 배송정책 복사 모달이 실제 국가별 설정을 반영하지 않는 버그 수정 — 플랫 필드(배송방법, 운송사, 부과정책)에서 country_settings 기반 반복 렌더링으로 재설계

## [0.16.2] - 2026-03-27

### Fixed

- 카테고리 크로스 depth 이동 시 parent_id가 null로 변경되지 않는 버그 수정 — `isset()`이 null에 대해 false를 반환하여 `array_key_exists()`로 교체 (CategoryService)
- 이커머스 부모 메뉴 URL을 null로 변경 — 대응 라우트 없는 `/admin/ecommerce` URL이 404 발생 (module.php)

## [0.16.1] - 2026-03-26

### Fixed

- 상품 하위 라우트(form, copy, can-delete, logs) `whereNumber` 제약으로 product_code(영숫자) 404 발생 수정 — `where('product', '[0-9a-zA-Z]+')` 패턴으로 변경하여 ID/코드 둘 다 대응

## [0.16.0] - 2026-03-26

### Added

- ProductLog → ActivityLog 통합: 상품 처리로그를 표준 ActivityLog 시스템으로 전환
  - ProductActivityLogListener: ActivityLog 표준 패턴 적용 (description_key, ChangeDetector)
  - ActivityLogDescriptionResolver: product_id → 상품명 실시간 해석
  - ProductController::logs(): 상품별 처리로그 API (OrderController::logs 패턴 동일)
- Bulk Update ChangeDetector 지원: 일괄 변경 시 필드별 old/new 변경 내역 기록
  - OrderService: bulkUpdate, bulkUpdateStatus, bulkUpdateShipping에 스냅샷 캡처 + ChangeDetector 적용
  - OrderOptionService: bulkChangeStatusWithQuantity에 스냅샷 캡처 + ChangeDetector 적용
  - ProductOptionService: bulkUpdatePriceByMixedIds, bulkUpdateStockByMixedIds, bulkUpdate(통합)에 스냅샷 캡처 + ChangeDetector 적용
  - EcommerceAdminActivityLogListener: `option.after_bulk_update` 훅 구독 추가 (통합 일괄 수정 로깅)
  - ProductOptionRepositoryInterface: `findByIds()`, `getIdsByProductIds()` 메서드 추가
- Listener 레벨 Bulk 스냅샷/ChangeDetector 전수 적용 (13건)
  - ProductActivityLogListener: bulk_update/bulk_price_update/bulk_stock_update before 스냅샷 + after ChangeDetector (6훅 추가)
  - CouponActivityLogListener: before_bulk_status 스냅샷 캡처 + after ChangeDetector 연동 (1훅 추가)
  - EcommerceAdminActivityLogListener: ExtraFeeTemplate bulk delete/toggle/create, ShippingPolicy bulk delete/toggle, ProductReview bulk delete 스냅샷 + ChangeDetector (9훅 추가)
  - 삭제 작업 시 deleted_items 스냅샷 보존 (ExtraFeeTemplate, ShippingPolicy, ProductReview)
- 21개 모델에 $activityLogFields 정의 (코어 5개, 이커머스 11개, 게시판 4개, 페이지 1개)
- Upgrade_0_16_0: ecommerce_product_logs 테이블 DROP + 레이아웃 캐시 클리어
- 활동 로그 Per-Item 로깅 전환: 모든 bulk 핸들러가 변경된 건별로 loggable_id 기록
  - OrderActivityLogListener: bulk_update/bulk_status_update/bulk_shipping_update per-Order, bulk_option_status_change per-OrderOption 전환
  - ProductActivityLogListener: bulk_update/bulk_price_update/bulk_stock_update per-Product 전환
  - CouponActivityLogListener: bulk_status per-Coupon 전환
  - EcommerceAdminActivityLogListener: ExtraFee/ShippingPolicy bulk toggle per-item, bulk_delete per-item (loggable_type/loggable_id 직접 지정) 전환
  - ShippingPolicyActivityLogListener: 중복 bulk 훅 구독 2개 삭제 (EcommerceAdminActivityLogListener에 위임)
- EcommerceUserActivityLogListener: 사용자 주문생성(order.after_create) + 구매확인(order-option.after_confirm) 로그 추가
- ActivityLogHandler: 삭제된 엔티티용 loggable_type/loggable_id 직접 지정 지원

### Removed

- ecommerce_product_logs 테이블 (ActivityLog로 대체)
- ProductLogService, ProductLogRepository, ProductLogController, ProductLog 모델 등 관련 코드 전량 삭제

### Changed

- 처리로그 레이아웃: log.description → log.localized_description (ActivityLogResource 기준)
- Bulk Update 훅 시그니처: after_bulk_* 훅에 $allChanges 파라미터 추가 (하위 호환 유지)

## [0.15.0] - 2026-03-26

### Added

- 구매확정 기능 — 마이페이지 주문상세에서 옵션별 구매확정 API 및 UI
  - `confirmed_at` 컬럼 추가 (ecommerce_order_options 테이블)
  - OrderOption 모델: `confirmed_at` fillable/casts, `review()` HasOne 관계
  - ConfirmOrderOptionRequest: 소유권, 상태, 중복 확정 검증
  - OrderService.confirmOption(): 옵션 확정 + 전체 옵션 확정 시 주문도 CONFIRMED 전환
  - User OrderController.confirmOption(): 구매확정 API 엔드포인트
  - `sirsoft-ecommerce.order-option.before_confirm` / `after_confirm` 훅
  - OrderConfirmPointListener: 구매확정 시 포인트 적립 더미 리스너
- 리뷰 작성 기능 — 구매확정 후 리뷰 작성 모달 UI 및 이미지 순차 업로드
  - userConfirmOrderHandlers.ts: 구매확정 커스텀 핸들러
  - userReviewHandlers.ts: 리뷰 작성 + 이미지 순차 업로드 커스텀 핸들러
- 구매확정/리뷰 작성 모달 레이아웃 (_modal_confirm_purchase.json, _modal_write_review.json)
- _items.json에 구매확정/리뷰작성/리뷰완료 버튼 조건부 렌더링 (PC + 모바일 동일)
- OrderOptionResource에 `can_confirm`, `can_write_review`, `has_review` 필드 추가
- 환경설정: `confirmable_statuses` (주문설정), `review_settings` (리뷰설정) 추가
- 사용자 권한: `user-orders.confirm`, `user-reviews.write` 등록 (module.php + Upgrade_0_15_0)
- 리뷰 라우트에 `permission:user,sirsoft-ecommerce.user-reviews.write` 미들웨어 추가
- 다국어 키 추가 (ko/en): 구매확정/리뷰 관련 메시지, 예외, 프론트엔드 번역

### Changed

- show.json: initLocal에 구매확정/리뷰 상태 변수 추가, modals에 구매확정/리뷰 모달 partial 등록
- handlers/index.ts: confirmOrderOption, submitReview 핸들러 등록

### Fixed

- 상품폼 처리로그 설명 미표시 — ProductLogResource는 `description` 필드를 반환하나 레이아웃이 `localized_description`을 참조하여 빈 값 표시 (v0.13.0 변경 시 오류)

## [0.14.0] - 2026-03-25

### Added

- 클래임 사유 관리 시스템 — DB 기반 환불 사유 관리 (ClaimReason 모델, Repository, Service, Controller, FormRequest, Resource)
  - `ecommerce_claim_reasons` 테이블: type, code, name(다국어), fault_type, is_user_selectable, is_active, sort_order
  - Admin API 7개: 목록/생성/상세/수정/삭제/상태토글/활성목록 (`/admin/claim-reasons`)
  - User API 1개: 사용자 선택 가능 사유 목록 (`/user/claim-reasons`)
  - ClaimReasonFaultTypeEnum (customer/seller/carrier), ClaimReasonTypeEnum (refund)
- 쇼핑몰 환경설정 > 클래임 탭 — 환불 사유 인라인 편집 UI (배송사 관리 패턴)
  - 테이블 뷰(PC) + 카드 뷰(모바일) 반응형 레이아웃
  - EcommerceSettingsController에 syncReasons() 연동
- 업그레이드 스크립트 (Upgrade_0_14_0) — 기존 CancelReasonTypeEnum 7개 값을 DB 시드 데이터로 마이그레이션

### Changed

- 주문 취소 사유를 하드코딩 Enum에서 DB 기반으로 전환
  - CancelReasonTypeEnum 삭제 → ClaimReason DB 조회로 대체
  - CancelOrderRequest (Admin/User): `Rule::in(enum)` → `Rule::exists(ecommerce_claim_reasons, code)`
  - OrderCancel 모델: Enum cast 제거, getRefundReasonLabel() 헬퍼 추가
  - OrderCancellationService: Enum 참조 제거, 단순 string 저장
- 관리자/사용자 취소 모달 — 하드코딩 Option에서 동적 iteration으로 변경
  - Admin 모달: refundReasons 데이터소스 + iteration 기반 동적 렌더링
  - User 모달: 동일 패턴 적용 (user/claim-reasons API 사용)

### Fixed

- 활동 로그 description 표시 시 번역 키가 원문 그대로 노출되는 문제 수정
  - ActivityLogDescriptionResolver 추가 — 표시 시점에 ID를 엔티티 이름으로 해석 (HookManager 필터 훅)
  - src/lang/{ko,en}/activity_log.php 번역 파일 동기화 (TranslationServiceProvider 로딩 경로 일치)
  - OrderActivityLogListener: handleOrderAfterSendEmail/handleOrderOptionAfterStatusChange description_params 불일치 수정
- OrderOption의 product_option_name, option_name, option_value 미저장 및 다국어 미지원 수정 (OrderProcessingService, OrderOption)
  - createOrderOptions()에서 product_option_name/option_value 누락 → ProductOption 다국어 원본 직접 저장
  - 3개 컬럼 varchar(255) → JSON 마이그레이션 + 모델 array cast 추가
  - Resource 3개(OrderOptionResource, OrderListResource, UserOrderListResource)에서 로케일 변환 추가
  - 기존 문자열 데이터 하위호환 (is_array 체크)
- StoreEcommerceSettingsRequest: claim/review_settings 탭 저장 실패 수정 — `_tab` validation 및 `validatedSettings()` 누락 보완
- FormRequest 로케일 하드코딩 수정 — `name.ko` 직접 검증을 `LocaleRequiredTranslatable` Rule로 교체 (StoreEcommerceSettingsRequest, StoreProductRequest)

## [0.13.8] - 2026-03-25

### Fixed

- 옵션 레벨 promotions_applied_snapshot이 camelCase로 저장되는 버그 수정 — DTO `toArray()` 호출 누락으로 PHP 프로퍼티명이 그대로 직렬화됨 (OrderProcessingService)

## [0.13.7] - 2026-03-25

### Fixed

- 취소 모달 debounce 타이머에서 __g7ActionContext 미복원으로 모달 상태 업데이트 실패 수정 (cancelOrderHandlers, userCancelOrderHandlers)
  - debounce 콜백 실행 시 ActionDispatcher의 try/finally가 이미 __g7ActionContext를 복원하여 모달의 actionContext 사라짐
  - 캡처/복원 패턴 적용으로 setLocal()이 모달의 actionContext.setState()를 정상 호출

### Changed

- OrderActivityLogListener에서 명시적 log_type 지정 제거 — 코어 resolveLogType() 요청 경로 기반 판별로 대체 (6개 핸들러)

## [0.13.6] - 2026-03-25

### Fixed

- toPreviewArray() refund_total 배송비 이중 계산: refundAmount에 이미 배송비 차이가 내포되어 있는데 shippingDifference를 별도 가산하여 환불 예정액 과다 표시 (AdjustmentResult)

### Added

- refund_total 정합성 검증 테스트 4건: 부분취소 무료→유료 전환, 전체취소 배송비 포함, 쿠폰+배송비 복합 시나리오 (OrderAdjustmentServiceTest G-1~G-4)

## [0.13.5] - 2026-03-25

### Fixed

- 상품금액 정액 쿠폰 수량 미반영 수정: 정액 할인이 수량과 무관하게 1회만 적용되던 결함 → 적용 대상 옵션의 수량만큼 할인 적용 (OrderCalculationService.calculateCouponDiscount)
- OrderSeeder 정액 상품쿠폰 수량 미반영 수정: 시더에서도 target_scope별 적용 대상 수량 계산하여 할인 적용 (OrderSeeder.getTargetQuantity)

### Added

- 부분취소 시 쿠폰 조건 미달로 결제금액 증가 시 취소 차단: validateRefundNotNegative 검증 + cancel_blocked 플래그 (OrderCancellationService, AdjustmentResult)
- Admin/User 취소 모달에 취소 불가 경고 배너 + 취소 버튼 비활성화 (_modal_cancel_order.json, _modal_cancel.json)
- 수량별 정액 할인 테스트 16개 + cancel_blocked 테스트 4개 (OrderCalculationServiceTest, OrderAdjustmentServiceTest)

## [0.13.4] - 2026-03-25

### Fixed

- 쿠폰 수정 화면 적용 상품 테이블 데이터 미표시: CouponResource의 included_products/excluded_products에 product_code, name_localized, selling_price_formatted 필드 추가 (CouponResource.php)

## [0.13.3] - 2026-03-25

### Fixed

- 관리자 취소 모달 수량 변경 시 비교 테이블 미갱신 결함 수정: updateCancelQuantity 핸들러에 value 파라미터 누락 추가 (_modal_cancel_order.json)
- 관리자 취소 모달 onMount setState로 인한 모달/페이지 scope 단절 해소: onMount 제거 + 초기화 핸들러에서 상태 설정 및 estimateRefundAmount 호출 패턴으로 전환 (orderDetailHandlers.ts, _modal_cancel_order.json)

## [0.13.2] - 2026-03-25

### Fixed

- 상품수량(item_count) 스냅샷이 고유 상품 수(distinct)가 아닌 총 수량 합계로 계산되도록 수정 (captureOrderSnapshot)
- 상품쿠폰 최대할인금액 초과 결함 수정: 아이템별 할인 산출 후 글로벌 cap 비례 안분 적용 (applyProductCoupons 3단계 리팩토링)
- CouponApplication 기록 시 존재하지 않는 필드($coupon->max_discount_amount) 대신 실제 DB 컬럼($coupon->discount_max_amount) 사용하도록 수정 (4곳)
- buildCouponSnapshotsFromOrder()에 max_discount_amount 필드 추가하여 취소 재계산 시 쿠폰 최대할인 정보 보존

## [0.13.1] - 2026-03-25

### Added

- 취소 모달 주문금액 비교 테이블: 취소 전/후 금액 비교 표시 (상품수량, 총 정가금액, 상품/주문/배송비쿠폰 할인, 할인코드 할인, 기본/추가배송비, 포인트 사용, 과세금액, 부가세, 실결제금액, 적립예정포인트)
- 쿠폰 상세 표시: 상품쿠폰/주문쿠폰/배송비쿠폰별 적용 쿠폰명 + 할인금액 표시 (original_coupons, recalculated_coupons)
- 다통화 금액 표시: 총 정가금액/실결제금액 셀 하단에 다통화 금액 인라인 표시 (mc_original_snapshot, mc_recalculated_snapshot)
- 스냅샷 필드 확장: captureOrderSnapshot/captureRecalcSnapshot에 5개 필드 추가 (total_product_coupon_discount_amount, total_order_coupon_discount_amount, total_vat_amount, total_earned_points_amount, item_count)
- AdjustmentResult DTO: mcOriginalSnapshot, mcRecalculatedSnapshot, originalCoupons, recalculatedCoupons 프로퍼티 추가
- 상품 소계 표시: 단가 × 수량 = 소계 형태로 변경 (Admin/User 모달 공통)
- Admin 취소 모달 크기 1.5배 확대 (750px → 1125px), User 모달 크기 조정 (lg → 750px)
- 다국어 키 18개 추가 (ko/en, Admin ecommerce + User sirsoft-basic)

### Changed

- 취소 모달 "환불 예정금액" 섹션을 "주문금액 비교" 테이블로 전면 교체 (Admin/User 공통)
- 변경된 금액에 적색 하이라이트 적용 (text-red-600 dark:text-red-400 font-semibold)

## [0.13.0] - 2026-03-25

### Added

- ActivityLog 리스너 전면 구현:
  - OrderActivityLogListener (주문 CUD + 상태변경 + 일괄처리 + 배송 + 이메일 + 취소/부분취소/환불)
  - CouponActivityLogListener (쿠폰 CUD + 일괄 상태변경)
  - ShippingPolicyActivityLogListener (배송정책 CUD + 토글 + 일괄처리)
  - CategoryActivityLogListener (카테고리 CUD + 순서변경 + 상태전환)
  - EcommerceAdminActivityLogListener (브랜드/라벨/공통정보/고시정보/추가비용/배송업체/상품옵션/상품이미지/리뷰 통합)
  - EcommerceUserActivityLogListener (장바구니/위시리스트/쿠폰사용/마일리지 — ActivityLogType::User)
- 활동 로그 다국어 키 100개 정의 (resources/lang/ko/activity_log.php, en/activity_log.php)
- $activityLogFields 메타데이터: Product, Order, Coupon, ShippingPolicy 모델
- ActivityLog 샘플 시더 (database/seeders/ActivityLogSampleSeeder.php)

### Changed

- ProductActivityLogListener: ActivityLogService 호출 → Log::channel('activity') 직접 호출로 전환
- 주문상세 로그 탭 레이아웃: log.description → log.localized_description
- 상품폼 로그 섹션 레이아웃: log.description → log.localized_description

## [0.12.6] - 2026-03-25

### Added

- OrderSeeder: 쿠폰 적용 주문 생성 — CouponIssue 테이블 조회 기반, 상품금액/주문금액 쿠폰 배분, promotions_applied_snapshot 스냅샷, 약 40% 주문에 쿠폰 적용
- OrderSeeder: 주문 삭제 시 쿠폰 발급 상태 자동 복원 (used → available)
- OrderSeeder: 배송비 쿠폰(shipping_fee) 적용 — 배송 레코드에 shipping_discount_amount 배분, 주문 합계에 배송 할인 반영
- OrderSeeder: 결제완료 이상 상태 쿠폰 적용 비율 80%로 상향, 쿠폰 우선 배정 상태를 먼저 생성하여 쿠폰 재고 확보
- OrderSeeder: payment_complete 비율 15→20%로 상향, 쿠폰 우선 상태의 비회원 비율 절반으로 축소

### Fixed

- OrderSeeder: 주문 합계 재계산 시 쿠폰별 할인 필드(product_coupon, order_coupon, coupon, code) 정확히 합산하도록 개선
- OrderSeeder: 주문 합계의 base_shipping_amount, shipping_discount_amount가 배송 레코드 실값을 반영하도록 수정
- OrderSeeder: orders.promotions_applied_snapshot 저장 누락 수정 — 부분취소 시 쿠폰 재적용 불가 문제 해결 (coupon_issue_ids, product_promotions, order_promotions 구조)
- OrderSeeder: 쿠폰 복원을 주문 삭제 전으로 이동 — order_id FK ON DELETE SET NULL로 인한 고아 쿠폰(status=used, order_id=null) 방지
- OrderAdjustmentService: 복원 쿠폰 정보에서 다국어 쿠폰명(JSON 배열)을 문자열로 변환하지 않아 React 렌더링 오류(#31) 발생 — getLocalizedName() 사용으로 수정

## [0.12.5] - 2026-03-25

### Fixed

- 시더/팩토리 유령 할인 제거 — 출처 없는 랜덤 할인이 환불 재계산 시 잘못된 discount_difference 유발 (OrderSeeder, OrderOptionFactory, OrderFactory)
- OrderSeeder: 결제 완료 주문의 mc_total_paid_amount가 항상 0이던 다중통화 불일치 수정

## [0.12.4] - 2026-03-25

### Added

- 사용자 주문 상품 선택 핸들러 추가 — toggleItemSelection, toggleSelectAllItems (userCancelOrderHandlers)
- initUserCancelItems: 선택된 상품(_local.selectedItemIds) 기반으로 취소 대상 필터링

### Fixed

- 사용자 주문 취소 모달이 열리지 않던 버그 수정 — modals 섹션 모달에 id 추가 및 openModal/closeModal 패턴으로 전환 (userCancelOrderHandlers, _modal_cancel.json)
- Upgrade_0_7_0: app(ModuleSettingsService::class) 직접 호출을 module_setting() 헬퍼로 교체

## [0.12.3] - 2026-03-24

### Fixed

- OrderResource(주문상세 API)에 can_cancel ability 누락 — 사용자 주문상세 화면에서 취소 버튼이 절대 표시되지 않던 버그 수정
  - OrderResource에 resolveAbilities() override 추가 (상태 + 환경설정 + 권한 복합 조건)
- Resource/Request에서 app(EcommerceSettingsService::class) 직접 호출을 module_setting() 헬퍼로 교체 (4개 파일)

### Added

- 주문상세 API abilities 테스트 3건 추가 (can_cancel true/false/권한 없음)

## [0.12.2] - 2026-03-24

### Fixed

- 주문 취소 시 환경설정(cancellable_statuses)을 참조하지 않고 하드코딩된 기본값만 사용하던 버그 수정
  - Admin/User CancelOrderRequest: EcommerceSettingsService에서 취소 가능 상태 조회
  - UserOrderListResource: can_cancel 판정에 환경설정 반영
  - OrderCancellationService: 상세 에러 메시지(현재 상태 + 취소 가능 상태 목록) 반환
- 프론트엔드 취소 핸들러에서 API 상세 에러 메시지를 토스트에 표시하도록 개선
- 주문 취소 시 취소 사유(reason) 미선택으로 검증 우회되던 버그 수정 (nullable → required)
- 취소 모달에서 422 validation 에러가 PG 에러 영역에 잘못 표시되던 버그 수정
  - 422 → 필드별 에러 표시, 기타 에러 → PG 에러 영역으로 분리
- CancelOrderRequest failedValidation에 필드별 에러(errors) 미포함 수정
- 취소 사유 필드 에러 메시지에 원시 필드명(reason) 노출 → 한국어 표시명(취소 사유) 적용

### Added

- Order 모델에 getCancelDeniedReason(), getCancellableStatuses() 헬퍼 메서드 추가
- 취소 불가 상세 에러 다국어 키 추가 (order_not_cancellable_detail, order_already_cancelled)
- 환경설정 기반 취소 가능 상태 검증 테스트 4건 추가
- 취소 사유 필수 검증 테스트 추가
- 취소 모달 validation_error_title 다국어 키 추가 (ko/en)

### Changed

- 취소 모달 환불 우선순위 라디오: 마일리지 사용 주문 조건 제거 → 항상 표시

## [0.12.1] - 2026-03-24

### Fixed

- 취소 모달 배송비 정책명이 번역 키 그대로 노출되던 버그 수정 (OrderAdjustmentService)
  - OrderShipping에 shippingPolicy 관계 추가 (누락)
  - buildFullCancelResult() / buildShippingDetails()에서 ShippingPolicy::getLocalizedName() 사용
  - order.json에 default_shipping 번역 키 추가 (폴백용)

## [0.12.0] - 2026-03-24

### Added

- 배송비 계산 확장 훅: `sirsoft-ecommerce.shipping.calculate_fee` — 외부 확장이 배송비 계산을 오버라이드 가능
- 프로모션 스냅샷 저장 훅: `sirsoft-ecommerce.calculation.filter_promotions_snapshot` — 주문 생성 시 스냅샷에 확장 데이터 주입 가능
- 프로모션 스냅샷 복원 훅: `sirsoft-ecommerce.adjustment.filter_restore_promotions` — 환불 재계산 시 스냅샷에서 확장 데이터 해석/복원 가능
- AppliedShippingPolicy DTO: `hookOverridden` 플래그 — 훅 오버라이드 배송비 식별용
- 스냅샷 기반 훅 오버라이드 배송비 보존: 환불 시 훅이 계산한 배송비를 스냅샷에서 복원 (훅 비활성 대응)
- PluginExtensibilityTest: 16개 테스트 (배송정책 6, 할인정책 7, 복합 시나리오 3)
- 관리자/사용자 취소 모달 환불 예상금액 UI 확장: 배송비 정책별 상세, 잔여 PG/마일리지 잔액, 복원 쿠폰 목록, 총 환불액(PG+마일리지+배송비)
- `changeRefundPriority` / `changeUserRefundPriority` 커스텀 핸들러: 환불 우선순위 변경 → debounce 재계산 연동
- `refund_priority` 파라미터를 환불 예상금액 API에 전달 (관리자/사용자 공통)
- mc_* 다중통화 환불 테스트 5건: 기본 변환, null 스냅샷, 마일리지 변환, 배송비 변환, previewArray 포함 확인
- 관리자 취소 모달 취소사유 라디오 7개 옵션 (CancelReasonTypeEnum 기반): order_mistake, changed_mind, reorder_other, delayed_delivery, product_info_different, admin_cancel, etc
- i18n 취소사유 키 중첩 구조 변경: `cancel_reason_type_label` + `reason.{type}` 형태 (ko/en)
- 스냅샷 기반 환불 재계산 테스트 84건 추가 (B-1~B-7):
  - OrderAdjustmentServiceTest +33건 (쿠폰 복원 감지, 환불 우선순위, 배송비 상세, mc_* 변환, 복합 시나리오)
  - OrderCancellationServiceTest +17건 (쿠폰 복원 실행, 환불 처리, mc_* 레코드, 스냅샷 갱신)
  - OrderCalculationServiceTest +11건 (스냅샷 모드 검증)
  - OrderCalculationServiceMultiCurrencyTest +5건 (스냅샷 환율 변환)
  - Admin/User Feature 테스트 +12건 (환불 예상 API, 쿠폰 복원 DB)
  - 프론트엔드 레이아웃 렌더링 테스트 +42건 (취소 모달 구조, 라디오, 조건부 렌더링)

### Fixed

- `buildFullCancelResult`에 mc_* 다통화 변환, 배송비 상세, 복원 쿠폰 정보, 잔여 잔액, 환불 우선순위 누락 수정
- `buildFullCancelResult`에서 OrderShipping 컬럼명 오류 수정 (`base_shipping_fee` → `base_shipping_amount`, `extra_shipping_fee` → `extra_shipping_amount`)

## [0.11.1] - 2026-03-24

### Added

- OrderShippingRepository: 배송 모델 Repository 인터페이스 + 구현체 신규 생성
- CouponIssueRepository: `update()`, `findByIds()`, `findByIdsWithRelations()` 메서드 추가
- SnapshotProduct, SnapshotProductOption DTO: 스냅샷 기반 환불 재계산용
- RefundPriorityEnum: 환불 우선순위 정의 (SHIPPING_DISCOUNT, ORDER_DISCOUNT, MILEAGE 등)
- CouponRestoreListener: 주문 취소 시 쿠폰 사용 상태 자동 복원
- OrderRefund 다통화 환불 컬럼 마이그레이션 (mc_refund_columns)
- 스냅샷 기반 환불 재계산 로직 (OrderCalculationService snapshot_mode, OrderAdjustmentService)
- 취소/환불 관련 예외 다국어 키 추가 (cancel_option_not_found, pg_refund_failed 등)
- 취소/환불 관련 Enum 다국어 라벨 추가 (CancelStatusEnum, RefundStatusEnum 등)
- 스냅샷 재계산 테스트 (SnapshotRecalculationTest), 쿠폰 복원 리스너 테스트 (CouponRestoreListenerTest)

### Fixed

- Repository 패턴 위반 수정: 4개 서비스 + 1개 리스너의 직접 Eloquent 쿼리 → Repository 인터페이스 주입
- CurrencyConversionService 예외 메시지 하드코딩 → `__()` 다국어 처리

### Changed

- OrderAdjustmentService: CouponIssue 직접 접근 → CouponIssueRepositoryInterface 주입
- OrderCancellationService: OrderShipping 직접 접근 → OrderShippingRepositoryInterface 주입
- OrderOptionService: OrderShipping 직접 접근 → OrderShippingRepositoryInterface 주입
- ShippingCarrierService: OrderShipping 직접 접근 → OrderShippingRepositoryInterface 주입
- CouponRestoreListener: CouponIssue 직접 접근 → CouponIssueRepositoryInterface 주입
- EcommerceServiceProvider: OrderShippingRepository 바인딩 등록

## [0.11.0] - 2026-03-24

### Added

- 주문 취소/환불 시스템: 관리자 전체취소·부분취소, 사용자 취소 요청, 환불 금액 산출
- 주문 취소 관련 모델 추가 (OrderCancel, OrderCancelOption, OrderRefund, OrderRefundOption)
- 주문 취소 Enum 추가 (CancelStatusEnum, CancelTypeEnum, CancelReasonTypeEnum, RefundStatusEnum, RefundMethodEnum 등)
- 주문 취소 서비스 추가 (OrderCancellationService, OrderAdjustmentService)
- 주문 취소 DTO 추가 (CancellationResult, CancellationAdjustment, OrderAdjustment, AdjustmentResult)
- 관리자 주문 취소 API 엔드포인트 (estimate-refund, cancel)
- 사용자 주문 취소 API 엔드포인트 (estimate-refund, cancel)
- 관리자 주문상세 취소 모달 레이아웃 (_modal_cancel_order.json)
- 사용자 마이페이지 주문 취소 모달 확장 (부분취소 지원)
- 주문 취소 프론트엔드 핸들러 (cancelOrderHandlers, userCancelOrderHandlers)
- 토스페이먼츠 환불 리스너 (PaymentRefundListener)
- 관리자 환경설정 주문취소 가능 상태 다중 선택 UI (_tab_order_settings.json)
- 관리자 주문상세 결제정보에 취소/환불 금액 표시 (_partial_payment_info.json)
- 주문관리 목록 주문자(회원) 검색 필터: SearchableDropdown 기반 UUID 검색
- 주문관리 DataGrid 주문자 클릭 시 회원/비회원 분기 검색 (회원: orderer_uuid, 비회원: search_keyword)
- 관리자 주문상세 일괄변경에서 주문취소 선택 시 PG 결제 취소 체크박스 인라인 표시

### Fixed

- 주문 취소 모달: 환불 예상금액 미표시 수정 (isolated 모달 스코프 상태 불일치 해결)
- 주문 취소 모달: 현재상태 컬럼에 enum 원시값 대신 다국어 라벨 표시
- 주문 취소 모달: PG 체크박스/라벨 클릭 연동 (htmlFor 추가)

### Changed

- 취소 상태 Enum 재설계: PENDING→REQUESTED, FAILED 제거 (2단계: 신청/완료)
- 환불 상태 Enum 재설계: 6단계 (신청/승인/처리중/보류/완료/반려), FAILED 제거
- Option 상태를 부모 상태와 동일하게 동기화
- OrderCancel 모델: isFailed() 메서드 제거
- OrderRefund 모델: isFailed()→isRejected() 변경
- PG 환불 실패 시 FAILED 상태 설정 대신 REQUESTED 유지 (에러 정보만 기록)
- OrderStatusEnum: 주문취소(CANCELLED) 상태 추가
- SequenceType: 취소(CANCEL), 환불(REFUND) 시퀀스 타입 추가
- Order 모델: 취소 관련 관계 및 메서드 추가 (cancels, refunds, isCancellable 등)
- OrderOption 모델: cancelled_quantity 컬럼 추가
- OrderProcessingService: 취소 처리 로직 연동
- 관리자 OrderController: 취소/환불 산출 액션 추가
- 사용자 OrderController: 취소 요청 액션 추가

## [0.10.6] - 2026-03-24

### Changed

- 상품목록 API: 비활성 옵션도 포함하여 로드 (관리자 화면에서 비활성 옵션 표시)
- 상품상세 API: `includeInactive` 파라미터 추가 (관리자 수정 화면에서 비활성 옵션 포함)
- 옵션 일괄 변경 `option_name` 검증 규칙: `string` → `array` (다국어 배열 지원)
- 옵션 일괄 변경 `list_price` 검증 규칙 추가
- 일괄 변경 확인 모달: 처리중 상태 표시 (spinner + 버튼 비활성화)
- 상품 인라인 수정 필드 추적 확장 (`modifiedProductFields` 추가)
- 상품목록 DataGrid 선택 개수 표시 비활성화
- 주문목록 배송국가: 국가 코드(KR) → 다국어 국가명(한국) 표시 (OrderListResource, DataGrid)

### Fixed

- 시더(OrderAddressFactory)에서 `recipient_country_code` 기본값 누락으로 주문목록 배송국가 미표시 문제 수정
- 관리자 주문 수정 시 배송국가(`recipient_country_code`) 변경 불가 문제 수정 (OrderService, UpdateOrderRequest)
- 상품목록 리소스: `options` relation 로드 시 `option_stock_sum`, `options_count`가 빈 값으로 표시되는 문제 수정 (`relationLoaded` 분기 추가)
- 상품옵션 일괄 변경 후 상품 재고(stock_quantity)가 동기화되지 않는 문제 수정 (`ProductOptionService::bulkUpdate`에 `syncStockFromOptions` 호출 추가)
- 옵션 재고 합산 시 비활성 옵션이 포함되는 문제 수정 (프론트엔드 `updateOptionField`, `calculateTotalOptionStock` 핸들러에 `is_active` 필터 추가)
- 일괄 변경 확인 모달: 인라인 수정 시 `{method}` 플레이스홀더가 리터럴로 표시되는 문제 수정 (인라인 전용 다국어 키 분리)

## [0.10.5] - 2026-03-23

### Changed

- 상품수정 화면: 일부 미구현 탭 숨김 처리

### Fixed

- UserAddressSeeder: name 필드에 다국어 배열 대입으로 인한 "Array to string conversion" 오류 수정 (단순 문자열로 변경)

## [0.10.4] - 2026-03-23

### Changed

- 주문상세 활동 로그: Badge → 아바타 원형+이름 스타일로 변경, 처리자 클릭 시 ActionMenu(회원정보 보기) 추가 (PC+모바일)
- 상품수정 활동 로그: "작업" 컬럼 제거, 처리자 클릭 시 ActionMenu(회원정보 보기) 추가 (PC+모바일)
- 주문상세/상품수정 활동 로그 UI 일관성 통일

### Fixed

- 상품수정 활동 로그: Select $event → $event.target.value, refreshDataSource → refetchDataSource, Pagination type:pageChange → event:onPageChange, 빈 상태 조건 경로 수정, 데이터소스 sort_order/per_page 파라미터 추가
- 주문상세 활동 로그: 동일 버그 6건 수정 (주문상세와 상품수정 패턴 통일)

## [0.10.3] - 2026-03-23

### Added

- 주문목록 주문상품 컬럼: 대표상품 썸네일, "외 X건" 표시, 상품수정 링크(새 창)
- OrderListResource: first_option에 product_code 필드 추가

## [0.10.2] - 2026-03-23

### Added

- 주문상세 상품 이미지/상품명 클릭 시 상품수정 페이지로 이동 (새 창)

## [0.10.1] - 2026-03-23

### Changed

- API Resource: user.id → user.uuid 전환 (OrderResource, OrderListResource, CouponResource, CouponIssueResource, ProductReviewResource, ProductLogResource, UserAddressResource, ExtraFeeTemplateResource)
- user_id FK → user.uuid 전환 (CouponIssueResource, ProductReviewResource, UserAddressResource)
- ExtraFeeTemplate: created_by → creator.uuid 전환 (creator 관계 활용)
- 쿠폰 관리 등록자 컬럼: eager-load에 uuid 컬럼 추가 (CouponRepository, ProductLogRepository)
- 관리자 레이아웃: user.id → user.uuid 참조 전환 (4개 파일)
- orderHandlers.ts: localStorage 키에 user.uuid 사용
- FormRequest: 정수 검증 → UUID 검증 전환 (CouponListRequest, CouponIssuesListRequest)
- MergeCartOnLoginListener: Activity Log user_id → uuid 전환

## [0.10.0] - 2026-03-22

### Added

- 주문상세 이메일 발송 기능 — `POST /orders/{order}/send-email` API + 모달 UI 연동
- SendOrderEmailRequest FormRequest — 이메일/메시지 검증
- OrderService.sendEmail() — `Mail::raw()` + `HookManager::doAction()` 패턴 (코어 `sendTestMail()` 참조)
- 이메일 발송 다국어 메시지 (ko/en messages + validation + 프론트엔드 toast)

### Fixed

- 이메일 모달 보내기 버튼 무반응 — `actions` 배열 추가 (sequence: setState → apiCall → toast/closeModal)
- 이메일 모달 Textarea 높이 미적용 — `min-h-[100px]` (빌드 CSS 미존재) → `min-h-32` (safelist 존재)
- 이메일 모달 API 호출 시 `/undefined` 요청 — `params.endpoint` → `action.target` 수정
- 이메일 발송 실패 시 에러 원인 미표시 — catch 블록에 `$e->getMessage()` 상세 포함

### Changed

- 이메일 모달 `_global.isSending` → `_local.isSending` (모달 스코프 내 상태 관리)

## [0.9.3] - 2026-03-22

### Added

- 비회원 주문 Factory/Seeder 추가 — `OrderFactory::guest()` 상태, `OrderSeeder`에 비회원 주문 생성 로직
- OrderOptionResource에 `list_price`, `list_price_formatted`, `final_amount`, `final_amount_formatted` 필드 추가
- 다국어 키 추가 — `phone_or_tel_hint` (ko/en)

### Fixed

- 주문상세 저장 버튼 미동작 — apiCall `params.endpoint` → `target` 속성, `auth_mode: "required"` 추가, onSuccess/onError 콜백 위치 수정
- 주문상세 수취인 정보 미표시 — `orderDetailHandlers.ts` 데이터 접근 경로 수정 (`_context.data.data` 우선 접근)
- 주문상세 수취인 정보 수정 값 미반영 — 폼 자동 바인딩 (`dataKey: "form"` + child `name`) 패턴 적용
- Validation error 적색 테두리 미표시 — `.input` 클래스에 border 없음, `border border-red-500 dark:border-red-500` 으로 수정

### Changed

- OrderService.update() 수취인 필드 저장 처리 추가 — shippingAddress 관계 업데이트
- 주문상세 폼 데이터 바인딩을 `initLocal` 패턴으로 변경 — 커스텀 핸들러(`initOrderDetailForm`) → 데이터소스 `initLocal`
- UpdateOrderRequest validation 강화 — `recipient_name`, `recipient_zipcode`, `recipient_detail_address` 필수, `recipient_phone`/`recipient_tel` 택1 필수 (`required_without`)
- CreateOrderRequest validation 통일 — `recipient_phone` required → `required_without:recipient_tel`, `address_detail` nullable → required
- 배송메시지 Textarea `textarea-sm` (80px), 관리자메모 Textarea `textarea-md` (128px) 크기 적용
- OrderOptionResource `mc_subtotal_discount_amount` 제거 — 존재하지 않는 DB 필드 참조

## [0.9.2] - 2026-03-22

### Added

- OrderResource에 `total_quantity`, `total_list_price`, `total_list_price_formatted` 필드 추가 — 합계행 백엔드 필드 기반 표시
- 주문상세 합계행(footerCells) 및 개별행에 다통화(보조 통화) 표시 — iteration 패턴으로 판매가/소계 하단에 표시

### Fixed

- 주문상세 합계행 판매가/수량/할인/적립 합계가 실제 데이터와 불일치 — computed 전면 제거 후 백엔드 필드로 대체
- 할인 0원에 마이너스 부호 표시(-0원) — 백엔드 `total_discount_amount > 0` 조건으로 수정
- 개별행 실구매가격이 할인 전 금액 표시 — `subtotal_price_formatted` → `final_amount_formatted`(할인 후)로 변경
- OrderOptionResource의 `mc_subtotal_discount_amount` 버그 — 잘못된 필드(`mc_coupon_discount_amount`) 참조 수정

### Changed

- 관리자 주문상세 라우트를 ID 기준에서 order_number 기준으로 변경 — URL이 `/admin/ecommerce/orders/ORD-xxx` 형태로 표시 (상품 수정의 product_code 패턴과 동일)
- Order 모델에 `resolveRouteBinding()` 추가 — 숫자는 ID, 문자열은 order_number로 자동 판별 (하위 호환)
- API 라우트 `whereNumber('order')` 제약 제거 — order_number(문자열) 수용
- 주문 목록/상세/리뷰 레이아웃 및 커스텀 핸들러에서 navigate 경로를 order_number 기반으로 변경

## [0.9.1] - 2026-03-21

### Fixed

- 상품리뷰 정렬 기준 validation 수정 — sort 파라미터 `latest,oldest` → `created_at_desc,created_at_asc`로 통일 (PublicReviewListRequest)

## [0.9.0] - 2026-03-21

### Added

- 주문상세 수량 단위 상태 변경 기능 — 주문상품옵션의 일부 수량만 선택하여 상태 변경 (예: 3개 중 2개만 배송중으로 변경)
- 주문상품옵션 병합(Merge) 로직 — 분할 후 남은 수량을 같은 상태로 변경 시 자동 병합 (cascade 삭제 안전 처리 포함)
- 일괄변경 확인 모달에 상품 목록 표시 — 썸네일, 상품명/옵션/SKU, 판매가, 수량 편집 Input, 현재상태 Badge
- updateChangeQuantityHandler 핸들러 추가 — 모달 내 수량 편집 시 1~최대수량 클램핑
- 다국어 키 5종 추가 — product_col, price_col, change_quantity_col, current_status_col, quantity_note (ko/en)
- 백엔드 테스트 15건 추가 — 금액 분할 정확성, 병합 로직, cascade 안전성, 스냅샷 보존, 순차 분할
- 프론트엔드 핸들러 테스트 12건 추가 — bulkConfirmItems 구성, 수량 클램핑, changeQuantities 반영

### Fixed

- 금액 분할 계산 2배 오류 수정 — replicate() 후 원본+복제본 금액이 동일하여 ratio 적용 시 2배로 계산되던 버그 (원본 금액 사전 캡처 방식으로 수정)

### Changed

- buildOrderDetailBulkConfirmDataHandler 개선 — 선택 상품의 상세 정보(bulkConfirmItems)와 수량 맵(changeQuantities)을 _local에 저장 후 모달 열기
- 일괄변경 확인 모달 크기 lg로 확대 및 상품 목록 테이블 추가

## [0.8.3] - 2026-03-21

### Fixed

- 주문목록/주문상세 모달 9개 규정 미준수 전면 수정 — btn 클래스 → 명시적 Tailwind 클래스, 푸터 gap/mt 규정 준수, Icon name 규정 준수 (pen-to-square, trash)
- 모달 onError 핸들러 배열 형식 수정 — 객체 → 배열, error.message → $error.message (preset_save, preset_edit, preset_delete_confirm)
- 모달 액션 버튼 스피너/비활성화 처리 추가 — _global.isProcessing/isSaving/isDeleting/isDownloading/isSending 상태 기반 로딩 UI
- Excel 다운로드 모달 meta.is_partial 누락 추가, 불필요한 props.id/closeOnOutsideClick/p-6 래퍼 제거

## [0.8.2] - 2026-03-20

### Changed

- 주문 일괄변경 드롭다운에서 pending_order(주문대기) 상태 제외 — 주문목록/주문상세 레이아웃 + 백엔드 FormRequest 검증 (BulkUpdateOrdersRequest)
- 주문상세 일괄변경 드롭다운에 누락된 상태 추가 — pending_payment, shipping_hold, confirmed, cancelled (pending_order 제외 전체 상태 표시)
- 주문 일괄변경 드롭다운 너비 확대(w-36 → w-44) — 주문목록/주문상세 공통, 한글 상태명 잘림 방지

### Fixed

- 주문상세 일괄변경 드롭다운 pending_payment 다국어 키 오류 수정 — order_status_options.pending_payment → order_status_options.payment_pending (enum 값과 다국어 키 불일치)

## [0.8.1] - 2026-03-20

### Added

- OrderStatusEnum 배송 정보 필수 메서드 추가 — requiresShippingInfo(), shippingInfoRequiredStatuses(), shippingInfoRequiredValues()
- 주문 일괄변경 배송 상태(shipping_ready/shipping/delivered) 선택 시 택배사/송장번호 필수 검증 — 프론트엔드 핸들러(toast 경고), 레이아웃 모달(버튼 비활성화 + 경고 메시지), 백엔드 FormRequest 3계층 검증
- BulkUpdateOrdersRequest carrier_id 존재 검증 추가 — Rule::exists(ShippingCarrier::class, 'id')
- 주문상세/주문관리 일괄변경 확인 모달에 배송정보 누락 경고 메시지 추가 (적색 텍스트)
- 다국어 키 추가 — carrier_required (ko/en), tracking_number_required (ko/en)
- OrderOptionBulkStatusTest 배송정보 필수 검증 테스트 3건 추가

### Fixed

- processOrderDetailBulkChangeHandler/saveAdminMemoHandler API 호출 수정 — G7Core.api.call() → G7Core.api.patch() (존재하지 않는 메서드 호출 오류)
- processOrderDetailBulkChangeHandler 모달/디스패치 호출 수정 — G7Core.modal?.close?.() / G7Core.dispatch?.() 패턴 적용
- processOrderDetailBulkChangeHandler 상태 조회 방식 변경 — getLocal() → action.params 직접 전달 (stale 상태 방지)

### Changed

- BulkUpdateOrdersRequest/BulkChangeOrderOptionStatusRequest 배송 상태 검증을 하드코딩 → OrderStatusEnum::shippingInfoRequiredValues() 메서드로 리팩토링

## [0.8.0] - 2026-03-20

### Changed

- OrderOptionStatusEnum 제거 및 OrderStatusEnum으로 통일 — 주문옵션 상태를 주문 상태와 동일한 Enum으로 관리
- 주문 일괄변경(bulkUpdate) 시 주문상품옵션 상태도 동일하게 일괄 변경 — 기존에는 orders 테이블만 업데이트

### Removed

- OrderOptionStatusEnum 삭제 — OrderStatusEnum으로 완전 대체 (클레임 상태는 별도 모듈에서 지원 예정)
- 다국어 order_option_status 섹션 제거 — order_status 키 공용 사용

### Added

- OrderRepository::bulkUpdateOptionStatus() 메서드 추가 — 주문 ID 배열 기준 옵션 상태 일괄 변경
- OrderStatusEnum::isShipped() / shippedStatuses() 메서드 추가 — 발송 이후 상태 판별
- OrderOption::shipped_quantity 접근자 추가 — 자신 및 분할 자식 옵션의 발송 수량 합산
- 업그레이드 스크립트 Upgrade_0_8_0 — option_status DB 값 변환 (pending→pending_order, shipped→shipping, 클레임→cancelled)

## [0.7.6] - 2026-03-20

### Changed

- 주문상세 주문상품 레이아웃을 CSS Grid → DataGrid 컴포넌트로 전환 — Tailwind purge 미적용 문제 해결, selectable/footerCells/footerCardChildren 활용
- 주문상세 합계 행을 DataGrid footerCells로 통합 — 기존 수동 CSS Grid 합계 영역 제거
- 주문상세 일괄변경 영역 스타일을 주문관리 목록과 동일하게 통일 — composite Select → basic Select, 배경/테두리/버튼 스타일 일치
- 주문상세 일괄변경 버튼을 buildOrderDetailBulkConfirmData 핸들러로 변경 — 검증(상태 미선택/상품 미선택) 후 모달 오픈 패턴 적용
- 주문상세 DataGrid selectedCountText를 빈 문자열로 설정 — 기본 "N개 선택됨" 텍스트 비활성화

### Fixed

- 주문상세 0원 항목 표시 — 할인/과세/부가세/쿠폰/할인코드/마일리지/예치금 금액이 0인 항목에 `condition` 속성 추가하여 숨김 처리
- 주문상세 합계 computed 문자열 연결 버그 — API 금액이 문자열(`"4000.00"`)로 반환되어 `reduce` 합산 시 문자열 연결 발생 → `Number()` 래핑으로 수정
- 주문상세 0원 금액 마이너스 표기 방지 — 금액 0원 항목이 `-0원`으로 표시되던 문제, condition 기반 숨김으로 해결
- 주문상세 일괄변경 버튼 클릭 무응답 — openModal 직접 호출 → buildOrderDetailBulkConfirmData 검증 핸들러로 교체
- 주문상세 일괄변경 확인 모달 closeModal 패턴 수정 — `params.target` → `target` 직접 지정
- 주문상세 DataGrid 셀 줄바꿈 방지 — 상품정보/헤더 제외 모든 셀과 footer에 whitespace-nowrap 적용

### Added

- 다국어 키 추가 — `no_products` (주문상품 없음) ko/en
- buildOrderDetailBulkConfirmDataHandler 핸들러 추가 — 상태/상품 미선택 시 경고 토스트, 검증 통과 시 확인 모달 오픈
- 운송사/송장번호 입력 필드 조건부 표시 — 배송 관련 상태(shipping_ready/shipping/delivered) 선택 시에만 표시

## [0.7.5] - 2026-03-20

### Fixed

- 주문상세 헤더 "상태:" 텍스트 누락 — Badge 앞에 상태 라벨 Span 추가
- 주문상세 결제정보 금액 경로 오류 — `payment.*` 주문레벨 금액을 `order.data.*` 경로로 수정
- 주문상세 처리로그 더미 데이터 표시 — `_local.dummyLogs` → `order_logs` API 데이터소스 연동
- 주문상세 일괄변경 핸들러 변수명 불일치 — `batchCarrier` → `batchCarrierId`로 통일
- OrderPaymentResource 필드명 5종 DB 컬럼 불일치 수정 (pg_tid→transaction_id, paid_amount→paid_amount_local, vbank_num_masked→vbank_number, vbank_due_date→vbank_due_at, cash_receipt_number→cash_receipt_identifier)

### Added

- 주문 처리 로그 API 엔드포인트 추가 (`GET /admin/orders/{id}/logs`) — ActivityLogService 연동, 페이지네이션/정렬 지원
- Order 모델 payments HasMany 관계 추가 (기존 payment HasOne 유지)
- OrderResource 플래튼 필드 추가 — 주문자(orderer_name/phone/email), 수취인(recipient_name/phone/zipcode/address), user_login_id, payments(복수), 세금(total_vat_amount)
- OrderPaymentResource 추가 필드 — payment_type_label, payment_number, account_info, requested_at_formatted, due_date_formatted, vat_amount_formatted, card_approval_number, is_interest_free
- OrderOptionResource option_name alias 추가
- OrderService 활동 로그 기록 — update/bulkUpdate 시 ActivityLogService 연동
- total_vat_amount 컬럼 마이그레이션 추가
- 처리로그 정렬/페이지당 옵션 변경 시 서버사이드 refetch 지원
- 다국어 키 추가 — 처리로그 정렬(date_asc), 페이지당 옵션(per_page_option), 로그 메시지 5종 (ko/en)
- 백엔드 테스트 3종 추가 (logs 엔드포인트 조회/페이지네이션/인증)
- 프론트엔드 레이아웃 테스트 갱신 — order_logs/active_carriers 데이터소스 검증, 금액 경로 검증, batchCarrierId 반영

## [0.7.4] - 2026-03-19

### Fixed

- 주문 목록 필터 5종 미작동 수정 — Repository 필터 키를 FormRequest 키와 정렬 (shipping_type, min_amount, max_amount, country_codes, order_device)
- 주문 목록 페이지네이션/총건수 미작동 — `orders?.data?.current_page` → `orders?.data?.pagination?.current_page` 경로 수정
- 배송국가 국기 미표시 — `row.country_code` → `row.address?.recipient_country_code` 경로 수정
- OrderRepositoryTest 가격 필터 테스트 OLD 키 수정 (min_price → min_amount, max_price → max_amount)

### Added

- 구매환경(디바이스) 컬럼 추가 — OrderListResource에 `order_device`, `order_device_label` 필드 추가 + DataGrid 컬럼
- 첫구매 여부 표시 — OrderListResource에 `is_first_order` 필드 추가 + orderer 컬럼에 첫구매 뱃지
- 상품카테고리 4단계 계층 필터 — categories API 데이터소스 + computed 옵션 바인딩
- 배송국가 필터 동적화 — 하드코딩 6개국 → ecommerce_settings API 기반 iteration 렌더링
- 결제수단 필터 동적화 — 하드코딩 6종 → ecommerce_settings API 기반 iteration 렌더링
- 다국어 키 추가 — `column.device` (구매환경), `column.first_order` (첫구매) ko/en
- 백엔드 테스트 7종 추가 — 금액/국가/디바이스/배송방법 필터 + 페이지네이션 구조 + 신규 필드 확인
- 프론트엔드 레이아웃 테스트 29종 — 페이지네이션 경로, 필터 키, 컬럼, ActionMenu, 날짜 포맷, DataGrid 설정, 동적 필터, 클레임 제거 검증

### Changed

- 페이지 헤더 아이콘 제거 (디자인 통일)
- 기간 버튼 CSS `btn btn-xs` → `btn-date` 패턴 통일 (상품관리와 동일)
- NO 컬럼 기본 숨김 (visibleColumns에서 제거)
- 주문자명 클릭 시 직접 navigate 제거 — 셀 레벨 ActionMenu로 변경 (쿠폰관리와 동일 패턴)
- 주문일시 날짜 표기 ISO → formatted 형식으로 수정 (`ordered_at` → `ordered_at_formatted`)
- DataGrid 컬럼선택기 활성화 (`showColumnSelector: true`) + `responsiveBreakpoint: 768` 모바일 대응 추가

### Removed

- 클레임상태 필터 전체 제거 (state, data_sources params, init_actions, filter UI)

## [0.7.3] - 2026-03-18

### Added

- `WishlistResource` + `WishlistCollection` 신규 생성 — 위시리스트 API에 표준 ResourceCollection 패턴 적용
- `EcommerceSettingsService::getPublicPaymentSettings()` 메서드 추가 — 은행명 매핑 로직을 Service로 이동

### Changed

- Public 컨트롤러 4개 `ResponseHelper` 패턴 일관성 수정 — `moduleSuccess`/`moduleError` 통일
  - `WishlistController`: 이중 번역 버그(`__()` + `success()`) 7곳 수정
  - `CartController`: `success('sirsoft-ecommerce::...')` → `moduleSuccess()` 10곳 수정
  - `CheckoutController`: success/error 패턴 혼재 6곳 수정
- `WishlistController::index()` 인라인 `through()` 변환 → `WishlistCollection` 적용
- `EcommerceSettingsController::payment()` 은행명 매핑 로직 → `EcommerceSettingsService`로 이동
- `CartController`: `issueCartKey()`, `count()`에 `logApiUsage` 추가
- `EcommerceSettingsController`: 3개 메서드에 `logApiUsage` 추가
- `CheckoutController`: PHPDoc `@param` 타입 3곳 실제 FormRequest 타입으로 수정
- `CategoryImageController::download()` 리턴 타입 선언 추가

## [0.7.2] - 2026-03-18

### Fixed

- Public 상품 목록 API에 `ProductCollection` 패턴 적용 — 페이지네이션/abilities 포함 응답 구조 정상화
- `ProductCollection` 콜백에서 `toArray()` → `resolve()` 변경 — 중첩 Resource(MissingValue) 안전 해석

### Changed

- `TestingSeeder`에 `guest` 역할 및 사용자 타입 권한 추가 — Public API 테스트에서 PermissionMiddleware 통과 보장

## [0.7.1] - 2026-03-18

### Added

- SEO 설정 변경 리스너 (`SeoSettingsCacheListener`) — 이커머스 모듈 SEO 설정 변경 시 영향받는 레이아웃 캐시를 선별적으로 무효화

### Changed

- `SeoProductCacheListener` 보강 — 상품 수정 시 `home`/`search/index` 캐시 무효화 추가, 생성/수정 시 상세 페이지 캐시 즉시 재생성
- `SeoCategoryCacheListener` 보강 — `search/index` 캐시 무효화 추가
- 배송지 `name` 필드를 다국어 JSON에서 단순 문자열로 변환 — 마이그레이션, 모델, 팩토리, 리포지토리, 서비스, FormRequest, 테스트 일괄 수정

## [0.7.0] - 2026-03-17

### Changed

- SEO 설정 중 코어 범위 항목을 코어 환경설정으로 이관 (`seo_user_agents` → 코어 `seo.bot_user_agents`)
- 이커머스 SEO 탭에서 메인화면 관련 설정 제거 (`meta_main_title`, `meta_main_description`, `seo_site_main`)
- 이커머스 SEO 탭에서 User-Agent 관리 섹션 제거 (코어로 이관)

### Added

- 업그레이드 스텝 (`Upgrade_0_7_0`) — 기존 사용자 정의 User-Agent를 코어로 자동 이관

### Removed

- `defaults.json`에서 `seo_user_agents`, `seo_site_main`, `meta_main_title`, `meta_main_description` 필드 제거
- `StoreEcommerceSettingsRequest`에서 제거된 필드의 검증 규칙 삭제
- SEO 탭 레이아웃에서 메인화면 메타 아코디언, 사이트 메인 체크박스, User-Agent 관리 섹션 제거

## [0.5.5] - 2026-03-18

### Added

- 상품 리뷰 기능 구현
  - DB: 리뷰 테이블, 리뷰 이미지 테이블 생성. 답변 수정일 컬럼 추가
  - 모델/서비스: 리뷰 작성 가능 여부 확인, 리뷰 생성·삭제, 상태 변경, 판매자 답변 등록·수정·삭제, 이미��� 업로드·삭제
  - 관리자 API: 리뷰 목록 조회(기간·별점·상태·포토리뷰·답변 여부 필터), 상태 변경, 삭제, 일괄 처리, 판매자 답변 CRUD
  - 유저 API: 리뷰 작성 가능 여부 확인, 리뷰 작성·삭제, 이미지 업로드·삭제 (최대 5장)
  - 공개 API: 상품별 리뷰 목록 (별점 통계, 옵션 필터 포함)
  - 관리자 레이아웃: 리뷰 목록 화면 (검색 필터, 답변 모달, 이미지 미리보기 모달, 일괄 처리)
  - 관리자 설정: 리뷰 작성 기한, 포토리뷰 최대 장수 설정 탭 추가
  - 권한 및 메뉴: 관리자 사이드바 "상품 리뷰 관리" 메뉴 등록
  - 다국어: 리뷰 관련 다국어 파일 추가 (한국어/영어)
  - 샘플 시더: 별점 분포·답변·포토리뷰·답변 수정일 랜덤 생성
  - 테스트: 관리자·유저·이미지 Feature 테스트 57개, 레이아웃 렌더링 테스트 94개

- 상품 목록 API에 평균 별점, 리뷰수 필드 추가

### Fixed

- 리뷰 작성 가능 여부 확인 시 구매확정 상태 비교가 항상 실패하던 버그 수정
- 리뷰 저장 시 옵션 스냅샷 배열 직렬화 오류 수정
- 리뷰 이미지 업로드 검증 실패 시 응답 코드가 400으로 반환되던 버그 수정 (422으로 수정)
- 리뷰 이미지 API 응답에 리뷰 ID 누락 수정

## [0.5.4] - 2026-03-17

### Fixed

- 주문 상세 SMS/이메일 발송 버튼 openModal 핸들러 포맷 수정 (`params.target` → `target`)

## [0.5.3] - 2026-03-16

### Fixed

- 쿠폰 리스트 DataGrid `cellChildren` 액션에서 등록자 필터 검색 시 SearchableDropdown에 선택값이 표시되지 않는 버그 수정 — `setState(target: "local")`가 globalStateUpdater 경로로 실행되어 Form stale 값에 덮어쓰이는 문제 (템플릿 DataGrid componentContext 수정으로 해결)
- CouponResource에 카테고리 로케일 브레드크럼 경로 표시 추가
- 쿠폰 1인당 발급 제한 `per_user_limit` null 오류 수정 — nullable 처리 및 검증 로직 보완
- 쿠폰 할인율 검증 수정 — 정률 할인 시 100% 초과 방지, 정액 할인 시 할인율 검증 제거
- 쿠폰 유효기간/발급기간 타임존 변환 구현 — `TimezoneHelper`를 통한 사용자 타임존 ↔ UTC 변환
- 쿠폰 발급기간 `datetime-local` 입력 변환 및 `CouponResource` 타임존 출력 수정
- 쿠폰 폼 레이아웃에서 `apiCall` 호출 시 `auth_mode` 누락 수정

### Changed

- 쿠폰 폼 상품 검색을 무한스크롤 방식으로 개선
- 쿠폰 폼 레이아웃에서 사용 조건 Partial 분리
- 마이그레이션 통합 — 증분 마이그레이션을 테이블당 1개 create 마이그레이션으로 정리
- 시더 디렉토리 분리 — 샘플 시더를 `Sample/` 하위로 이동
- 라이선스 프로그램 명칭 정비
- 쿠폰 폼 하단 버튼 영역 제거 — 상단 PageHeader 액션으로 통일

## [0.5.1] - 2026-03-11

### Added

- 주문 취소 API 엔드포인트 추가 (`POST /user/orders/{id}/cancel`) + `user-orders.cancel` 권한 미들웨어 적용
- `CancelOrderRequest` — 주문 취소 요청 검증 (소유자 확인, 취소 가능 상태 검증)
- `UserOrderListResource` — `abilities` 키 추가 (`can_view`, `can_cancel` — 상태+권한 기반)
- `UserOrderCollection` — `abilities` 키 추가 (`can_create` — 권한 기반)
- `UserAddressResource` — `abilities` 키 추가 (`can_update`, `can_delete`, `can_set_default`)
- `UserAddressCollection` — `abilities` 키 추가 (`can_create`)
- `EcommerceUserAbilitiesTest` — 주문/배송지 abilities 및 주문 취소 API 테스트 13개
- manifest에 license 필드 및 LICENSE 파일 추가

## [0.5.0] - 2026-03-11

### Added

- 사용자 권한 3개 추가 (블랙컨슈머 차단용)
  - `sirsoft-ecommerce.user-products.read`: 상품 조회 권한
  - `sirsoft-ecommerce.user-orders.create`: 주문하기 권한
  - `sirsoft-ecommerce.user-orders.cancel`: 주문 취소 권한
- 상품 조회 라우트에 `optional.sanctum` + `permission:user` 미들웨어 적용
- 주문 생성 라우트에 `permission:user` 미들웨어 적용
- 주문 배송지 변경 API (`PUT /user/orders/{id}/shipping-address`)
- 주문 상세 배송지 변경 모달 (주문상세 화면)
- 배송지명 중복 덮어쓰기 확인 모달
- 업그레이드 스크립트 `Upgrade_0_5_0.php` (권한 생성 + 기존 역할 할당)
- 업그레이드 스크립트 `Upgrade_0_6_0.php` (배송지명 컬럼 검증 + 캐시 클리어)
- 사용자 권한 미들웨어 테스트 `EcommerceUserPermissionTest` (7개)

### Changed

- 배송지명(`name`) 필드 다국어 JSON → 단순 string 변환 (마이그레이션 포함)

### Fixed

- 마이페이지 배송지 관리 API 엔드포인트 및 필드명 불일치 수정
- 체크아웃 배송지 모달 필드명 불일치 수정
- 무통장입금(dbank) 주문 시 결제수단이 'card'로 잘못 전송되던 버그 수정
- 도서산간 추가배송비 제주도 누락 수정
- 배송지 변경 시 배송비 미재계산 수정
- 배송지 모달 폼 전송 실패 수정
- 주문 완료 후 장바구니 아이템이 삭제되지 않던 버그 수정 (재고 차감 타이밍과 동기화)

## [0.4.2] - 2026-03-10

### Changed

- 권한 카테고리에 `resource_route_key`, `owner_key` 스코프 메타데이터 추가 (products, orders, brands, promotion-coupon, shipping-policies)
- 라우트 미들웨어 `except:owner:*` 옵션 제거 (scope_type 데이터 기반 시스템으로 전환)
- Repository 목록 조회에 `PermissionHelper::applyPermissionScope()` 적용 (Product, Order, Brand, ShippingPolicy, Coupon)
- `PermissionBypassable` 인터페이스 및 `getBypassUserId()` 제거 (Product, Order, Brand, ShippingPolicy, Coupon 등 모델)

## [0.4.1] - 2026-03-08

### Changed

- API 리소스 권한 플래그 키 `permissions` → `abilities`로 변경 (코어 표준화)
- `permissionMap()` → `abilityMap()` 메서드명 변경 (11개 리소스)
- `BrandCollection`, `CategoryCollection`, `ShippingCarrierCollection`에 `HasAbilityCheck` 트레이트 적용
- 컬렉션 레벨 권한 응답 키를 `abilities`로 통일
- 관리자 레이아웃 JSON의 `permissions.can_*` 바인딩을 `abilities.can_*`로 변경

## [0.4.0] - 2026-03-06

### Changed
- 메일 템플릿 목록 API에 검색/필터/페이지네이션 지원 추가
- 메일 템플릿 편집 모달 UX 개선 (blur_until_loaded, sticky footer, 함수형 body)
- `getDefaultTemplateData()`를 Controller에서 Service로 이동 (Service-Repository 패턴 준수)
- 메일 템플릿 탭 UI를 코어 환경설정과 동일한 구조로 변경

### Added
- 메일 템플릿 미리보기(preview) API 엔드포인트 추가
- 메일 템플릿 검색 기능 (제목/본문/전체)
- 페이지당 항목 수 선택 및 페이지네이션
- 메일 템플릿 관련 다국어 키 추가 (검색/필터/빈 상태/편집 모달)

## [0.3.1] - 2026-03-06

### Fixed
- `CategoryService::deleteCategory()` — 카테고리 삭제 시 이미지(`images()`) 명시적 삭제 추가 (DB CASCADE 의존 제거)
- `OrderService::delete()` — 주문 삭제 시 관계 레코드 5건 명시적 삭제 추가 (`taxInvoices`, `shippings`, `addresses`, `payment`, `options`)

## [0.3.0] - 2026-03-06

### Added
- 사용자 쿠폰 다운로드 API (`UserCouponController`) — 다운로드 가능 쿠폰 목록 조회/다운로드
- 상품별 다운로드 가능 쿠폰 공개 API (`PublicCouponController`) — 비로그인 사용자도 조회 가능
- `UserCouponService` — 쿠폰 다운로드 비즈니스 로직 (중복 다운로드 방지, 수량 검증)
- `CouponRepository`, `CouponIssueRepository` — 쿠폰 데이터 접근 계층
- `DownloadCouponRequest` — 쿠폰 다운로드 요청 검증
- 쿠폰 다운로드 관련 다국어 메시지 (ko/en `messages.php`, `validation.php`)
- 사용자 쿠폰 다운로드 Feature 테스트 14건 (UserCouponControllerTest + PublicCouponControllerTest)
- DB 기반 메일 템플릿 시스템 (5종: 주문 확인, 배송 시작, 구매 확정, 주문 취소, 관리자 신규 주문)
- `ecommerce_mail_templates` 테이블, EcommerceMailTemplate 모델/서비스/리포지토리/컨트롤러
- 메일 템플릿 관리 API 및 환경설정 UI
- 업그레이드 스텝 (`Upgrade_0_3_0`) — 기존 설치에 메일 템플릿 초기 시딩
- `getSeeders()`에 `EcommerceMailTemplateSeeder` 추가

## [0.2.1] - 2026-03-03

### Added
- 도서산간 추가배송비 템플릿 시더 (`ExtraFeeTemplateSeeder`) — 34건 도서산간 우편번호 데이터
- 도서산간 템플릿 모달 CRUD 기능 (생성/수정/삭제/일괄삭제/검색)
- 템플릿 모달에서 전체 적용/선택 적용 기능
- 백엔드 다국어 메시지 (`extra_fee_template` 섹션) 추가 (ko/en)

### Changed
- 도서산간 템플릿 모달 크기 `md` → `xl` 확대
- 도서산간 템플릿 모달을 API 응답 구조에 맞게 전면 재설계
- `zipcode` 컬럼 `string(10)` → `string(20)` 확장 (범위 형식 지원)
- FormRequest zipcode 검증 `max:10` → `max:20` 변경
- 부모 레이아웃에 템플릿 CRUD 상태 및 데이터소스 검색 params 추가

## [0.2.0] - 2026-03-03

### Added
- 배송사 관리 기능 신설 (CRUD API + 관리 UI)
- 배송사 마스터 테이블 (`ecommerce_shipping_carriers`) 생성
- 배송 설정 탭에 배송사 관리 인라인 섹션 추가
- 주문 배송 추적 URL 생성 (`OrderShipping.getTrackingUrl()`)
- 해외배송 비활성화 확인 모달 추가
- 배송가능국가 테이블에 국기 아이콘 표시
- 업그레이드 스텝 (`Upgrade_0_2_0`) — 배송사 시딩 + 기존 주문 carrier_id 역매핑

### Changed
- 해외배송 토글 수동 바인딩 전환 (해외배송 OFF 시 국가 추가/토글/삭제 비활성화)
- 주문 레이아웃 배송사 옵션을 하드코딩에서 API 기반 동적 로딩으로 전환
- 모듈 설치 시더에 `ShippingCarrierSeeder` 추가

### Removed
- `CarrierEnum` 삭제 — 배송사를 하드코딩 Enum에서 DB 기반 동적 관리로 전환
- 배송정책에서 carrier 필드 제거 (`shipping_policy_country_settings.carrier` 컬럼 삭제)
- 배송정책 폼/목록에서 carrier Select 및 필터 UI 제거

## [0.1.7] - 2026-02-27

### Fixed
- 배송정책 수정 폼에서 가시성 플래그가 잘못 설정되는 버그 수정 (init_actions if 미지원 대응)
- 구간별 배송비 정책 수정 시 구간 테이블 미표시 및 고정배송비 필드 오표시 문제 해결
- 배송정책명(MultilingualInput) 수정 모드 미표시 문제 해결 (dataKey 패턴 적용)

### Changed

- 배송정책 폼에 dataKey="form" + debounce 패턴 적용 (상품 폼과 동일 구조)
- 단순 setState 핸들러 제거 (shipping_method, currency_code → 자동 바인딩)

## [0.1.2] - 2026-02-25

### Changed
- 모듈 라우트 admin/user 분기 서빙 적용 (routes.json → routes/admin.json 이동)

## [0.1.1] - 2026-02-24

### Changed
- 버전 체계 조정 (정식 출시 전 0.x 체계로 변경)

## [0.1.0] - 2026-02-23

### Added
- 이커머스 모듈 초기 구현
- 상품 관리: CRUD, 옵션(사이즈/색상), 라벨, SEO 메타, 이미지 갤러리
- 상품 카테고리: 계층형 카테고리, 순서 관리, TreeView 편집
- 브랜드 관리: CRUD, 로고, 설명
- 쿠폰 시스템: 정액/정률 할인, 사용 조건 (최소 금액, 특정 상품), 유효기간, 사용 횟수 제한
- 배송 정책: 무료/유료/조건부 배송
- 공통 정보 관리: 배송/교환/환불 안내, 판매자 정보
- 장바구니: 추가/수량 변경/삭제, 옵션별 관리
- 체크아웃: 주소 입력, 결제수단 선택, 쿠폰 적용
- 주문 관리: 주문 목록, 상태 변경, 상세 정보, 주문 타임라인
- 주문 완료: 주문 번호, 결제 정보 요약
- 결제 연동 인터페이스 (PaymentInterface)
- 상품 상세 페이지: 이미지 갤러리, 옵션 선택, 수량 입력, 장바구니 담기
- 상품 목록: 필터링 (카테고리/브랜드/가격), 정렬, 페이지네이션, 카드 그리드
- 위시리스트: 찜하기/해제 기능
- 상품 검색: 키워드 검색, 카테고리 필터 연동
- 관리자 레이아웃 (상품, 주문, 카테고리, 브랜드, 쿠폰, 배송정책 관리)
- 사용자 레이아웃 (상품 목록/상세, 장바구니, 체크아웃, 주문완료)
- 권한 시스템 연동
- 다국어 지원 (ko, en)
