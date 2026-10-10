# 이커머스 — 설정·권한·라우트

> 설정 스키마·권한·메뉴·라우트·의존 관계 · 진입점: [AGENTS.md](../AGENTS.md)

## 설정 스키마

<!-- @generated:settings-schema START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_`getSettingsSchema()` 선언이 없습니다._

기본값 파일: `config/settings/defaults.json`
<!-- @generated:settings-schema END -->

<!-- @intent START -->
`getSettingsSchema()` 선언이 없는 것은 누락이 아닙니다. 이 모듈의 설정은 코드가 아니라
`config/settings/defaults.json` 이 SSoT 이며, 그 파일 하나가 세 가지를 함께 담습니다:

| 키 | 역할 |
|---|---|
| `_meta.categories` | 설정 그룹 10개의 목록과 순서 (`basic_info` · `language_currency` · `order_settings` · `claim` · `shipping` · `seo` · `review_settings` · `inquiry` · `notifications` · `mileage`) |
| `defaults` | 그룹별 기본값. 설치 시 `storage/app/settings/` 로 동기화되어 `module_setting()` 이 읽는 값이 됩니다 |
| `frontend_schema` | 관리자 화면이 자동으로 그리는 입력 폼 정의 |

**`frontend_schema` 는 8개 그룹뿐이고 `claim` 과 `mileage` 가 없습니다.** 자동 생성 폼으로는
표현할 수 없는 입력(통화별 적립 규칙 표, 유형별 신청 가능 상태·환불 자동 승인 결제수단 같은
다중 선택 묶음)이 있어서 이 두 탭만 레이아웃 JSON 으로 직접 그리기 때문입니다
(`resources/layouts/admin/partials/admin_ecommerce_settings/_tab_mileage*.json` 6개,
`_tab_claim.json` 과 `_claim_policy_section.json`). 새 그룹을
추가할 때는 이 셋 중 어디까지 손댈지를 먼저 정합니다 — `_meta.categories` 에만 넣고 `defaults`
를 빠뜨리면 그 그룹은 화면에 뜨지만 저장할 값이 없습니다.

클레임 탭의 사유 카탈로그 3종(교환·반품·환불)은 **하위 탭**으로 한 번에 하나만 그립니다. 어느
하위 탭인지는 URL 쿼리 `sub_tab`(`exchange` · `return` · `refund`, 없거나 모르는 값이면 `exchange`)
이 정하므로 `?tab=claim&sub_tab=return` 처럼 링크로 특정 카탈로그를 열 수 있습니다. 세 카탈로그는
같은 폼(`claim.*_reasons`) 안의 배열이라 하위 탭을 옮겨도 편집 내용은 남고 페이지 [저장] 한 번에
함께 저장됩니다. 하위 탭을 옮길 때는 열려 있던 사유 추가 폼을 비웁니다 — 저장 가드가 그 폼
플래그를 하위 탭과 무관하게 읽어, 남겨 두면 보이지 않는 폼 때문에 저장이 막힙니다.

설정 값을 코드에서 읽을 때는 `EcommerceSettingsService` 를 거칩니다. 통화 설정처럼 요청마다
여러 번 읽히는 값은 `CurrencySettingsCache` 가 따로 캐시하며, 설정 저장 후 캐시를 비우는 것은
`core.module_settings.after_save` 훅을 받는 리스너들입니다 — 설정을 직접 파일에서 읽으면 그
무효화 경로를 타지 않아 화면과 서버가 서로 다른 값을 봅니다.

결제수단 목록(`order_settings.payment_methods`)만은 성격이 다릅니다. **저장값과 플러그인이
등록한 카탈로그의 병합**이라, 플러그인을 삭제·비활성화하면 저장값은 남아 있는데 카탈로그에서
사라지는 고아 항목이 생깁니다. 공개 응답은 고아 항목을 걸러 내보내고 관리자 응답은 그대로
노출하는 것이 규칙입니다 — 운영자는 그것을 보고 지워야 하기 때문입니다.

`order_settings.pending_order_expire_minutes` 는 결제창까지 갔으나 결제가 성립하지 않은
주문(`PENDING_ORDER`)을 만료 자동취소 대상에 넣는 기준입니다(기본 1440분 = 24시간). 0 이면 그
부류를 정리하지 않습니다.

이 값은 **환경설정 > 주문 설정 > 주문 자동취소** 카드에 있습니다. 형제 값
`auto_cancel_days` 와 달리 `auto_cancel_expired` 토글 **하위가 아닙니다** — 정리 스케줄러가 그
토글과 무관하게 이 값을 읽기 때문에, 토글을 끈 상태에서 이 입력만 사라지면 화면이 실제 동작을
설명하지 못합니다. 입력 경계(0 ~ 20160분)는 `config/ecommerce.php` 의 `limits` 가 단일 출처이며,
화면은 설정 응답의 `_meta.limits` 로 같은 값을 받습니다.

