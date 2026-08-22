# Daum 우편번호 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 2종 / 호출 지점 0곳.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `sirsoft-daum_postcode.address.selected` | action | 주소 선택 완료 시 실행되는 액션 훅 | 선언 (호출 위치 미확인) |
| `sirsoft-daum_postcode.filter_address_data` | filter | 선택된 주소 데이터를 필터링하는 훅 | 선언 (호출 위치 미확인) |
<!-- @generated:hooks-published END -->

<!-- @intent START -->
2종이며 **선택된 주소를 가로채는 전/후 한 쌍**입니다.

| 훅 | 시점 | 파라미터 |
|---|---|---|
| `filter_address_data` | 필드에 쓰기 **전** | `data`(주소 배열) → 가공된 배열 반환 |
| `address.selected` | 확정 **후** | `zonecode` · `address` · `roadAddress` · `jibunAddress` · `buildingName` |

앞의 것은 "무엇을 어느 칸에 넣을 것인가" 를 사이트가 정하는 자리입니다 — 도로명과 지번 중
어느 것을 기본 주소로 쓸지, 건물명을 상세주소 칸에 미리 채울지는 사이트마다 다르므로 코드에
고정하지 않습니다. 뒤의 것은 "주소가 정해졌으니 이제 무엇을 할 것인가" 로, 배송비 재계산이나
배송 가능 지역 판정을 붙이는 자리입니다.

발행 위치가 "선언(호출 위치 미확인)" 인 것은 실제 발행이 **프론트 핸들러**에서 이루어져 PHP
소스 스캔에 잡히지 않기 때문입니다. `getHooks()` 에 파라미터까지 선언되어 있으므로 계약은
그 선언이 SSoT 입니다.

구독 훅은 없습니다 — 이 플러그인은 다른 확장의 흐름에 개입하지 않습니다.
<!-- @intent END -->

## 구독 훅

<!-- @generated:hooks-subscribed START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_이 확장은 훅을 구독하지 않습니다._
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
없습니다. 이 플러그인은 자기 확장점 안에서만 동작하며 다른 확장의 흐름에 끼어들지 않습니다.

관계는 반대 방향입니다 — 주소를 다루는 확장이 **이 플러그인의 훅을 구독**합니다. 그래서 이
플러그인이 훅 이름이나 파라미터를 바꾸면 그쪽 배선이 예외 없이 조용히 끊깁니다.
<!-- @intent END -->

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_훅 리스너가 없습니다._
<!-- @generated:listeners END -->

<!-- @intent START -->
없습니다. 구독하는 훅이 없고 서버에서 하는 일도 없으므로 리스너가 필요하지 않습니다.

이 플러그인의 동작은 전부 프론트 핸들러 둘(`setFieldReadOnly` · `openPostcode`)에 있습니다.
<!-- @intent END -->

## 레이아웃 확장

<!-- @generated:layout-extensions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 대상 | 설명 |
|---|---|
| `resources/extensions/ecommerce-address-search.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
조각 하나가 이 플러그인의 **UI 본체**입니다.

파일 이름은 `ecommerce-address-search.json` 이지만 **확장점 이름(`address_search_slot`)으로
매칭**되므로 이커머스 전용이 아닙니다. 그 확장점을 여는 화면이면 어디든 붙습니다 — 이름은
최초 도입 맥락이 남은 것일 뿐입니다.

조각의 `scripts` 에 **외부 호스트 URL 이 문자열로 박혀 있습니다.** 같은 URL 이
`resources/js/handlers/postcodeSdk.ts` 의 `DAUM_POSTCODE_SDK_URL` 상수에도 있으므로, 주소가
바뀌면 **두 곳을 함께** 고쳐야 합니다. 한쪽만 고치면 조각이 로드한 스크립트와 핸들러가 찾는
스크립트가 달라져 확보 판정이 어긋납니다.

대상 화면을 소유한 쪽이 그 확장점을 없애면 조각은 오류 없이 사라집니다 — 증상은 "주소 입력
화면에 검색 버튼이 없다" 뿐입니다.
<!-- @intent END -->

## 미들웨어

<!-- @generated:middleware START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 미들웨어가 없습니다._
<!-- @generated:middleware END -->

<!-- @intent START -->
없습니다. 이 플러그인에는 라우트가 없으므로 요청 흐름 자체가 없습니다.
<!-- @intent END -->

## 브로드캐스트 채널

<!-- @generated:channels START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 브로드캐스트 채널이 없습니다._
<!-- @generated:channels END -->

<!-- @intent START -->
없습니다. 주소 검색은 한 사용자의 브라우저 안에서 끝나는 일이라 다른 접속자에게 알릴 사건이
없습니다.
<!-- @intent END -->

## 스케줄

<!-- @generated:schedules START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 스케줄이 없습니다._
<!-- @generated:schedules END -->

<!-- @intent START -->
없습니다. 저장하는 데이터가 없으므로 주기적으로 정리하거나 갱신할 대상이 없습니다.
<!-- @intent END -->

## 알림 정의

<!-- @generated:notifications START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 알림 정의가 없습니다._
<!-- @generated:notifications END -->

<!-- @intent START -->
없습니다. 주소 선택은 사용자 자신의 조작이므로 통지할 사건이 아닙니다.

SDK 확보 실패 통지는 알림 시스템이 아니라 **화면 안의 자산 실패 통지**
(`G7Core.assets.notifyFailure`)로 처리합니다. 지금 이 화면에서 무엇을 할 수 없는지를 그
자리에서 알려야 하고, 메일이나 앱 알림으로 보낼 성질이 아니기 때문입니다.
<!-- @intent END -->
