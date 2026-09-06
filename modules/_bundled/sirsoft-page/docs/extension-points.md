# 페이지 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 21종 / 호출 지점 23곳. 이 중 21종은 `getHooks()` 선언에 없어 소스에서 자동 감지한 것입니다 — 선언에 추가하면 유형과 설명이 함께 실립니다.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `sirsoft-page.attachment.after_delete` | action | — | `src/Services/PageAttachmentService.php:208` |
| `sirsoft-page.attachment.after_reorder` | action | — | `src/Services/PageAttachmentService.php:335` |
| `sirsoft-page.attachment.after_upload` | action | — | `src/Services/PageAttachmentService.php:139` |
| `sirsoft-page.attachment.before_delete` | action | — | `src/Services/PageAttachmentService.php:200` |
| `sirsoft-page.attachment.before_reorder` | action | — | `src/Services/PageAttachmentService.php:331` |
| `sirsoft-page.attachment.before_upload` | action | — | `src/Services/PageAttachmentService.php:90` |
| `sirsoft-page.attachment.filter_upload_file` | filter | — | `src/Services/PageAttachmentService.php:92` |
| `sirsoft-page.page.after_create` | action | — | `src/Services/PageService.php:106` |
| `sirsoft-page.page.after_delete` | action | — | `src/Services/PageService.php:190` |
| `sirsoft-page.page.after_publish` | action | — | `src/Services/PageService.php:223` 외 1곳 |
| `sirsoft-page.page.after_restore` | action | — | `src/Services/PageService.php:322` |
| `sirsoft-page.page.after_update` | action | — | `src/Services/PageService.php:159` |
| `sirsoft-page.page.before_create` | action | — | `src/Services/PageService.php:75` |
| `sirsoft-page.page.before_delete` | action | — | `src/Services/PageService.php:179` |
| `sirsoft-page.page.before_publish` | action | — | `src/Services/PageService.php:209` 외 1곳 |
| `sirsoft-page.page.before_update` | action | — | `src/Services/PageService.php:127` |
| `sirsoft-page.page.filter_content_thumbnail` | filter | — | `src/Models/Page.php:128` |
| `sirsoft-page.page.filter_create_data` | filter | — | `src/Services/PageService.php:77` |
| `sirsoft-page.page.filter_list_query` | filter | — | `src/Services/PageService.php:60` |
| `sirsoft-page.page.filter_update_data` | filter | — | `src/Services/PageService.php:131` |
| `sirsoft-page.search.page.index_should_update` | filter | — | `src/Models/Page.php:270` |
<!-- @generated:hooks-published END -->

<!-- @intent START -->
21종은 두 도메인의 3단 패턴이 대부분입니다 — `page.*` 14종과 `attachment.*` 7종이
`before_{동작}`(action) → `filter_{동작}_data`(filter) → `after_{동작}`(action) 을 반복합니다.
CRUD 동작을 바꾸고 싶으면 이 셋 중 하나를 잡습니다.

패턴에서 벗어나는 셋이 이 모듈 고유의 확장점입니다:

| 훅 | 무엇을 열어 주는가 |
|---|---|
| `page.filter_content_thumbnail` | 본문에서 대표 이미지를 뽑는 규칙. 본문 형식이 특이한 사이트가 자기 방식으로 바꿉니다 (모델에서 발행되므로 조회 경로 전체에 걸립니다) |
| `search.page.index_should_update` | 어떤 변경에 검색 색인을 다시 태울지. 색인 비용이 큰 설치가 조건을 좁히는 자리입니다 |
| `attachment.filter_upload_file` | 저장 직전 파일 가공(리사이즈·형식 변환) |

`page.after_restore` 는 소프트 삭제 복원이 아니라 **버전 복원**입니다. 이 모듈은 소프트 삭제를
쓰지 않으므로 "복원" 이라는 말이 나오면 언제나 버전 이력 쪽입니다.

