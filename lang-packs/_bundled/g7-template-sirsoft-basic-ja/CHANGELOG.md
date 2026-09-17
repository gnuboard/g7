# Changelog

이 언어팩의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [1.1.4] - 2026-09-14

### Added

- 拡張アイコンを同梱しました。管理者の拡張管理画面とインストールウィザードに表示されます。
- 게시글 스크랩(북마크) 기능의 일본어 번역을 추가했습니다. 게시글 상세의 스크랩 버튼, 마이페이지 스크랩 탭·목록·빈 목록 안내, 스크랩 해제 확인 모달 문구가 일본어 로케일에서 자연스럽게 표시됩니다. 스크랩 목록이 표 형태로 개편되면서 추가된 컬럼 제목(게시판·제목·작성자·댓글·스크랩일·관리)과 게시글 제목 검색 입력란 안내 문구(`mypage.scrap.search_placeholder`)도 함께 번역했습니다. 원글이 삭제된 스크랩은 "삭제됨" 표시로 구분되며, 목록 상단에는 원글이 삭제돼도 스크랩 기록이 남아있다는 안내가 표시됩니다.
- 마이페이지 스크랩 화면이 표 형태로 개편되면서 늘어난 문구와, 게시글 검색 대상(제목·내용·제목+내용) 라벨의 일본어 번역을 추가했습니다. 스크랩 일괄 해제 확인 모달 이름도 함께 번역했습니다.
- 마이페이지 「내 반응」·「내 신고」 화면의 일본어 번역을 추가했습니다 — 서브탭 이름, 목록 컬럼(게시판·대상·반응·일시·관리), 반응 유형과 대상 구분 필터, 단건·선택 해제 확인 모달, 신고 상태 배지(접수·검토·처리·반려)와 대기 중 신고 취소 확인 문구, 그리고 원글이 지워졌거나 게시판에서 반응을 끈 행에 붙는 배지 문구가 일본어 로케일에서 표시됩니다. 게시글·댓글 반응 버튼의 안내 문구(로그인 필요·본인 글·본인 댓글)도 함께 번역했습니다.
- 「내 신고」의 찾을 곳 선택에 붙는 「신고 사유」 라벨을 일본어(`通報理由`)로 추가했습니다. 같은 화면의 다른 찾을 곳(제목+내용·제목·내용)과 나란히 표시됩니다.

### Removed

- 쓰이지 않던 레거시 반응 컴포넌트(`PostReactions`)가 템플릿에서 빠지면서, 그 컴포넌트만 쓰던
  일본어 문구도 함께 걷어냈습니다 — 반응 이름 5종(`board.reaction.{like,laugh,agree,thanks,wow}`),
  편집기 속성·컴포넌트 라벨(`editor.control.pr_*`·`editor.component.post_reactions`·
  `editor.data_prop.reactions`), 팔레트 항목(`layout_editor.palette.post_reactions`).
  실제 반응 기능의 문구는 게시판 모듈 언어팩이 제공하므로 화면에 보이는 것은 달라지지 않습니다.

### Changed

- 언어팩을 전량 재생성하면서 기존 일본어 문구 669건이 최신 번역으로 바뀌었습니다 — 「会社概要」→「企業情報」, 「秘密の投稿です」→「非公開投稿」 처럼 뜻은 같고 표현이 달라진 것이 대부분입니다. ko 원본에서 이미 지워진 고아 키 3건(`mypage.scrap.notice`·`notice_sub`·`scrapped_at`)을 이 팩에서도 걷어내려면 전량 재생성이 필요했습니다.

## [1.1.3] - 2026-09-09

### Added

- 로그인 화면의 2단계 인증(인증번호 입력·다시 받기·처음부터·유효 시각)과 계정 잠금 해제 시각 안내의 일본어 번역을 추가했습니다.

## [1.1.2] - 2026-08-24

### Fixed

- 첫 화면 소개 문구의 제품명이 한국어로 표시되던 문제를 수정했습니다.

## [1.1.1] - 2026-08-19

### Added

