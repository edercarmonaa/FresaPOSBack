<?php

namespace Tests\Fixtures\Images;

use Illuminate\Filesystem\FilesystemAdapter;

class FailingFilesystem extends FilesystemAdapter
{
    public bool $failWrites = false;

    public bool $failDeletes = false;

    public function put($path, $contents, $options = [])
    {
        $result = parent::put($path, $contents, $options);

        return $this->failWrites ? false : $result;
    }

    public function delete($paths)
    {
        return $this->failDeletes ? false : parent::delete($paths);
    }
}
