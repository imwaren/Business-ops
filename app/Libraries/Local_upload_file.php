<?php

namespace App\Libraries;

class Local_upload_file
{
    protected $path;
    protected $name;
    protected $mime;

    public function __construct(string $path, string $name, string $mime = 'application/pdf')
    {
        $this->path = $path;
        $this->name = $name;
        $this->mime = $mime;
    }

    public function getTempName(): string
    {
        return $this->path;
    }

    public function getClientMimeType(): string
    {
        return $this->mime;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
