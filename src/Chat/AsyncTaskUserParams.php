<?php

namespace Chat;

use HttpServer\Request;
use HttpServer\Response;
use Scheduler\IAsyncTaskParameters;

class AsyncTaskUserParams implements IAsyncTaskParameters
{
    public User $User;
    public Request $Request;
    public Response $Response;
}