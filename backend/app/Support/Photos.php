<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** People's photos: stored on the public disk, one file per person, the old one removed when replaced. */
class Photos
{
    public static function store(UploadedFile $file, string $dir, ?string $old = null): string
    {
        self::delete($old);

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
