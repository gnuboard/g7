# 이미지 썸네일

> 코어 7.2.0 부터 업로드 이미지의 썸네일을 서버가 만들어 둡니다. 이미지 주소에 `?size=sm` 또는 `?w=300` 을
> 붙이면 환경설정에 정의한 규격으로 줄인 파일을 내줍니다.

## TL;DR (5초 요약)

```text
1. 요청 규약: 서빙 주소 + ?size={규격 이름} 또는 ?w={숫자}. 둘 다 없으면 원본(기존과 같음)
2. 규격: 코어 고정 xs·sm·md·lg·xl + og, 확장 등록({확장 식별자}.{키}), 운영자 추가 — 환경설정 > 업로드
3. 게이트(권한·비밀·서명) 바로 뒤, 원본 응답 바로 앞에서 ThumbnailResponder 를 한 줄 부른다
4. 원본을 지우거나 덮어쓰면 저장소 계층이 파생본을 자동으로 지운다(스토리지 드라이버 사용 시)
5. 서명 URL 은 size·w 를 빼고 검증한다 — ThumbnailQuery::ignoreClosure()
6. 공개 자산 디스크(CDN) 직접 주소에는 썸네일이 붙지 않는다 — 이미지 응답은 원본 download_url 과 썸네일 기준 preview_url(항상 API)을 함께 낸다
```

---

## 목차

