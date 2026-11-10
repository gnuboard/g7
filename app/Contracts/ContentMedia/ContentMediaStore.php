<?php

namespace App\Contracts\ContentMedia;

use App\Enums\ContentMediaKind;
use App\Support\ContentMedia\ContentMediaContext;
use App\Support\ContentMedia\ContentMediaPolicy;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * 본문 미디어 어댑터 계약 — 확장(모듈·플러그인)이 구현하는 저장소·권한 부분 (#887)
 *
 * 업로드·저장 동기화(연결 → 복제 → 제거)·서명·서빙·정리 흐름은 코어 골격(`AbstractContentMediaService`)이 한 곳에서 돌리고,
 * 확장은 이 계약으로 「어디에 어떤 모양으로 저장하나」와 「누가 볼 수 있나」만 답한다. 확장마다 행 표(게시판
 * `board_attachments`, 페이지 `page_attachments` 등)·저장 경로·meta 모양이 달라도 골격은 이 계약만 부른다.
 *
 * 행(`Model`)은 다음 속성을 읽을 수 있어야 한다: `hash`(12자 서빙 해시), `disk`, `mime_type`, `original_filename`,
 * `stored_filename`, `size`, `meta`, `created_by`. 카테고리 기준 파일 경로는 `storagePathOf()` 로 얻는다(행의 `path` 열이
 * 디스크 기준 경로여도 된다).
 *
 * @since 7.1.5
 */
interface ContentMediaStore
{
    /**
     * 확장 종류.
     *
     * @return string `module` 또는 `plugin` (`ContentMediaContext::EXTENSION_*`)
     */
    public function extensionType(): string;

    /**
     * 확장 식별자.
     *
     * @return string 식별자 (예: `sirsoft-board`)
     */
    public function extensionIdentifier(): string;

    /**
     * 서빙 라우트 이름 — 서명 주소를 만들고, 부팅 때 미디어 출처 허용 패턴으로 등록한다(`ContentMediaRegistrar`).
     *
     * @return string 라우트 이름 (예: `api.modules.sirsoft-board.boards.content-media.show`)
     */
    public function serveRouteName(): string;

    /**
     * 서빙 라우트 파라미터.
     *
     * @param  ContentMediaContext  $context  문맥
     * @param  string  $hash  해시
     * @return array<string, mixed> 파라미터 (예: `['slug' => …, 'hash' => …]`)
     */
    public function serveRouteParameters(ContentMediaContext $context, string $hash): array;

    /**
     * 이 문맥의 서빙 주소 정규식 본문(구분자 없음, 명명 그룹 `hash` 필수) — 본문 참조 추출·서명·정규화·편집기 개수 판정에 쓴다.
     *
     * @param  ContentMediaContext  $context  문맥
     * @return string 정규식 본문 (예: `/api/modules/x/content-media/(?<hash>[A-Za-z0-9]{12})`)
     */
    public function servePattern(ContentMediaContext $context): string;

    /**
     * 편집기 업로드 주소(같은 출처 상대 경로).
     *
     * @param  ContentMediaContext  $context  문맥
     * @param  bool  $admin  관리자 화면 여부
     * @return string 업로드 주소
     */
    public function uploadEndpoint(ContentMediaContext $context, bool $admin): string;

    /**
     * 업로드 요청에 함께 보낼 폼 필드(임시 키는 화면이 더한다).
     *
     * @param  ContentMediaContext  $context  문맥
     * @return array<string, string|int> 필드 (없으면 빈 배열)
     */
    public function uploadFields(ContentMediaContext $context): array;

    /**
     * 저장된 정책 값(확장 설정·게시판 컬럼 등). 없으면 null — 대체 정책을 쓴다.
     *
     * @param  ContentMediaContext  $context  문맥
     * @return array<string, mixed>|null 정책 배열 (`ContentMediaPolicy::toArray` 형식)
     */
    public function policyValue(ContentMediaContext $context): ?array;

    /**
     * 정책 값이 없을 때 쓸 대체 정책(보통 확장의 첨부 정책으로 만든 것).
     *
     * @param  ContentMediaContext  $context  문맥
     * @return ContentMediaPolicy 대체 정책
     */
    public function fallbackPolicy(ContentMediaContext $context): ContentMediaPolicy;

    /**
     * 파일을 두는 저장소 카테고리.
     *
     * @return string 카테고리 (예: `attachments`)
     */
    public function storageCategory(): string;

    /**
     * 새 파일의 카테고리 기준 경로 — 업로드와 복제가 쓴다. 날짜 디렉터리 등 경로 모양은 확장이 정한다.
     *
     * @param  ContentMediaContext  $context  문맥 (복제면 새 소유 문서의 문맥)
     * @param  string  $storedFilename  저장 파일명 (UUID + 확장자)
     * @return string 카테고리 기준 상대 경로
     */
    public function storagePath(ContentMediaContext $context, string $storedFilename): string;

    /**
     * 기존 행 파일의 카테고리 기준 경로.
     *
     * @param  Model  $row  본문 미디어 행
     * @return string 카테고리 기준 상대 경로
     */
    public function storagePathOf(Model $row): string;

    /**
     * 업로드 행에 둘 meta.
     *
     * @param  ContentMediaContext  $context  문맥
     * @param  ContentMediaKind|null  $kind  내용으로 판정한 종류 (판정 불가면 null)
     * @param  array{width: int, height: int}|null  $dimensions  이미지 크기 (이미지가 아니거나 읽지 못하면 null)
     * @return array<string, mixed>|null meta
     */
    public function uploadMeta(ContentMediaContext $context, ?ContentMediaKind $kind, ?array $dimensions): ?array;

    /**
     * 이 문맥(임시 키·범위)의 임시 행 개수 — 업로드 시점 상한 판정.
     *
     * @param  ContentMediaContext  $context  문맥 (`tempKey` 있음)
     * @return int 개수
     */
    public function countTemp(ContentMediaContext $context): int;

    /**
     * 이 문맥(임시 키·범위)의 임시 행 중 해시가 맞는 것.
     *
     * @param  ContentMediaContext  $context  문맥 (`tempKey` 있음)
     * @param  array<int, string>  $hashes  해시
     * @return Collection<int, Model> 임시 행
     */
    public function tempRowsByHashes(ContentMediaContext $context, array $hashes): Collection;

    /**
     * 문서에 이어도 되는 임시 행만 남깁니다 — 올린 사람 규칙(회원 본인, 비회원은 소유 증명 등).
     *
     * @param  ContentMediaContext  $context  문맥
     * @param  Collection<int, Model>  $rows  같은 임시 키의 임시 행
     * @return Collection<int, Model> 이을 행
     */
    public function filterLinkableTempRows(ContentMediaContext $context, Collection $rows): Collection;

    /**
     * 문서에 이어진 본문 미디어 행.
     *
     * @param  ContentMediaContext  $context  문맥 (`documentId` 있음)
     * @return Collection<int, Model> 행
     */
    public function ownedRows(ContentMediaContext $context): Collection;

    /**
     * 서빙 범위(게시판이면 그 게시판)에서 해시로 행을 찾습니다 — 소프트 삭제된 행은 제외.
     *
     * @param  ContentMediaContext  $context  문맥
     * @param  string  $hash  해시
     * @return Model|null 행
     */
    public function findByHash(ContentMediaContext $context, string $hash): ?Model;

    /**
     * 행을 만듭니다.
     *
     * `$attributes` 키: `temp_key`·`original_filename`·`stored_filename`·`disk`·`path`(카테고리 기준 경로 — 확장이 자기 열 형식으로
     * 바꿔 저장)·`mime_type`·`size`·`meta`·`order`·`created_by`·`guest_owner_hash`. 소유 열(문서 ID·범위)은 확장이 문맥으로 채운다.
     *
     * @param  ContentMediaContext  $context  문맥
     * @param  array<string, mixed>  $attributes  공통 속성
     * @param  bool  $attachToDocument  true 면 문맥 문서 소유(복제), false 면 임시 행(업로드 — 문맥의 임시 키)
     * @return Model 새 행 (해시가 채워져 있어야 한다)
     */
    public function createRow(ContentMediaContext $context, array $attributes, bool $attachToDocument): Model;

    /**
     * 임시 행을 문서에 잇습니다(DB 갱신, 같은 저장 트랜잭션).
     *
     * @param  ContentMediaContext  $context  문맥 (`documentId` 있음)
     * @param  Model  $row  임시 행
     * @param  int  $order  본문 안 순서 (1부터)
     * @return void 반환값 없음
     */
    public function linkRow(ContentMediaContext $context, Model $row, int $order): void;

    /**
     * 본문에서 빠진 행을 확장의 제거 정책대로 처리합니다.
     *
     * 영구 삭제가 필요하면 `$purge($row)` 를 부른다 — 골격이 `core.content_media.before_delete` 를 발화하고, 파일을 행
     * 디스크에서 커밋 뒤에 지우고, `forceDeleteRow()` 로 행을 지운다. 소프트 삭제(휴지통·보존기간 뒤 정리)는 확장이 직접 한다
     * (훅 없음). 남겨 둘 이유가 있으면(버전이 참조하는 등) 아무것도 하지 않고 false 를 돌려준다.
     *
     * @param  ContentMediaContext  $context  문맥
     * @param  Model  $row  본문에서 빠진 행
     * @param  Closure(Model): void  $purge  골격의 영구 삭제
     * @return bool 제거(소프트·영구)했으면 true, 남겼으면 false
     */
    public function handleRemoved(ContentMediaContext $context, Model $row, Closure $purge): bool;

    /**
     * 행을 영구 삭제합니다(소프트 삭제 행 포함). 파일은 골격이 지운다.
     *
     * @param  Model  $row  행
     * @return void 반환값 없음
     */
    public function forceDeleteRow(Model $row): void;

    /**
     * 아직 문서에 잇지 않은 임시 행인지.
     *
     * @param  Model  $row  행
     * @return bool 임시 행이면 true
     */
    public function isTemp(Model $row): bool;

    /**
     * 요청자가 이 행을 서명 없이 볼 수 있는지 — 서빙 게이트이자 복제 게이트의 기준(문서 열람 권한·삭제·비밀 등).
     *
     * @param  ContentMediaContext  $context  문맥 (요청자 포함)
     * @param  Model  $row  문서에 이어진 행
     * @return bool 볼 수 있으면 true
     */
    public function canView(ContentMediaContext $context, Model $row): bool;

    /**
     * 서명 없이 **아무나**(비회원 포함) 볼 수 있는지 — 응답 캐시(public/private)와 서명 생략(공개 문서는 정식 주소)의 기준.
     *
     * @param  ContentMediaContext  $context  문맥
     * @param  Model|null  $row  행 (null 이면 문맥의 문서 기준)
     * @return bool 공개면 true
     */
    public function isPublic(ContentMediaContext $context, ?Model $row = null): bool;

    /**
     * 서빙 응답 캐시 수명(초).
     *
     * @param  bool  $public  공개 응답 여부
     * @return int 초
     */
    public function cacheMaxAge(bool $public): int;

    /**
     * 요청자가 이 문맥에 본문 미디어를 올릴 수 있는지 — 표준 계약 엔드포인트(`…/content-media/target`)가 본다.
     *
     * @param  ContentMediaContext  $context  문맥
     * @return bool 올릴 수 있으면 true
     */
    public function canUpload(ContentMediaContext $context): bool;

    /**
     * 행이 없을 때 404 문구 키.
     *
     * @return string 다국어 키
     */
    public function notFoundMessageKey(): string;

    /**
     * 복제 원본 후보 — 확장 안 복제 범위(게시판 사이 등)에서 해시로 찾는다.
     *
     * @param  ContentMediaContext  $context  저장 중인 문서의 문맥
     * @param  string  $hash  해시
     * @return Model|null 원본 행
     */
    public function findCloneSource(ContentMediaContext $context, string $hash): ?Model;

    /**
     * 이 원본을 이 문서 소유로 복제해도 되는지 — 임시 행은 요청자 본인 것, 이어진 행은 `canView` 와 같은 기준이어야 한다
     * (복제가 열람 권한을 넓히지 않게).
     *
     * @param  ContentMediaContext  $context  저장 중인 문서의 문맥
     * @param  Model  $row  원본 행
     * @return bool 복제하면 true
     */
    public function mayClone(ContentMediaContext $context, Model $row): bool;

    /**
     * 정리할 행 — 보존기간이 지난 임시 행, 확장이 소프트 삭제 뒤 정리하는 행.
     *
     * @param  CarbonInterface  $threshold  이 시각 이전 것
     * @param  int  $limit  종류마다 최대 건수
     * @return Collection<int, Model> 행
     */
    public function staleRows(CarbonInterface $threshold, int $limit): Collection;

    /**
     * 행으로 문맥을 만듭니다(정리 명령처럼 요청 문맥이 없는 곳의 훅 인자).
     *
     * @param  Model  $row  행
     * @return ContentMediaContext 문맥
     */
    public function contextForRow(Model $row): ContentMediaContext;
}
