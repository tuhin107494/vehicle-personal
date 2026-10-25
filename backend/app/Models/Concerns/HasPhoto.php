<?php

namespace App\Models\Concerns;

use App\Support\Photos;

/** A `photo` column (a path on the public disk) shown to the API as `photo_url`. */
trait HasPhoto
{
    public function initializeHasPhoto(): void
    {
        $this->append('photo_url');
        $this->makeHidden('photo');
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return Photos::url($this->photo);
    }
}