발행 훅이 `getHooks()` 선언에 없어 소스에서 자동 감지된 상태입니다. 선언에 추가하면 유형과
설명이 표에 함께 실리며, 이 모듈처럼 훅 수가 적은 확장은 선언을 채우는 비용이 낮습니다.
<!-- @intent END -->

## 구독 훅

<!-- @generated:hooks-subscribed START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 훅 이름 | 유형 | 리스너 | 메서드 | 우선순위 |
|---|---|---|---|---|
| `core.activity_log.filter_description_params` | filter | `ActivityLogDescriptionResolver` | `resolveDescriptionParams` | 10 |
| `core.search.build_response` | filter | `SearchPagesListener` | `buildPagesResponse` | 10 |
| `core.search.index_validation_rules` | filter | `SearchPagesListener` | `addValidationRules` | 10 |
| `core.search.results` | filter | `SearchPagesListener` | `searchPages` | 10 |
| `sirsoft-ckeditor5.image.filter_reference_sources` | filter | `Ckeditor5ReferenceSourcesListener` | `addPageSources` | 10 |
| `sirsoft-page.attachment.after_delete` | action (미선언) | `PageActivityLogListener` | `handleAttachmentAfterDelete` | 20 |
| `sirsoft-page.attachment.after_upload` | action (미선언) | `PageActivityLogListener` | `handleAttachmentAfterUpload` | 20 |
| `sirsoft-page.page.after_create` | action (미선언) | `PageActivityLogListener` | `handlePageAfterCreate` | 20 |
| `sirsoft-page.page.after_create` | action (미선언) | `SeoPageCacheListener` | `onPageChange` | 20 |
| `sirsoft-page.page.after_delete` | action (미선언) | `PageActivityLogListener` | `handlePageAfterDelete` | 20 |
| `sirsoft-page.page.after_delete` | action (미선언) | `SeoPageCacheListener` | `onPageDelete` | 20 |
| `sirsoft-page.page.after_publish` | action (미선언) | `PageActivityLogListener` | `handlePageAfterPublish` | 20 |
| `sirsoft-page.page.after_publish` | action (미선언) | `SeoPageCacheListener` | `onPageChange` | 20 |
| `sirsoft-page.page.after_restore` | action (미선언) | `PageActivityLogListener` | `handlePageAfterRestore` | 20 |
| `sirsoft-page.page.after_restore` | action (미선언) | `SeoPageCacheListener` | `onPageChange` | 20 |
| `sirsoft-page.page.after_update` | action (미선언) | `PageActivityLogListener` | `handlePageAfterUpdate` | 20 |
| `sirsoft-page.page.after_update` | action (미선언) | `SeoPageCacheListener` | `onPageChange` | 20 |
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
17개 중 12개는 자기 훅입니다(활동 로그 7 + SEO 캐시 5). 바깥을 향한 5개가 이 모듈이 다른
확장과 맞물리는 전부입니다:

| 상대 훅 | 리스너 | 무엇을 위해 |
|---|---|---|
| `core.search.results` · `core.search.build_response` · `core.search.index_validation_rules` | `SearchPagesListener` | 사이트 통합 검색 결과에 페이지를 섞고, 검색 요청의 검증 규칙에 페이지 축을 더합니다 |
| `core.activity_log.filter_description_params` | `ActivityLogDescriptionResolver` | 활동 로그 문장의 치환 변수(페이지 제목 등)를 ID 에서 표시명으로 해석합니다 |
| `sirsoft-ckeditor5.image.filter_reference_sources` | `Ckeditor5ReferenceSourcesListener` | 편집기가 이미지를 고를 때 페이지 첨부를 출처 목록에 더합니다 |

`sirsoft-ckeditor5` 는 **manifest 의존에 없습니다.** 훅 구독은 상대가 없으면 발화하지 않으므로
편집기 플러그인이 없어도 이 모듈은 정상 동작하고 그 기능만 비어 있습니다. 대신 상대가 훅
이름을 바꾸면 예외 없이 조용히 끊기므로, 코어 검색이나 ckeditor5 를 손댈 때 이 구독이 함께
확인 대상입니다.

