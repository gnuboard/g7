<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\CustomAssetOperationException;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\Extension\ReadExtensionCustomAssetRequest;
use App\Http\Requests\Admin\Extension\SaveExtensionCustomAssetRequest;
use App\Http\Requests\Admin\Extension\UploadExtensionCustomAssetRequest;
use App\Rules\AllowedTemplateFileType;
use App\Services\CustomAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * 확장 사용자 추가 에셋(`custom/`) 어드민 컨트롤러
 *
 * 운영자가 자기 CSS·JS·폰트·이미지를 화면에서 직접 넣고 고칠 수 있게 한다. 레이아웃
 * 편집기의 [커스텀 자산] 모달이 본 API 를 호출한다.
 *
 * 모듈·플러그인·템플릿을 **한 엔드포인트**가 다룬다. 타입별로 나누면 같은 검증·문서·테스트가
 * 세 벌로 갈리고, 그중 하나만 약해지면 그 경로가 조용한 우회로가 된다. 기존
 * `extensions/{type}/{identifier}` 선례(확장 복구 API)와 같은 형태다.
 *
 * 권한은 라우트의 permission 미들웨어(`core.extensions.custom_assets.manage`)가 담당한다.
 * 레이아웃 편집 권한과 **분리**된 이유: 여기서 올린 스크립트는 그 레이아웃 한 장이 아니라
 * 사이트 전 화면에서 실행되므로, 레이아웃을 고칠 수 있다는 것이 곧 그 권한이 될 수 없다.
 */
class AdminExtensionCustomAssetController extends AdminBaseController
{
    /**
     * 라우트 파라미터(단수) → 해석기 어휘(복수)
     *
     * 라우트는 기존 확장 공통 API 와 같은 단수형을 쓰고(`module|plugin|template`),
     * 해석기·서빙은 디렉토리 이름과 같은 복수형을 쓴다. 변환을 한 곳에 모아 둔다 —
     * 흩어지면 한쪽 표기만 고쳐 놓고 다른 쪽에서 조용히 빈 목록이 된다.
     */
    private const TYPE_MAP = [
        'module' => 'modules',
        'plugin' => 'plugins',
        'template' => 'templates',
    ];

    public function __construct(
        private CustomAssetService $service,
    ) {
        parent::__construct();
    }

