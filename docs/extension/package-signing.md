# 패키지 서명과 무결성 검증 (Package Signing)

> 코어 7.2.0. 모듈·플러그인·템플릿·외부 언어팩·코어 배포본을 받아 설치하거나 업데이트할 때, 패키지 ZIP 옆에 둔 서명 파일
> (`{zip}.sig.json`)로 **누가 만들었고 도중에 바뀌지 않았는지**를 확인합니다. 서명 알고리즘은 Ed25519, 파일 대조는 SHA-256 입니다.
> 제작자(서명하는 쪽)와 사이트 운영자(검증하는 쪽)의 절차, 서명 파일 형식, 검증 상태와 오류 응답을 다룹니다.

## TL;DR (5초 요약)

```text
1. 서명 파일 = ZIP 이름 뒤에 .sig.json — 자산 이름 {identifier}-{version}.zip(코어 g7-{version}.zip), ZIP 루트는 {identifier}-{version}/ 하나
2. 제작자: extension:keygen 으로 키 → extension:sign 으로 ZIP·서명 파일 → 태그 릴리스에 둘 다 올림. 비밀키 파일은 저장소·배포물 밖
3. 운영자: 제작자 공개키를 환경설정 > 보안 「확장 신뢰 키」(또는 extension:trust-key add)에 vendor 범위와 함께 등록
4. 서명 불일치(invalid)·미신뢰 키(untrusted)는 늘 차단(422). 미서명(unsigned)·검증 불가(unverifiable)는 관리자 확인 뒤 계속(409)
5. 「미서명 패키지 차단」(security.extension_block_unsigned, 기본 꺼짐)을 켜면 미서명은 확인으로도 진행 불가
6. 코어 배포본은 코어 내장 공식 키로만 검증. 공식 키는 코드에 박혀 있어 .env·설정으로 바꿀 수 없다
7. 답할 수 없는 Artisan 실행(cron·CI·--no-interaction)은 미서명이면 --allow-unsigned 없이 exit 1. --force·--yes 는 대신하지 않음
8. GitHub 설치·업데이트는 태그 릴리스만 받는다 — 릴리스·태그가 없으면 main·master 브랜치로 물러서지 않고 실패
```

## 목차

