# 웹서버 파일 위임 (X-Sendfile / X-Accel-Redirect)

서버에 있는 파일(확장 자산·번들, 공개 스토리지 파일, 확장 아이콘, 배너 영상, 본문의 로컬 미디어)을 PHP 가 읽어
보내는 대신 웹서버(Apache mod_xsendfile · nginx)가 직접 보내게 하는 선택 기능을 설명한다. 기본은 「사용 안 함」이며,
켜려면 웹서버 설정이 함께 필요하다.

## TL;DR (5초 요약)

```text
1. 환경설정 > 드라이버 > 웹서버 파일 위임에서 X-Sendfile(Apache) 또는 X-Accel-Redirect(nginx)를 고른다 (.env 우선 모드 G7_ENV_PRIORITY=true 면 G7_FILE_DELEGATION 도 가능)
2. 웹서버에 짝 설정을 넣는다 — Apache: XSendFile On + XSendFilePath <설치 위치>, nginx: internal location 하나
3. 앱은 요청 헤더(X-Sendfile-Type)를 보지 않는다 — 방식은 운영자 설정으로만 정해진다
4. 확인: 「설정 점검」 → 저장 → 「실제 전송 확인」. curl 로 위임 헤더가 응답에 보이면 미구성이다
5. 켠 뒤 화면이 깨지면 「사용 안 함」으로 저장, 관리 화면에 못 들어가면 php artisan settings:set drivers.file_delegation none (또는 storage/app/settings/drivers.json 의 file_delegation 을 none 으로)
```

## 목차

