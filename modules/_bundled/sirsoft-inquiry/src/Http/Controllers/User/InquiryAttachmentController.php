<?php

namespace Modules\Sirsoft\Inquiry\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Sirsoft\Inquiry\Http\Requests\UploadInquiryAttachmentRequest;
use Modules\Sirsoft\Inquiry\Http\Resources\InquiryAttachmentResource;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Models\InquiryAttachment;
use Modules\Sirsoft\Inquiry\Services\InquiryAttachmentStorage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InquiryAttachmentController extends Controller
{
    public function __construct(
        private readonly InquiryAttachmentStorage $storage,
    ) {}

    public function uploadInquiryBody(UploadInquiryAttachmentRequest $request, Inquiry $inquiry)
    {
        try {
            $att = $this->storage->store(
                $inquiry,
                $request->user()->id,
                $request->file('file'),
                context: 'inquiry',
            );
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return (new InquiryAttachmentResource($att))
            ->response()
            ->setStatusCode(201);
    }

    public function uploadMessage(UploadInquiryAttachmentRequest $request, Inquiry $inquiry)
    {
        try {
            $att = $this->storage->store(
                $inquiry,
                $request->user()->id,
                $request->file('file'),
                context: 'message',
            );
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return (new InquiryAttachmentResource($att))
            ->response()
            ->setStatusCode(201);
    }

    public function download(Request $request, InquiryAttachment $attachment): StreamedResponse
    {
        $inquiry = $attachment->inquiry;
        $this->authorize('viewAttachment', $inquiry);

        return \Illuminate\Support\Facades\Storage::disk($attachment->disk)->download(
            $attachment->path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime]
        );
    }
}
