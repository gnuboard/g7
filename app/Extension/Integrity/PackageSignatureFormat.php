<?php

namespace App\Extension\Integrity;

/**
 * 패키지 서명 파일(`{zip}.sig.json`) 형식 — 서명 대상 문자열·key_id 규칙·형태 검사.
 *
 * 서명 대상은 JSON 정규화를 피한 줄 단위 문자열이다. PHP(`extension:sign`)와 Node(릴리스 도구)가 같은 바이트를 만들도록
 * 줄바꿈은 LF 하나, 끝 줄바꿈 없음, 각 칸은 서명 파일에 적힌 문자열 그대로다.
 *
 * ```
 * G7-PACKAGE-SIG-V1\n{type}\n{identifier}\n{version}\n{filename}\n{size}\n{sha256}\n{signed_at}\n{key_id}
 * ```
 *
 * key_id 는 `{label 소문자}:{sha256(raw 공개키) 앞 16 hex}` 이고 서버가 늘 다시 계산한다. 공개키는 raw 32바이트의 표준 base64.
 *
 * @since 7.2.0
 */
final class PackageSignatureFormat
{
    /** 서명 파일 format 칸 값 */
    public const FORMAT = 'g7-package-signature';

    /** 서명 파일 형식 판 */
    public const VERSION = 1;

    /** 서명 알고리즘 */
    public const ALGORITHM = 'ed25519';

    /** 서명 대상 문자열 머리 */
    public const SIGNING_PREFIX = 'G7-PACKAGE-SIG-V1';

    /** 서명 파일 확장자 (ZIP 경로 뒤에 붙는다) */
    public const FILE_SUFFIX = '.sig.json';

    /** 서명 파일 최대 크기 (바이트) — 업로드 칸 검증(64KB)과 같다 */
    public const MAX_FILE_BYTES = 65536;

    /** 서명자 이름 규칙 — key_id 앞부분이 되므로 영문·숫자·`_`·`-` 만 */
    public const LABEL_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,31}$/';

    /** key_id 규칙 */
    public const KEY_ID_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,31}:[0-9a-f]{16}$/';

    /**
     * 서명 대상 문자열을 만듭니다.
     *
     * @param  array{package: array{type: string, identifier: string, version: string}, artifact: array{filename: string, size: int|string, sha256: string}, signed_at: string, signer: array{key_id: string}}  $document  서명 파일 내용(서명 칸 제외)
     * @return string 서명 대상 문자열
     */
    public static function signingString(array $document): string
    {
        return implode("\n", [
            self::SIGNING_PREFIX,
            (string) $document['package']['type'],
            (string) $document['package']['identifier'],
            (string) $document['package']['version'],
            (string) $document['artifact']['filename'],
            (string) $document['artifact']['size'],
            strtolower((string) $document['artifact']['sha256']),
            (string) $document['signed_at'],
            (string) $document['signer']['key_id'],
        ]);
    }

    /**
     * 공개키와 서명자 이름으로 key_id 를 계산합니다.
     *
     * @param  string  $label  서명자 이름
     * @param  string  $rawPublicKey  raw 32바이트 공개키
     * @return string key_id
     */
    public static function keyId(string $label, string $rawPublicKey): string
    {
        return strtolower($label).':'.substr(hash('sha256', $rawPublicKey), 0, 16);
    }

    /**
     * base64 공개키를 raw 32바이트로 풉니다.
     *
     * @param  string  $publicKey  표준 base64 공개키
     * @return string|null raw 공개키 (형식이 틀리면 null)
     */
    public static function decodePublicKey(string $publicKey): ?string
    {
        $raw = base64_decode(trim($publicKey), true);

        return is_string($raw) && strlen($raw) === 32 ? $raw : null;
    }

    /**
     * 서명 파일 문자열을 해석하고 형태를 검사합니다.
     *
     * @param  string  $json  서명 파일 내용
     * @return array<string, mixed>|null 서명 파일 배열 (형식·알고리즘·필수 칸이 맞지 않으면 null)
     */
    public static function parse(string $json): ?array
    {
        if (strlen($json) > self::MAX_FILE_BYTES) {
            return null;
        }

        try {
            $doc = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($doc)
            || ($doc['format'] ?? null) !== self::FORMAT
            || ($doc['version'] ?? null) !== self::VERSION
            || ($doc['algorithm'] ?? null) !== self::ALGORITHM) {
            return null;
        }

        $package = $doc['package'] ?? null;
        $artifact = $doc['artifact'] ?? null;
        $signer = $doc['signer'] ?? null;

        if (! is_array($package) || ! is_array($artifact) || ! is_array($signer)) {
            return null;
        }

        foreach ([$package['type'] ?? null, $package['identifier'] ?? null, $package['version'] ?? null,
            $artifact['filename'] ?? null, $artifact['sha256'] ?? null, $signer['key_id'] ?? null,
            $doc['signed_at'] ?? null, $doc['signature'] ?? null] as $value) {
            if (! is_string($value) || $value === '' || str_contains($value, "\n")) {
                return null;
            }
        }

        if (! is_int($artifact['size'] ?? null) || $artifact['size'] < 0) {
            return null;
        }

        if (! in_array($package['type'], PackageExpectation::TYPES, true)
            || ! preg_match('/^[0-9a-f]{64}$/i', $artifact['sha256'])
            || ! preg_match(self::KEY_ID_PATTERN, $signer['key_id'])) {
            return null;
        }

        return $doc;
    }

    /**
     * 서명 파일 배열을 만듭니다 (서명 전 — `signature` 칸 없음).
     *
     * @param  string  $type  패키지 종류
     * @param  string  $identifier  패키지 식별자
     * @param  string  $version  패키지 버전
     * @param  string  $filename  ZIP 파일 이름
     * @param  int  $size  ZIP 크기 (바이트)
     * @param  string  $sha256  ZIP SHA-256 (hex)
     * @param  string  $keyId  서명 키 식별자
     * @param  string  $label  서명자 이름
     * @param  string  $signedAt  서명 시각 (UTC ISO 8601, 초 단위 `Z`)
     * @return array<string, mixed> 서명 전 문서
     */
    public static function buildDocument(
        string $type,
        string $identifier,
        string $version,
        string $filename,
        int $size,
        string $sha256,
        string $keyId,
        string $label,
        string $signedAt,
    ): array {
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'algorithm' => self::ALGORITHM,
            'package' => ['type' => $type, 'identifier' => $identifier, 'version' => $version],
            'artifact' => ['filename' => $filename, 'size' => $size, 'sha256' => strtolower($sha256)],
            'signer' => ['key_id' => $keyId, 'label' => $label],
            'signed_at' => $signedAt,
        ];
    }

    /**
     * 서명 파일을 사람이 읽는 JSON 으로 씁니다 (PHP·Node 출력이 같도록 2칸 들여쓰기, 끝 줄바꿈 1개).
     *
     * @param  array<string, mixed>  $document  서명 파일 배열
     * @return string JSON 문자열
     */
    public static function encode(array $document): string
    {
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // PHP 는 4칸 들여쓰기 — Node `JSON.stringify(doc, null, 2)` 와 같은 2칸으로 맞춘다
        $json = preg_replace_callback('/^( +)/m', fn (array $m) => str_repeat(' ', intdiv(strlen($m[1]), 2)), (string) $json);

        return $json."\n";
    }
}
