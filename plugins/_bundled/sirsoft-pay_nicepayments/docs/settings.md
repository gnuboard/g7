# 나이스페이먼츠 — 설정·권한·라우트

> 설정 스키마·권한·메뉴·라우트·의존 관계 · 진입점: [AGENTS.md](../AGENTS.md)

## 설정 스키마

<!-- @generated:settings-schema START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 키 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| `is_test_mode` | `boolean` | `true` | 테스트 모드 |
| `test_mid` | `string` | `nicepay00m` | 테스트 가맹점 ID (MID) |
| `test_merchant_key` | `string` | `EYzu8jGGMfqaDEp76gSckuvnaHHu+bC4opsSN6lHv3b2lurNYkVXrZ7Z1AoqQnXI3eLuaUFyoRNC6FkrzVjceg==` | 테스트 가맹점 키 |
| `live_mid` | `string` | - | 라이브 가맹점 ID (MID) |
| `live_merchant_key` | `string` | - | 라이브 가맹점 키 |
| `redirect_success_url` | `string` | `{shopBase}/orders/{orderId}/complete` | 결제 성공 리다이렉트 URL |
| `redirect_fail_url` | `string` | `{shopBase}/checkout` | 결제 실패 리다이렉트 URL |
| `use_escrow` | `boolean` | `false` | 에스크로 결제 사용 |
| `easy_pay_allow_with_other_pg` | `boolean` | `false` | 타 PG와 사용가능함 |
| `easy_pay_naverpay` | `boolean` | `false` | 네이버페이 간편결제 |
| `easy_pay_kakaopay` | `boolean` | `false` | 카카오페이 간편결제 |
| `easy_pay_samsungpay` | `boolean` | `false` | 삼성페이 간편결제 |
| `easy_pay_applepay` | `boolean` | `false` | 애플페이 간편결제 |
| `easy_pay_payco` | `boolean` | `false` | PAYCO 간편결제 |
| `easy_pay_skpay` | `boolean` | `false` | 11pay (SK페이) 간편결제 |
| `easy_pay_ssgpay` | `boolean` | `false` | SSG페이 간편결제 |
| `easy_pay_lpay` | `boolean` | `false` | L.pay 간편결제 |

기본값 파일: `config/settings/defaults.json` · 설정 화면 레이아웃: `resources/layouts/admin/plugin_settings.json`
<!-- @generated:settings-schema END -->

<!-- @intent START -->
`test_*`/`live_*` 쌍 구조는 다른 PG 플러그인과 동일한 이유입니다 — 테스트 모드와 운영
모드가 완전히 다른 자격증명을 쓰므로 `is_test_mode`를 켜고 꺼도 서로의 값을 덮어쓰지
않습니다. 간편결제 플래그가 8개(네이버페이/카카오페이/삼성페이/애플페이/PAYCO/11pay/
SSG페이/L.pay)로 다른 PG 플러그인보다 많은 것은 나이스페이먼츠가 실제로 이 8종 전부를
중계하기 때문입니다 — 계약이 없는 수단을 켜면 결제 시도 시점에 PG 오류로 드러납니다
(§AGENTS.md "금지 패턴"과 유사하게, 계약 여부는 이 플러그인이 검증하지 않고 PG 응답에
위임합니다).
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
| `api` | `src/routes/api.php` | `/api/plugins/sirsoft-pay_nicepayments/...` |
| `web` | `src/routes/web.php` | `/plugins/sirsoft-pay_nicepayments/...` |

확장 라우트는 **활성 상태인 확장의 것만** 등록됩니다. 라우트 정의를 바꾸면 라우트 캐시 재생성이 필요합니다.
<!-- @generated:routes END -->

<!-- @intent START -->
`api`(Bearer 토큰 인증, 관리자 거래조회·에스크로 배송등록처럼 로그인 사용자가 직접
호출하는 엔드포인트)와 `web`(결제 콜백·가상계좌 입금통보처럼 결제창이나 PG 서버가
도달하는 엔드포인트)이 분리된 이유는 인증 방식이 다르기 때문입니다 — 나이스페이먼츠는
우리 서비스의 Bearer 토큰을 모르므로 콜백 라우트에 `api` 인증 미들웨어를 걸 수 없습니다.
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
구조가 바뀌면 이 최소 버전을 올려야 합니다(§코어 AGENTS.md "확장 → 확장 동기화").
<!-- @intent END -->
