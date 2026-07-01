# Changelog

이 언어팩의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

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
