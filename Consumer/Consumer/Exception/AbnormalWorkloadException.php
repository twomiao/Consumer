<?php
namespace Consumer\Consumer\Exception;

use Consumer\Consumer\ExitedCode;

class AbnormalWorkloadException extends \RuntimeException
{
    protected $code =  ExitedCode::TASKS_TOTAL;


}