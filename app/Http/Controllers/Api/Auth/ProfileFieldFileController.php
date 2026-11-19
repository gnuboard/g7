<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\Base\AuthBaseController;
use App\Http\Requests\User\DeleteMemberFieldFileRequest;
use App\Http\Requests\User\UploadMemberFieldFileRequest;
use App\Services\MemberFieldFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * 본인의 파일·이미지 항목에 파일을 올리고 지우는 컨트롤러 (내 정보 수정 화면).
 *
 * 파일 항목은 폼 저장으로 오가지 않고 이 API 로 바로 올리고 지운다. 본인 수정 범위의 항목만 다룬다.
 *
 * @since 7.2.0
 */
class ProfileFieldFileController extends AuthBaseController
{
    /**
     * @param  MemberFieldFileService  $service  회원 파일 항목 Service
     */
    public function __construct(private MemberFieldFileService $service)
    {
        parent::__construct();
    }

    /**
     * 본인의 파일 항목에 파일 한 개를 올립니다.
     *
     * @param  UploadMemberFieldFileRequest  $request  검증된 요청
     * @param  string  $key  항목 키
     * @return JsonResponse 올린 파일과 그 항목의 전체 파일 목록
     */
    public function store(UploadMemberFieldFileRequest $request, string $key): JsonResponse
    {
        $user = $this->getCurrentUser();
        if (! $user) {
            return $this->unauthorized('auth.unauthenticated');
        }

        $definition = $request->targetField();
        if ($definition === null || $definition->key !== $key) {
            return $this->notFound('fields.file.field_not_found');
        }

        try {
            return $this->success('fields.file.uploaded', $this->service->upload($user, $definition, $request->file('file')), 201);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }
    }

    /**
     * 본인의 파일 항목에서 파일 한 개를 지웁니다.
     *
     * @param  DeleteMemberFieldFileRequest  $request  검증된 요청
     * @param  string  $key  항목 키
     * @return JsonResponse 남은 파일 목록
     */
    public function destroy(DeleteMemberFieldFileRequest $request, string $key): JsonResponse
    {
        $user = $this->getCurrentUser();
        if (! $user) {
            return $this->unauthorized('auth.unauthenticated');
        }

        $definition = $request->targetField();
        if ($definition === null || $definition->key !== $key) {
            return $this->notFound('fields.file.field_not_found');
        }

        $files = $this->service->delete($user, $definition, (int) $request->validated('attachment_id'));
        if ($files === null) {
            return $this->notFound('fields.file.not_found');
        }

        return $this->success('fields.file.deleted', ['files' => $files]);
    }
}
