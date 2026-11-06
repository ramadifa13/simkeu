<?php

namespace App\Support;

final class ImageLimits
{
    public const STORED_MAX_BYTES = 1024 * 1024;

    /** Foto kamera HP/tablet flagship boleh besar. Server yang menyimpan hasil maksimal 1 MB. */
    public const SOURCE_MAX_BYTES = 40 * 1024 * 1024;

    public const SOURCE_MAX_KILOBYTES = 40960;
}