- [동작 개요](#동작-개요)
- [요청 규약](#요청-규약)
- [규격](#규격)
- [원본 분류와 저장 위치](#원본-분류와-저장-위치)
- [수명주기와 원본 변경 감지](#수명주기와-원본-변경-감지)
- [CDN](#cdn)
- [공개 자산 디스크와 두 주소](#공개-자산-디스크와-두-주소)
- [명령과 스케줄](#명령과-스케줄)
- [확장 서빙 지점 계약](#확장-서빙-지점-계약)
- [확장 규격 등록](#확장-규격-등록)
- [훅](#훅)
- [프론트엔드](#프론트엔드)
- [FAQ](#faq)

---

## 동작 개요

```text
[요청] GET /api/attachment/{hash}?size=sm
  └ 기존 게이트(권한·비밀·미발행·숨김·서명)
      └ 썸네일 헬퍼
           ├ 낼 수 없음(꺼짐·엔진 없음·비래스터·움직이는 이미지·작은 원본·미정의 규격) → 원본 응답
           ├ 파생본 있음 → 비공개 디스크면 스트림(ETag·304), 공개 자산 디스크면 302
           └ 없음 → 생성 제한 → 잠금 → 엔진(GD·Imagick) → 저장 → 위와 같이 응답
```

- 302 도 스트림과 같은 보안 헤더(`X-Content-Type-Options: nosniff`, `InlineMediaPolicy::harden`)를 단다. 코어와 확장의 썸네일 302 는 모두 `ThumbnailResponder` 한 지점에서 나간다.
- JPEG 은 EXIF 방향 8가지(회전 3·6·8, 뒤집기가 든 2·4·5·7)를 모두 세운 뒤 줄인다. GD 와 Imagick 이 같은 배치를 낸다.

- 첫 요청 때 만들고(이후 재사용), 규격별로 「등록 시 미리 생성」을 켜면 업로드 직후에 만든다.
- 모든 썸네일 응답에 `X-G7-Thumbnail` 헤더가 붙는다: `hit`·`miss`·`bypass:{사유}`.
  사유: `disabled`·`no-engine`·`not-raster`·`animated`·`unknown-preset`·`smaller-than-preset`·`rate-limited`·`too-large`·`error`.
- 이미지 처리 엔진은 환경설정 > 드라이버 > 「이미지 처리」에서 고른다(GD 기본, Imagick 은 확장이 설치된 서버에서만 보인다).
  업로드 축소(최대 가로·세로)도 같은 엔진을 쓴다.

## 요청 규약

| 파라미터 | 형식 | 해석 |
|---|---|---|
| `size` | 규격 이름 문자열 | 그 규격(사용 중일 때만) |
| `w` | 정수 1~10000 | 「숫자 요청 대상」 규격 중 가로 ≥ w 인 가장 작은 것, 없으면 가로가 가장 큰 것(올려 맞춤) |

- `size` 가 `w` 보다 우선한다.
- 배열 파라미터(`size[]=`)·같은 키 두 번(`size=sm&size=md`)·비정수·미정의 규격은 원본 + `bypass:unknown-preset`.
- 움직이는 GIF·WebP·PNG(APNG)는 원본(`bypass:animated`), SVG 등 래스터가 아닌 파일은 기존 다운로드 응답(`bypass:not-raster`).
  형식은 MIME 이 아니라 파일 머리로 가리고, 프레임이 둘 이상일 때만 움직이는 이미지로 본다.
  - 환경설정 > 업로드 「움직이는 이미지는 원본 사용」(`upload.thumbnail_animated_original`, 기본 켬)을 끄면 첫 프레임으로 정지 썸네일을 만든다.
    GD 엔진은 움직이는 WebP 를 읽지 못해 꺼도 원본(`bypass:animated`)이다. Imagick 은 세 형식 모두 첫 프레임을 쓴다.
  - 확장은 `core.thumbnail.filter_animated_original` 로 형식·원본·규격별로 판정을 바꾼다(아래 「훅」).
  - 첫 프레임 썸네일은 정지 이미지와 다른 지문(`{규격}_{첫 프레임 지문}.{ext}`)으로 저장한다. 설정을 다시 켜면 이미 만든 첫 프레임
    썸네일이 있어도 바로 원본으로 돌아가고, 남은 파일은 「비우기」나 기간 정리로 지운다(정리 명령은 두 지문 모두 현재 파일로 본다).
- 원본이 규격보다 작고 「작은 원본 확대」가 꺼져 있으면 원본(`bypass:smaller-than-preset`).
  자르기(`cover`) 규격에서 원본이 한쪽만 작으면 원본 안에서 규격 비율로 가장 크게 잘라 그 크기 그대로 낸다(800×800 원본 → 1200×630 규격이면 800×420) — 결과 비율은 언제나 규격 비율이다.
- 같은 IP 가 1분에 새로 만들게 할 수 있는 썸네일 수는 `config('core.thumbnail.generate_per_minute')`(기본 120). 넘으면 원본(`bypass:rate-limited`).
- 같은 원본·같은 규격을 다른 요청이 만들고 있으면 `config('core.thumbnail.lock_wait_seconds')`(기본 10, 1~30 으로 묶음 — 생성 잠금은 30초 뒤 저절로 풀린다)만큼 기다리고, 넘으면 원본(`bypass:error`, 캐시하지 않음).
- 원본 픽셀 수가 `config('core.thumbnail.max_source_pixels')`(기본 1600만 — 4000×4000)를 넘거나 서버 `memory_limit` 으로 감당할 수 없으면 줄이지 않고 원본(`bypass:too-large`).

### 서버 보호 값 (`.env`·업로드 탭 「고급」)

위의 생성 한도·잠금 대기·원본 픽셀 상한과 「기존 이미지 썸네일 만들기」의 배치 크기·시간 예산, 「비우기」의 시간 예산 여섯은 `.env` 로도, 환경설정 > 업로드
썸네일 카드 아래 「고급」 칸으로도 정한다. 우선순위는 다른 `.env` 대응 설정과 같다([admin-settings-access.md](admin-settings-access.md) 「env 우선 모드」).

| 업로드 탭 칸 (설정 키) | `.env` | config | 기본 | 화면 범위 |
|---|---|---|---|---|
| `upload.thumbnail_generate_per_minute` | `G7_THUMBNAIL_GENERATE_PER_MINUTE` | `core.thumbnail.generate_per_minute` | 120 | 1~10000 |
| `upload.thumbnail_lock_wait_seconds` | `G7_THUMBNAIL_LOCK_WAIT_SECONDS` | `core.thumbnail.lock_wait_seconds` | 10 | 1~30 |
| `upload.thumbnail_max_source_pixels` | `G7_THUMBNAIL_MAX_SOURCE_PIXELS` | `core.thumbnail.max_source_pixels` | 16000000 | 1000000~100000000 |
| `upload.thumbnail_pregenerate_batch` | `G7_THUMBNAIL_PREGENERATE_BATCH` | `core.thumbnail.pregenerate_batch` | 20 | 1~500 |
| `upload.thumbnail_pregenerate_time_budget` | `G7_THUMBNAIL_PREGENERATE_TIME_BUDGET` | `core.thumbnail.pregenerate_time_budget` | 15 | 1~60 |
| `upload.thumbnail_purge_time_budget` | `G7_THUMBNAIL_PURGE_TIME_BUDGET` | `core.thumbnail.purge_time_budget` | 10 | 1~60 |

- `.env` 우선 모드(`G7_ENV_PRIORITY=true`)에서 `.env` 에 값이 있으면 그 값이 이긴다. 화면 칸은 「.env 고정」 배지를 달고 잠기며,
  탭 저장은 그 키를 버리고(200) 단건 저장(`PUT /api/admin/settings/{key}`)과 `settings:set` 은 거부한다.
- 그렇지 않으면 화면에 저장한 값이 이긴다. 칸을 비우면(기본값 파일도 비운 값이다) `config/core.php` 가 읽은 값 — `.env` 가 있으면 그 값,
  없으면 위 기본 — 을 쓴다. 화면은 그 값을 `_meta.thumbnail_server_defaults` 로 받아 칸의 placeholder 로 보인다.
- 범위는 `config('core.settings_limits.upload_thumbnail_*')` 이고 벗어나면 422 다. `.env` 값은 범위로 막지 않는다 — 읽는 쪽이 따로
  묶는다(생성 한도·배치는 1 이상, 잠금 대기는 1~30, 원본 픽셀은 `memory_limit` 으로 한 번 더 줄인다). 시간 예산은 0 이상이고,
  서버의 `max_execution_time` 이 있으면 그 절반을 넘지 않는다(`max_execution_time` 이 0 인 CLI 에서는 위쪽이 없다). 생성 한도·배치의
  위쪽은 읽는 쪽이 묶지 않으므로 `.env` 에 화면 범위(10000·500)보다 큰 값을 적으면 그대로 쓴다. 숫자가 아닌 `.env` 값은 기본값이다.
- 범위 근거: 생성 한도 0 은 생성을 끄는 뜻인데 그것은 「썸네일 사용」이 맡는다. 잠금 대기는 생성 잠금이 30초 뒤 저절로 풀려 그보다 길게
  기다리지 않는다. 원본 픽셀 상한의 위쪽 1억은 업로드 축소 한계 10000×10000 이다. 시간 예산 위쪽 60초는 웹 서버·프록시의 흔한 응답 대기
  한도(nginx `fastcgi_read_timeout` 기본 60초)다.

## 규격

환경설정 > 업로드 > 「썸네일 규격」 표에서 관리한다.

| 출처 | 이름 | 고칠 수 있는 것 |
|---|---|---|
| 코어 고정 | `xs` 64×64 자르기 · `sm` 160×160 자르기 · `md` 가로 640 · `lg` 가로 1280 · `xl` 가로 1920 | 값만(이름 잠금) |
| 코어 og | `og` — 크기는 SEO 탭의 기본 og 이미지 크기(기본 1200×630), 자르기, 작은 원본 확대(메타가 선언한 `og:image:width/height` 와 언제나 같은 크기) | 업로드 탭에서는 읽기 전용 |
| 확장 등록 | `{확장 식별자}.{키}` | 값만, 「기본값으로」 되돌리기 |
| 운영자 추가 | `^[a-z][a-z0-9_]{1,31}$` (예약어 xs·sm·md·lg·xl·og 와 `true`, `false`, `null`, `undefined` 제외) | 이름·값·삭제 |

`true`, `false`, `null`, `undefined` 는 형식에는 맞지만 규격 이름으로 받지 않는다(저장 422). 레이아웃 파이프 인자는 따옴표 안이라도 이 네 낱말을 불리언, null, undefined 로 읽어, `{{url | thumb('true')}}` 가 규격 대신 원본(`undefined` 는 기본 `sm`)을 내기 때문이다. 확장 규격은 이름이 `{확장 식별자}.{키}` 라 키가 이 낱말이어도 상관없다.

규격 속성: 가로 · 세로 · 맞춤(자르기 `cover` / 맞추기 `contain` / 가로만 `width`) · 자르기 기준점(가운데·위·아래·왼쪽·오른쪽) · 품질(비우면 업로드 탭 이미지 품질) · 출력 형식(원본 / WebP / AVIF — 엔진이 못 쓰면 원본 형식) · 작은 원본 확대 · 등록 시 미리 생성 · 숫자 요청 대상(기본 md·lg·xl) · 사용 여부.

규격 값을 바꾸면 파일명의 규격 지문이 바뀌어 새 파일을 만든다. 옛 파일은 정리 명령이 지운다.

## 원본 분류와 저장 위치

| 분류 | 원본 | 썸네일 저장 디스크 | 응답 |
|---|---|---|---|
| 완전 공개 | 레이아웃 첨부·상품·카테고리·에디터 본문 이미지 | `upload.thumbnail_disk_public` — 비공개 `thumbnails`(기본) 또는 공개 자산 디스크(public·S3·CDN) | 비공개면 스트림(`Cache-Control: public`), 공개 자산 디스크면 302 |
| 권한이 걸림 | 코어 첨부·게시판 첨부·페이지 첨부·리뷰·클레임·본문 미디어(게시판·페이지·쇼핑몰 편집기 본문 이미지) | `upload.thumbnail_disk_gated` — 비공개 디스크만(아래 허용 규칙) | 항상 스트림, `Cache-Control: private` |

- 권한이 걸린 원본의 썸네일 디스크 허용 규칙: `thumbnails`, `s3`, 비공개 첨부 저장소 선택지(환경설정 > 드라이버 「비공개 첨부 저장소」, 필터 `core.settings.available_private_attachment_drivers` 로 플러그인이 올린 디스크 포함, 「기본」 제외)를 합친 뒤 공개 자산 디스크 선택지(`public`, CDN 플러그인 디스크 등)를 뺀다. 코어 `s3` 는 공개 자산 디스크 선택지에도 있지만 권한 썸네일은 늘 서버를 거쳐 스트림으로 내므로 예외로 허용한다. 이 서버에서 쓸 수 없는 디스크(S3 어댑터가 없거나 `filesystems.disks` 에 정의되지 않은 플러그인 디스크)도 뺀다.
  - 업로드 탭 선택지(`_meta.thumbnail_disk_options.gated`), 저장 검증, 서빙 시점의 디스크 판정(`ThumbnailStore::diskFor(true)`)이 같은 목록(`ThumbnailDiskOptions::ids('gated')`)을 쓴다. 설정 파일을 직접 고쳤거나 저장한 뒤 디스크를 등록한 플러그인을 꺼서 목록 밖 값이 남으면 서빙은 `thumbnails` 로 쓰고 경고 로그를 남긴다. 판정이 실패하면 `thumbnails`, `s3` 로 좁힌다.
  - 공개 자산 디스크와 권한 썸네일 디스크를 둘 다 `s3` 로 고르면 같은 버킷이다. 저장은 되지만 저장 응답 `warnings` 와 업로드 탭 「저장 위치」 칸 아래에 경고가 나온다. 버킷에 공개 읽기 정책이 있으면 권한 원본의 썸네일도 버킷 주소로 직접 읽힐 수 있으니 `thumbnails/` 경로에 공개 정책이 걸리지 않게 한다.
- 파생본 키: `thumbnails/{원본 디스크}/{원본 전체 경로}/{규격명}_{지문}.{ext}` — 어느 디스크든 고정 루트 `thumbnails/` 아래라 「비우기」가 원본을 건드리지 않는다.
- 두 분류가 같은 디스크를 써도 관리 화면의 「비우기」는 분류별로 지운다(원본 제공자로 판정).
- 코어 첨부는 CDN 처리하지 않는다(권한 게이트 원본).
- 본문 미디어는 문서가 지금 공개여도 권한 원본이다. 게시글의 비밀 전환, 페이지 발행 취소, 상품 전시 중단으로 공개 여부가 바뀌므로 같은 원본의 파생본이 두 저장 위치로 갈리지 않게 고정한다. 원본 응답의 `public`/`private` 는 종전대로 어댑터의 `isPublic` 을 따른다.
- 원본 응답은 게이트가 실제로 걸린 경우에만 `private` 다: 비밀글·삭제글·블라인드 글 첨부, 미발행 페이지 첨부, 숨김 리뷰, 권한 훅이 매핑되었거나 켜진 본인인증 정책(hook 범위 `core.attachment.before_download_action`·route 범위 `api.attachment.download`)이 걸린 코어 첨부, 관리자 다운로드. 나머지 공개 첨부는 `public` 그대로다.

## 수명주기와 원본 변경 감지

- 원본을 스토리지 드라이버(`StorageInterface`)로 지우거나 덮어쓰면 그 원본의 파생본 디렉터리를 모든 썸네일 디스크에서 지운다. 디렉터리째 지우면 그 아래 파생본도 지운다.
- 덮어쓰기의 파생본 삭제는 쓴 뒤 한 번이다. 쓰기 전에 비워도 쓰는 동안 옛 원본으로 다시 만든 파생본은 쓴 뒤에야 걷히므로 같은 경우를 다 덮는다.
- 덮어쓰기 판정(원본 파일 확인)은 판정이 원격 요청을 줄일 때만 한다. 원본에 공개 주소(CDN)가 있으면 덮어쓸 때만 그 주소를 무효화해야 해서 늘 판정한다. 공개 주소가 없으면 원격 썸네일 디스크 수와 원본 확인 요청 수를 견줘, 원격 썸네일 디스크가 원본 확인 요청 수 이하이면 판정 없이 쓴 뒤 모든 썸네일 디스크를 한 번 재확인한다. 판정으로 새 파일임을 알면 로컬 썸네일 디스크만 재확인한다.

  | 구성 (원본 s3, 공개 주소 없음) | 새 파일 put 부가 원격 요청 | 덮어쓰기 put 부가 원격 요청 |
  |---|---|---|
  | 썸네일 디스크 로컬 | 0 | 0 |
  | 썸네일 디스크 s3 | 디렉터리 조회 1 | 디렉터리 조회 1 (파생본이 있으면 삭제 2 더함) |
  | 원본에 공개 주소 있음 + 썸네일 디스크 로컬 | 파일 확인 1 | 파일 확인 1 |
  | 원본에 공개 주소 있음 + 썸네일 디스크 s3 | 파일 확인 1 | 파일 확인 1 + 디렉터리 조회 1 |

- 썸네일을 끄면(`upload.thumbnail_enabled=false`) put 의 파생본 연동을 건너뛴다. 원본에 공개 주소가 있으면 판정 1회와 덮어쓴 원본 주소의 CDN 무효화만 남는다(원본 캐시는 썸네일 기능과 별개다). 삭제 연동은 꺼도 한다(지운 원본의 파생본이 공개 디스크에서 계속 열리지 않게).
- 원본 공개 주소의 CDN 무효화는 확장자와 무관하다. 래스터가 아닌 원본(PDF 등)도 공개 주소가 있으면 덮어쓰기(판정 1회)와 삭제(요청 없음) 때 그 주소를 `core.thumbnail.after_purge` 에 싣는다. 파생본 연동은 래스터만 한다.
- 끈 동안 덮어쓴 원본의 옛 파생본은 무효화되지 않았으므로, 설정을 **다시 켜는 저장**이 지금 쓰는 썸네일 디스크의 `thumbnails/` 를 비운다(`PurgeThumbnailsOnReenableJob`, 큐가 없으면 응답 뒤). 어느 원본이 바뀌었는지 기록하지 않아 디스크째 비운다. 비우기가 돌기 전 짧은 사이에는 옛 파생본이 나갈 수 있다. 설정 파일을 직접 고쳐 켜면 이 비우기가 돌지 않으니 「비우기」를 누른다.
- 로컬 디스크 원본은 서빙 때 원본 수정 시각이 파생본보다 늦으면 다시 만든다.
- **한계**: 원격 디스크(S3 등)의 원본을 앱 밖에서 직접 바꾸면 자동으로 다시 만들지 않는다. 환경설정 > 업로드 > 「저장 위치 · 캐시」의 「비우기」로 갱신한다.
- 확장을 제거하면 그 확장 원본의 썸네일도 지운다.
- 재설치의 업로드 삭제 대상에 `storage/app/thumbnails` 가 포함된다. 썸네일은 캐시라 백업 대상이 아니다.

## CDN

세 가지 방식이 있다.

1. **파생본을 CDN 디스크에 저장 + 302** — 공개 썸네일 저장 디스크로 공개 자산 디스크(예: CloudFront 플러그인의 `cloudfront`)를 고르면 서버는 파생본을 그 디스크에 쓰고 요청에 302 로 CDN 주소를 준다. 권한 원본은 이 방식을 쓰지 않는다.
2. **서버 앞단 CDN** — CDN 이 사이트 전체를 캐시하는 구성. 공개 원본 썸네일은 `Cache-Control: public` 이라 캐시되고, 권한 원본 썸네일은 `private` 라 캐시되지 않는다.
3. **변환 서비스 위임** — 필터 `core.thumbnail.filter_url` 로 봇 화면과 `og:image` 의 썸네일 주소를 이미지 변환 서비스 주소로 바꾼다. 서버 쪽 주소 생성(`ThumbnailUrl::for()`)만 이 필터를 지나므로 사람 화면 주소는 바뀌지 않는다.

퍼지: 파일명에 규격 지문이 들어가 규격을 바꾸면 주소 자체가 바뀐다. 원본 삭제·덮어쓰기·「비우기」로 지운 접두는 요청 하나 동안 모았다가 액션 `core.thumbnail.after_purge` 로 한 번에 알린다. 번들 CloudFront 플러그인이 이 훅으로 원본 단위 와일드카드 무효화를 보낸다.

## 공개 자산 디스크와 두 주소

확장이 이미지를 공개 자산 디스크(public·URL 을 둔 S3·CloudFront 등)에 저장하면 원본 주소가 그 디스크의 직접
주소(CDN)가 된다. 직접 주소는 같은 출처 + 등록된 서빙 경로가 아니라서 `thumbPreset`·`thumb()`·`?size=` 가 붙지
않는다. 서버가 주소에서 해시를 거꾸로 찾을 수 없으므로, 직접 주소 접두를 `core.thumbnail.filter_url_patterns`
에 넣어도 효과가 없다.

그래서 공개 자산 디스크를 쓰는 이미지 응답은 용도가 다른 두 주소를 함께 낸다.

| 필드 | 값 | 쓰는 자리 |
|---|---|---|
| `download_url` | 원본 한 장 — 행 disk 가 직접 주소를 낼 수 있으면 그 주소, 아니면 API 서빙 주소 | 확대 창·라이트박스 슬라이드·다운로드·내보내기 |
| `preview_url` | 썸네일 기준 — 항상 API 서빙 주소 | 목록·미리보기 칸의 규격, 업로더 미리보기, srcset 후보 |
| `thumbnail_url` (대표 이미지 하나만 내는 자리) | 항상 API 서빙 주소 | 상품 목록·카드·og:image·주문 스냅샷 |

API 서빙 주소에 `?size=` 가 붙으면 서버가 썸네일을 만들고, 공개 썸네일 저장 위치가 공개 자산 디스크면 302 로 그
디스크 주소를 준다 — 썸네일도 CDN 에서 나가고 원본 한 장만 직접 주소에서 받는다. 예외로 노출·서명 게이트가 있는
이미지(이커머스 리뷰 이미지)는 썸네일을 비공개 저장 위치에 두고 서빙 경로가 스트리밍한다(302 없음). 직접 주소가 없는 행(로컬 디스크·
`core.storage.filter_url` 훅 차단)은 두 주소가 같다.

- 직접 주소는 행에 기록된 disk 만 본다(운영자 설정과 무관). 공개 자산 디스크 설정을 끈 뒤에도 그 디스크에 남은
  행은 계속 직접 주소를 내고, 그 파일도 계속 열린다 — 행 삭제·노출 상태 변경은 이미 알려진 직접 주소를 회수하지 않는다.
- 편집기 본문처럼 주소가 HTML 에 굳는 자리는 저장 때 썸네일 후보를 함께 남긴다. CKEditor 플러그인은 업로드 응답의
  `urls`(원본·마지막 원본 가로 후보 = 직접 주소, 원본 가로 이하 숫자 규격 = 사이트 절대 API 주소 + `?size=`)를 받아 본문 `<img srcset sizes width>` 로
  저장한다. 본문 렌더(`HtmlSanitizer`·`contentImages`)는 `src` 가 썸네일 대상이 아니면 작성자가 남긴 `srcset` 을
  그대로 둔다. 이 동작 전에 저장된 본문의 직접 주소 이미지는 원본 그대로다.
- 권한이 걸린 원본(코어 첨부·게시판·페이지 첨부·클레임 첨부)은 공개 자산 디스크에 두지 않는다 — 직접 주소는 게이트를
  지나지 않는다.

## 명령과 스케줄

| 명령 | 설명 |
|---|---|
| `php artisan thumbnails:prune [--days=N] [--dry-run]` | 규격 지문이 현재와 다른 파일, 원본이 사라진 파일, N일 넘게 쓰이지 않은 파일을 지운다 |
| `php artisan thumbnails:pregenerate [--source=…] [--preset=…] [--limit=N] [--dry-run]` | 기존 이미지의 썸네일을 미리 만든다(이미 있는 것은 건너뜀) |

- 정리 스케줄은 매일 05:20(`thumbnails:prune --scheduled`), 환경설정 > 업로드 「정리 스케줄」로 끈다.
- 관리 화면의 「기존 이미지 썸네일 만들기」는 같은 처리를 20건씩 나눠 돌린다(진행률·멈춤·다시 시작).
- 관리 화면의 「비우기」는 두 위치가 같은 디스크를 쓰거나 규격 하나만 지울 때 파일마다 판정한다. 요청 하나가 디스크 전체 목록을 만들지 않게 `thumbnails/` 트리를 디렉터리 단위로 이름 순서대로 업로드 탭 「고급」 「비우기 한 번 호출의 시간 예산」(`upload.thumbnail_purge_time_budget`, 기본 10초, 서버 실행 시간 한도가 더 짧으면 그 절반)씩 걷고, 화면이 `next_cursor` 로 이어 부른다. 커서는 마지막으로 본 파일 경로라 직전 요청이 지운 파일이 사라져도 자리가 밀리지 않는다. 다른 위치와 디스크를 나눠 쓰는 전 규격 비우기는 로컬 디스크면 `thumbnails/` 를 한 번에 지우고(원격 왕복이 없는 파일시스템 삭제, 개수 -1), 원격 디스크(S3 등)면 같은 방식으로 걸어 지운다(한 번에 지우면 파일 1000개마다 목록·일괄 삭제 요청이 나간다). 원격 통째 비우기의 CDN 무효화는 끝나는 요청에 루트 와일드카드 하나다.
- 업로드 탭의 썸네일 설정(사용 여부·규격·본문 규격·저장 디스크·정리·움직이는 이미지)을 저장해 값이 실제로 바뀌면 큐 워커에 재시작 신호(`queue:restart`)를 보낸다. 다른 업로드 값만 바꾸거나 같은 값을 다시 저장하면 보내지 않는다.

## 확장 서빙 지점 계약

확장이 업로드 이미지를 내주는 지점(컨트롤러 또는 서비스)은 **게이트 바로 뒤, 원본 응답 바로 앞**에서 헬퍼를 한 줄 부른다.

```php
use App\Thumbnails\ThumbnailResponder;
use App\Thumbnails\DTO\ThumbnailSource;

public function preview(Request $request, int $id): Response|JsonResponse
{
    $image = $this->service->findVisible($id, $request->user()); // 기존 게이트
    if (! $image) {
        return $this->forbidden('...');
    }

    $source = ThumbnailSource::fromStorage(
        $this->storage->withDisk($image->disk),   // 행 disk 로 맞춘 드라이버
        'images',                                   // 카테고리
        $image->path,                               // 카테고리 하위 경로
        $image->mime_type,
        gated: true,                                // 권한이 걸린 원본이면 true
        width: $image->width,
        height: $image->height,
    );

    if ($thumbnail = $this->thumbnails->respond($request, $source)) {
        return $thumbnail;
    }

    $response = $this->streamedFileResponseFromMeta(/* 기존 원본 응답 */);

    return ThumbnailResponder::markBypass($response, $request);
}
```

- 게이트는 호출처가 먼저 지난다. 헬퍼는 권한을 다시 보지 않는다.
- 분기(`respond()`)와 원본 표시(`markBypass()`)는 한 메서드에 함께 둔다. 서빙 메서드나 그 메서드를 부르는 컨트롤러 메서드 중 한 곳이다. 한 메서드에 원본 응답이 여럿이면(이미지 분기와 다운로드 분기) 하나하나 `markBypass()` 를 거친다. 감사 규칙 `thumbnail-serving-point-uses-helper` 가 이것을 본다. `ThumbnailResponder` 를 주입만 하고 둘 중 하나를 빠뜨리면 오류다.
- 원본 응답은 무파라미터 주소와 같은 캐시 정책으로 만든다. 썸네일을 요청했는데 원본으로 끝난 응답의 캐시는 `markBypass()` 가 맞춘다.
  - 영구 사유(미정의 규격·작은 원본·움직이는 이미지 등): `max-age` 를 썸네일 응답과 같은 `cache.layout_ttl` 로 줄인다(원본 통로의 값과 1년 중 작은 값을 넘지 않음). `immutable` 은 `must-revalidate` 로 바꾸고, `Expires` 가 있으면 함께 줄인다. 공유 캐시 유지 시간 `s-maxage` 가 있으면 같은 상한으로 줄인다(`max-age` 가 없어도) — CDN 은 `s-maxage` 를 먼저 따르므로 남겨 두면 앞단에 원래 시간 동안 원본으로 남는다. 운영자가 규격이나 「움직이는 이미지는 원본 사용」을 바꾸면 같은 `?size=` 주소가 그 시간 안에 썸네일로 바뀐다.
  - 일시 사유(`rate-limited`·`error`): `no-store`.
  - 비운영 환경의 `no-cache` 처럼 `max-age` 가 없는 응답과 `?size=` 가 없는 원본 응답은 그대로다. 호출처가 유지 시간을 따로 줄이지 않는다.
- 권한이 걸린 원본(`gated: true`)의 썸네일은 비공개 디스크에만 저장하고 `private` 로 낸다.
- 서명 URL 을 검증하는 곳은 `size`·`w` 를 빼고 검증한다:

  ```php
  use App\Thumbnails\ThumbnailQuery;

  if (! $request->hasValidRelativeSignatureWhileIgnoring(ThumbnailQuery::ignoreClosure())) {
      return $this->forbidden('...');
  }
  ```

- 다운로드 전용·비이미지 통로라면 메서드 앞이나 본문의 주석으로 `// thumbnail-exempt: <사유>` 를 남긴다. 사유가 없거나 문자열 안에 적은 표기는 면제가 아니다.
- 훅 리스너로 두 필터를 구독하고 모듈·플러그인의 `getHookListeners()` 에 올린다:

  ```php
  use App\Contracts\Extension\HookListenerInterface;

  class ThumbnailServingListener implements HookListenerInterface
  {
      public static function getSubscribedHooks(): array
      {
          return [
              'core.thumbnail.filter_url_patterns' => ['method' => 'addUrlPatterns', 'priority' => 10, 'type' => 'filter'],
              'core.thumbnail.filter_source_providers' => ['method' => 'addSourceProviders', 'priority' => 10, 'type' => 'filter'],
          ];
      }

      public function handle(...$args): void {}

      // 화면·SEO 가 size 를 붙여도 되는 자기 서빙 경로 (고정 접두 + * 한 조각)
      public function addUrlPatterns(mixed $patterns): mixed
      {
          if (! is_array($patterns)) {
              return $patterns;
          }

          $patterns[] = '/api/modules/vendor-module/images/*';

          return $patterns;
      }

      // 원본 제공자 — 게이트 여부·소속 경로 판정·기존 이미지 순회
      public function addSourceProviders(mixed $providers): mixed
      {
          if (! is_array($providers)) {
              return $providers;
          }

          $providers[] = app(MyImageSourceProvider::class); // App\Thumbnails\Contracts\ThumbnailSourceProvider 구현

          return $providers;
      }
  }
  ```

- 제공자 `matches()` 는 임시 업로드를 뺀다. 다만 임시 파일과 정식 파일이 같은 경로에 저장되면(이커머스 카테고리 이미지, 본문 미디어) 경로로는 가를 수 없으므로 `count()`, `chunk()` 에서 행 기준으로 뺀다.
- 본문 미디어(`AbstractContentMediaService` 로 서빙하는 편집기 본문 이미지)는 서빙 지점에 헬퍼를 따로 부르지 않는다. 코어 골격이 게이트(`findServable()`) 뒤의 `respond()` 에서 헬퍼를 부른다. 확장이 할 일은 셋이다.
  - 서빙 컨트롤러의 서명 검증을 `hasValidRelativeSignatureWhileIgnoring(ThumbnailQuery::ignoreClosure())` 로 한다.
  - 그다음에 본문 미디어 서빙 주소를 `core.thumbnail.filter_url_patterns` 에 더한다(예: `/api/modules/vendor-module/content-media/*`). 서명 검증을 고치기 전에 주소부터 올리면 화면이 서명 주소 뒤에 규격을 덧붙여 비공개 문서의 본문 이미지가 403 이 된다.
  - 본문 미디어 이미지를 원본 제공자 하나에 넣는다(`gated()` 는 `true`). 제공자가 `chunk()` 로 내는 원본 정보는 서빙이 쓰는 것과 디스크, 카테고리, 경로, `gated` 가 같아야 한다(다르면 서빙과 일괄 생성의 파생본 키가 갈린다). 본문 미디어가 첨부와 같은 표, 같은 저장 위치를 쓰면(게시판, 페이지) 첨부 제공자가 함께 맡고, 이때 본문 미디어 행의 원본 정보도 첨부 서비스의 `thumbnailSourceFor()`(`AttachmentService`, `PageAttachmentService`)로 만든다. 서빙 쪽 골격의 `thumbnailSourceFor($row)` 와 메서드는 다르지만 값이 같다. 한쪽을 고치면 다른 쪽도 함께 맞춘다. 따로 쓰면(쇼핑몰) 제공자를 따로 두고 골격의 `thumbnailSourceFor($row)`(`$service->engine()->thumbnailSourceFor($row)`)를 그대로 쓴다. 한 파일을 두 제공자의 `matches()` 가 받거나 한 행을 두 제공자가 세면 안 된다.
- 확장 저장 디렉터리를 드라이버 밖에서 `File::deleteDirectory()` 로 지우면 `app(ThumbnailStore::class)->purgeIdentifier($identifier)` 도 부른다.
- 의존 확장은 `g7_version` 을 `>=7.2.0` 으로 선언한다.

## 확장 규격 등록

리스너로 `core.thumbnail.filter_presets` 를 구독한다(`HookListenerInterface` + `getHookListeners()` 등록, 위 「확장 서빙 지점 계약」과 같은 방식).

```php
// getSubscribedHooks(): 'core.thumbnail.filter_presets' => ['method' => 'addPreset', 'priority' => 10, 'type' => 'filter']
public function addPreset(mixed $presets): mixed
{
    if (! is_array($presets)) {
        return $presets;
    }

    $presets[] = [
        'name' => 'vendor-module.card',   // {확장 식별자}.{키} 형식만 받는다
        'label' => 'vendor-module::messages.thumbnail.card_preset', // 표에 보일 이름(다국어 키, 생략 가능)
        'width' => 480,
        'height' => 270,
        'fit' => 'cover',                 // cover·contain·width
        'anchor' => 'center',
        'numeric' => false,
    ];

    return $presets;
}
```

- 등록한 값이 「기본값」이 되고, 운영자가 고친 값은 설정에 남는다. 확장을 비활성화하면 목록에서 빠지고 재활성화하면 고친 값이 되살아난다.
- `label` 은 「썸네일 규격」 표와 본문 이미지 규격·비우기 규격 선택지에 식별자 대신 보일 사람이 읽는 이름이다. 썸네일 원본 제공자의
  `label()` 과 같은 백엔드 다국어 키(`{확장 식별자}::{파일}.{키}`)를 준다. 코어가 활성 언어마다 풀어 관리자 응답 행에
  로케일 맵(`{"ko": "…", "en": "…"}`)으로 싣고, 화면은 현재 언어를 고른다. ko·en 은 확장의 `lang/` 에, 그 밖의 언어는 언어팩의
  같은 키에 둔다. 로케일 맵(`['ko' => '…', 'en' => '…']`)을 직접 줘도 된다.
  - 번역이 없는 언어는 맵에서 빠져 다른 언어로 폴백하고, `label` 을 생략하거나 키가 풀리지 않으면 표는 식별자만 보인다.
  - 식별자(`?size=` 값)는 이름 아래에 작게 남는다.
  - 이름은 결과 그림과 무관해 규격 지문과 저장 행에 들지 않는다. 이름만 바꿔도 파일을 다시 만들지 않고, 확장·언어팩을 갱신하면 바로 반영된다.

## 훅

| 훅 | 종류 | 값·인자 |
|---|---|---|
| `core.thumbnail.filter_presets` | filter | 규격 행 목록 (행의 `label` 은 표시 이름 다국어 키, 위 「확장 규격 등록」) |
| `core.thumbnail.filter_url_patterns` | filter | 서빙 경로 패턴 목록 |
| `core.thumbnail.filter_source_providers` | filter | 원본 제공자 목록 |
| `core.thumbnail.filter_url` | filter | 썸네일 URL, 인자: 원 URL·규격 이름. 봇 화면과 `og:image` 한정이고 사람 화면 주소는 바뀌지 않는다 |
| `core.thumbnail.filter_processors` | filter | `[엔진 id => ImageProcessorInterface]`. 배열이 아니면 코어 엔진만 쓰고, 엔진이 아닌 항목은 버리며, 비거나 빠진 코어 id(`gd`·`imagick`) 자리는 코어 엔진으로 채운다 |
| `core.thumbnail.filter_animated_original` | filter | 움직이는 원본을 그대로 낼지(bool, 기본값은 설정 `upload.thumbnail_animated_original`), 인자: 형식(`gif`·`webp`·`png`)·`ThumbnailSource`·`ThumbnailSpec`. `false` 면 첫 프레임 썸네일 |
| `core.thumbnail.after_purge` | action | 지운 접두 목록 `[{source_disk, prefix, thumbnail_disk, public_url_prefix, original_public_url, original_is_directory}]` |
| `core.settings.available_image_drivers` | filter | 이미지 처리 드라이버 카탈로그 |
| `core.thumbnail.before_purge` | action | 관리자 「비우기」 직전 — 인자: 위치(public·gated·all)·규격 이름 |
| `core.thumbnail.before_pregenerate` · `after_pregenerate` | action | 「기존 이미지 썸네일 만들기」 한 번분 앞뒤 — 인자: 커서(또는 결과)·제공자 키·규격 이름 |
| `core.thumbnail.stats_validation_rules` · `purge_validation_rules` · `pregenerate_validation_rules` | filter | 관리 API 요청 검증 규칙 |

## 프론트엔드

- 레이아웃의 `<img>` 는 `thumbPreset="sm"` 을, 데이터 바인딩은 `{{url | thumb('sm')}}`·`{{url | thumbSrcset('lg')}}` 를 쓴다. 같은 출처 + 등록된 서빙 경로인 주소에만 `size` 를 붙이고, 정적 `/storage/…`·외부·CDN 직접 주소는 그대로 둔다.
- 규격을 거는 입력 필드는 썸네일 기준 주소다 — 응답에 `preview_url` 이 있으면 `{{img.preview_url ?? img.download_url}}`(예전 응답 호환), 대표 이미지는 `thumbnail_url`. 원본이 필요한 확대 창·다운로드만 `download_url` 을 쓴다. 캔버스 색 추출·`naturalWidth` 측정처럼 같은 출처가 필요한 자리도 API 주소를 쓴다(CDN 주소는 캔버스를 오염시킨다).
- 규격은 표시 크기로 고른다: 32px 이하 `xs`, 33~96px `sm`, 97~400px `md`, 그보다 크면 `lg`. 고밀도 화면용 2x 후보는 `Img` 가 `srcset` 으로 더한다.
- `object-contain` 자리에는 자르기 규격(`xs`·`sm`)을 쓰지 않는다. 이미지가 잘리므로 가로 맞춤 규격(`md` 이상)이나 `contain` 으로 등록한 확장 규격을 쓴다.
- 규격을 붙이지 않는 자리는 `<img>` 바로 위 두 줄 안에 `<!-- thumb-exempt: 사유 -->` 를 남긴다. 허용 사유는 원본이 의도인 자리(확대 창·라이트박스 메인), 업로드 이미지가 아닌 자리, 규격을 붙여도 효과가 없는 자리(잘리면 안 되는 아이콘, 썸네일 서빙 지점이 아닌 경로)다. 사유가 없으면 면제가 아니다.
- 본문 HTML 의 이미지는 표시할 때 `src` 를 `upload.thumbnail_content_preset`(기본 lg) 규격으로 바꾼다. 이미지에 정수 `width` 가 있으면 그 가로 이하 후보만 `srcset` 으로 더하고, 모르면 `srcset` 을 넣지 않는다. 저장된 HTML 은 바뀌지 않는다.
- 링크가 없는 본문 이미지를 누르면 확대 보기가 원본으로 열린다.
- 검색엔진 봇 렌더와 `og:image` 도 같은 규칙을 쓴다(`og:image` 는 `og` 규격).

## FAQ

**썸네일 설정을 바꾸면 무엇이 비워지나요?** SEO 캐시가 비워져 봇의 첫 요청이 잠시 느려질 수 있다.

**서드파티 저장소(스토리지 인터페이스만 구현)의 이미지는?** 썸네일이 없다. 원본을 그대로 낸다.

**CDN 직접 주소로 저장된 상품 이미지는?** 직접 주소(`download_url`)에는 썸네일이 붙지 않는다. 그래서 응답이 함께 내는 `preview_url`(API 서빙 주소)에 규격을 건다 — 서버가 썸네일을 만들고, 공개 썸네일 저장 위치가 공개 자산 디스크면 302 로 CDN 주소를 준다. 대표 이미지 `thumbnail_url` 도 API 주소다. [공개 자산 디스크와 두 주소](#공개-자산-디스크와-두-주소) 참고.

**DB 에 박힌 이미지 주소(주문 스냅샷·본문 HTML)는 고쳐야 하나요?** 아니다. 화면이 표시할 때 붙이므로 그대로 혜택을 받는다. 단 공개 자산 디스크 직접 주소로 박힌 값(그 전환 전에 저장된 주문 스냅샷·srcset 없이 저장된 본문 이미지)은 서빙 경로가 아니라 원본 그대로다.
