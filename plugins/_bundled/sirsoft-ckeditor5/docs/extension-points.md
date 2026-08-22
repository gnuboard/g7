# CKEditor 5 WYSIWYG 에디터 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 4종 / 호출 지점 4곳. 훅 이름이 상수·변수로 조립된 호출이 1곳 있어 호출 위치가 표에 다 실리지 않을 수 있습니다.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `sirsoft-ckeditor5.image.after_upload` | action | 에디터 이미지 업로드 기록 생성 후 발화 | `src/Services/ImageUploadService.php:72` |
| `sirsoft-ckeditor5.image.before_upload` | action | 에디터 이미지 업로드 직전 발화 (본인인증·쿼터 등 확장 지점) | `src/Services/ImageUploadService.php:40` |
| `sirsoft-ckeditor5.image.filter_reference_sources` | filter | 에디터 이미지 참조 스캔 대상 테이블/컬럼 목록에 확장 콘텐츠를 추가 | 선언 (호출 위치 미확인) |
| `sirsoft-ckeditor5.image.filter_upload_file` | filter | 업로드 파일 변형 지점 (압축·리사이즈 등) | `src/Services/ImageUploadService.php:45` |
<!-- @generated:hooks-published END -->

<!-- @intent START -->
4종 중 셋은 업로드 파이프라인(`before_upload` → `filter_upload_file` → `after_upload`)이고,
나머지 하나가 이 플러그인에서 가장 중요한 훅입니다.

**`image.filter_reference_sources`** — 업로드 이미지가 어느 콘텐츠에서 쓰이는지 판정할 때
훑을 테이블·컬럼 목록을 만드는 자리입니다. 본문에 이미지를 담는 확장(게시판 글·상품 설명·
페이지 내용)은 **반드시 자기 테이블·컬럼을 여기에 등록**합니다. 등록하지 않으면 그 확장의
콘텐츠가 판정에서 통째로 빠지고, 화면에 멀쩡히 보이는 이미지가 "미참조" 로 분류되어 정리
대상이 됩니다 — 오류 없이 이미지가 깨지는 형태로만 드러납니다.

등록 시 **로그 사본 테이블을 소스로 삼지 않습니다.** 알림 발송 로그·메일 로그·신고 스냅샷·
레이아웃 미리보기는 자체 보존기간으로 지워지는 사본이라, 소스로 넣으면 "로그가 지워지는 순간
이미지가 고아가 되는" 역전이 생깁니다. 코어가 그 넷을 명시적으로 제외한 이유입니다.

`before_upload` 는 본인인증 강제·업로드 쿼터 같은 게이트를 붙이는 자리이고,
`filter_upload_file` 은 저장 직전 압축·리사이즈·형식 변환 자리입니다. `after_upload` 는 기록
생성 후이므로 외부 저장소 미러링처럼 사후 처리에 씁니다.
<!-- @intent END -->

## 구독 훅

<!-- @generated:hooks-subscribed START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_이 확장은 훅을 구독하지 않습니다._
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
하나도 구독하지 않습니다. 이 플러그인은 다른 확장의 흐름에 개입하지 않고, 자기 확장점 안에서만
동작합니다.

관계는 반대 방향으로 흐릅니다 — 다른 확장이 **이 플러그인의 훅을 구독**합니다. 게시판·페이지·
이커머스가 `image.filter_reference_sources` 에 자기 콘텐츠 테이블을 등록하는 것이 그
예입니다. 그래서 이 플러그인의 훅 이름을 바꾸면 그 확장들의 등록이 예외 없이 조용히 끊기고,
결과는 "쓰이는 이미지가 정리 대상이 되는" 형태로 나타납니다.
<!-- @intent END -->

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_훅 리스너가 없습니다._
<!-- @generated:listeners END -->

<!-- @intent START -->
없습니다. 구독하는 훅이 없으므로 리스너도 필요하지 않습니다.

이미지 정리는 훅이 아니라 **스케줄 커맨드**가 수행합니다. 콘텐츠 변경마다 반응하는 것이 아니라
주기적으로 전체를 훑는 방식인데, 본문에서 이미지 주소가 빠지는 사건을 훅으로 잡으려면 본문을
가진 모든 확장이 그 사실을 발행해야 하기 때문입니다. 주기 검사는 그 협조 없이도 성립합니다.
<!-- @intent END -->

## 레이아웃 확장