이 부류를 정리 대상에 넣은 이유는 **정리 주체가 아예 없었기** 때문입니다. 기존 자동취소는 입금
기한(`vbank_due_at`·`deposit_due_at`)이 있는 결제수단만 훑고, 임시주문 정리는 다른 테이블을 봅니다.
브라우저 리턴 콜백이 주문 상태를 바꾸지 않게 된 뒤로는 승인 거절분도 여기에 머무르므로, 이 정리가
선차감 마일리지 복원의 최종 안전망입니다.

`claim` 그룹(스키마 1.5.0)은 신청 가능 조건(교환·반품·즉시취소·환불신청 가능 상품 상태, 신청
가능 기간) · 청구(허용 청구 방식 · 추가 결제수단) · 자동 승인 3종 · **환불 자동 승인 결제수단** ·
첨부 상한 · 완료 처리 기본값 2종을 담습니다. `complete_defaults.*` 는 **완료 처리 모달의 초기
선택값**일 뿐 서비스가 읽지 않습니다 — 서비스가 읽으면 "요청에 없으면 설정대로" 라는 숨은 자동
실행이 됩니다. 즉시취소 가능 상태는 업그레이드 시 기존 `order_settings.cancellable_statuses` 를
승계합니다.

`auto_approve_refund_payment_methods`(기본 `card · point · free`)는 **사람의 개입 없이 환불이
집행되는 결제수단**의 목록입니다. 목록 밖 수단(무통장입금·가상계좌·계좌이체·휴대폰·예치금 등)은
결제완료 상품이라도 환불신청서가 `requested` 에서 멈추고, 운영자가 승인한 뒤 [환불하기]로
송금을 마칩니다. 자동 승인 3종 토글과 **독립적으로 항상** 적용되며, 세 경로(즉시취소 분기 ·
`auto_approve_refund_request` 토글 · 관리자 [주문취소] '자동 전이' 체크)가 모두 이 목록을
지납니다. 자동 승인해 두면 신청서만 완료로 앞서 나가고 고객에게 "환불 완료" 알림이 가는데 돈은
아직 나가지 않은 상태가 되기 때문입니다.

선택지는 주문 설정 탭의 결제수단 카탈로그에서 파생하며(`is_active` 로 거르지 않습니다 — 이 축이
판정하는 것은 앞으로 받을 주문이 아니라 **이미 그 수단으로 결제된 주문의 환불**입니다), 결제
플러그인이 카탈로그 항목에 능력 키 `auto_approve_refund: true` 를 선언한 수단은 화면에 `PG 즉시
환불` 배지로 표시되고 **운영자가 아직 결정한 적 없을 때만** 목록에 자동 합류합니다. 합류 규칙이
필요한 이유는 PG 플러그인을 새로 설치했을 때 그 수단의 환불이 전부 승인 대기로 쌓이는 것을 막기
위함이고, 저장 시점에 화면이 제시한 선언 수단 전부를 `auto_approve_refund_payment_methods_decided`
(운영자 결정 이력)에 남겨 **운영자가 뺀 수단이 다음 조회에서 되살아나지 않게** 합니다. 이 이력
키는 화면이 보내지 않고 저장 직전에 서버가 채웁니다 — 요청에 실으면 422 이고, 관리자 조회 응답에도
실리지 않습니다(화면이 조회한 claim 섹션을 되보내도 저장이 막히지 않게). 전체 저장이든 개별 설정
저장이든 목록을 고치면 같은 이력이 남으며, 이 조립은 `EcommerceSettingsAdminService` 한 곳에 있습니다.
<!-- @intent END -->

`language_currency.rate_provider` 는 환율을 자동으로 갱신할 **제공자 선택값**입니다(기본 `null`
= 운영자 직접 입력). 모듈은 제공자를 구현하지도 자동 선택하지도 않습니다 — 필터 훅
`sirsoft-ecommerce.currency.registered_rate_providers` 로 등록된 목록을 설정 응답의
`available_rate_providers` 로 내려보내고, 이 키의 값과 `id` 가 일치하는 항목 하나만
`active_rate_provider` 로 해석합니다. 선택값이 비었거나 그 `id` 가 목록에 없으면(플러그인 비활성·
삭제) 활성 제공자는 `null` 이고 전 통화가 다시 수동 편집 가능해집니다 — **저장값 자체는 보존**되어
플러그인을 다시 켜면 그대로 복구됩니다. 활성 제공자의 `managed_codes` 에 든 통화는 환율 입력이
잠기고, 그 값을 바꿔 저장하면 422 입니다(비교는 `1e-9` 허용오차 — 화면은 문자열, 저장본은 float).
제공자가 환율을 쓰는 경로는 `EcommerceSettingsService::applyExchangeRates($ratesByCode, $source)`
하나뿐이며, `$source` 가 선택된 제공자가 아니면 거부합니다. 적용 후에는
`sirsoft-ecommerce.currency.after_apply_rates` 액션만 발행합니다(`settings.after_save` 를 쓰면
구독 플러그인과 무한 재귀합니다).

