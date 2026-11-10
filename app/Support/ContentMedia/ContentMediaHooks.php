<?php

namespace App\Support\ContentMedia;

/**
 * 본문 미디어 공용 훅 이름 (#887)
 *
 * 본문 미디어를 다루는 모든 확장(모듈·플러그인)의 업로드·영구 삭제에서 코어 골격(`AbstractContentMediaService`)이 발화한다.
 * 리스너는 두 번째 인자 `ContentMediaContext` 로 어느 확장·문서의 미디어인지 안다. 확장별 첨부 훅(`{확장}.attachment.*`)은
 * 본문 미디어에 발화하지 않는다 — 두 번 발화하면 기존 첨부 리스너가 본문 미디어를 첨부로 오인한다.
 *
 * | 훅 | 종류 | 인자 | 발화 시점 |
 * | --- | --- | --- | --- |
 * | `core.content_media.before_upload` | action | `UploadedFile $file, ContentMediaContext $ctx` | 문서당 업로드 상한 검사 뒤, 저장 전 |
 * | `core.content_media.filter_upload_file` | filter | `UploadedFile $file, ContentMediaContext $ctx` → `UploadedFile` | before_upload 직후. 돌려준 파일은 내용 MIME·종류·정책 판정을 다시 거친다 |
 * | `core.content_media.after_upload` | action | `Model $row, ContentMediaContext $ctx` | 행을 만든 뒤 |
 * | `core.content_media.before_delete` | action | `Model $row, ContentMediaContext $ctx` | 행·파일을 **영구** 삭제하기 직전에만(소프트 삭제 때는 발화하지 않음) |
 *
 * 리스너 등록·시험은 이 상수를 쓴다. 골격의 발화 지점은 공개 훅 목록 생성기가 이름을 읽을 수 있게 같은 값을 문자열로 적는다
 * (골격 시험이 이 상수로 등록한 리스너가 불리는지로 둘이 같음을 잠근다).
 *
 * @since 7.1.5
 */
final class ContentMediaHooks
{
    /** 저장 전 (action) */
    public const BEFORE_UPLOAD = 'core.content_media.before_upload';

    /** 저장할 파일 교체 (filter) */
    public const FILTER_UPLOAD_FILE = 'core.content_media.filter_upload_file';

    /** 행 생성 뒤 (action) */
    public const AFTER_UPLOAD = 'core.content_media.after_upload';

    /** 영구 삭제 직전 (action) */
    public const BEFORE_DELETE = 'core.content_media.before_delete';
}
