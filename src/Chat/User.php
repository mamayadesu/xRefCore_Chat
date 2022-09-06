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
    public int $LastActive, $LastType;

    /**
     * @var array<AsyncTask>
     */
    public array $TaskCloser = [];

    /**
     * @var array<Request>
     */
    public array $Request = [];

    /**
     * @var array<Response>
     */
    public array $Response = [];
    public array $UnreadEvents = array();
    public ?MenuBoxItem $MenuBoxItem;

    /**
     * 0 - not authorized
     * 1 - authorized
     * 2 - logged in from another tab
     */
    public function IsAuthorized(Request $request, Response $response) : bool
    {
        if (!isset($request->Cookie["username"]) || !isset($request->Cookie["access_token"]) || strtolower($request->Cookie["username"]) !== strtolower($this->Username))
            return false;

        if (
            $this->AccessToken !== $request->Cookie["access_token"] ||
            $this->IpAddress !== $request->RemoteAddress
        )
        {
            return false;
        }

        return true;
    }
}