# CKEditor 5 WYSIWYG 에디터 — 설정·권한·라우트

> 설정 스키마·권한·메뉴·라우트·의존 관계 · 진입점: [AGENTS.md](../AGENTS.md)

## 설정 스키마

<!-- @generated:settings-schema START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 키 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| `imageUpload` | `boolean` | `true` | 이미지 업로드 |
| `imageMaxSizeMb` | `integer` | `2` | 이미지 최대 크기 (MB) |
| `editorHeight` | `integer` | `400` | 에디터 높이 (px) |
| `toolbar` | `enum` | `standard` | 툴바 유형 |
| `public_asset_disk` | `string` | - | 공개 자산 디스크 |
| `unusedImageCleanup` | `boolean` | `false` | 미사용 이미지 자동 정리 |
| `unusedImageRetentionDays` | `integer` | `30` | 미사용 이미지 보존기간 (일) |

기본값 파일: `config/settings/defaults.json` · 설정 화면 레이아웃: `resources/layouts/admin/plugin_settings.json`
<!-- @generated:settings-schema END -->

<!-- @intent START -->
7개 항목이 세 무리입니다.

| 무리 | 항목 | 성격 |
|---|---|---|
| 편집기 표현 | `toolbar` · `editorHeight` | 화면에만 영향. 잘못 설정해도 데이터는 안전합니다 |
| 업로드 | `imageUpload` · `imageMaxSizeMb` · `public_asset_disk` | 저장 위치와 허용 범위 |
| 정리 | `unusedImageCleanup` · `unusedImageRetentionDays` | **파일을 지우는 설정** — 기본이 꺼짐입니다 |

`public_asset_disk` 만 `enum` 이 아니라 `string` 인 이유가 있습니다. 선택지가 코어 카탈로그 +
플러그인이 훅으로 등록한 디스크로 **동적**이라 스키마 단계에서 열거할 수 없습니다. 존재하지
않는 디스크 값이 들어오면 `resolvePublicAssetDisk()` 가 스트리밍 서빙으로 안전하게 폴백하므로,
설정 오타가 이미지 소실로 이어지지는 않습니다.

`unusedImageCleanup` 의 기본값이 `false` 인 것은 **의도적인 보수 설정**입니다. 참조 판정이
다른 확장들의 훅 등록에 의존하므로 사이트마다 정확도가 다를 수 있고, 잘못 지운 이미지는
되돌릴 수 없습니다.

설정 화면은 `resources/layouts/admin/plugin_settings.json` 이 그립니다 — 코어가 플러그인
디렉토리의 이 고정 경로를 찾으므로 파일 이름을 바꾸면 설정 화면 자체가 사라집니다.
<!-- @intent END -->

## 권한

<!-- @generated:permissions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 카테고리 | 이름 | 액션 | 라우트 키 |
|---|---|---|---|
| `uploads` | 에디터 업로드 이미지 | `read`, `delete` | - |
<!-- @generated:permissions END -->

<!-- @intent START -->
`uploads` 하나에 `read`/`delete` 두 액션뿐입니다. 업로드 이미지 **관리 화면**에 대한 권한이며,
편집기를 쓰는 권한이 아닙니다 — 편집기는 본문을 쓸 수 있는 사람이면 누구나 씁니다.

`create` 가 없는 것은 이미지가 관리 화면이 아니라 **편집기에서** 올라오기 때문입니다. 그
경로의 게이트는 권한이 아니라 훅(`image.before_upload`)이 담당합니다 — 업로드 제한 정책이
사이트마다 다르고(회원 등급별 쿼터, 본인인증 요구 등) 권한 하나로 표현되지 않습니다.

`delete` 는 파일을 실제로 지우는 권한입니다. 본문에서 아직 쓰이는 이미지를 지우면 그 자리가
깨지므로 넓게 부여하지 않습니다.
<!-- @intent END -->

## 메뉴

