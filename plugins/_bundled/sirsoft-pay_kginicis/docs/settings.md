# KG 이니시스 — 설정·권한·라우트

> 설정 스키마·권한·메뉴·라우트·의존 관계 · 진입점: [AGENTS.md](../AGENTS.md)

## 설정 스키마

<!-- @generated:settings-schema START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 키 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| `is_test_mode` | `boolean` | `true` | 테스트 모드 |
| `test_mid` | `string` | `INIpayTest` | 테스트 가맹점 ID (MID) |
| `test_sign_key` | `string` | `SU5JTElURV9UUklQTEVERVNfS0VZU1RS` | 테스트 사인키 |
| `test_iniapi_key` | `string` | `ItEQKi3rY7uvDS8l` | 테스트 INIAPI 키 |
| `test_iniapi_iv` | `string` | `HYb3yQ4f65QL89==` | 테스트 INIAPI IV |
| `live_mid` | `string` | - | 라이브 가맹점 ID (MID) |
| `live_sign_key` | `string` | - | 라이브 사인키 |
| `live_iniapi_key` | `string` | - | 라이브 INIAPI 키 |
| `live_iniapi_iv` | `string` | - | 라이브 INIAPI IV |
| `test_mobile_hash_key` | `string` | `3CB8183A4BE283555ACC8363C0360223` | 테스트 모바일 해시키 |
| `live_mobile_hash_key` | `string` | - | 라이브 모바일 해시키 |
| `use_escrow` | `boolean` | `false` | 에스크로 결제 활성화 |
| `japan_enabled` | `boolean` | `false` | 일본 결제 활성화 |
| `japan_restrict_jpy_payment_methods` | `boolean` | `false` | JPY 주문 결제수단 제한 |
| `test_japan_sign_key` | `string` | `5AL5Djb1Ipualn0F` | 테스트 일본 CBT 해시키 |
| `live_japan_mid` | `string` | - | 라이브 일본 MID |
| `live_japan_sign_key` | `string` | - | 라이브 일본 CBT 해시키 |
| `japan_merchant_name` | `string` | `サンプルストア` | 일본 결제 가맹점명 |
| `japan_merchant_name_kana` | `string` | `サンプルストア` | 일본 결제 가맹점명 Kana |
| `japan_merchant_name_alphabet` | `string` | `Sample Store` | 일본 결제 가맹점명 영문 |
| `japan_merchant_name_short` | `string` | `サンプル` | 일본 결제 가맹점 약칭 |
| `japan_contact_name` | `string` | `サポート窓口` | 일본 결제 문의처명 |
| `japan_contact_email` | `string` | `support@example.com` | 일본 결제 문의 이메일 |
| `japan_contact_phone` | `string` | `0120-123-456` | 일본 결제 문의 전화번호 |
| `japan_contact_opening_hours` | `string` | `10:00-18:00` | 일본 결제 문의 영업시간 |
| `redirect_success_url` | `string` | `{shopBase}/orders/{orderId}/complete` | 결제 성공 리다이렉트 URL |
| `redirect_fail_url` | `string` | `{shopBase}/checkout` | 결제 실패 리다이렉트 URL |
| `easy_pay_allow_with_other_pg` | `boolean` | `false` | 타 PG와 사용가능함 |
| `easy_pay_samsung_pay` | `boolean` | `false` | KG이니시스 삼성페이 사용 |
| `easy_pay_naverpay` | `boolean` | `false` | KG이니시스 네이버페이 사용 |
| `easy_pay_show_brand_button` | `boolean` | `false` | 간편결제 브랜드 버튼 표시 |
| `easy_pay_lpay` | `boolean` | `false` | KG이니시스 L.pay 사용 |
| `easy_pay_kakaopay` | `boolean` | `false` | KG이니시스 카카오페이 사용 |
| `use_credit_point` | `boolean` | `false` | 신용카드 포인트 사용 |

기본값 파일: `config/settings/defaults.json` · 설정 화면 레이아웃: `resources/layouts/admin/plugin_settings.json`
<!-- @generated:settings-schema END -->

<!-- @intent START -->
`test_*`/`live_*` 접두어 쌍이 반복되는 것이 이 스키마의 핵심 구조입니다 — 테스트 모드와
운영 모드가 완전히 다른 자격증명 집합을 쓰기 때문에, 하나의 키를 두고 모드에 따라 값을
바꾸는 대신 애초에 별도 키로 분리했습니다. 이 덕분에 `is_test_mode` 를 껐다 켰다 해도 각
모드의 자격증명은 서로 덮어쓰이지 않습니다. 일본(`japan_*`) 설정군이 특히 많은 이유는
KG 이니시스 CBT 결제창의 `extraData`(가맹점 표시 정보)가 한국 표준결제와 별개의 계약·심사
단위이기 때문입니다.
<!-- @intent END -->

## 권한

<!-- @generated:permissions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_선언된 권한이 없습니다._
<!-- @generated:permissions END -->

<!-- @intent START -->
결제 설정은 이커머스의 관리자 권한 체계 안에서 다뤄집니다 — "결제 설정을 볼 수 있는 사람"은
PG 마다 다시 정의할 이유가 없는 하나의 개념이라, 이 플러그인이 별도 권한을 선언하지 않고
이커머스의 결제/설정 권한에 얹혀 갑니다.
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
| `api` | `src/routes/api.php` | `/api/plugins/sirsoft-pay_kginicis/...` |
| `web` | `src/routes/web.php` | `/plugins/sirsoft-pay_kginicis/...` |

확장 라우트는 **활성 상태인 확장의 것만** 등록됩니다. 라우트 정의를 바꾸면 라우트 캐시 재생성이 필요합니다.
<!-- @generated:routes END -->

<!-- @intent START -->
`api`(Bearer 토큰 인증, 결제창 서명·해시 생성처럼 로그인 사용자가 브라우저에서 직접 호출하는
엔드포인트)와 `web`(콜백·입금통보처럼 KG 이니시스 서버나 리다이렉트로 도달하는 엔드포인트)이
분리된 이유는 인증 방식이 다르기 때문입니다 — KG 이니시스는 우리 서비스의 Bearer 토큰을 모르므로
콜백 라우트에 `api` 인증 미들웨어를 걸 수 없습니다. 새 KG 이니시스 콜백을 추가할 때는 `web`
쪽에, 프론트엔드가 로그인 상태로 직접 호출하는 기능은 `api` 쪽에 둡니다.
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
`sirsoft-ecommerce >=1.1.0` 하드 의존은 §data-model.md 에서 설명한 구조(결제 상태는 이커머스가
소유, 이 플러그인은 절차만 소유)의 직접적 결과입니다 — 이커머스 없이는 이 플러그인이 다룰
주문 자체가 존재하지 않습니다. 이커머스의 PG 등록 훅(`registered_pg_providers` 등)이나
`Order` 모델 구조가 바뀌면 이 최소 버전을 올려야 합니다(§코어 AGENTS.md "확장 → 확장 동기화").
<!-- @intent END -->
