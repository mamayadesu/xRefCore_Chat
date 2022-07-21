<?php

declare(ticks=1);

namespace Chat;

use CliForms\MenuBox\MenuBoxItem;
use HttpServer\Request;
use HttpServer\Response;
use Scheduler\AsyncTask;

class User
{
    public string $Username, $AccessToken, $IpAddress;
    public int $LastActive;
    public ?AsyncTask $TaskCloser = null;
    public ?Request $Request = null;
    public ?Response $Response = null;
    public array $UnreadEvents = array();
    public ?MenuBoxItem $MenuBoxItem;

    /**
     * 0 - not authorized
     * 1 - authorized
     * 2 - logged in from another tab
     */
    public function IsAuthorized(Request $request, Response $response, bool $checkAnotherTab) : int
    {
        if (!isset($request->Cookie["username"]) || !isset($request->Cookie["access_token"]) || strtolower($request->Cookie["username"]) !== strtolower($this->Username))
            return 0;

        if (
            $this->AccessToken !== $request->Cookie["access_token"] ||
            $this->IpAddress !== $request->RemoteAddress
        )
        {
            return 0;
        }

        if ($checkAnotherTab)
        {
            if ($this->Request !== null && $this->Request !== $request)
                return 2;
        }

        return 1;
    }
}