<!-- @generated:menus START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 구분 | slug | 이름 | URL | 하위 |
|---|---|---|---|---|
| 관리자 | `sirsoft-ckeditor5-uploads` | 에디터 업로드 이미지 | `/admin/plugins/sirsoft-ckeditor5/uploads` | - |
<!-- @generated:menus END -->

<!-- @intent START -->
관리자 메뉴 하나(`/admin/plugins/sirsoft-ckeditor5/uploads`)뿐이며 업로드 이미지 목록으로
갑니다.

설정 화면은 이 메뉴에 없습니다 — 코어의 플러그인 목록에서 이 플러그인의 설정으로 들어가는
공통 경로를 씁니다. 플러그인 설정은 코어가 `resources/layouts/admin/plugin_settings.json` 을
찾아 그리므로 자체 메뉴가 필요하지 않습니다.

메뉴는 권한과 짝을 이룰 때만 보입니다 — `sirsoft-ckeditor5.uploads.read` 가 없는 역할에는
렌더되지 않습니다.
<!-- @intent END -->

## 라우트

<!-- @generated:routes START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 파일 | URL prefix |
|---|---|---|
| `api` | `src/routes/api.php` | `/api/plugins/sirsoft-ckeditor5/...` |

확장 라우트는 **활성 상태인 확장의 것만** 등록됩니다. 라우트 정의를 바꾸면 라우트 캐시 재생성이 필요합니다.
<!-- @generated:routes END -->

<!-- @intent START -->
5개가 두 무리입니다.

| 무리 | 경로 | 인증 |
|---|---|---|
| 편집기용 | `POST upload` · `GET images/{hash}` | 업로드는 인증 필요, 서빙은 본문을 보는 사람이 접근 |
| 관리자 | `GET admin/uploads` · `POST admin/uploads/bulk-delete` · `DELETE admin/uploads/{id}` | `auth:sanctum` + 권한 |

**서빙 경로가 해시 기반**(`images/{hash}`)인 것은 순번 ID 를 노출하지 않기 위해서입니다.
다만 이 경로는 항상 쓰이지는 않습니다 — 설정 디스크가 공개 URL 을 주는 환경에서는 본문에
디스크 직접 URL 이 박히므로, 같은 이미지가 사이트 설정에 따라 두 형태의 주소를 갖습니다.
미참조 판정이 두 토큰을 모두 검사하는 이유가 여기 있습니다.

라우트를 바꾼 뒤에는 라우트 캐시를 다시 굽습니다. 확장 라우트는 활성 상태인 확장의 것만
등록되고, 캐시에 없는 라우트는 예외도 경고도 없이 404 가 됩니다.
<!-- @intent END -->

## 의존 관계

<!-- @generated:dependencies START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
**이 확장이 의존하는 확장**

없음 — 코어만으로 동작합니다.

**이 확장에 의존하는 확장** (이 확장을 비활성화하면 함께 영향을 받습니다)

없음.
<!-- @generated:dependencies END -->

<!-- @intent START -->
양방향 모두 비어 있습니다. 이 플러그인은 코어만으로 동작하고, manifest 상 이 플러그인을
요구하는 확장도 없습니다.

**그런데 실제 관계는 표가 보여주는 것보다 많습니다.** 게시판·페이지·이커머스가 이 플러그인의
`image.filter_reference_sources` 를 구독해 자기 콘텐츠 테이블을 등록합니다. manifest 의존이
아닌 이유는 편집기가 없어도 그 확장들이 정상 동작하기 때문이며(본문을 평문으로 쓸 뿐),
그 판단은 맞습니다.

대신 그 대가로 **이 플러그인이 훅 이름을 바꾸면 구독하던 확장들의 등록이 예외 없이 조용히
끊깁니다.** 그 결과는 "쓰이는 이미지가 정리 대상이 되는" 형태로 나타나므로, 훅 이름·페이로드
스키마를 바꿀 때는 구독 확장을 전수 확인하고 그 확장들의 `dependencies` 최소 버전 상향이
필요한지 검토합니다.
<!-- @intent END -->
