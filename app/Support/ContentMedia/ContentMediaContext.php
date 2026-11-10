<?php

namespace App\Support\ContentMedia;

use App\Enums\ContentMediaKind;
use InvalidArgumentException;

/**
 * 본문 미디어 문맥 — 어느 확장·어느 문서의 본문 미디어를 누가 다루는지 (#887)
 *
 * 코어 골격(`AbstractContentMediaService`)·확장 어댑터(`ContentMediaStore`)·공용 훅(`core.content_media.*`)이 같은 값을
 * 주고받는다. 플러그인도 본문을 가질 수 있으므로 확장 종류(module|plugin)와 식별자를 함께 싣는다.
 *
 * | 필드 | 뜻 | 예 |
 * | --- | --- | --- |
 * | `extensionType` | 확장 종류 | `module`·`plugin` |
 * | `extension` | 확장 식별자 | `sirsoft-board` |
 * | `documentType` | 문서 종류(확장이 정함) | `post`·`page`·`product` |
 * | `documentId` | 문서 ID — 저장 전(임시 업로드)이면 null | `15` |
 * | `tempKey` | 편집 화면이 발급한 임시 업로드 키 | `tk_…` |
 * | `scope` | 확장 안 범위(게시판 슬러그 등), 없으면 null | `notice` |
 * | `actorId` | 요청한 회원 ID, 비회원·요청 없음이면 null | `3` |
 * | `guestOwnerHash` | 비회원 업로드 소유 증명 해시(쿠키), 없으면 null | — |
 * | `kind` | 다루는 파일의 종류(업로드 훅에서 채움), 모르면 null | `image` |
 * | `extra` | 확장 전용 부가 값(골격은 읽지 않음) | `['trigger' => 'admin']` |
 *
 * 불변 값 객체다 — 값을 바꾸려면 `with*()` 로 사본을 만든다.
 *
 * @since 7.1.5
 */
final class ContentMediaContext
{
    /** 확장 종류: 모듈 */
    public const EXTENSION_MODULE = 'module';

    /** 확장 종류: 플러그인 */
    public const EXTENSION_PLUGIN = 'plugin';

    /**
     * @param  string  $extensionType  확장 종류 (`module`|`plugin`)
     * @param  string  $extension  확장 식별자
     * @param  string  $documentType  문서 종류
     * @param  int|null  $documentId  문서 ID (저장 전이면 null)
     * @param  string|null  $tempKey  임시 업로드 키
     * @param  string|null  $scope  확장 안 범위 (게시판 슬러그 등)
     * @param  int|null  $actorId  요청한 회원 ID
     * @param  string|null  $guestOwnerHash  비회원 업로드 소유 증명 해시
     * @param  ContentMediaKind|null  $kind  파일 종류
     * @param  array<string, mixed>  $extra  확장 전용 부가 값
     *
     * @throws InvalidArgumentException 확장 종류·식별자·문서 종류가 형식에 맞지 않을 때
     */
    public function __construct(
        public readonly string $extensionType,
        public readonly string $extension,
        public readonly string $documentType,
        public readonly ?int $documentId = null,
        public readonly ?string $tempKey = null,
        public readonly ?string $scope = null,
        public readonly ?int $actorId = null,
        public readonly ?string $guestOwnerHash = null,
        public readonly ?ContentMediaKind $kind = null,
        public readonly array $extra = [],
    ) {
        if (! in_array($extensionType, [self::EXTENSION_MODULE, self::EXTENSION_PLUGIN], true)) {
            throw new InvalidArgumentException("Content media context extension type must be module or plugin: {$extensionType}");
        }
        if ($extension === '' || $documentType === '') {
            throw new InvalidArgumentException('Content media context requires an extension identifier and a document type.');
        }
    }

    /**
     * 모듈 문맥을 만듭니다.
     *
     * @param  string  $identifier  모듈 식별자
     * @param  string  $documentType  문서 종류
     * @param  int|null  $documentId  문서 ID
     * @return self 문맥
     */
    public static function module(string $identifier, string $documentType, ?int $documentId = null): self
    {
        return new self(self::EXTENSION_MODULE, $identifier, $documentType, $documentId);
    }

    /**
     * 플러그인 문맥을 만듭니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @param  string  $documentType  문서 종류
     * @param  int|null  $documentId  문서 ID
     * @return self 문맥
     */
    public static function plugin(string $identifier, string $documentType, ?int $documentId = null): self
    {
        return new self(self::EXTENSION_PLUGIN, $identifier, $documentType, $documentId);
    }

