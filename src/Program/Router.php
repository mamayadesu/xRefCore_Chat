<?php

declare(ticks=1);

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
     * ЗДЕСЬ МЫ ОБРАБАТЫВАЕМ ДИНАМИЧЕСКИЕ СТРАНИЦЫ ПО ТИПУ `mypage.php`.
     *
     * НЕ НУЖНО СОЗДАВАТЬ .php ФАЙЛЫ В КОРНЕ САЙТА,
     * ПРОСТО ДОБАВЬТЕ URI СТРАНИЦЫ В "SWITCH" НИЖЕ
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

            case "/typing":
                $this->Typing($request, $response);
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
        $response->End($user->IsAuthorized($request, $response) ? "YES" : "NO");
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
        $user->LastType = 0;
        $user->MenuBoxItem = new MenuBoxItem($user->Username, $user->IpAddress, function(ItemClickedEvent $event) : void
        {
            $this->main->mainMenu->KickUser($event);
        });
        $this->main->mainMenu->UsersMenuBox->AddItem($user->MenuBoxItem);

        $this->main->chat->AddUser($user);
        $this->main->chat->Broadcast(array(
            "type" => "connected",
            "username" => $user->Username,
            "time" => time(),
            "date" => date("d.m.Y H:i:s", time())
        ), true, [strtolower($user->Username)]);

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

        $isAuthorized = $user->IsAuthorized($request, $response);
        if (!$isAuthorized)
        {
            $response->End($unauthorized);
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
            $result .= base64_encode(json_encode($this->main->chat->GetUsersListData()));
            $response->End($result);
            return;
        }

        // Пользователь мог потерять соединение, а новые данные могли прийти
        // Проверяем, пропустил ли юзер новые данные
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

        // Не отвечаем пользователю на запрос сразу
        // Ждём появления новых данных в течение 25 секунд
        // В противном случае закрываем соединение
        $params = new AsyncTaskUserParams();
        $params->User = $user;
        $params->Request = $request;
        $params->Response = $response;
        $user->Request[] = $request;
        $user->Response[] = $response;
        $user->LastActive = time();

        $user->TaskCloser[] = new AsyncTask($this, 25000, true, function(AsyncTask $task, AsyncTaskUserParams $params) : void
        {
            $user = $params->User;

            $request = $params->Request;
            $response = $params->Response;
            $response->End("");

            unset($user->Request[array_search($request, $user->Request)]);
            unset($user->Response[array_search($response, $user->Response)]);

            $user->Request = array_values($user->Request);
            $user->Response = array_values($user->Response);

            unset($user->TaskCloser[array_search($task, $user->TaskCloser)]);
            $user->TaskCloser = array_values($user->TaskCloser);

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

        $isAuthorized = $user->IsAuthorized($request, $response);
        if (!$isAuthorized)
        {
            $response->End("NOTAUTHORIZED");
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

        $this->main->chat->Broadcast(array(
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

        $isAuthorized = $user->IsAuthorized($request, $response);
        if (!$isAuthorized)
        {
            $response->End("");
            return;
        }

        // Удаляем пользователя из чата
        $this->main->chat->Kick($user->Username, "", "disconnected");

        $response->End("OK");
    }

    public function Typing(Request $request, Response $response) : void
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

        $isAuthorized = $user->IsAuthorized($request, $response);
        if (!$isAuthorized)
        {
            $response->End("");
            return;
        }

        if ((time() - $user->LastType) < 2)
        {
            $response->End("");
            return;
        }

        $user->LastType = time();

        $this->main->chat->Broadcast(array(
            "type" => "typing",
            "username" => $user->Username,
        ), false, [strtolower($user->Username)]);
        $response->End("");
    }
}