리스너에서 `Model::query()` · `DB::table()` · `$row->save()` 를 직접 부르지 않습니다 — 데이터
접근은 Repository 인터페이스 주입으로만 합니다.
<!-- @intent END -->

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 리스너 | 구독 훅 | 등록 방식 | HookListenerInterface | 파일 |
|---|---|---|---|---|
| `ActivityLogDescriptionResolver` | 1개 | 명시 등록 | ✅ | `src/Listeners/ActivityLogDescriptionResolver.php` |
| `Ckeditor5ReferenceSourcesListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/Ckeditor5ReferenceSourcesListener.php` |
| `PageActivityLogListener` | 7개 | 명시 등록 | ✅ | `src/Listeners/PageActivityLogListener.php` |
| `SearchPagesListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/SearchPagesListener.php` |
| `SeoPageCacheListener` | 5개 | 명시 등록 | ✅ | `src/Listeners/SeoPageCacheListener.php` |
<!-- @generated:listeners END -->

<!-- @intent START -->
5개 전부 `HookListenerInterface` 를 구현하고 `getSubscribedHooks()` 로 자기 구독을 선언합니다.

| 리스너 | 역할 |
|---|---|
| `PageActivityLogListener` | 페이지·첨부 변경을 코어 `activity_logs` 에 기록 |
| `ActivityLogDescriptionResolver` | 그 기록의 설명 변수(ID → 표시명) 해석 |
| `SeoPageCacheListener` | 내용이 바뀌면 봇 화면 캐시 무효화 |
| `SearchPagesListener` | 코어 통합 검색에 페이지 결과 편입 |
| `Ckeditor5ReferenceSourcesListener` | 편집기 이미지 출처에 페이지 첨부 제공 |

새 활동 로그 항목을 더할 때는 코어 `lang/{ko,en}/activity_log.php` 의 action 라벨과 description
본문이 함께 필요합니다 — **모듈 lang 파일에 넣으면 해석되지 않습니다.** 번들 일본어 팩도 같은
작업 단위에서 동기화합니다.
<!-- @intent END -->

## 레이아웃 확장

<!-- @generated:layout-extensions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_레이아웃 확장이 없습니다._
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
없습니다. 이 모듈은 다른 확장·템플릿의 화면에 조각을 주입하지 않습니다.

페이지 내용을 다른 화면에 노출하고 싶다면 그 화면을 소유한 쪽(템플릿 또는 그 모듈)이 이
모듈의 공개 API 를 호출하는 것이 맞는 방향입니다. 여기에 조각을 더하면 대상 화면이 슬롯을
없앨 때 오류 없이 사라지는 결합이 생깁니다.
<!-- @intent END -->

## 미들웨어

<!-- @generated:middleware START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 미들웨어가 없습니다._
<!-- @generated:middleware END -->

<!-- @intent START -->
없습니다. 이 모듈의 라우트는 코어가 제공하는 인증 미들웨어(`auth:sanctum` ·
`optional.sanctum`)와 요율 제한만 씁니다.

발행 상태·열람 권한 판정은 미들웨어가 아니라 **컨트롤러 안에서** 이루어집니다. 페이지 본문과
첨부 두 종류의 응답에 서로 다른 판정이 필요하고(첨부 미리보기는 서명 링크도 인정), 그 차이를
미들웨어 하나로 표현하면 어느 쪽이든 과하거나 모자라기 때문입니다. 그 대신 **경로마다 게이트를
재적용해야 한다**는 의무가 생깁니다.
<!-- @intent END -->

## 브로드캐스트 채널

<!-- @generated:channels START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 브로드캐스트 채널이 없습니다._
<!-- @generated:channels END -->

<!-- @intent START -->
없습니다. 페이지는 실시간 갱신이 필요한 콘텐츠가 아닙니다 — 발행 시점이 운영자의 조작이고,
방문자는 그 시점 이후의 접속에서 새 내용을 봅니다.

실시간이 필요한 화면이 생기면 이 모듈에 채널을 더하는 것이 아니라, `page.after_publish` 를
구독하는 쪽에서 자기 채널로 내보냅니다.
<!-- @intent END -->

