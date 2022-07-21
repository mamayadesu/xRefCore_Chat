<?php

namespace Program;

use Chat\AsyncTaskUserParams;
use Chat\User;
use CliForms\MenuBox\Events\ItemClickedEvent;
use CliForms\MenuBox\MenuBoxItem;
use HttpServer\Request;
use HttpServer\Response;
use Scheduler\AsyncTask;

class Router
{
    private Main $main;

    public function __construct(Main $main)
    {
        $this->main = $main;
    }

    /**
     * #################
     * HERE WE ARE HANDLING DYNAMIC PAGES LIKE `mypage.php`.
     *
     * YOU DON'T NEED TO CREATE .php FILES IN YOUR DOCUMENT ROOT,
     * JUST ADD URI OF YOUR PAGE TO "SWITCH" BELOW
     * #################
     */
    public function HandleRequest(Request $request, Response $response) : bool
    {
        $response->ClientNonBlockMode = true;
        switch ($request->PathInfo)
        {
            case "/checkauth":
                $this->CheckAuth($request, $response);
                break;

            case "/join":
                $this->Join($request, $response);
                break;

            case "/load":
                $this->Load($request, $response);
                break;

            case "/send":
                $this->Send($request, $response);
                break;

            case "/logout":
                $this->Logout($request, $response);
                break;

            default:
                return false;
        }
        return true;
    }

    /**
     * http://.../checkauth
     */
    public function CheckAuth(Request $request, Response $response) : void
    {
        if (!isset($request->Cookie["username"]) || !isset($request->Cookie["access_token"]))
        {
            $response->End("NO");
            return;
        }

        /** @var User|null $user */$user = $this->main->chat->GetUser($request->Cookie["username"]);
        if ($user === null)
        {
            $response->End("NO");
            return;
        }
        switch ($user->IsAuthorized($request, $response, true))
        {
            case 0:
                $response->End("NO");
                break;

            case 1:
                $response->End("YES");
                break;

            case 2:
                $response->End("ANOTHERTAB");
        }
    }

    /**
     * http://.../join
     */
    public function Join(Request $request, Response $response) : void
    {
        if (!isset($request->Post["username"]) || strlen($request->Post["username"]) == 0)
        {
            $response->End("WRITEYOURUSERNAME");
            return;
        }
        $len = strlen($request->Post["username"]);
        $minUsernameLength = 3;
        $maxUsernameLength = 14;

        if ($len < $minUsernameLength)
        {
            $response->End("TOOSHORT");
            return;
        }
        else if ($len > $maxUsernameLength)
        {
            $response->End("TOOLONG");
            return;
        }

        $allUsers = $this->main->chat->GetAllUsers();
        if (count($allUsers) >= $this->main->config->MaxUsers && $this->main->config->MaxUsers != -1)
        {
            $response->End("CHATISFULL");
            return;
        }

        $usersWithSameIp = 0;

        foreach ($allUsers as $user)
        {
            if ($user->IpAddress == $request->RemoteAddress)
            {
                $usersWithSameIp++;
            }
        }

        if ($usersWithSameIp >= $this->main->config->MaxUsersWithSameIp)
        {
            $response->End("TOOMANYUSERWITHTHISIP");
            return;
        }

        if ($this->main->chat->GetUser($request->Post["username"]) !== null)
        {
            $response->End("ALREADYUSING");
            return;
        }

        $user = new User();
        $user->IpAddress = $request->RemoteAddress;
        $user->AccessToken = md5(md5(rand(1, 1000) . " " . microtime(true)));
        $user->Username = $request->Post["username"];
        $user->LastActive = time();
        $user->MenuBoxItem = new MenuBoxItem($user->Username, "", function(ItemClickedEvent $event) : void
        {
            $this->main->mainMenu->KickUser($event);
        });
        $this->main->mainMenu->UsersMenuBox->AddItem($user->MenuBoxItem);

        $this->main->chat->PublishEvent(array(
            "type" => "connected",
            "username" => $user->Username,
            "time" => time(),
            "date" => date("d.m.Y H:i:s", time())
        ));

        $this->main->chat->AddUser($user);

        $response->SetCookie("username", $user->Username, time() + 60 * 60 * 24 * 30);
        $response->SetCookie("access_token", $user->AccessToken, time() + 60 * 60 * 24 * 30);

        $response->End("OK");
    }

