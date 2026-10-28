<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** People's photos: stored on the public disk, one file per person. A replaced file is removed only after the row points at the new one, so a failed save never leaves a row with no file. */
class Photos
{
    public static function store(UploadedFile $file, string $dir): string
    {
        return $file->store("photos/$dir", 'public');
    }

    public static function delete(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }

    public static function url(?string $path): ?string
    {
        return $path ? url('storage/'.$path) : null;
    }
}