<!-- @generated:layout-extensions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 대상 | 설명 |
|---|---|
| `resources/extensions/html-content.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/html-editor.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
두 조각이 이 플러그인의 **본체**입니다. 백엔드가 아니라 이 조각들이 편집기를 화면에 올립니다.

| 조각 | 확장점 | 하는 일 |
|---|---|---|
| `html-editor.json` | `html_editor` | 동봉 CKEditor 5 UMD 를 로드하고 컨테이너 `onMount` 에서 `initEditor` 실행 |
| `html-content.json` | `html_content` | 저장된 본문을 읽기 화면에 렌더 |

`html_editor` 를 쓰는 쪽은 `props` 로 `name` · `content` · `multilingual` · `placeholder` ·
`readOnly` 를 선언합니다. 여기에 **`trackChanges`(기본 `true`)** 가 있습니다 — 이 편집기의 입력을
"폼이 변경됨"(`_local.hasChanges`)으로 칠지 여부입니다.

저장 대상이 아닌 편집기(설정 화면의 미리보기처럼 시험용으로 두는 자리)는 `false` 로 선언합니다.
그러지 않으면 운영자가 시험 삼아 글자만 쳐도 [저장] 버튼이 켜져, 바뀐 것이 없는데 바뀐 것처럼
보입니다. 어느 편집기가 저장 대상인지는 **레이아웃만 압니다** — 그래서 핸들러가 필드명으로
알아보지 않고 선언으로 받습니다. 필드명을 알아보게 만들면 다른 확장이 같은 미리보기 패턴을
쓸 때 그대로 재발합니다.

저장 대상이 아닌 편집기는 **저장 요청 body 에서도 그 필드를 빼야** 합니다. 자동바인딩은
`trackChanges` 와 무관하게 값을 `_local.form` 에 쌓으므로, body 를 `_local.form` 통째로 보내면
미리보기 입력이 설정으로 저장됩니다. 서버는 200 을 돌려주므로 화면에는 아무 이상이 없습니다.

둘 다 `mode: replace` 입니다 — 확장점 자리를 비우고 대신 들어갑니다. 같은 확장점을 노리는
다른 편집기 플러그인이 함께 활성화되면 어느 쪽이 이기는지가 설치 순서에 좌우되므로, 편집기
플러그인은 하나만 켭니다.

조각 안의 `scripts.src` 에 **동봉 자산의 버전 경로가 문자열로 박혀 있습니다.** CKEditor 5
버전을 올릴 때 디렉토리명만 바꾸고 이 값을 빠뜨리면 그 자산이 404 가 되는데, 빌드도 테스트도
통과합니다. 버전 기재는 디렉토리명 · 이 조각 · 소스 상수 · 테스트 단언이 **한 벌**로 움직여야
합니다.
<!-- @intent END -->

## 미들웨어

<!-- @generated:middleware START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 미들웨어가 없습니다._
<!-- @generated:middleware END -->

<!-- @intent START -->
없습니다. 업로드·서빙 라우트는 코어 인증 미들웨어만 씁니다.

업로드 게이트가 필요하면 미들웨어가 아니라 `image.before_upload` 훅을 잡습니다 — 그 편이
확장에 열려 있고, 미들웨어 부착 대상(targets) 선언을 늘리지 않아도 됩니다.
<!-- @intent END -->

## 브로드캐스트 채널

<!-- @generated:channels START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 브로드캐스트 채널이 없습니다._
<!-- @generated:channels END -->

<!-- @intent START -->
없습니다. 편집기 동작은 각 사용자의 브라우저 안에서 끝나므로 서버가 다른 접속자에게 알릴
사건이 없습니다.

여러 사람이 같은 문서를 동시에 편집하는 협업 기능은 CKEditor 5 상용 부가 기능의 영역이며 이
플러그인의 범위 밖입니다.
<!-- @intent END -->

## 스케줄

<!-- @generated:schedules START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 스케줄 | 주기 | 설명 |
|---|---|---|
| `sirsoft-ckeditor5:prune-unused-images --scheduled` | `daily` | 미참조 에디터 업로드 이미지 정리 |
<!-- @generated:schedules END -->

<!-- @intent START -->
하나뿐입니다. `prune-unused-images --scheduled` 는 어느 콘텐츠에서도 참조되지 않고 보존기간
(`unusedImageRetentionDays`, 기본 30일)이 지난 업로드 이미지를 정리합니다.

**기본값이 꺼짐**(`unusedImageCleanup: false`)인 것이 이 스케줄의 핵심입니다. 잘못 지운
이미지는 되돌릴 수 없고, 참조 판정은 다른 확장들의 협조(훅 등록)에 의존하므로 사이트마다
정확도가 다를 수 있습니다. 운영자가 자기 사이트에서 무엇이 정리되는지 확인한 뒤 켜는 것이
전제입니다.

켜져 있어도 **비활성 설치 모듈이 하나라도 있으면 판정을 중단**합니다. 그 모듈의 콘텐츠가
소스 목록에 등록되지 않아 실제로 쓰이는 이미지를 미참조로 오판하기 때문입니다.
<!-- @intent END -->

## 알림 정의

<!-- @generated:notifications START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 알림 정의가 없습니다._
<!-- @generated:notifications END -->

<!-- @intent START -->
없습니다. 편집기 사용은 알릴 사건이 아니고, 이미지 정리는 운영자가 설정으로 켠 배치 작업이라
그 결과는 커맨드 출력과 로그로 남습니다.

정리된 건수를 운영자에게 통지해야 한다면 `prune-unused-images` 를 감싸는 별도 확장에서
코어 `GenericNotification` 으로 보내는 것이 맞습니다 — 수신자 범위를 이 플러그인이 정할 수
없습니다.
<!-- @intent END -->