    /**
     * 사용자 추가 에셋 목록 조회.
     *
     * @param  string  $type  확장 타입 (`module` | `plugin` | `template`)
     * @param  string  $identifier  확장 식별자
     * @return JsonResponse 파일 목록 + 편집기 메타(허용 확장자·크기 상한)
     */
    public function index(string $type, string $identifier): JsonResponse
    {
        try {
            $files = $this->service->list($this->resolveType($type), $identifier);
        } catch (CustomAssetOperationException $e) {
            return $this->error($e->errorKey, 422, null, $e->params);
        } catch (\Throwable $e) {
            Log::error('사용자 추가 에셋 목록 조회 실패', [
                'type' => $type,
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return $this->error('custom_assets.errors.read_failed', 500, $e, ['path' => 'custom/']);
        }

        return $this->success('custom_assets.messages.listed', [
            'files' => $files,
            'editable_extensions' => CustomAssetService::EDITABLE_EXTENSIONS,
            'uploadable_extensions' => AllowedTemplateFileType::getAllowedExtensions(),
            'max_text_bytes' => CustomAssetService::MAX_TEXT_BYTES,
            'max_upload_bytes' => CustomAssetService::MAX_UPLOAD_BYTES,
        ]);
    }

    /**
     * 텍스트 파일 본문 조회.
     *
     * @param  ReadExtensionCustomAssetRequest  $request  검증된 요청 (`path`)
     * @param  string  $type  확장 타입
     * @param  string  $identifier  확장 식별자
     * @return JsonResponse 본문 응답
     */
    public function show(ReadExtensionCustomAssetRequest $request, string $type, string $identifier): JsonResponse
    {
        $path = (string) $request->validated('path');

        try {
            $file = $this->service->read($this->resolveType($type), $identifier, $path);
        } catch (CustomAssetOperationException $e) {
            return $this->error($e->errorKey, 422, null, $e->params);
        } catch (\Throwable $e) {
            Log::error('사용자 추가 에셋 본문 조회 실패', [
                'type' => $type,
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return $this->error('custom_assets.errors.read_failed', 500, $e, ['path' => $path]);
        }

        return $this->success('custom_assets.messages.listed', $file);
    }

    /**
     * 텍스트 파일 본문 저장 (없으면 생성).
     *
     * @param  SaveExtensionCustomAssetRequest  $request  검증된 요청 (`path`, `content`)
     * @param  string  $type  확장 타입
     * @param  string  $identifier  확장 식별자
     * @return JsonResponse 저장 결과
     */
    public function store(SaveExtensionCustomAssetRequest $request, string $type, string $identifier): JsonResponse
    {
        $path = (string) $request->validated('path');

        try {
            $saved = $this->service->save(
                $this->resolveType($type),
                $identifier,
                $path,
                (string) $request->validated('content'),
            );
        } catch (CustomAssetOperationException $e) {
            return $this->error($e->errorKey, 422, null, $e->params);
        } catch (\Throwable $e) {
            Log::error('사용자 추가 에셋 저장 실패', [
                'type' => $type,
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return $this->error('custom_assets.errors.write_failed', 500, $e, ['path' => $path]);
        }

        return $this->success('custom_assets.messages.saved', $saved);
    }

    /**
     * 파일 업로드 (폰트·이미지 등 바이너리 포함).
     *
     * @param  UploadExtensionCustomAssetRequest  $request  검증된 요청 (`file`, `directory`)
     * @param  string  $type  확장 타입
     * @param  string  $identifier  확장 식별자
     * @return JsonResponse 업로드 결과
     */
    public function upload(UploadExtensionCustomAssetRequest $request, string $type, string $identifier): JsonResponse
    {
        $directory = $request->validated('directory');

        try {
            $uploaded = $this->service->upload(
                $this->resolveType($type),
                $identifier,
                $request->file('file'),
                is_string($directory) ? $directory : null,
            );
        } catch (CustomAssetOperationException $e) {
            return $this->error($e->errorKey, 422, null, $e->params);
        } catch (\Throwable $e) {
            Log::error('사용자 추가 에셋 업로드 실패', [
                'type' => $type,
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return $this->error('custom_assets.errors.write_failed', 500, $e, ['path' => (string) $directory]);
        }

        return $this->success('custom_assets.messages.uploaded', $uploaded);
    }

    /**
     * 파일 삭제.
     *
     * @param  ReadExtensionCustomAssetRequest  $request  검증된 요청 (`path`)
     * @param  string  $type  확장 타입
     * @param  string  $identifier  확장 식별자
     * @return JsonResponse 삭제 결과
     */
    public function destroy(ReadExtensionCustomAssetRequest $request, string $type, string $identifier): JsonResponse
    {
        $path = (string) $request->validated('path');

        try {
            $this->service->delete($this->resolveType($type), $identifier, $path);
        } catch (CustomAssetOperationException $e) {
            return $this->error($e->errorKey, 422, null, $e->params);
        } catch (\Throwable $e) {
            Log::error('사용자 추가 에셋 삭제 실패', [
                'type' => $type,
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return $this->error('custom_assets.errors.delete_failed', 500, $e, ['path' => $path]);
        }

        return $this->success('custom_assets.messages.deleted', ['path' => $path]);
    }

    /**
     * 라우트 타입 파라미터를 해석기 어휘로 바꿉니다.
     *
     * 라우트 정규식이 이미 세 값으로 제한하지만, 매핑에 없으면 그대로 넘긴다 —
     * 해석기가 알 수 없는 타입을 무효로 판정해 422 를 만든다(빈 목록으로 조용히
     * 통과시키지 않는다).
     *
     * @param  string  $type  라우트 파라미터
     * @return string 해석기 어휘
     */
    private function resolveType(string $type): string
    {
        return self::TYPE_MAP[$type] ?? $type;
    }
}
