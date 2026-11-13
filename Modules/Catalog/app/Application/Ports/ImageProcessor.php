<?php

namespace Modules\Catalog\Application\Ports;

use SplFileInfo;

interface ImageProcessor
{
    /** Valida contenido local y devuelve únicamente WebP optimizado, sin originales. */
    public function process(SplFileInfo $file): string;
}