관리 환율은 **화면이 보내지 않고 서버가 채웁니다.** 설정 화면의 저장 본문은 `language_currency` 탭에서
활성 제공자가 관리하는(`_computed.managedRateCodes`) **기존 통화**의 `exchange_rate` 키를 빼고
보냅니다. 받아 둔 값을 그대로 돌려보내면, 화면을 연 사이 제공자가 환율을 갱신한 경우 저장값과 달라져
다른 칸만 고친 저장까지 「관리 환율 변경」 422 로 막히기 때문입니다.
`StoreEcommerceSettingsRequest::prepareForValidation()` 의 `fillOmittedManagedExchangeRates()` 가
**키가 빠진** 관리 통화에만 저장된 환율을 채워 넣고, 값을 명시해 보낸 요청은 종전대로
`validateManagedExchangeRatesUnchanged()` 가 비교해 거절합니다. 따라서 화면 쪽에서 이 키를 다시
싣거나, 서버 쪽에서 「키 없음」을 「변경」으로 판정하게 바꾸면 이 결함이 되살아납니다.

### 설정 저장 요청의 계약 (`PUT …/admin/settings`)

`StoreEcommerceSettingsRequest::SECTIONS` 상수(`basic_info` · `language_currency` · `seo` ·
`order_settings` · `claim` · `shipping` · `review_settings` · `notification_definitions` ·
`notifications` · `inquiry` · `mileage` · `privacy_retention`)가 `_tab` 허용값이자 「저장할 구역이
있는가」의 판정 기준입니다. 이 가운데 배열로 실린 구역이 **하나도 없으면** `validateHasSettingsSection()`
이 `_tab` 에 422(`validation.settings.empty_payload` — 새로고침 후 다시 저장 안내)를 냅니다. 종전에는
빈 요청에도 아무것도 저장하지 않은 채 「설정이 저장되었습니다」로 응답해, 화면의 본문 식이 평가에
실패해 식 원문 문자열이 본문으로 나갔을 때 운영자는 저장된 줄 알았습니다(#703). 새 설정 구역(탭)을
더하면 이 상수에 함께 넣어야 그 탭 저장이 빈 요청으로 거절되지 않습니다.

저장 본문을 만드는 레이아웃 식(`admin_ecommerce_settings.g7.html` 의 저장 `api-call` `body`)에서 객체
키를 뺄 때 **구조분해 매개변수를 쓰지 않습니다** — 식 평가기가 읽지 못하면 본문 전체가 식 원문 문자열로
나가 모든 탭 저장이 아무것도 저장하지 못했습니다(#703 실측). 관리 환율 제외처럼 키를 걸러야 하면
`Object.keys(…).filter(…).reduce(…)` 로 구성합니다.

**무통장입금은 사용할 수 있는 계좌와 짝입니다.** 「사용할 수 있는 계좌」는 사용 중(`is_active`)이고
계좌번호가 채워진 계좌이며, 판정은 `EcommerceSettingsService::hasUsableBankAccount()` 한 곳입니다.
공개 결제 설정(`getPublicPaymentSettings()`)은 그런 계좌가 없으면 무통장입금(`dbank`)을 결제수단
목록에서 걸러 주문서에 내보내지 않고, 저장 검증 `validateDbankRequiresUsableAccount()` 는 무통장입금을
켠 채 그런 계좌가 없으면 `order_settings.bank_accounts` 에 422 를 냅니다. 저장 검증은 이번 요청에
실린 결제수단·계좌를 우선하고 빠진 쪽은 저장값으로 판정하며(결제수단만 또는 계좌만 보내는 저장도
같은 결과), 주문설정을 싣지 않은 다른 탭 저장에는 관여하지 않습니다. 설치 기본값(무통장입금 활성 +
비어 있는 비활성 계좌 1건)을 그대로 두면 주문서가 무통장입금을 기본 선택하고 결제하기가 「입금 은행을
선택해주세요」로만 끝났기 때문에 둔 짝입니다.

**공개 자산 디스크(`basic_info.public_asset_disk`)가 사라진 경우**: 선택지는 코어
`DriverRegistryService::getAvailableDrivers('public_asset')` 카탈로그이고, 설정 조회·저장 응답 모두
`EcommerceSettingsAdminService::withPublicAssetCatalog()` 로 `available_public_asset_disks` 를 붙입니다.
저장된 디스크가 카탈로그에 없으면(그 디스크를 등록하던 확장이 꺼진 경우) 응답은 값을 실제 동작인
스트리밍(`none`)으로 바꿔 싣고, 사라진 디스크 이름을 표시용 키 `basic_info.public_asset_disk_unavailable`
로 알립니다. 기본정보 탭은 이 키가 있으면 「저장했던 디스크를 쓸 수 없게 됐다」는 안내를 보이고, 저장
성공 시 폼의 이 키를 저장 응답값으로 덮어 새 디스크를 고르면 안내가 바로 사라집니다(설정 재조회는 폼에
없는 키를 지우지 않으므로 덮어쓰기가 필요합니다). 이 키는 저장 요청의 `prepareForValidation()` 이
걷어 내므로 설정 파일에 남지 않습니다. 저장값을 그대로 실으면 선택 칸이 어떤 선택지와도 맞지 않고,
기본정보 탭 저장이 다른 항목만 고쳐도 매번 카탈로그 검증 422 에 막혔습니다.

**마일리지 알림 카드의 「활성 채널」** 은 알림 정의의 채널 목록을 코어
`NotificationChannelService::resolveChannelCandidates('sirsoft-ecommerce', …)` 로 거른 뒤 채널별
활성 여부로 좁힙니다. 이 진입점이 채널 제공 확장(문자 등)이 코어 훅에 더한 후보와 이 모듈의
`sirsoft-ecommerce.notification.channels` 필터를 **발송 경로와 같은 순서로** 거치므로, 화면 표시와
실제 발송 채널이 갈라지지 않습니다. 이 모듈 필터만 직접 부르면 코어에 등록된 채널이 카드에서 빠집니다.

### 개인정보 보유기간 (`privacy_retention`, 이슈 #636)

「개인정보 보유기간」 탭이 저장하는 카테고리입니다. 종결 주문의 개인정보를 보유기간이 지나면
익명화하거나 주문째 삭제합니다.

| 키 | 기본값 | 의미 |
|---|---|---|
| `enabled` | `false` | 보유기간 처리 사용 여부 — 되돌릴 수 없는 처리라 기본 꺼짐 |
| `retention_years` | `5` | 종결 후 보유기간(년). **하한 5년**(전자상거래법 §6) — 저장 검증(`limits.privacy_retention_years_min`)과 판독부 바닥값 둘 다 강제 |
| `mode` | `anonymize` | `anonymize`(개인정보만 가림) / `delete`(주문째 삭제) |

실행은 이 모듈이 하지 않습니다 — 코어 `privacy:process-retention`(매일)이 `core.privacy.retention_handlers`
로 등록된 `sirsoft-ecommerce.order`(`src/Privacy/OrderRetentionHandler.php`)를 부르고, 주문 한 건의
처리는 `OrderPrivacyRetentionService` 가 합니다. 대상 건수는 `GET …/admin/settings/privacy-retention-preview`
(저장된 설정 기준, **꺼져 있어도 센다** — 꺼짐은 `enabled` 로 구분)가 커맨드의 `--dry-run` 과 같은 조건식으로 셉니다.
건수 옆 「대상 주문 보기」는 코어 공통 대상 명단 API(`GET /api/admin/privacy/retention-targets/sirsoft-ecommerce.order`)를
무한 스크롤 모달로 엽니다(대상이 0건이어도 버튼은 보이고, 창은 빈 명단을 안내합니다) — 행은 주문번호·주문자·주문 상태·종결일이고 주문 상세로 새 탭을 엽니다(휴지통 주문은 관리자 상세가 없어 링크 없이 주문번호만 — 대상에는 든다). 권한은 적용 범위가 전체여야 합니다(범위를 좁혀 위임받은 관리자는 건수만 봅니다). 명단은 주문자 이름이
그대로 나오므로 `sirsoft-ecommerce.settings.read` + `sirsoft-ecommerce.orders.read` 가 모두 있어야 보입니다.
핸들러 계약 전체는 코어 [개인정보 보유기간 처리 가이드](../../../../docs/backend/privacy-retention.md)에 있습니다.

「보유기간이 지난 종결 주문」 대상 행(`privacy_retention_target_row` — 이름 · 건수 · 대상 보기 · 결과 줄)의 **「지금 실행」**은 코어 관리자 직권 실행(`retention:sirsoft-ecommerce.order`)입니다 — 핸들러를 등록한 것만으로
코어가 대상을 만들므로 이 모듈에 별도 등록은 없습니다. 확인 창이 코어 `…/manual-runs/{target}/preview` 로 건수·방식을
다시 세고, 「실행」은 큐에 접수합니다(`enabled` 가 꺼져 있어도 저장된 기간·방식으로 `process(force: true)` 한 번).
결과는 탭의 상태 줄이 코어 상태 API(`privacyManualRuns` 데이터 소스)로 보여 주고, 진행 중이면 3초마다 다시 받습니다.
실행 권한은 `runPermissions()` — `sirsoft-ecommerce.settings.update` + `sirsoft-ecommerce.orders.update`, 방식이
`delete` 면 `sirsoft-ecommerce.orders.delete` 까지(전체 범위). 계약은 코어 가이드 「관리자 직권 실행」 절 참조.
확인 창의 실행 버튼은 `lockRepeatClick` 으로 첫 클릭 즉시 잠급니다 — 빠른 두 번째 클릭이 접수를 한 번 더 넣지 않도록(코어 확인 창과 같은 규칙).

- **「종결」**: 주문 상태 구매확정·취소·반품완료 + 진행 중 환불·교환·반품 없음(완료·거절·철회가 아닌 상태 — 교환·반품은
  진행 중에는 주문 상태를 바꾸지 않고 주문 곁에서 진행되므로 신청서 상태로 봅니다. 반품을 마친 뒤 살아 있는 상품이 하나도
  남지 않으면 주문이 반품완료가 됩니다). 종결 시각은 `confirmed_at`/`cancelled_at`(반품완료는 마지막 반품 완료 시각) 과
  완료된 환불의 `refunded_at`·완료된 교환·반품의 `completed_at` 중 가장 늦은 것이고, 비어 있는 레거시 주문은
  `updated_at` 으로 대신합니다(실제보다 늦거나 같아 더 오래 보존하는 쪽으로만 틀립니다). 거절·철회로 끝난
  신청서는 종결 시각을 늦추지 않습니다. 배송 중·구매확정 전 주문은 어떤 경우에도 대상이 아닙니다.
- **익명화가 NULL 이 아니라 `***` 인 이유**: 주소 테이블의 이름·연락처·우편번호·주소가 NOT NULL 이라
  스키마를 건드리지 않고 치환값을 넣습니다(nullable 칸만 NULL). 주문번호·금액·상태는 법정 거래
  기록이라 남으므로 결제 플러그인의 주문번호 역조회(영수증·정산 대사)는 계속 동작합니다. 대신
  **비회원 주문조회(주문자 연락처 인증)와 관리자 주문 검색(이름·연락처)으로는 찾을 수 없습니다**.
- **결제 칸에서 지우는 것과 남기는 것**: 구매자 연락처·입금/환불 계좌 명의·현금영수증 식별번호·
  **카드번호 마스킹본**(앞뒤 자리가 카드를 가리키는 파생 식별자)·접속 환경은 지웁니다. 카드 승인번호·
  카드사명·할부개월은 결제 대행사와의 대사·취소 조회에 쓰는 대금결제 기록이라 남깁니다. 세금계산서는
  사업자 정보(상호·등록번호·주소)를 두고 대표자명·담당자 연락처만 지웁니다.
- **교환·반품·환불 신청서**: 환불 계좌 명의·번호와 상세 사유·관리자 메모(상담 중 받아 적은 연락처가 섞이는
  자유 기재 칸)는 NULL, 교환 재배송지 스냅샷은 주문 배송지와 같은 기준(받는 분·연락처·우편번호·주소 `***`,
  상세 주소 NULL, 국가 유지)으로 가립니다. 신청서 번호·은행 코드·사유 코드·금액·상태와 첨부 사진은
  청약철회 기록이라 남깁니다.
- **주문 메타의 사본**: 주소 테이블만 가리면 사본이 남습니다. PG 결제에서 「배송지 저장」을 고르고
  결제가 끝나지 않아 자동 취소된 주문은 저장 대기 배송지 전체(`order_meta.shipping_info_for_save`)를,
  임시 주문을 거친 주문은 배송비 계산 주소(`order_meta.calculation_input.shipping_address`)를 갖고
  있습니다. 익명화는 앞의 것을 지우고 뒤의 것은 국가 코드만 남깁니다.
- **확장이 남긴 사본 — `sirsoft-ecommerce.order.after_anonymize`**: 주문 한 건을 익명화할 때마다
  주문 ID 를 인자로 발행합니다. 결제 플러그인처럼 자기 칸에 주문자·수령인 정보를 복사해 둔 확장은
  이 훅에서 그 사본을 지웁니다(예: `sirsoft-pay_kginicis` 가 에스크로 배송 등록의 수령인 이름·주소를
  지운다). 익명화 트랜잭션 안에서 발행하므로 리스너는 `sync: true` 로 구독하고, 던지면 그 주문의
  익명화 전체가 롤백되어 다음 실행이 다시 잡습니다.
- **삭제가 잃게 하는 것**: 주문 옵션에 NOT NULL 로 묶인 **상품 리뷰가 이미지째** 사라지고(평점 변동),
  교환·반품 신청서·클레임 결제와 **클레임 첨부 사진이 파일째**(신청으로 이어지지 않은 임시 첨부 포함) 사라지며,
  결제 플러그인 화면의 영수증·거래 조회가 그 주문번호로 열리지 않으며(FK 없는 문자열 참조),
  쿠폰 발급·마일리지 거래는 남지만 주문·클레임 결제·환불 연결이 끊깁니다. 그래서 기본은 익명화이고, 설정 화면이
  삭제를 고를 때 이를 적색으로 경고합니다.
- **FK 없는 문자열 참조 — 삭제가 고아로 만드는 것**: 결제 플러그인 네 개(`sirsoft-pay_kginicis` ·
  `sirsoft-pay_nhnkcp` · `sirsoft-pay_nicepayments` · `sirsoft-tosspayments`)의 거래·영수증 기록은
  주문을 `order_number` 문자열로 가리킵니다. 외래키가 없어 이 모듈의 명시 삭제 목록에 잡히지 않고,
  삭제 모드 뒤에는 그 기록이 가리킬 주문이 없어집니다(익명화는 주문번호를 남기므로 해당 없음).
- **운영자 사전 고지**: 켜기 전에 개인정보 처리방침에 보유기간과 파기 방법을 안내해야 합니다.

연간 개인정보 이용내역 통지(`core.privacy.notice_sections`)에는 `PrivacyRetentionListener` 가
「주문 정보」 섹션을 수신자 로케일로 기여합니다 — 법정 보존(5년) 사실을 적고, 처리를 켰다면
방식과 기간을 덧붙입니다. 개인 식별정보는 넣지 않습니다.
<!-- @intent END -->

## 권한

<!-- @generated:permissions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 카테고리 | 이름 | 액션 | 라우트 키 |
|---|---|---|---|
| `products` | 상품 관리 | `read`, `create`, `update`, `delete` | `product` |
| `orders` | 주문 관리 | `read`, `update`, `delete` | `order` |
| `claims` | 취소/반품/교환 관리 | `read`, `update-status`, `create`, `update-amounts`, `execute-refund` | `order` |
| `categories` | 카테고리 관리 | `read`, `create`, `update`, `delete` | - |
| `brands` | 브랜드 관리 | `read`, `create`, `update`, `delete` | `brand` |
| `product-notice-templates` | 상품정보제공고시 관리 | `read`, `create`, `update`, `delete` | - |
| `product-common-infos` | 공통정보 관리 | `read`, `create`, `update`, `delete` | - |
| `settings` | 환경설정 | `read`, `update` | - |
| `promotion-coupon` | 쿠폰 관리 | `read`, `create`, `update`, `delete` | `coupon` |
| `shipping-policies` | 배송정책 관리 | `read`, `create`, `update`, `delete` | `shippingPolicy` |
| `product-labels` | 상품 라벨 관리 | `read`, `create`, `update`, `delete` | - |
| `identity.policies` | 이커머스 본인인증 정책 | `read`, `update` | - |
| `reviews` | 리뷰 관리 | `read`, `update`, `delete` | `review` |
| `inquiries` | 문의 관리 | `read`, `update`, `delete` | - |
| `dashboard` | 대시보드 | `view` | - |
| `user-products` | 사용자 상품 | `read` | - |
| `user-orders` | 사용자 주문 | `create`, `cancel`, `confirm` | - |
| `user-claims` | 사용자 클레임 | `request`, `read`, `withdraw`, `pay` | - |
| `user-reviews` | 사용자 리뷰 | `write` | - |
| `wishlists` | 찜 관리 | `read`, `manage` | `wishlist` |
| `carts` | 장바구니 관리 | `read`, `manage` | `cart` |
| `user-addresses` | 회원 배송지 관리 | `read`, `manage` | `user-address` |
| `mileage` | 마일리지 관리 | `read`, `manage` | `mileage-transaction` |
| `user-currency` | 회원 결제 통화 관리 | `manage` | - |
| `user-shipping-country` | 회원 배송국가 관리 | `manage` | - |
<!-- @generated:permissions END -->

<!-- @intent START -->
권한 22종은 네 무리로 갈립니다.

- **관리자 CRUD 13종** (`products` · `orders` · `claims` · `categories` · `brands` ·
  `product-notice-templates` · `product-common-infos` · `promotion-coupon` ·
  `shipping-policies` · `product-labels` · `reviews` · `inquiries` · `mileage`): 관리자 화면과
  1:1 대응하며, 라우트 키가 있는 것은 그 라우트에 스코프 미들웨어가 걸립니다.
- **사용자 측 4종** (`user-products` · `user-orders` · `user-claims` · `user-reviews`): 구매자가 자기 자원에 대해
  갖는 권한입니다. 관리자 권한과 이름이 겹치지 않도록 `user-` 접두사를 씁니다.
- **회원 선호값 관리 2종** (`user-currency` · `user-shipping-country`): 이름은 `user-` 로 시작하지만
  **관리자 권한**입니다. 관리자가 회원 수정 화면에서 다른 회원의 결제 통화·배송국가를 **바꿀 때만**
  확인되며(코어 회원 저장 요청의 검증 단계), 없으면 그 칸에 검증 오류가 뜹니다. 구매자가 자기 선호값을
  바꾸는 데에는 권한이 필요 없습니다.
- **횡단 3종** (`settings` · `dashboard` · `identity.policies`): 화면 하나에 대응합니다.

`orders` 에 `create`/`delete` 가 없는 것은 의도입니다 — 주문은 구매자 결제로 생기고
(`user-orders.create`), 삭제 대신 취소·환불로 처리합니다. `inquiries` 에 `read` 가 없는 것도
같은 성격입니다: 문의 **본문은 게시판 모듈이 소유**하므로 읽기 권한은 그 게시판의 권한이
정하고, 이 모듈은 답변·삭제만 관장합니다.

새 관리자 화면을 추가하면 권한·메뉴·라우트 이름 셋이 서로를 정확히 가리키는지 확인합니다.
권한만 추가하고 메뉴를 빠뜨리면 화면에 도달할 길이 없고, 반대면 눌러도 403 입니다.

클레임은 관리자 `claims`(조회·상태 변경·금액 수정·환불 실행)와 사용자 `user-claims`(신청·조회·
철회·결제) 두 그룹입니다. 사용자 그룹이 따로 있는 이유는 **특정 회원의 신청 권한만 회수**할 수
있게 하기 위해서입니다 — 신청 권한을 뺀 회원은 화면 진입부터 막힙니다. 비회원 클레임 경로도 같은
`user-claims.*` 를 guest 역할로 판정하므로, guest 역할에서 빼면 비회원 창구가 함께 닫힙니다.

관리자 `claims` 의 스코프 축은 **주문의 것을 물려받습니다**(`orders` 와 같은 라우트 키 `order` ·
소유자 컬럼 `user_id`). 신청서는 언제나 한 주문에 속하므로, 역할에 `self`·`role` 스코프를 걸면 그 신청서가
속한 주문이 스코프 안일 때만 목록에 보이고 열리고 처리됩니다 — 주문을 볼 수 없는 운영자가 신청서로는
같은 주문의 환불 계좌를 보거나 환불을 실행하는 우회로를 막습니다. 판정은 클레임 권한 범위와 그 운영자의
**주문 조회 범위의 교집합**이라, 주문 조회를 「본인만」 으로 묶으면 클레임 권한을 「전체」 로 두어도 본인
주문의 신청서만 다룹니다. 주문 조회 권한이 아예 없는 클레임 전담 역할은 클레임 권한 범위만 따릅니다.
<!-- @intent END -->

## 메뉴

<!-- @generated:menus START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 구분 | slug | 이름 | URL | 하위 |
|---|---|---|---|---|
| 관리자 | `sirsoft-ecommerce` | 이커머스 | - | 16개 |
<!-- @generated:menus END -->

<!-- @intent START -->
최상위 `sirsoft-ecommerce` 아래 11개 하위 메뉴가 붙습니다 — 환경설정 · 상품 · 카테고리 ·
브랜드 · 상품정보제공고시 · 공통정보 · 주문 · 쿠폰 · 배송정책 · 리뷰 · 마일리지 내역.

메뉴는 **권한과 짝을 이룰 때만 보입니다.** 운영자에게 역할이 부여되어도 그 역할에 해당 권한이
없으면 메뉴가 렌더되지 않으므로, 새 화면을 추가할 때는 `getPermissions()` 와 `getAdminMenus()`
를 함께 바꿉니다.

권한 표에는 있는데 메뉴가 없는 것들(`dashboard` · `identity.policies` · `product-labels` 등)은
독립 메뉴가 아니라 다른 화면 안에 들어 있기 때문입니다 — 대시보드는 코어 관리자 첫 화면에
레이아웃 조각으로 주입되고, 본인인증 정책은 쇼핑몰 환경설정의 「본인인증 정책」 탭에서 다뤄지며, 상품 라벨은
상품 관리 화면 안에 있습니다.

「본인인증 정책」 탭은 7.1.0 부터 `identity.policies.read`(조회)·`identity.policies.update`(추가·수정·삭제) 권한으로
동작합니다. 탭은 이커머스 경로(`/api/modules/sirsoft-ecommerce/admin/identity-policies`)를 쓰고, 그 경로는 코어 정책 표에서
이커머스 몫(`source_identifier = sirsoft-ecommerce`)만 다룹니다. 조회 권한이 없으면 탭에 「권한 없음」 안내가, 수정 권한이
없으면 읽기 전용 안내가 뜨고 추가·켜고 끄기·편집·삭제가 잠깁니다. 두 권한은 기본 관리자 역할(`admin`)에 기본으로 주어집니다.
코어 관리자 환경설정의 본인인증 정책 화면은 코어 권한(`core.admin.identity.policies.*`)으로 모든 출처의 정책을 다룹니다.

주문 관리 직후에 '취소/반품/교환 관리'(`admin/ecommerce/claims`) 메뉴가 붙습니다 — `claims`
그룹의 조회 권한과 짝입니다.
<!-- @intent END -->

## 라우트

<!-- @generated:routes START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 파일 | URL prefix |
|---|---|---|
| `api` | `src/routes/api.php` | `/api/modules/sirsoft-ecommerce/...` |

확장 라우트는 **활성 상태인 확장의 것만** 등록됩니다. 라우트 정의를 바꾸면 라우트 캐시 재생성이 필요합니다.
<!-- @generated:routes END -->

<!-- @intent START -->
라우트 239개가 파일 하나(`src/routes/api.php`)에 모여 있고 전부 `/api/modules/sirsoft-ecommerce/`
아래로 나갑니다. 화면용 라우트는 없습니다 — 관리자 화면은 레이아웃 JSON 이 이 API 를 호출해
그리고, 방문자 화면은 템플릿이 같은 API 를 씁니다.

대상별로 셋으로 갈립니다:

| 무리 | 인증 | 비고 |
|---|---|---|
| `admin.*` | 관리자 인증 + 권한 스코프 | 라우트 키가 선언된 권한이 여기에 걸립니다 |
| `user.*` · 공개 조회 | Sanctum(일부는 `optional.sanctum`) | 비로그인도 상품·카테고리는 봅니다 |
| `guest.orders.*` | `VerifyGuestOrderToken` | 비회원 주문 조회·취소. 토큰이 곧 신원이므로 **새 라우트를 추가하면 미들웨어 선언(`getMiddleware()`)에도 그 이름을 반드시 추가**합니다 |

라우트를 추가·변경한 뒤에는 라우트 캐시를 다시 구워야 합니다. 확장 라우트는 **활성 상태인
확장의 것만** 등록되고, 캐시에 없는 라우트는 예외도 경고도 없이 그대로 404 가 됩니다.

모든 라우트에 `name()` 이 필요합니다 — 이름이 없으면 미들웨어 self-gate 의 `targets` 패턴과
IDV 정책의 라우트명 인덱스가 그 라우트를 찾지 못해, 보호가 걸린 것처럼 보이지만 실제로는
통과합니다.

클레임 라우트는 세 무리 모두에 걸칩니다 — 관리자 `admin/claims` · `admin/{exchanges,returns,refunds}/{id}`
계열, 구매자 `user/orders/{id}/claims/…` + `user/claims/{claimNumber}` 단일 서브트리(클레임 번호의
접두사 EX/RT/RF/CN 이 유형을 내포하므로 유형 세그먼트를 두지 않습니다), 비회원
`guest/orders/{orderNumber}/claims/…` 동형. 목록 `page` 상한은 코어
`PaginationLimits::maxPage('ecommerce.claims')` 를 그대로 씁니다.
<!-- @intent END -->

## 의존 관계

<!-- @generated:dependencies START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
**이 확장이 의존하는 확장**

없음 — 코어만으로 동작합니다.

**이 확장에 의존하는 확장** (이 확장을 비활성화하면 함께 영향을 받습니다)

| 확장 | 유형 | 요구 버전 |
|---|---|---|
| `sirsoft-koreaexim` | 플러그인 | `>=1.3.0` |
| `sirsoft-pay_kginicis` | 플러그인 | `>=1.3.0` |
| `sirsoft-pay_nhnkcp` | 플러그인 | `>=1.3.0` |
| `sirsoft-pay_nicepayments` | 플러그인 | `>=1.3.0` |
| `sirsoft-tosspayments` | 플러그인 | `>=1.3.0` |
| `sirsoft-basic` | 템플릿 | `>=1.3.0` |

**함께 쓰면 연동되는 확장** (manifest `integrations` — 없어도 이 확장은 동작합니다)

| 확장 | 유형 | 번들 |
|---|---|---|
| `sirsoft-board` | 모듈 | ✅ |

**이 확장과 연동하는 확장** (이 확장이 있으면 기능을 더합니다 — 이 확장의 화면·훅 이름을 바꿀 때 함께 확인)

| 확장 | 유형 |
|---|---|
| `sirsoft-message_bizppurio` | 플러그인 |
<!-- @generated:dependencies END -->

<!-- @intent START -->
이 모듈은 **아무 확장에도 의존하지 않습니다.** 코어만 있으면 동작하며, 관계는 전부 한 방향으로
들어옵니다 — 결제 플러그인 4종과 템플릿 `sirsoft-basic` 이 이 모듈을 요구합니다.

그 방향이 뒤집히지 않게 유지하는 것이 이 모듈 설계의 핵심입니다. PG 이름을 이 모듈 코드에 넣는
순간 의존이 양방향이 되고, 새 PG 를 붙일 때마다 이 모듈을 고쳐야 합니다.

manifest 에는 없지만 **실제로 맞물리는 확장이 둘 더** 있습니다:

| 확장 | 무엇으로 연결되는가 | 없으면 |
|---|---|---|
| `sirsoft-board` | 훅 3종 구독 (문의 글 삭제·복원·일괄삭제 시 피벗 정리) + 설정 `inquiry.board_slug` | 상품 문의 기능만 비고 나머지는 정상 |
| `sirsoft-ckeditor5` | 훅 1종 (편집기가 참조할 이커머스 리소스 목록 제공) | 편집기에서 상품 링크를 고를 수 없을 뿐 |

훅 구독은 상대가 없으면 발화하지 않으므로 이 둘을 manifest 의존으로 올리지 않는 것이 맞습니다.
다만 그 대가로 **상대 확장이 훅 이름을 바꾸면 예외 없이 조용히 연동이 끊깁니다** — 상대의
`docs/extension-points.md` 를 함께 확인해야 하는 이유입니다.

이 모듈의 공개 표면(Service·Repository·Contracts·라우트·발행 훅)을 바꿀 때는 위 4+1 개 확장의
`dependencies` 최소 버전 상향이 필요한지 검토합니다.

클레임 추가결제가 이 방향을 유지하는 예입니다 — 결제 플러그인 4종은 이 모듈의
`claim_payment.approve` / `claim_payment.refund` 필터를 **구현**하고, 그 필터가 처음 생긴 1.3.0 을
`dependencies.modules.sirsoft-ecommerce` 하한으로 잡습니다. 하한이 그보다 낮으면 필터가 없는
모듈로도 제약이 충족되어, 플러그인은 정상 활성화된 채 추가결제만 동작하지 않습니다(오류·로그 없음).
<!-- @intent END -->