    /**
     * 문서 ID 를 바꾼 사본.
     *
     * @param  int|null  $documentId  문서 ID
     * @return self 사본
     */
    public function withDocument(?int $documentId): self
    {
        return $this->copy(['documentId' => $documentId]);
    }

    /**
     * 임시 업로드 키를 바꾼 사본.
     *
     * @param  string|null  $tempKey  임시 키 (빈 문자열은 null)
     * @return self 사본
     */
    public function withTempKey(?string $tempKey): self
    {
        return $this->copy(['tempKey' => $tempKey === '' ? null : $tempKey]);
    }

    /**
     * 확장 안 범위를 바꾼 사본.
     *
     * @param  string|null  $scope  범위
     * @return self 사본
     */
    public function withScope(?string $scope): self
    {
        return $this->copy(['scope' => $scope]);
    }

    /**
     * 요청자를 바꾼 사본.
     *
     * @param  int|null  $actorId  회원 ID
     * @param  string|null  $guestOwnerHash  비회원 소유 증명 해시 (회원이면 버린다)
     * @return self 사본
     */
    public function withActor(?int $actorId, ?string $guestOwnerHash = null): self
    {
        return $this->copy(['actorId' => $actorId, 'guestOwnerHash' => $actorId === null ? $guestOwnerHash : null]);
    }

    /**
     * 파일 종류를 바꾼 사본.
     *
     * @param  ContentMediaKind|null  $kind  종류
     * @return self 사본
     */
    public function withKind(?ContentMediaKind $kind): self
    {
        return $this->copy(['kind' => $kind]);
    }

    /**
     * 확장 전용 부가 값을 더한 사본(같은 키는 덮어쓴다).
     *
     * @param  array<string, mixed>  $extra  부가 값
     * @return self 사본
     */
    public function withExtra(array $extra): self
    {
        return $this->copy(['extra' => array_replace($this->extra, $extra)]);
    }

    /**
     * 확장 전용 부가 값 하나.
     *
     * @param  string  $key  키
     * @param  mixed  $default  없을 때 값
     * @return mixed 값
     */
    public function extra(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->extra) ? $this->extra[$key] : $default;
    }

    /**
     * 저장된 문서의 문맥인지(문서 ID 가 있는지).
     *
     * @return bool 문서 ID 가 있으면 true
     */
    public function hasDocument(): bool
    {
        return $this->documentId !== null;
    }

    /**
     * 요청자가 그 행을 올린 사람인지 — 회원은 올린 회원 ID, 비회원은 소유 증명 해시가 같을 때.
     *
     * 비회원 판정은 행에 소유 증명 해시가 있고 문맥에도 있을 때만 참이다(둘 중 하나가 없으면 거짓 — 비회원끼리 구분할 수 없는
     * 행을 본인 것으로 보지 않는다).
     *
     * @param  int|string|null  $createdBy  행을 올린 회원 ID
     * @param  string|null  $rowGuestOwnerHash  행의 비회원 소유 증명 해시
     * @return bool 본인이 올린 행이면 true
     */
    public function owns(int|string|null $createdBy, ?string $rowGuestOwnerHash = null): bool
    {
        if ($this->actorId !== null) {
            return $createdBy !== null && (int) $createdBy === $this->actorId;
        }

        return $createdBy === null
            && $this->guestOwnerHash !== null && $this->guestOwnerHash !== ''
            && $rowGuestOwnerHash !== null && hash_equals($rowGuestOwnerHash, $this->guestOwnerHash);
    }

    /**
     * 로그·훅 리스너용 배열.
     *
     * @return array<string, mixed> 필드 값 (비회원 소유 증명 해시는 싣지 않는다)
     */
    public function toArray(): array
    {
        return [
            'extension_type' => $this->extensionType,
            'extension' => $this->extension,
            'document_type' => $this->documentType,
            'document_id' => $this->documentId,
            'scope' => $this->scope,
            'actor_id' => $this->actorId,
            'kind' => $this->kind?->value,
        ];
    }

    /**
     * 일부 필드를 바꾼 사본을 만듭니다.
     *
     * @param  array<string, mixed>  $changes  바꿀 필드
     * @return self 사본
     */
    private function copy(array $changes): self
    {
        $values = array_replace([
            'extensionType' => $this->extensionType,
            'extension' => $this->extension,
            'documentType' => $this->documentType,
            'documentId' => $this->documentId,
            'tempKey' => $this->tempKey,
            'scope' => $this->scope,
            'actorId' => $this->actorId,
            'guestOwnerHash' => $this->guestOwnerHash,
            'kind' => $this->kind,
            'extra' => $this->extra,
        ], $changes);

        return new self(...$values);
    }
}
