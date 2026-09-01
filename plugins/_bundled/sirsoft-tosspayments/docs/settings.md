# 토스페이먼츠 — 설정·권한·라우트

> 설정 스키마·권한·메뉴·라우트·의존 관계 · 진입점: [AGENTS.md](../AGENTS.md)

## 설정 스키마

<!-- @generated:settings-schema START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 키 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| `is_test_mode` | `boolean` | `true` | 테스트 모드 |
| `test_client_key` | `string` | - | 테스트 클라이언트 키 |
| `test_secret_key` | `string` | - | 테스트 시크릿 키 |
| `live_client_key` | `string` | - | 라이브 클라이언트 키 |
| `live_secret_key` | `string` | - | 라이브 시크릿 키 |
| `redirect_success_url` | `string` | `{shopBase}/orders/{orderId}/complete` | 결제 성공 리다이렉트 URL |
| `redirect_fail_url` | `string` | `{shopBase}/checkout` | 결제 실패 리다이렉트 URL |
| `order_sheet_mode` | `boolean` | `false` | 주문서형 결제 |
| `method_card` | `boolean` | `true` | 카드 |
| `method_virtual_account` | `boolean` | `false` | 가상계좌 |
| `method_transfer` | `boolean` | `false` | 계좌이체 |
| `method_mobile_phone` | `boolean` | `false` | 휴대폰 |
| `method_tosspay` | `boolean` | `false` | 토스페이 |
| `method_kakaopay` | `boolean` | `false` | 카카오페이 |
| `method_naverpay` | `boolean` | `false` | 네이버페이 |
| `method_payco` | `boolean` | `false` | 페이코 |
| `method_samsungpay` | `boolean` | `false` | 삼성페이 |
| `vbank_valid_hours` | `integer` | `24` | 가상계좌 입금기한(시간) |
| `vbank_cash_receipt_type` | `string` | - | 가상계좌 현금영수증 유형 |
| `use_escrow` | `string` | `off` | 에스크로 사용 |
| `webhook_secret_verify` | `boolean` | `true` | 웹훅 secret 검증 |

기본값 파일: `config/settings/defaults.json` · 설정 화면 레이아웃: `resources/layouts/admin/plugin_settings.json`
<!-- @generated:settings-schema END -->

<!-- @intent START -->
`order_sheet_mode`가 이 스키마의 분기점입니다 — `false`(결제창형)면 `method_*` 8개 플래그는
읽히지 않고 통합결제창이 결제수단 선택을 전담합니다. `true`(주문서형)로 켜야 `method_*`
플래그가 실제로 체크아웃 화면의 개별 버튼 노출 여부를 결정합니다(§AGENTS.md "이 확장은
무엇인가"). `vbank_valid_hours`(1~2160시간)와 `use_escrow`(`off`/`on`/`buyer_choice`)는
`ValidateTossSettingsListener`가 저장 시점에 범위를 강제합니다 — UI 의 input 힌트만으로는
관리자 설정 저장 API 직접 호출을 막을 수 없기 때문입니다. `webhook_secret_verify`를 끄면
가상계좌 웹훅의 유일한 위조 방지 수단이 사라지므로 기본값 `true`를 유지해야 합니다.
<!-- @intent END -->

## 권한

<!-- @generated:permissions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_선언된 권한이 없습니다._
<!-- @generated:permissions END -->

<!-- @intent START -->
결제 설정 접근 권한은 이커머스의 관리자 권한 체계 안에서 다뤄집니다 — PG 마다 별도 권한을
선언하면 PG 를 여러 개 설치했을 때 "결제 설정을 볼 수 있는 사람"이라는 하나의 개념이
플러그인 수만큼 중복 정의됩니다.
<!-- @intent END -->

## 메뉴

<!-- @generated:menus START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 메뉴가 없습니다._
<!-- @generated:menus END -->

<!-- @intent START -->
설정 화면(`plugin_settings.json`)은 코어의 "플러그인 관리 > 설정" 공통 진입점을 통해
접근합니다 — PG 플러그인마다 전용 사이드바 메뉴를 만들면 PG 를 여러 개 설치했을 때 메뉴가
난립합니다.
<!-- @intent END -->

## 라우트

<!-- @generated:routes START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 파일 | URL prefix |
|---|---|---|
| `web` | `src/routes/web.php` | `/plugins/sirsoft-tosspayments/...` |

확장 라우트는 **활성 상태인 확장의 것만** 등록됩니다. 라우트 정의를 바꾸면 라우트 캐시 재생성이 필요합니다.
<!-- @generated:routes END -->

<!-- @intent START -->
다른 PG 플러그인과 달리 `api` 라우트 파일이 없습니다 — 이 플러그인은 로그인 사용자가
Bearer 토큰으로 직접 호출하는 엔드포인트(예: 관리자 거래조회)를 두지 않습니다. 승인
콜백·가상계좌 웹훅 모두 결제창 리다이렉트나 토스 서버가 도달하는 경로라 `web`에만
있습니다.
<!-- @intent END -->

## 의존 관계

<!-- @generated:dependencies START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
**이 확장이 의존하는 확장**

| 확장 | 유형 | 버전 제약 | 번들 |
|---|---|---|---|
| `sirsoft-ecommerce` | 모듈 | `>=1.1.0` | ✅ |

**이 확장에 의존하는 확장** (이 확장을 비활성화하면 함께 영향을 받습니다)

없음.
<!-- @generated:dependencies END -->

<!-- @intent START -->
`sirsoft-ecommerce >=1.1.0` 하드 의존은 §data-model.md 에서 설명한 구조(결제 상태는
이커머스가 소유, 이 플러그인은 절차만 소유)의 직접적 결과입니다 — 이커머스 없이는 이
플러그인이 다룰 주문 자체가 존재하지 않습니다. 이커머스의 PG 등록 훅이나 `Order` 모델
구조가 바뀌면 이 최소 버전을 올려야 합니다(§CLAUDE.md "확장 → 확장 동기화").
<!-- @intent END -->
