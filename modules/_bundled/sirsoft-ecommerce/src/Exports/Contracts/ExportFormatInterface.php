<?php

namespace Modules\Sirsoft\Ecommerce\Exports\Contracts;

/**
 * 내보내기 파일 형식
 *
 * CSV 가 첫 구현체다. 확장은 `sirsoft-ecommerce.export.registered_formats` 필터로 형식을 더한다
 * (예: XLSX). 형식은 「파일에 어떻게 쓰는가」만 책임지고, 무엇을 쓸지(열·값)는 엔진이 정한다.
 */
interface ExportFormatInterface
{
    /**
     * 형식 키 (요청 본문·작업 행에 저장되는 값)
     *
     * @return string 형식 키 (예: csv)
     */
    public function key(): string;

    /**
     * 화면에 보일 형식 이름의 다국어 키
     *
     * @return string 다국어 키
     */
    public function labelKey(): string;

    /**
     * 파일 확장자 (점 없이)
     *
     * @return string 확장자
     */
    public function extension(): string;

    /**
     * 내려받기 응답의 MIME 형식
     *
     * @return string MIME 형식
     */
    public function mimeType(): string;

    /**
     * 작성기 버전 — 같은 형식이라도 규칙이 바뀌면 올려 어떤 규칙으로 만든 파일인지 남긴다
     *
     * @return string 버전
     */
    public function version(): string;

    /**
     * 로컬 임시 파일에 쓰는 작성기를 엽니다.
     *
     * @param  string  $absolutePath  쓸 파일의 절대 경로
     * @return ExportWriterInterface 작성기
     */
    public function createWriter(string $absolutePath): ExportWriterInterface;
}
