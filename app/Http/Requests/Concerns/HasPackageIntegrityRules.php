<?php

namespace App\Http\Requests\Concerns;

use App\Extension\Integrity\PackageIntegrityOptions;
use App\Extension\Integrity\PackageSignatureFormat;

/**
 * 설치·업데이트 요청의 패키지 무결성 입력 규칙 (7.2.0).
 *
 * - `allow_unsigned`: 409 미서명 경고 창의 「미서명으로 계속」이 같은 요청을 다시 보낼 때 1.
 * - `signature`: ZIP 업로드 옆 서명 파일(`.sig.json`, 64KB 이하) — 업로드 요청에만.
 * - `signature_url`: 원격 서명 파일 주소 — 언어팩 URL 설치에만(자동 추측 요청은 하지 않는다).
 * - `integrity_aware`: 무결성을 아는 관리자 화면이 늘 싣는 표식(1). 없으면 「미서명으로 계속」을 그릴 수 없는 옛 화면
 *   (7.1.x 템플릿 복사본)으로 보고 `PackageIntegrityOptions::legacyClient` 를 켠다 — 판정은 `UnsignedPackagePolicy` 가 한다.
 *   `allow_unsigned` 는 첫 요청에서 비어 multipart 에서 빠질 수 있어 옛 화면 판별에 쓰지 않는다.
 *
 * @since 7.2.0
 */
trait HasPackageIntegrityRules
{
    /**
     * 무결성을 아는 화면의 표식 칸 이름을 돌려줍니다.
     *
     * @return string 칸 이름
     */
    public static function integrityAwareField(): string
    {
        return 'integrity_aware';
    }

    /**
     * 무결성 입력 규칙을 돌려줍니다.
     *
     * @param  bool  $signatureFile  서명 파일 업로드 칸을 받을지
     * @param  bool  $signatureUrl  서명 파일 주소 칸을 받을지
     * @return array<string, array<int, string>> 규칙
     */
    protected function packageIntegrityRules(bool $signatureFile = false, bool $signatureUrl = false): array
    {
        $rules = [
            'allow_unsigned' => ['nullable', 'boolean'],
            self::integrityAwareField() => ['nullable', 'boolean'],
        ];

        if ($signatureFile) {
            $rules['signature'] = ['nullable', 'file', 'max:'.intdiv(PackageSignatureFormat::MAX_FILE_BYTES, 1024)];
        }

        if ($signatureUrl) {
            $rules['signature_url'] = ['nullable', 'string', 'url', 'max:500'];
        }

        return $rules;
    }

    /**
     * 무결성 입력 메시지를 돌려줍니다.
     *
     * @return array<string, string> 메시지
     */
    protected function packageIntegrityMessages(): array
    {
        return [
            'allow_unsigned.boolean' => __('extension_integrity.validation.allow_unsigned_boolean'),
            self::integrityAwareField().'.boolean' => __('extension_integrity.validation.integrity_aware_boolean'),
            'signature.file' => __('extension_integrity.validation.signature_file'),
            'signature.max' => __('extension_integrity.validation.signature_max'),
            'signature_url.url' => __('extension_integrity.validation.signature_url_invalid'),
        ];
    }

    /**
     * 무결성 입력의 칸 이름 라벨을 돌려줍니다.
     *
     * 트레잇 키는 요청 파일에 글자로 적히지 않아 라벨 감사 룰이 보지 못한다 — 규칙을 주는 이 트레잇이 라벨도 준다.
     * 요청의 `attributes()` 는 자기 키 배열에 이것을 합친다(docs/backend/validation.md 「트레이트로 규칙을 나눠 쓸 때」).
     *
     * @return array<string, string> 라벨
     */
    protected function packageIntegrityAttributes(): array
    {
        return [
            'allow_unsigned' => __('extension_integrity.attributes.allow_unsigned'),
            self::integrityAwareField() => __('extension_integrity.attributes.integrity_aware'),
            'signature' => __('extension_integrity.attributes.signature'),
            'signature_url' => __('extension_integrity.attributes.signature_url'),
        ];
    }

    /**
     * 검증된 입력으로 무결성 선택을 만듭니다.
     *
     * @return PackageIntegrityOptions 운영자 선택 (서명 파일은 업로드 임시 경로, 표식이 없으면 옛 화면)
     */
    public function packageIntegrityOptions(): PackageIntegrityOptions
    {
        $signature = $this->file('signature');

        return new PackageIntegrityOptions(
            allowUnsigned: $this->boolean('allow_unsigned'),
            signaturePath: $signature !== null ? $signature->getRealPath() : null,
            legacyClient: ! $this->boolean(self::integrityAwareField()),
        );
    }
}