- [왜 서명하는가](#왜-서명하는가)
- [검증하는 경로](#검증하는-경로)
- [서명 파일 형식](#서명-파일-형식)
- [제작자 절차](#제작자-절차)
- [운영자 절차](#운영자-절차)
- [신뢰 모델](#신뢰-모델)
- [검증 순서](#검증-순서)
- [검증 상태](#검증-상태)
- [오류 응답](#오류-응답)
- [sodium 확장이 없을 때](#sodium-확장이-없을-때)
- [GitHub 설치는 태그 릴리스만](#github-설치는-태그-릴리스만)
- [서명이 시작되는 판](#서명이-시작되는-판)
- [관련 문서](#관련-문서)

## 왜 서명하는가

확장과 코어 배포본은 서버에서 그대로 실행되는 PHP 코드입니다. 저장소 계정이 털리거나, 내려받는 도중 파일이 바뀌거나, 누군가
그럴듯한 이름의 ZIP 을 건네면 운영자는 ZIP 만 보고는 구별할 수 없습니다. 언어팩 URL 설치의 SHA-256 체크섬은 **전송이
온전한지**만 확인할 뿐, 체크섬을 함께 바꾼 사람까지 걸러 내지 못합니다.

서명은 제작자가 가진 비밀키로만 만들 수 있으므로, 운영자가 그 제작자의 공개키를 믿기로 등록해 두면 「이 ZIP 은 그 제작자가
낸 그 판 그대로다」를 서버가 확인할 수 있습니다. 서명 파일에는 패키지 종류·식별자·버전도 들어 있어, 다른 확장이나 다른 판의
정상 서명 파일을 옆에 붙여 통과시키는 것도 막습니다.

## 검증하는 경로

| 경로 | 서명 파일을 얻는 곳 |
| --- | --- |
| 모듈·플러그인·템플릿 GitHub 설치(관리자 화면) | 태그 릴리스 자산 `{base}.zip` + `{base}.zip.sig.json` |
| 모듈·플러그인·템플릿 수동 ZIP 업로드(관리자 화면) | 업로드 창의 선택 칸 「서명 파일」(`signature`) |
| 모듈·플러그인·템플릿 업데이트 — GitHub(화면·`{type}:update`) | 태그 릴리스 자산 |
| 모듈·플러그인·템플릿 업데이트 — `{type}:update --zip=` | `--signature=` 로 준 파일, 생략하면 ZIP 옆 `<zip>.sig.json` |
| 코어 업데이트 — GitHub(화면·`core:update`) | 태그 릴리스 자산 `g7-{version}.zip` + `.sig.json` |
| 코어 업데이트 — 운영자 ZIP(화면의 올려 둔 ZIP, `core:update --zip=`) | ZIP 옆 `<zip>.sig.json`(명령은 `--signature=` 로 지정 가능) |
| 외부 언어팩 GitHub 설치 | 태그 릴리스 자산 |
| 외부 언어팩 URL 설치 | 선택 칸 서명 파일 주소(`signature_url`). 주소를 추측해 따로 요청하지 않는다 |
| 외부 언어팩 업로드 | 업로드 창의 선택 칸 「서명 파일」(`signature`) |
| 외부 언어팩 업데이트(GitHub) | 태그 릴리스 자산 |

검증하지 않는 경로도 있습니다. 코어와 함께 배포된 원본은 부모 패키지(코어 배포본)의 서명이 덮으므로 따로 서명하지 않습니다.

| 경로 | 기록 |
| --- | --- |
| `_bundled` 원본에서 설치·업데이트(`{type}:install`, `--source=bundled`, 코어 업데이트 뒤 번들 일괄 업데이트) | `bundled` |
| 공식 언어팩(코어 `lang-packs/_bundled`, 확장 안 `lang/`)과 `_bundled` 언어팩 설치 | `bundled` |
| 운영자가 `_pending` 에 직접 둔 원본 설치 | 기록 없음(`null`) — 무엇으로 들어왔는지 알 수 없다 |
| `core:update --source=`·`--local` | 대상 아님 — 배포 패키지가 아니라 운영자가 둔 디렉터리 |

`module:install`·`plugin:install`·`template:install` 은 `_bundled`·`_pending` 원본만 설치하는 명령이라 서명 옵션이 없습니다.
GitHub 주소나 ZIP 파일로 새로 설치하는 일은 관리자 화면에서 합니다.

## 서명 파일 형식

### 예시

`glitter-vector-1.0.0.zip` 옆에 두는 `glitter-vector-1.0.0.zip.sig.json`:

```json
{
  "format": "g7-package-signature",
  "version": 1,
  "algorithm": "ed25519",
  "package": {
    "type": "module",
    "identifier": "glitter-vector",
    "version": "1.0.0"
  },
  "artifact": {
    "filename": "glitter-vector-1.0.0.zip",
    "size": 387,
    "sha256": "3f3aa3594dc72c4e490e7f067e2165512df73467655a7d6105f9eb1cda9f5479"
  },
  "signer": {
    "key_id": "glitter:93a4eed863537167",
    "label": "glitter"
  },
  "signed_at": "2026-11-20T00:00:00Z",
  "signature": "dzq0kDah3rL2XkXaxH9Nd7nB3yah8tPvJB6YR4lGmExY7cVtP/4jS0kHoXS/4M9SnD9wYWYSJzJ4klLKUmRUDA=="
}
```

### 칸

| 칸 | 값 |
| --- | --- |
| `format` | 늘 `g7-package-signature` |
| `version` | 형식 판. 지금은 정수 `1` |
| `algorithm` | 늘 `ed25519` |
| `package.type` | `module`·`plugin`·`template`·`language_pack`·`core` 중 하나 |
| `package.identifier` | 패키지 식별자. 코어는 `g7` |
| `package.version` | 패키지 버전 (앞의 `v` 는 비교할 때 뗀다) |
| `artifact.filename` | ZIP 파일 이름 |
| `artifact.size` | ZIP 크기 (바이트, 정수) |
| `artifact.sha256` | ZIP 의 SHA-256 (소문자 hex 64자) |
| `signer.key_id` | 서명 키 식별자 — 아래 [key_id 규칙](#key_id-규칙) |
| `signer.label` | 서명자 이름 (표시용) |
| `signed_at` | 서명 시각. UTC ISO 8601 초 단위 `Z` (`2026-11-20T00:00:00Z`) |
| `signature` | 서명 대상 문자열에 대한 Ed25519 서명(64바이트)의 표준 base64 |

파일은 64KB 를 넘을 수 없고, 문자열 칸에는 줄바꿈이 들어갈 수 없습니다. 형식·알고리즘·필수 칸 중 하나라도 맞지 않으면
「형식을 알 수 없음」(`invalid`)으로 거절합니다.

### 서명 대상 문자열

서명은 JSON 자체가 아니라 아래 칸들을 줄 단위로 이은 문자열에 대해 만듭니다. JSON 정규화 차이(칸 순서·공백·이스케이프)로
같은 내용이 다른 바이트가 되는 일을 피하기 위해서입니다. 줄바꿈은 LF 하나, 끝 줄바꿈은 없고, 각 칸은 서명 파일에 적힌
문자열 그대로입니다(`sha256` 만 소문자로).

```text
G7-PACKAGE-SIG-V1
{package.type}
{package.identifier}
{package.version}
{artifact.filename}
{artifact.size}
{artifact.sha256}
{signed_at}
{signer.key_id}
```

예시 파일의 서명 대상 문자열(LF 로 이음)은 다음과 같습니다.

```text
G7-PACKAGE-SIG-V1
module
glitter-vector
1.0.0
glitter-vector-1.0.0.zip
387
3f3aa3594dc72c4e490e7f067e2165512df73467655a7d6105f9eb1cda9f5479
2026-11-20T00:00:00Z
glitter:93a4eed863537167
```

### key_id 규칙

```text
key_id = {label 소문자}:{sha256(raw 공개키 32바이트) 의 앞 16 hex}
```

- 공개키는 Ed25519 raw 32바이트의 표준 base64 입니다(PEM·DER 아님).
- 서명자 이름(label)은 영문·숫자·`_`·`-` 로 32자 이내, 첫 글자는 영문·숫자입니다.
- 서버는 서명 파일의 `key_id` 를 믿지 않고, 신뢰 목록에 있는 label·공개키로 늘 다시 계산해 대조합니다.

### 자산 이름과 ZIP 구조

| 항목 | 규칙 |
| --- | --- |
| ZIP 이름 | `{identifier}-{version}.zip` (코어는 `g7-{version}.zip`) |
| 서명 파일 이름 | ZIP 이름 뒤에 `.sig.json` (`sirsoft-board-1.3.0.zip.sig.json`) |
| ZIP 안 | 루트 디렉터리 하나 `{identifier}-{version}/` 아래에 패키지 파일. manifest(`module.json`·`plugin.json`·`template.json`·`language-pack.json`)는 그 바로 아래 |

GitHub 릴리스에서는 두 자산이 **짝으로** 있어야 서명된 패키지로 봅니다. `{identifier}-{version}.zip` 짝을 먼저 찾고, 식별자를
아직 모르는 첫 설치(GitHub 주소로 설치)에서는 짝이 맞는 첫 ZIP 을 씁니다. 짝이 없으면 미서명입니다.

## 제작자 절차

`extension:keygen`·`extension:sign` 은 PHP sodium 확장이 있어야 동작합니다. 서명은 G7 이 설치된 개발 환경(코어 저장소 체크아웃)
에서 합니다.

### 1. 서명 키 만들기 (한 번)

```bash
php artisan extension:keygen --label=acme --out=/secure/keys/acme-signing-key.json
```

| 옵션 | 설명 |
| --- | --- |
| `--label=` | 서명자 이름 (필수). key_id 앞부분이 된다. `sirsoft` 로 시작하는 이름은 운영자가 등록할 수 없으니 쓰지 않는다 |
| `--out=` | 비밀키 파일 경로. 생략하면 현재 디렉터리의 `{label}-signing-key.json`. 같은 경로에 파일이 있으면 덮지 않고 실패 |

출력에 공개키(base64)와 key_id 가 나옵니다. 공개키와 key_id 는 README·릴리스 노트·웹사이트처럼 **저장소와 다른 경로로도**
알려 두어, 운영자가 대조할 수 있게 합니다.

키 파일은 JSON(`format: g7-signing-key`, `label`, `key_id`, `public_key`, `secret_seed`)이고 권한 0600 으로 쓰입니다.
`secret_seed` 는 Ed25519 시드 32바이트의 base64 이며, JWK 로 옮기면 `d` = 시드, `x` = 공개키라 Node `crypto` 같은 다른 도구로도
같은 키를 쓸 수 있습니다.

### 2. 릴리스 ZIP 과 서명 파일 만들기 (판마다)

패키지 디렉터리를 주면 결정적 ZIP 을 만든 뒤 서명합니다.

```bash
php artisan extension:sign ./acme-shop --key=/secure/keys/acme-signing-key.json --out=./dist
# → dist/acme-shop-1.2.0.zip, dist/acme-shop-1.2.0.zip.sig.json
```

이미 만든 ZIP 을 주면 ZIP 은 그대로 두고 서명 파일만 만듭니다.

```bash
php artisan extension:sign ./dist/acme-shop-1.2.0.zip --key=/secure/keys/acme-signing-key.json
```

| 옵션 | 설명 |
| --- | --- |
| `path` | 서명할 ZIP 또는 패키지 디렉터리 |
| `--key=` | `extension:keygen` 으로 만든 비밀키 파일 (필수) |
| `--type=` | `module`·`plugin`·`template`·`language_pack`·`core`. 생략하면 manifest 로 판별 |
| `--out=` | 결과를 쓸 디렉터리. 생략하면 ZIP 이면 ZIP 옆, 디렉터리면 그 상위 |
| `--signed-at=` | 서명 시각(UTC, `2026-11-20T00:00:00Z`). 같은 서명을 다시 만들어야 할 때만. 생략하면 지금 |

종류·식별자·버전은 manifest(코어는 `config/app.php` 의 `APP_VERSION` 기본값)에서 읽습니다. 디렉터리에서 만드는 ZIP 은
루트 `{identifier}-{version}/`, 경로 순 정렬, 고정 시각이라 같은 내용이면 같은 바이트가 나옵니다. `.git`·`.github`·`node_modules`·
`.DS_Store`·`Thumbs.db` 와 심볼릭 링크는 넣지 않습니다. 빌드 산출물(`dist/` 등)이 필요한 확장은 빌드를 마친 디렉터리를 줍니다.

### 3. 릴리스에 올리기

GitHub 에 **태그 릴리스**(`v1.2.0` 또는 `1.2.0`)를 만들고 ZIP 과 서명 파일을 둘 다 자산으로 올립니다. 태그만 푸시하고 릴리스를
만들지 않으면 자산이 없으므로 미서명으로 다뤄집니다. 서명한 뒤 ZIP 을 다시 만들거나 고치면 크기·SHA-256 이 달라져 `invalid`
가 되므로, 올린 ZIP 이 서명한 그 파일인지 확인합니다.

### 비밀키 관리

- 비밀키 파일은 확장 저장소·배포 ZIP·CI 로그·공유 드라이브에 넣지 않습니다. `extension:sign` 의 디렉터리 ZIP 은 패키지 디렉터리
  안의 모든 파일을 담으므로, 키 파일을 패키지 디렉터리 안에 두지 않습니다.
- CI 에서 서명한다면 키 파일을 비밀 저장소에서 작업 중에만 꺼내고 끝나면 지웁니다.
- 키를 잃으면 같은 key_id 로 서명할 수 없습니다. 새 키(새 key_id)를 만들고 운영자에게 새 공개키 등록을 안내합니다.
- 키가 새어 나갔다면 새 키로 바꾸고 운영자에게 옛 key_id 삭제를 요청합니다. 운영자가 지운 키로 서명한 패키지는
  「신뢰하지 않는 키」로 거절됩니다.

## 운영자 절차

### 신뢰 키 등록

제작자가 알려 준 공개키를 **이 키를 믿을 vendor** 와 함께 등록합니다. 등록한 키를 제작자 키라고 부릅니다.

- 화면: 환경설정 > 보안 「확장 신뢰 키」. 코어 내장 키는 읽기 전용으로 함께 보입니다. 추가·삭제는 탭 저장과 따로 즉시 반영되고,
  그때마다 관리자 비밀번호를 다시 묻습니다(비밀번호가 없는 관리자는 본인인증).
- 명령:

```bash
php artisan extension:trust-key add "<공개키 base64>" --label=acme --vendor=acme
php artisan extension:trust-key add "<공개키 base64>" --label=acme --vendor=acme --vendor=acmelabs
php artisan extension:trust-key list
php artisan extension:trust-key remove acme:1a2b3c4d5e6f7a8b
```

- API: [extension-trust-keys API](../backend/api/extension-trust-keys.md)

등록 규칙:

| 항목 | 규칙 |
| --- | --- |
| 공개키 | Ed25519 raw 32바이트의 표준 base64 |
| 서명자 이름 | 영문·숫자·`_`·`-`, 32자 이내. `sirsoft` 로 시작하는 이름은 코어 내장 키 전용이라 불가 |
| vendor 범위 | 1개 이상 필수(최대 50개). 영문 소문자·숫자로 시작하는 확장 식별자 앞부분. `sirsoft` 불가 |
| 중복 | 같은 key_id 는 다시 등록할 수 없고, 코어 내장 키와 같은 키도 등록할 수 없다 |

key_id 는 서버가 이름과 공개키로 계산합니다. 제작자가 알려 준 key_id 와 화면·명령 출력의 key_id 가 같은지 대조합니다.

### vendor 범위

제작자 키는 등록할 때 정한 vendor 의 패키지에만 유효합니다. 한 제작자의 키가 다른 제작자 이름의 패키지를 대신 보증하지
못하게 하기 위해서입니다.

| 패키지 | vendor 를 읽는 곳 |
| --- | --- |
| 모듈·플러그인·템플릿 | 식별자의 첫 `-` 앞부분 (`acme-shop` → `acme`) |
| 언어팩 | 언어팩 manifest 의 `vendor` 칸 — ZIP 을 푼 뒤 manifest 를 읽고 대조한다 |

범위 밖이면 서명이 맞아도 `untrusted`(사유 `vendor_out_of_scope`)로 거절합니다.

### 미서명 패키지를 만났을 때 (관리자 화면)

미서명(`unsigned`) 또는 이 서버에서 검증할 수 없는(`unverifiable`) 패키지는 설치·업데이트 전에 경고 창이 뜹니다. 내용을
확인했다면 「미서명으로 계속」을 누릅니다. 화면은 같은 요청을 `allow_unsigned=1` 로 다시 보내고, 그때 설치·업데이트가
진행됩니다. GitHub 경로는 서명 자산이 없음을 확인한 시점에 ZIP 을 받기 **전에** 묻습니다.

서명 불일치(`invalid`)와 신뢰하지 않는 키(`untrusted`)는 계속할 단추가 없습니다. 제작자에게 확인하거나, 정말 믿는 제작자의
키라면 그 공개키를 신뢰 키로 등록한 뒤 다시 시도합니다.

설치를 마친 확장의 상세 창 「패키지 서명」 배지에서 어떤 상태로 들어왔는지(서명 확인됨·서명 없음·번들 원본·서명 검증 불가,
서명자)를 볼 수 있습니다.

### 옛 관리자 화면 호환

7.1.x 관리자 템플릿을 복사해 쓰는 사이트의 설치·업데이트 창에는 「미서명으로 계속」 단추가 없습니다. 그 화면이 7.2.0 서버에
서명 없는 패키지를 올리면 409 를 받고도 계속할 방법이 없으므로, 서버는 두 화면을 표식으로 구별합니다.

- 7.2.0 관리자 화면은 모든 설치·업데이트 요청과 코어 업데이트 착수에 `integrity_aware=1` 을 늘 싣습니다. 이 요청은 위
  절차대로 미서명이면 409 로 확인을 묻습니다.
- 이 표식이 없는 요청은 옛 화면으로 보고 7.1 동작대로 미서명·검증 불가 패키지를 확인 없이 설치·업데이트합니다. 설치 행에는
  `unsigned`(또는 `unverifiable`)로 기록되고, 서버 경고 로그에 「옛 화면 호환으로 미서명 패키지를 허용했습니다」가 남습니다.
- 「미서명 패키지 차단」이 켜져 있으면 옛 화면도 409(계속 불가)이고, 서명 불일치(`invalid`)와 신뢰하지 않는 키(`untrusted`)도
  표식과 관계없이 422 로 막힙니다.

복사한 템플릿에서도 확인 창을 거치게 하려면 7.2.0 관리자 템플릿으로 바꾸거나, 그 화면의 요청 본문에 `integrity_aware: 1` 과
409 응답의 `errors.integrity.can_continue` 를 보고 `allow_unsigned: 1` 로 다시 보내는 흐름을 더합니다. Artisan 명령은 이
호환을 타지 않습니다(`--allow-unsigned` 또는 대화형 확인).

### 미서명 패키지 차단

환경설정 > 보안 「미서명 패키지 차단」(`security.extension_block_unsigned`, 기본 꺼짐)을 켜면 미서명·검증 불가 패키지는
관리자 확인으로도, `--allow-unsigned` 로도 설치·업데이트할 수 없습니다(409, 계속 불가). 이 스위치는 신뢰 키 추가·삭제와 달리
보안 탭의 저장 버튼으로 반영됩니다. `_bundled` 원본(`bundled`)은 이 스위치와 관계없이 설치됩니다. 쓰는 확장이 모두 서명된 판을
낸 뒤에 켭니다.

### Artisan 명령

| 명령 | 무결성 옵션 |
| --- | --- |
| `module:update`·`plugin:update`·`template:update` | `--allow-unsigned`, `--signature=`(`--zip` 의 서명 파일. 생략하면 ZIP 옆 `<zip>.sig.json`) |
| `core:update` | `--allow-unsigned`, `--signature=`(`--zip` 의 서명 파일. 생략하면 ZIP 옆 `<zip>.sig.json`) |
| `language-pack:install` | `--allow-unsigned`, `--signature=`(`--source=url` 일 때 서명 파일 주소) |
| `language-pack:update` | `--allow-unsigned` |

- 터미널에서 직접 실행하면 미서명 패키지에서 계속할지 묻습니다.
- 확인에 답할 수 없는 실행(cron·CI·원격 명령·`--no-interaction`)은 미서명이면 `--allow-unsigned` 없이 사유와 안내를 출력하고
  **종료 코드 1** 로 끝납니다. 아무것도 바꾸기 전에 멈춥니다.
- `--force`·`--yes` 는 이 확인을 대신하지 않습니다. 미서명 확인은 `--allow-unsigned` 하나로만 합니다.
- 「미서명 패키지 차단」이 켜져 있으면 `--allow-unsigned` 도 무시됩니다. 서명 불일치·미신뢰 키는 어떤 옵션으로도 통과하지 않습니다.
- 스케줄(예약 작업)에 설치·업데이트 명령을 넣는다면 미서명 패키지를 받을 수 있는 경우 `--allow-unsigned` 를 명시해야 합니다.
  넣지 않으면 그 실행은 매번 실패로 끝납니다.

## 신뢰 모델

| 키 | 누가 정하나 | 유효 범위 |
| --- | --- | --- |
| 코어 내장 키 | 코어 코드 상수. 공식 발행자의 키로 코어에 포함되어 배포된다. `.env`·설정·DB 로 추가·변경할 수 없다 | 모든 vendor 의 확장·언어팩, 그리고 코어 배포본 |
| 제작자 키 | 운영자가 등록 | 등록할 때 정한 vendor 의 확장·언어팩. **코어 배포본에는 쓰이지 않는다** |

- 코어 업데이트(`core:update`, 관리자 화면 업데이트)는 코어 내장 키로만 검증합니다. 제작자 키로 서명한 코어 배포본은
  `untrusted`(사유 `core_requires_builtin_key`)입니다. 운영자가 등록한 키가 코어 자체를 바꿀 수 있는 통로가 되지 않게 하기 위해서입니다.
- 코어 내장 키에는 상태가 있습니다.

| 상태 | 의미 |
| --- | --- |
| `active` | 검증에 쓰고, 발행자가 새 서명에도 쓰는 키 |
| `retired` | 검증은 통과한다. 발행자가 더는 새 서명에 쓰지 않는 은퇴 키 |
| `revoked` | 폐기된 키. 이 키의 서명은 `untrusted`(사유 `key_revoked`) |

내장 키 목록은 코어 업데이트로만 바뀝니다. 제작자 키를 지우면 그 키로 서명한 패키지는 그때부터 `untrusted` 입니다.

7.2.0 의 코어 내장 키는 다음 하나입니다. `php artisan extension:trust-key list` 의 「내장」 행과 같습니다.

| key_id | 상태 |
| --- | --- |
| `sirsoft:dc05cd817a9ac65c` | `active` |

발행자가 키를 바꿀 때는 새 키를 `active` 로 더한 코어 릴리스를 옛 키로 서명해 먼저 내보내고, 사이트가 그 판으로 올라온 뒤부터
새 키로 서명합니다. 코어 배포본은 내장 키로만 검증하므로, 한 판을 겹치지 않으면 새 키로 서명한 코어 업데이트를 옛 사이트가
검증할 수 없습니다.

### 서버 상태 확인

`php artisan about` 의 「패키지 무결성」 구획에 이 서버의 검증 준비 상태가 나옵니다.

| 항목 | 값 |
| --- | --- |
| 내장 신뢰 키 | 폐기되지 않은 코어 내장 키 수. 0 이면 노란 `0` 과 함께 「코어 내장 신뢰 키가 비어 있습니다 — 공식 서명 패키지도 「신뢰하지 않는 키」로 차단됩니다.」 |
| PHP sodium 확장 | 사용 가능 / 없음(서명을 검증할 수 없어 미서명 패키지로 다룸) |
| 미서명 패키지 차단 | 켜짐 / 꺼짐 (설정을 읽지 못하면 알 수 없음) |

내장 키가 0 이면 코어 배포본은 내장 키로만 검증하므로 공식 서명 코어 업데이트도 `untrusted` 로 막힙니다. 그래서
`core:check-updates` 도 같은 경고를 출력하고, `extension:trust-key list` 는 내장 키 목록이 비었을 때 같은 경고를 함께 보입니다.
이 경고가 보이면 코어를 공식 배포본으로 다시 받았는지 확인합니다.

## 검증 순서

서버는 아래 순서로 보고, 처음 걸린 단계에서 멈춥니다.

1. 형식·알고리즘 — 서명 파일을 해석하고 `format`·`version`·`algorithm`·필수 칸을 확인한다
2. 기대 패키지 — 서명 파일의 종류·식별자·버전이 지금 설치·업데이트하려는 것과 같은지 (모르는 칸은 해제 뒤 manifest 로)
3. 크기·SHA-256 — 받은 ZIP 이 서명 파일에 적힌 크기·해시와 같은지
4. 키 신뢰 — key_id 가 내장 키 또는 제작자 키에 있는지, 폐기되지 않았는지, vendor 범위 안인지
5. 서명 — 서명 대상 문자열에 대한 Ed25519 서명이 맞는지
6. 해제 뒤 manifest — 풀어 낸 manifest 의 식별자·버전이 서명된 값과 같은지(코어는 버전만), 언어팩은 manifest `vendor` 가 제작자 키 범위 안인지

1~5 는 ZIP 을 풀기 전에, 6 은 풀어서 활성 디렉터리로 복사하기 전에 합니다. 거절된 패키지는 설치 경로에 파일을 남기지 않고
이미 설치된 판도 건드리지 않습니다.

## 검증 상태

설치 행(모듈·플러그인·템플릿·언어팩)의 `integrity_status`·`integrity_key_id` 에 결과가 남고, API 응답과 상세 창 배지가 이 값을 씁니다.
7.2.0 이전에 설치해 기록이 없는 행은 `null` 입니다.

| 상태 | 뜻 | 처리 |
| --- | --- | --- |
| `verified` | 신뢰하는 키로 서명이 확인됨 | 통과 |
| `bundled` | 코어와 함께 배포된 `_bundled` 원본에서 설치 — 부모 패키지 서명이 덮는다 | 통과 |
| `unsigned` | 서명 파일이 없음 | 관리자 확인 뒤 계속(409). 차단 스위치가 켜져 있으면 불가 |
| `unverifiable` | 서명은 있으나 이 서버에 sodium 확장이 없어 확인할 수 없음 | `unsigned` 와 같다 |
| `invalid` | 서명이 있으나 내용·형식이 맞지 않음(변조, 식별자·버전 불일치 등) | 늘 차단(422) |
| `untrusted` | 서명이 있으나 신뢰하지 않는 키(미등록·폐기·vendor 범위 밖·코어에 제작자 키) | 늘 차단(422) |

거절 사유(`reason`):

| 사유 | 상태 | 뜻 |
| --- | --- | --- |
| `format_unknown` | invalid | 형식·알고리즘을 모르거나 필수 칸이 빠졌거나 64KB 초과 |
| `type_mismatch`·`identifier_mismatch`·`version_mismatch` | invalid | 서명 파일의 종류·식별자·버전이 기대와 다름 |
| `size_mismatch`·`digest_mismatch` | invalid | ZIP 크기·SHA-256 이 서명 파일과 다름 |
| `signature_mismatch` | invalid | 서명 값이 맞지 않음 |
| `manifest_identifier_mismatch`·`manifest_version_mismatch` | invalid | 푼 manifest 가 서명된 식별자·버전과 다름 |
| `key_unknown` | untrusted | key_id 가 신뢰 목록에 없음 |
| `key_revoked` | untrusted | 폐기된 내장 키 |
| `key_id_mismatch` | untrusted | key_id 가 등록된 이름·공개키로 계산한 값과 다름 |
| `vendor_out_of_scope` | untrusted | 제작자 키의 vendor 범위 밖 |
| `core_requires_builtin_key` | untrusted | 코어 배포본을 내장 키가 아닌 키로 서명 |
| `sodium_missing` | unverifiable | sodium 확장이 없어 서명을 확인할 수 없음 |

## 오류 응답

설치·업데이트 API 가 무결성 때문에 멈추면 다음 응답을 냅니다.

| 상태코드 | `errors` 표지 | 경우 |
| --- | --- | --- |
| 409 | `package_unsigned: true` | 미서명·검증 불가. `errors.integrity.can_continue` 가 true 면 같은 요청을 `allow_unsigned=1` 로 다시 보내 계속. 차단 스위치가 켜져 있으면 false |
| 422 | `package_signature_invalid: true` | 서명 불일치 — 계속 불가 |
| 422 | `package_signer_untrusted: true` | 신뢰하지 않는 키 — 계속 불가 |

같은 409 는 작업 잠금(`errors.operation_in_progress`)에도 쓰이므로, 화면은 표지로 둘을 가릅니다.

```json
{
    "success": false,
    "message": "서명되지 않은 패키지입니다. 내용을 확인했다면 「미서명으로 계속」을 눌러 설치하세요. 이 패키지에는 서명 파일이 없습니다.",
    "errors": {
        "package_unsigned": true,
        "integrity": {
            "status": "unsigned",
            "reason": "unsigned",
            "message": "이 패키지에는 서명 파일이 없습니다.",
            "key_id": null,
            "signer_label": null,
            "key_source": null,
            "package": null,
            "code": "package_unsigned",
            "can_continue": true,
            "blocked_by_policy": false
        }
    }
}
```

| `errors.integrity` 칸 | 설명 |
| --- | --- |
| `status` | 검증 상태 ([검증 상태](#검증-상태)) |
| `reason` | 사유 코드 (표 위) |
| `message` | 사유 문장 (현재 로케일) |
| `key_id`·`signer_label` | 서명 파일의 key_id·서명자 이름 (서명이 없으면 null) |
| `key_source` | `builtin`(코어 내장 키)·`vendor`(제작자 키)·null |
| `package` | 서명 파일이 말한 `{type, identifier, version}` (서명이 없으면 null) |
| `code` | `package_unsigned`·`package_signature_invalid`·`package_signer_untrusted` |
| `can_continue` | 관리자 확인(`allow_unsigned=1`)으로 계속할 수 있는지 |
| `blocked_by_policy` | 「미서명 패키지 차단」 스위치로 막혔는지 |

요청 필드:

| 필드 | 받는 요청 | 설명 |
| --- | --- | --- |
| `allow_unsigned` | 모든 설치·업데이트 요청, 코어 업데이트 착수 | 미서명·검증 불가 패키지를 계속한다 (boolean) |
| `integrity_aware` | 모든 설치·업데이트 요청, 코어 업데이트 착수 | 패키지 서명 확인을 아는 화면의 표식 (boolean). 없으면 옛 관리자 화면으로 보고 미서명을 확인 없이 진행한다 — [옛 관리자 화면 호환](#옛-관리자-화면-호환) |
| `signature` | ZIP 업로드 요청(모듈·플러그인·템플릿·언어팩) | 서명 파일(`.sig.json`), 64KB 이하. 업로드 ZIP 은 옆 파일을 자동으로 찾지 않으므로 이 칸으로만 준다 |
| `signature_url` | 언어팩 URL 설치 | 서명 파일 주소 (공개 http·https, 500자 이내). 받지 못하면 설치하지 않는다 |

코어 업데이트 확인(`POST /api/admin/core-update/check`) 응답의 `signature_available` 은 최신 릴리스에 서명 자산 짝이 있는지
(모르면 null)를 알려 주고, 업데이트 확인 화면의 「릴리스 서명」 표시가 이 값을 씁니다. 엔드포인트별 표는 [modules](../backend/api/modules.md)·[plugins](../backend/api/plugins.md)·
[templates](../backend/api/templates.md)·[language-packs](../backend/api/language-packs.md)·[core-update](../backend/api/core-update.md)
API 문서에 있습니다.

## sodium 확장이 없을 때

Ed25519 검증에는 PHP sodium 확장이 필요합니다. 설치 마법사의 요구사항 화면은 이를 권장 항목으로 보이고, 없어도 G7 은 동작합니다.

- 서명 파일이 있는 패키지는 `unverifiable`(사유 `sodium_missing`)이 되고, 미서명과 같은 정책(관리자 확인·차단 스위치)을 따릅니다.
  서명 파일의 형식·크기·SHA-256·키 신뢰는 sodium 없이도 확인하므로, 그 단계에서 걸리면 여전히 `invalid`·`untrusted` 로 차단됩니다.
- 신뢰 키 화면에 이 서버에서 서명을 확인할 수 없다는 사실이 함께 보입니다(API `sodium_available`).
- `extension:keygen`·`extension:sign` 은 sodium 이 없으면 실패합니다.

요구사항 표: [requirements.md](../requirements.md)

## GitHub 설치는 태그 릴리스만

7.2.0 부터 GitHub 설치·업데이트(확장·언어팩·코어)는 **태그 릴리스**에서만 받습니다. 받을 릴리스나 태그가 없으면 `main`·`master`
브랜치 압축본으로 물러서지 않고 실패합니다. 브랜치 끝은 서명할 수 없는 움직이는 대상이고, 아직 릴리스하지 않은 코드가 운영
사이트에 들어가는 통로였기 때문입니다.

- 릴리스에 서명 자산 짝이 있으면 그 ZIP 을 받아 검증합니다.
- 짝이 없으면(자산 없는 릴리스, 릴리스 없이 푸시한 태그) 태그 압축본을 미서명으로 받습니다. 정책이 허락하지 않으면 받기 전에 멈춥니다.
- 제작자는 설치를 받으려면 판마다 태그 릴리스를 만들어야 합니다.

## 서명이 시작되는 판

서명은 7.2.0 과 그에 맞춰 낸 확장 릴리스부터입니다. 이미 배포된 옛 판에 서명을 소급해 붙이지 않으므로, 옛 판을 GitHub 에서
다시 받으면 미서명으로 다뤄집니다. 옛 판으로 설치된 행은 다음 업데이트까지 `integrity_status` 가 `null` 로 남습니다.

## 관련 문서

- [확장 업데이트 시스템](extension-update-system.md) — GitHub·`--zip` 업데이트 경로
- [확장 관리자](extension-manager.md) — 설치 경로
- [언어팩 시스템](language-packs.md) — 외부 언어팩 설치 경로별 서명
- [모듈 Artisan 커맨드](module-commands.md) · [템플릿 Artisan 커맨드](template-commands.md)
- [extension-trust-keys API](../backend/api/extension-trust-keys.md)
- [코어 업데이트 API](../backend/api/core-update.md)
