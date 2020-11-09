<?php

namespace Consumer;

class OutOfMemoryException extends \RuntimeException
{
    protected $code =  ExitedCode::RELEASE_MEMORY;
}
