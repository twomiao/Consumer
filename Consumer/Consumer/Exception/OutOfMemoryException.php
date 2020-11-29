<?php
namespace Consumer\Consumer;

class OutOfMemoryException extends \RuntimeException
{
    protected $code =  ExitedCode::RELEASE_MEMORY;
}