    /**
     * http://.../load
     */
    public function Load(Request $request, Response $response) : void
    {
        $unauthorized = base64_encode(json_encode(array(
            "type" => "kicked",
            "username" => $request->Cookie["username"] ?? "",
            "time" => time(),
            "reason" => "Unauthorized",
            "date" => date("d.m.Y H:i:s", time())
        )));

        $anothertab = base64_encode(json_encode(array(
            "type" => "anothertab",
            "username" => $request->Cookie["username"] ?? "",
            "time" => time(),
            "date" => date("d.m.Y H:i:s", time())
        )));
        if (!isset($request->Cookie["username"]) || !isset($request->Cookie["access_token"]))
        {
            $response->End($unauthorized);
            return;
        }

        /** @var User|null $user */$user = $this->main->chat->GetUser($request->Cookie["username"]);
        if ($user === null)
        {
            $response->End($unauthorized);
            return;
        }

        $isAuthorized = $user->IsAuthorized($request, $response, true);
        if ($isAuthorized === 0)
        {
            $response->End($unauthorized);
            return;
        }
        if ($isAuthorized === 2)
        {
            $response->End($anothertab);
            return;
        }

        $firstLoad = isset($request->Post["firstload"]) && $request->Post["firstload"] === "yes";

        if ($firstLoad)
        {
            $result = "";
            $history = $this->main->chat->GetHistory();
            $user->LastActive = time();
            foreach ($history as $row)
            {
                $result .= base64_encode(json_encode($row)) . "\n";
            }

            $response->End($result);
            return;
        }

        // The user could lose the connection, and new data could arrive.
        // Checking if the user missed the new data.
        if (count($user->UnreadEvents) > 0)
        {
            $result = "";
            $user->LastActive = time();
            foreach ($user->UnreadEvents as $row)
            {
                $result .= base64_encode(json_encode($row)) . "\n";
            }
            $user->UnreadEvents = array();
            $response->End($result);
            return;
        }

        // We do not send a response to the user immediately.
        // We wait 25 seconds for new data to appear.
        // Otherwise, terminate the connection
        $params = new AsyncTaskUserParams();
        $params->User = $user;
        $user->Request = $request;
        $user->Response = $response;
        $user->LastActive = time();

        $user->TaskCloser = new AsyncTask($this, 25000, true, function(AsyncTask $task, AsyncTaskUserParams $params) : void
        {
            $user = $params->User;
            $user->Response->End("");
            $user->Request = null;
            $user->Response = null;
            $user->TaskCloser = null;

        }, $params);
    }

    public function Send(Request $request, Response $response) : void
    {
        if (!isset($request->Cookie["username"]) || !isset($request->Cookie["access_token"]))
        {
            $response->End("NOTAUTHORIZED");
            return;
        }

        /** @var User|null $user */$user = $this->main->chat->GetUser($request->Cookie["username"]);
        if ($user === null)
        {
            $response->End("NOTAUTHORIZED");
            return;
        }

        $isAuthorized = $user->IsAuthorized($request, $response, false);
        if ($isAuthorized === 0)
        {
            $response->End("NOTAUTHORIZED");
            return;
        }
        if ($isAuthorized === 2)
        {
            $response->End("ANOTHERTAB");
            return;
        }

        if (!isset($request->Post["message"]) || strlen($request->Post["message"]) == 0)
        {
            $response->End("EMPTY");
            return;
        }

        $message = $request->Post["message"];
        $message = htmlspecialchars($message);
        $message = str_replace("\n", "<br>", $message);
        $message = str_replace("\r", "", $message);

        $this->main->chat->PublishEvent(array(
            "type" => "message",
            "message" => $message,
            "sender" => $user->Username,
            "time" => time(),
            "date" => date("d.m.Y H:i:s", time())
        ));
        $response->End("OK");

        if ($message == "*#*STOP*#*")
        {
            $this->main->chat->Shutdown();
        }
    }

    public function Logout(Request $request, Response $response) : void
    {
        if (!isset($request->Cookie["username"]) || !isset($request->Cookie["access_token"]))
        {
            $response->End("");
            return;
        }

        /** @var User|null $user */$user = $this->main->chat->GetUser($request->Cookie["username"]);
        if ($user === null)
        {
            $response->End("");
            return;
        }

        $isAuthorized = $user->IsAuthorized($request, $response, false);
        if ($isAuthorized !== 1)
        {
            $response->End("");
            return;
        }

        // removing user from chat
        $this->main->chat->Kick($user->Username, "", true);

        $response->End("OK");
    }
}