## 스케줄

<!-- @generated:schedules START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 스케줄 | 주기 | 설명 |
|---|---|---|
| `sirsoft-page:prune-temp-attachments` | `daily` | 미연결 임시 페이지 첨부 자동 삭제 |
<!-- @generated:schedules END -->

<!-- @intent START -->
하나뿐입니다. `prune-temp-attachments` 는 **업로드했지만 페이지 저장까지 이어지지 않은 파일**을
정리합니다 — 편집 중 창을 닫은 세션의 부산물이라 운영 데이터가 아니며, 그래서 설정 토글 없이
상시 동작합니다(보존 기간은 커맨드 옵션).

이미 페이지에 연결된 첨부는 이 스케줄의 대상이 아닙니다. 페이지를 지우면 그 첨부는 삭제 흐름
안에서 함께 정리됩니다.
<!-- @intent END -->

## 알림 정의

<!-- @generated:notifications START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 알림 정의가 없습니다._
<!-- @generated:notifications END -->

<!-- @intent START -->
없습니다. 페이지 발행은 특정 수신자를 향한 사건이 아니라 사이트 전체에 대한 게시라, 누구에게
보내야 할지가 정해지지 않습니다.

약관 개정 안내처럼 발행을 계기로 알림을 보내야 한다면 `page.after_publish` 를 구독해 코어
`GenericNotification` 으로 발송하는 리스너를 **그 알림을 필요로 하는 확장 쪽에** 둡니다.
수신자 범위가 사이트마다 다르므로 이 모듈이 정할 수 없습니다.
<!-- @intent END -->

## 활동 로그 훅

> 이 확장이 코어 활동 로그(`activity_logs`)에 기록을 남기기 위해 구독하는 훅 7개입니다.
> 코어 `docs/backend/activity-log-hooks.md` 에 있던 목록을 이 확장 소유로 옮긴 것입니다(#601) —
> 확장이 훅을 더할 때 코어 문서를 고쳐야 하던 역방향 의존을 없애기 위해서입니다. 코어 문서에는
> 총계와 이 문서로의 링크만 남습니다.

> 새 항목을 추가하면 코어 `lang/{ko,en}/activity_log.php` 의 action 라벨과 description 본문,
> 그리고 번들 일본어 팩까지 함께 정의해야 합니다 — **모듈 lang 파일에 넣으면 해석되지
> 않습니다.**

### 페이지 모듈 훅 (PageActivityLogListener)

**파일**: `modules/_bundled/sirsoft-page/src/Listeners/PageActivityLogListener.php`
**총 7훅**

> 이 표에 `before_*` 훅이 없는 것은 누락이 아닙니다. 수정 전 스냅샷은 이 리스너가
> `before_*` 훅으로 직접 잡지 않고 **Service 가 잡아 `after_*` 훅의 인자로 넘깁니다**
> (`ChangeDetector::detect($model, $snapshot)`). `before_*` 훅 자체는 발행되며 그 목록은
> 위 「발행 훅」 절에 있습니다.

#### Page (5훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-page.page.after_create` | `handlePageAfterCreate` | `page.create` | Admin | Page |
| `sirsoft-page.page.after_update` | `handlePageAfterUpdate` | `page.update` | Admin | Page |
| `sirsoft-page.page.after_delete` | `handlePageAfterDelete` | `page.delete` | Admin | Page |
| `sirsoft-page.page.after_publish` | `handlePageAfterPublish` | `page.publish` / `page.unpublish` | Admin | Page |
| `sirsoft-page.page.after_restore` | `handlePageAfterRestore` | `page.restore` | Admin | Page |

#### PageAttachment (2훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-page.attachment.after_upload` | `handleAttachmentAfterUpload` | `page_attachment.upload` | Admin | PageAttachment |
| `sirsoft-page.attachment.after_delete` | `handleAttachmentAfterDelete` | `page_attachment.delete` | Admin | PageAttachment |
