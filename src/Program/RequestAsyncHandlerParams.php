<?php

declare(ticks=1);

namespace Program;

use HttpServer\Response;
use Scheduler\IAsyncTaskParameters;
use TypeError;

class RequestAsyncHandlerParams implements IAsyncTaskParameters
{
    public Response $Response;

    /**
     * @var resource
     */
    public $File;

    public function __construct($f, Response $response)
    {
        $t = gettype($f);
        if ($t != "resource")
        {
            throw new TypeError("Argument 1 passed to Program\RequestAsyncHandlerParams::__construct() must be an resource, " . $t . " given");
        }

        $this->File = $f;
        $this->Response = $response;
    }

    public function GetNext() : string
    {
        /**
         * Читаем следующие 8KB данных файла
         */
        return fread($this->File, 8 * 1024);
    }
}