- 통합검색 실패 안내 문구(`search.failed.title`, `search.failed.suggestion`) 일본어 번역 추가 — 검색 중 오류가 발생했을 때 "검색 결과 없음" 과 구분되는 오류 안내가 일본어 로케일에서 자연스럽게 표시됩니다. (#103 @Tuwasduliebst 님께서 제보해주셨습니다.)

## [1.1.0] - 2026-08-10

### Added

- 게시글 반응(추천/비추천) 버튼의 로그인 필요 안내·처리 실패 안내 문구(`board.reaction.login_required`, `board.reaction.failed`) 일본어 번역 추가 — 반응 버튼 조작 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
- 회원가입 화면의 휴대폰번호·전화번호 입력란 라벨·안내 문구(`auth.mobile`, `auth.phone`) 일본어 번역 추가 — 회원가입 시 연락처 입력란이 일본어 로케일에서 자연스럽게 표시됩니다.
- 모달 닫기 버튼의 안내 라벨(`common.close_modal`) 일본어 번역 추가 — 화면 낭독기 사용자에게 버튼 용도가 일본어로 안내됩니다.
- 검색 결과 건수 표기와 검색어 구체화 안내(`search.result_count_suffix_at_least`, `search.refine_query_hint`) 일본어 번역 추가 — 총 건수를 상한까지만 센 경우의 "N건 이상" 표기가 일본어 로케일에서 자연스럽게 표시됩니다.
- 편집기 화면 문구(`editor.*`) 일본어 번역 보강.
- 주문서의 환불 계좌 입력란 문구(`shop.checkout.refund_bank_*` — 안내 제목, 은행 선택, 계좌번호·예금주 입력 안내, 부분 입력 주의) 일본어 번역 추가 — 무통장입금·가상계좌로 주문할 때 환불받을 계좌를 남기는 영역이 일본어 로케일에서 자연스럽게 표시됩니다.

### Changed

- 화면 편집기 상품 상세의 상태 미리보기 라벨(`editor.state.out_of_stock`, `editor.state.sold_out`)을 서로 구분되도록 정정했습니다. 두 상태 모두 "品切れ" 로 표시되어 목록에서 어느 쪽인지 구별할 수 없었습니다.
- 본인 글에 반응 버튼을 눌렀을 때의 안내 문구(`board.reaction.self_post_denied`) 일본어 번역 추가 — 본인 글 반응 시도 안내가 일본어 로케일에서 자연스럽게 표시됩니다.

## [1.0.1] - 2026-07-08

### Added

- 미발행 페이지 관리자 미리보기 안내 배너(`user.page.preview_banner`) 일본어 번역 추가 — 관리자가 발행 전 페이지를 사용자 화면에서 미리 볼 때의 안내 문구가 일본어 로케일에서 자연스럽게 표시됩니다.
- 첨부 개수 초과 안내 문구(`attachment.upload_limit_exceeded`) 일본어 번역 추가 — 게시글 첨부를 허용 개수보다 많이 선택했을 때의 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
- 게시판·상품 사용자 화면의 관리자 이동 링크 라벨(`admin_links.posts`·`admin_links.manage`·상품 `admin_edit`) 일본어 번역 추가 — 관리자가 사용자 화면에서 관리자 게시글 목록·게시판 관리·상품 편집으로 이동하는 링크가 일본어 로케일에서 자연스럽게 표시됩니다.

## [1.0.0] - 2026-07-01

### Added

- 비회원 주문 완료 화면의 주문 확인 메일 발송 안내(`shop.order_complete.guest_email_sent`) 일본어 번역 추가 — 비회원으로 주문 시 입력한 이메일로 주문 확인 안내를 보냈다는 문구가 일본어 로케일에서 자연스럽게 표시됩니다.
- 마이페이지 주문내역의 '일부취소' 보조 배지 일본어 번역 추가 (`orders.partial_cancelled_badge`) — 일부 상품만 취소된 주문에 표시되는 보조 배지가 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 데이터 소스 라벨에 "리뷰 정책 설정"(`editor.data_source.reviewSettings`) 일본어 번역 추가 — 리뷰 작성 화면의 리뷰 정책 데이터 소스 표시 명칭이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 "비회원 주문 토큰 저장" 동작 라벨(`editor.action.save_guest_order_token.*`) 일본어 번역 추가 — 동작 추가 목록의 비회원 주문 토큰 저장 동작과 토큰·주문번호·만료시각 입력 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 상품 상세 옵션 품절 표시 접미 문구(`shop.product.sold_out_suffix`) 일본어 번역 추가 — 재고 없는 옵션의 "(품절)" 표시가 일본어 로케일에서 자연스럽게 표시됩니다.
- 상품 상세 품절 옵션 선택 안내 문구(`shop.product.sold_out_option`) 일본어 번역 추가 — 품절된 옵션 선택 시 안내 메시지가 일본어 로케일에서 자연스럽게 표시됩니다.
- 주문서 상품 쿠폰의 중복 적용 비활성 라벨(`checkout.coupon_already_used`)과 쿠폰 적용 불가 안내 토스트(`checkout.coupon_not_applied`) 일본어 번역 추가 — 1인 사용 제한 쿠폰을 다른 상품에 이미 적용했거나 적용 조건을 충족하지 못한 경우의 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
- 추가옵션 직접입력 안내 일본어 번역 추가 — 추가옵션 직접입력 라벨·안내문구(`shop.additional_option_custom_text_label`, `..._placeholder`, `..._required`)와 추가옵션 선택·금액·필수 안내(`shop.additional_option_placeholder`, `additional_options_amount`, `additional_option_required`)가 일본어 로케일에서 자연스럽게 표시됩니다. 상품 상세·장바구니 옵션변경에서 각인 문구 등을 직접 입력할 때의 라벨과 안내가 일본어로 노출됩니다.
- 배송국 설정·배송 가능 여부 안내 일본어 번역 추가 — 회원가입·마이페이지의 기본 배송국 설정 라벨·안내(`register_shipping_country.*`, `mypage.shipping_country_settings.*`)와 장바구니·주문서의 배송 가능 여부 안내(`shop.shippability.*`, `shop.shipping_country`)가 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 헤더·테마·알림 컴포넌트 속성 컨트롤 라벨 일본어 번역 추가 (`editor.control.{hdr_*,tt_*,nc_*}` + `editor.component.header`) — 헤더(사이트명·로고·게시판 표시 수 등)·테마 토글·알림 센터 컴포넌트의 속성 편집 컨트롤과 컴포넌트명이 일본어 로케일에서 자연스럽게 표시됩니다.
- 마이페이지 주문 상세의 결제 통화 청구 안내(`order_detail.charged_in_payment_currency`) 일본어 번역 추가 — 기본 통화와 다른 통화로 결제한 주문의 청구 통화·금액이 일본어 로케일에서 표시됩니다.

## [1.0.0-beta.2] - 2026-05-21

### Added

- 레이아웃 편집기 트리의 모달 화면명 일본어 번역 추가 (`modal_label.*` — 이용약관·개인정보처리방침·주문 취소·장바구니 삭제 확인 등) — 위지윅 편집기 트리의 모달 노드가 영문 식별자 대신 일본어 화면명으로 표시됩니다.
- 레이아웃 편집기 페이지 상태 라벨 일본어 번역 추가 (`editor.state.{address_modal_list,address_modal_form,order_show_default,order_change_address_manual,user_base_default,mobile_menu_open,gdpr_cookie_banner_visible,identity_challenge_inicis}`) — 배송지 모달·주문 상세 배송지 변경·모바일 메뉴 펼침·쿠키 배너·본인인증 모달 등 화면 상태 미리보기 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 데이터 소스·편집기 라벨 일본어 번역 동기화 (`editor.data_source.*` 등 신규/누락 키) — 속성 패널의 데이터 소스 표시 명칭 등 편집기 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 배열 항목 편집기 라벨 일본어 번역 추가 (`editor.array_item.*`·`editor.array_field.*`·탭/라벨/아이콘/배지/비활성 항목 라벨 등) — 탭 네비게이션 등 배열 prop 의 항목 단위 편집 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 쇼핑몰 재주문 화면의 신규 텍스트 일본어 번역 추가
- 비회원 주문 흐름(주문 방식 선택, 비회원 주문서, 주문 조회/상세, 30분 자동 인증 안내) 신규 텍스트 일본어 번역 추가
- 로그인 화면 비회원 주문 진입 및 마이페이지 배송지 변경 관련 신규 텍스트 일본어 번역 추가
- 사용자 페이지(홈·로그인·게시판·쇼핑·검색·에러)의 페이지 제목 일본어 번역 추가
- 레이아웃 편집기 소셜 로그인 버튼 제공자 정의 편집 라벨 일본어 번역 추가 (`editor.array_item.provider`·`editor.array_field.provider`·`editor.provider.{naver,kakao}`) — 로그인 화면의 소셜 로그인 제공자 목록 편집 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 표(Table) 컴포넌트 라벨 일본어 번역 추가 (`editor.component.table`)
- 레이아웃 편집기 표 셀 배경색·내부 여백 카탈로그 및 표 셀(Td/Th) 컴포넌트 라벨 일본어 번역 추가 (`editor.cell_fill.*`·`editor.cell_padding.*`·`editor.component.{td,th}`) — 셀 배경색 프리셋 색·내부 여백 단계 라벨과 표 셀 컴포넌트명이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 정렬 박스 on/off 라벨 일본어 번역 추가 (`editor.control.flex_enable.{on,off}`) — 디바이스별 정렬 박스 적용/해제 2상태 선택 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 색 컨트롤 프리셋 색 라벨 일본어 번역 추가 (`editor.control.color_preset.*`) — 글자색·배경색 컨트롤의 프리셋 색(회색 단계·흰색·파랑·빨강·초록) 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 신규 스타일 컨트롤 라벨 일본어 번역 추가 (`editor.control.{box_shadow,border_style,border_color,border_radius,opacity,overflow,font_bold,font_italic,text_underline,whitespace,toggle}.*` + `text_align.justify` + `color_preset.gray_300`) — 그림자·테두리·모서리 둥글기·투명도·스크롤·굵게/기울임/밑줄·줄바꿈·양쪽 정렬 컨트롤 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 페이지 상태 토글 라벨 일본어 번역 추가 (`editor.state.*`) — 캔버스 툴바의 페이지 상태(로그인/검증 실패·찜·배송지·재주문·상품 리뷰/문의 탭·게시판 활동 서브탭·검색 결과 탭·금액/세금 상세 펼침 등) 미리보기 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 속성 탭 컨트롤 라벨 일본어 번역 추가 (`editor.control.{icon_name,img_src,img_alt,link_href,link_target,input_type,input_placeholder,input_name,input_required,input_disabled,field_label,field_error,field_helper,html_for,textarea_rows,elem_id,select_placeholder,select_options,component_size,button_variant,component_disabled}.*`) — 아이콘·이미지·링크·입력 필드·선택지 등 비-스타일 속성 편집 컨트롤 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.

## [1.0.0-beta.1] - 2026-05-11

### Added

- 기본 사용자 템플릿(sirsoft-basic)의 일본어 번들 언어팩 초기 제공
