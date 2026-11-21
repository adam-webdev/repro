<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Foto bukti. Diunggah langsung saat dipilih (sudah diperkecil di HP), lalu ditautkan
 * ke mesin / pengecekan saat form disimpan.
 */
class AttachmentController extends Controller
{
    public function store(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:jpg,jpeg,png,webp|max:8192'], [
            'file.mimes' => 'Foto harus JPG, PNG, atau WEBP.',
            'file.max' => 'Ukuran foto maksimal 8 MB.',
        ]);
        $file = $request->file('file');
        $path = $file->store('evidence/'.now()->format('Y/m'), 'local');

        $a = Attachment::create([
            'user_id' => $request->user()->id,
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 250),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);

        return response()->json(['id' => $a->id, 'url' => $a->url()]);
    }

    public function show(Request $request, Attachment $attachment)
    {
        $user = $request->user();
        if ($user->role === 'karu' && $attachment->user_id !== $user->id) {
            $owner = $attachment->attachable?->form?->user_id;
            abort_unless($owner === $user->id, 403);
        }
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->response($attachment->path, null, ['Cache-Control' => 'private, max-age=604800']);
    }
}