- [1. 무엇이 달라지나](#1-무엇이-달라지나)
- [2. 설정](#2-설정)
- [3. Apache (mod_xsendfile)](#3-apache-mod_xsendfile)
- [4. nginx (X-Accel-Redirect)](#4-nginx-x-accel-redirect)
- [5. 확인 방법](#5-확인-방법)
- [6. 복구](#6-복구)
- [7. 주의](#7-주의)
- [관련 문서](#관련-문서)

---

## 1. 무엇이 달라지나

위임을 켜면 앱은 파일 본문 대신 응답 헤더 하나(`X-Sendfile: <절대 경로>` 또는 `X-Accel-Redirect: <내부 URI>`)를
세운다. 웹서버가 그 헤더를 소비해 파일을 직접 보내고, 범위 요청(영상 탐색·이어받기, `Range` → 206)도 웹서버가
처리한다. PHP 프로세스는 헤더만 만들고 바로 풀려난다.

위임 여부와 무관하게 앱이 정하는 것은 그대로다 — 권한·게이트 판정, `ETag`·`Last-Modified` 와 조건부 요청(304),
`Cache-Control`·`Expires`, `X-Content-Type-Options: nosniff`, `Content-Disposition`(활성 문서는 내려받기).
단 nginx 는 내부 이동 때 `X-Content-Type-Options`·`Content-Security-Policy` 를 넘기지 않으므로 내부 위치에서
`add_header` 로 다시 붙여야 같아진다(4절 — 화면 안내에 그 줄이 들어 있다).
304 는 위임 전에 앱이 답한다.

| 통로 | 위임 대상 | 비고 |
| --- | --- | --- |
| 템플릿·모듈·플러그인 번들 JS/CSS, 확장 자산 | 예 | CSS 안 주소를 고쳐 쓰는 응답(치환본)은 메모리 본문이라 제외 |
| 공개 스토리지 대체 통로(`/api/system/public-storage/...`) | 예 | `public/storage` 링크가 없는 설치 |
| 확장 아이콘 | 예 | |
| 히어로섹션 배너 영상(로컬 디스크) | 예 | |
| 게시판·페이지·상품 본문의 로컬 미디어 | 예 | 게이트(읽기 권한·발행·전시)를 통과한 뒤에만 |
| 원격 디스크(S3 등) 파일, 첨부 다운로드, 썸네일 응답 | 아니오 | 스트림 통로 — 웹서버가 읽을 로컬 파일이 없다 |
| 레이아웃 첨부·사이트맵·생성 본문·빈 번들 응답 | 아니오 | 원격 가능·메모리 본문 |

## 2. 설정

| 위치 | 키 | 값 |
| --- | --- | --- |
| 환경설정 > 드라이버 > 웹서버 파일 위임 | `drivers.file_delegation` | `none`(기본) · `x-sendfile` · `x-accel-redirect` |
| 같은 카드 (nginx 일 때) | `drivers.file_delegation_accel_prefix` | 내부 위치 접두사, 기본 `/_g7_sendfile/` |
| `.env` | `G7_FILE_DELEGATION`, `G7_FILE_DELEGATION_ACCEL_PREFIX` | 같은 값 |

- 우선순위는 다른 드라이버 설정과 같다. 화면에 저장한 값이 `.env` 를 덮고, `.env` 우선 모드(`G7_ENV_PRIORITY=true`)에서
  `.env` 에 값이 있으면 `.env` 가 이기고 화면 칸은 잠긴다 ([관리자 설정 접근](admin-settings-access.md)).
- 접두사는 `/` 로 시작하고 끝나야 하며 세그먼트는 영숫자와 `._-` 만 쓴다(점만으로 된 세그먼트 불가). 바깥에서 짐작하기
  어려운 이름을 쓴다.
- nginx 매핑(`X-Accel-Mapping`)은 운영자가 적지 않는다. 앱이 **설치 위치 실경로**(심볼릭 링크를 푼 값)와 접두사로
  `<실경로>/=<접두사>` 를 만든다. 화면 카드의 안내 문구에 실제 경로가 찍혀 나오므로 그대로 복사한다.

## 3. Apache (mod_xsendfile)

```apache
# 모듈 설치·활성화 (배포판에 따라 패키지 이름이 다르다 — 예: libapache2-mod-xsendfile)
# a2enmod xsendfile

<VirtualHost *:443>
    # ... 기존 설정 ...
    XSendFile On
    XSendFilePath /var/www/g7
</VirtualHost>
```

- `XSendFilePath` 는 화면 안내에 나온 **설치 위치 실경로**를 적는다. 앱이 `X-Sendfile` 에 싣는 경로도 실경로라, 설치
  위치가 심볼릭 링크(배포 도구의 `current` 링크 등)면 링크가 아니라 실제 디렉터리를 적어야 한다. `storage` 를 다른
  위치로 링크해 둔 배치는 그 실제 위치도 `XSendFilePath` 로 하나 더 적는다.
- 스크립트가 보낸 `ETag`·`Last-Modified` 를 무시하게 하는 지시어(`XSendFileIgnoreEtag`·`XSendFileIgnoreLastModified`)가
  있다. 앱이 정한 검증자를 쓰려면 켜지 않는다 — 두 지시어의 기본값은 모두 `off` 다(mod_xsendfile 설명서).
- PHP 를 FastCGI(PHP-FPM)로 연결한 구성에서도 mod_xsendfile 이 응답 헤더를 처리하는지는 구성마다 다르다 —
  [5. 확인 방법](#5-확인-방법)의 curl 절차로 반드시 확인한다. 화면의 「설정 점검」은 Apache 모듈로 도는 PHP 에서만
  모듈 목록을 읽을 수 있다.

## 4. nginx (X-Accel-Redirect)

```nginx
server {
    # ... 기존 설정 ...

    # G7 웹서버 파일 위임 — 접두사는 환경설정 값과 같아야 한다
    location /_g7_sendfile/ {
        internal;
        alias /var/www/g7/;
    }
}
```

- `internal;` 은 **필수**다. 빠지면 `https://사이트/_g7_sendfile/.env` 처럼 설치 폴더 전체가 바깥에 열린다.
- 매핑은 앱이 만들어 응답 헤더로 보내므로 `fastcgi_param HTTP_X_SENDFILE_TYPE`·`HTTP_X_ACCEL_MAPPING` 을 적지 않는다
  (적어도 앱은 그 요청 헤더를 보지 않는다).
- nginx 는 내부 위치에서 파일을 보낼 때 `ETag`·`Last-Modified` 를 파일 기준으로 다시 세운다. 내부 이동 때 원래 응답
  헤더 중 일부(`Content-Type`·`Content-Disposition`·`Cache-Control`·`Expires` 등)만 넘기는 것으로 알려져 있으며,
  `X-Content-Type-Options`·`Content-Security-Policy` 는 넘기지 않는다. 내부 위치에서 다시 붙이는 것이 일반적이다.

  ```nginx
  location /_g7_sendfile/ {
      internal;
      alias /var/www/g7/;
      add_header X-Content-Type-Options "nosniff" always;
      add_header Content-Security-Policy $upstream_http_content_security_policy always;
  }
  ```

  설치판에서 `curl -sI` 로 어떤 헤더가 남는지 확인한다.
- Windows 의 nginx 는 `alias` 경로를 `/` 로 적는다(예: `alias C:/www/g7/;`).

## 5. 확인 방법

### 관리자 화면

1. 드라이버 탭 「웹서버 파일 위임」에서 방식을 고르고 **「설정 점검」** — 웹서버 종류와(읽을 수 있으면) 모듈 유무만 보고
   판정한다. 확정 가능한 실패(Apache 인데 mod_xsendfile 없음, 방식과 웹서버 종류 불일치)만 실패로 낸다. 저장을 막지 않는다.
2. 저장한다.
3. **「실제 전송 확인」** — 저장된 설정으로 작은 JSON 파일(`GET /api/admin/settings/file-delegation-probe`)을 받아 본문이
   오는지 본다. 본문이 비거나 404 면 미구성이다. 적용 중인 방식이 「사용 안 함」이거나 프로브 파일이 매핑 밖이라 PHP 가
   보낼 응답이면 성공이 아니라 그 사유(`data.reason` = `inactive`·`skipped`)를 보인다 — 화면에서 방식만 바꾸고 저장하지 않았다면 먼저 저장한다.

### 직접 확인

```bash
# 확장 번들 하나를 받는다 (주소는 개발자 도구 네트워크 탭에서 복사)
curl -sI "https://사이트/api/plugins/bundle.css?v=..."
curl -s  "https://사이트/api/plugins/bundle.css?v=..." | wc -c
```

| 결과 | 판정 |
| --- | --- |
| 응답 헤더에 `X-Sendfile` 또는 `X-Accel-Redirect` 가 **보인다** | 미구성 — 웹서버가 헤더를 소비하지 않았다 |
| 본문 길이(`wc -c`)가 0 | 미구성 |
| `X-G7-File-Delegation: skipped` | 파일 실경로가 매핑 밖이다(심볼릭 링크로 설치 위치 밖 등) — PHP 가 보냈다 |
| 본문이 정상이고 위임 헤더가 보이지 않는다 | 정상 (nginx 는 `X-G7-File-Delegation: applied` 를 넘기지 않을 수 있다) |
| `curl -s -H "Range: bytes=0-99" -D - -o /dev/null <영상 주소>` 가 206 | 웹서버가 범위 요청을 처리한다 |

## 6. 복구

켠 뒤 화면 자산(관리자 화면 번들 포함)이나 이미지가 열리지 않으면 웹서버가 위임을 처리하지 못하는 것이다.

1. 관리 화면에 들어갈 수 있으면 「웹서버 파일 위임」을 「사용 안 함」으로 저장한다.
2. 들어갈 수 없으면 서버 터미널에서 `php artisan settings:set drivers.file_delegation none` 을 실행한다(화면 저장과 같은
   검증·저장 경로). 터미널 명령을 쓸 수 없으면 `storage/app/settings/drivers.json` 의 `"file_delegation"` 값을 `"none"` 으로
   고친다. 설정 파일은 요청마다 읽히므로 바로 반영된다.
3. `.env` 로 관리하는 설치는 `G7_ENV_PRIORITY=true` 와 `G7_FILE_DELEGATION=none` 을 두고, 설정 캐시를 쓰면
   `php artisan config:cache` 를 다시 실행한다. (`.env` 우선 모드가 꺼져 있으면 화면 저장값이 `.env` 를 덮는다.)

## 7. 주의

- **요청 헤더 위조와 무관하다.** 앱은 방문자가 보낸 `X-Sendfile-Type`·`X-Accel-Mapping` 을 보지 않고 설정값으로 덮어쓴다.
  Symfony 의 전역 신뢰 플래그는 응답 하나를 준비하는 동안만 켰다가 되돌린다.
- **nginx `internal;` 누락은 설치 폴더 노출이다.** 접두사 기본값을 짐작하기 어렵게 둔 것은 2차 방어일 뿐이다.
- **심볼릭 링크·네트워크 파일 시스템.** 앱이 넘기는 경로는 실경로다. 실경로가 nginx 매핑(설치 위치) 밖이면 위임하지
  않고 PHP 가 보낸다(`X-G7-File-Delegation: skipped`, `APP_DEBUG` 면 로그). Apache 는 그 실경로를 `XSendFilePath` 에
  더한다.
- **압축.** 위임한 응답은 앱의 gzip 압축에서 빠진다(본문을 바꿔 치우면 웹서버가 원본 바이트에 `Content-Encoding: gzip` 을
  붙이게 된다). JS·CSS 압축은 웹서버 설정(`mod_deflate`, nginx `gzip_types`)으로 한다.
- **공유 호스팅·Windows 로컬 개발.** 대개 모듈을 쓸 수 없으므로 기본값(사용 안 함)을 둔다.
- **대상 밖.** 원격 디스크(S3) 파일·첨부 다운로드·썸네일 응답은 스트림 통로라 위임하지 않는다. 공개 이미지를 CDN 으로
  보내려면 공개 자산 디스크를 쓴다([스토리지 드라이버](../extension/storage-driver.md)).

## 관련 문서

- [관리자 설정 접근과 .env 우선](admin-settings-access.md)
- [리버스 프록시 환경](reverse-proxy.md)
- [이미지 썸네일](image-thumbnails.md)
- [Settings API — file-delegation-probe](api/settings.md#get-apiadminsettingsfile-delegation-probe)
