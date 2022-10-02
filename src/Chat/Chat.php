<?php

declare(ticks=1);

namespace Chat;

use Data\String\ColoredString;
use Data\String\ForegroundColors;
use IO\Console;
use Program\Main;
use Scheduler\AsyncTask;
use Scheduler\IAsyncTaskParameters;

class Chat
{
    /**
     * @var array<string, User>
     */
    private array $users = array();
    private Main $main;
    private array $history = array();
    private bool $WindowLogOpened = false;

    public function __construct(Main $main)
    {
        $this->main = $main;

        /**
         * Эта асинхронная задача каждую секунду проверяет на наличие "зомби"-юзеров
         */
        new AsyncTask($this, 1000, false, function(AsyncTask $task, IAsyncTaskParameters $params) : void
        {
            foreach ($this->users as $key => $user)
            {
                // Если юзер не переподключается в течение 50 секунд, кикаем его
                if (($user->LastActive + 50) < time())
                {
                    $this->Kick($user->Username, "", "timed out");
                }
            }
        });
    }

    public function Shutdown() : void
    {
        foreach ($this->users as $user)
        {
            $this->Kick($user->Username, "Chat Closed");
        }
        $this->main->server->Shutdown();
    }

    public function GetUser(string $username) : ?User
    {
        return $this->users[strtolower($username)] ?? null;
    }

    /**
     * @return array<User>
     */
    public function GetAllUsers() : array
    {
        return $this->users;
    }

    public function AddUser(User $user) : void
    {
        $this->users[strtolower($user->Username)] = $user;
    }

    public function Kick(string $username, string $reason, string $type = "kicked") : void
    {
        $lusername = strtolower($username);
        $user = $this->users[$lusername] ?? null;
        if ($user === null)
            return;

        $data = array(
            "type" => $type,
            "username" => $user->Username,
            "time" => time(),
            "date" => date("d.m.Y H:i:s", time())
        );

        if ($type == "kicked")
        {
            $data["reason"] = $reason;
        }
        $user->LastActive = 0;
        $user->UnreadEvents = array();
        if (count($user->TaskCloser) > 0)
        {
            foreach ($user->TaskCloser as $task)
            {
                $task->Cancel();
            }
            $user->TaskCloser = [];
        }
        $user->Request = [];
        if (count($user->Response) > 0)
        {
            foreach ($user->Response as $response)
            {
                $response->End(base64_encode(json_encode($data)));
            }
            $user->Response = [];
        }
        $user->MenuBoxItem->Remove();
        $user->MenuBoxItem = null;
        $user->AccessToken = "";

        /**
         * Пишем сообщение в чат
         */
        unset($this->users[$lusername]);
        $this->Broadcast($data);
    }

    public function SendMessage(string $username, array $data) : void
    {
        $username = strtolower($username);

        if (!isset($this->users[$username]))
            return;

        $user = $this->users[$username];
        if (count($user->Response) > 0)
        {
            // Отправляем события всем юзерам

            foreach ($user->Response as $response)
            {
                $response->End(base64_encode(json_encode($data)) . "\n");
            }

            foreach ($user->TaskCloser as $task)
            {
                $task->Cancel();
            }

            $user->TaskCloser = [];
            $user->Response = [];
            $user->Request = [];
        }
        else
        {
            // Если пользователь потерял соединение, пишем событие в массив
            // Как пользователь переподключится, он увидит все пропущенные события
            $user->UnreadEvents[] = $data;
        }
    }

    public function GetUsersListData() : array
    {
        $usersList = array
        (
            "type" => "list",
            "list" => []
        );
        foreach ($this->users as $user)
        {
            $usersList["list"][] = $user->Username;
        }
        return $usersList;
    }

    public function Broadcast(array $data, bool $save = true, array $excludeUsernames = []) : void
    {
        $usersListPrepared = base64_encode(json_encode((in_array($data["type"], ["connected", "disconnected", "kicked", "timed out"])) ? $this->GetUsersListData() : ""));
        foreach ($this->users as $lusername => $user)
        {
            if (in_array($lusername, $excludeUsernames))
                continue;
            if (count($user->Response) > 0)
            {
                // Отправляем события всем юзерам

                foreach ($user->Response as $response)
                {
                    $response->End(base64_encode(json_encode($data)). "\n" . $usersListPrepared);
                }

                foreach ($user->TaskCloser as $task)
                {
                    $task->Cancel();
                }

                $user->TaskCloser = [];
                $user->Response = [];
                $user->Request = [];
            }
            else if ($save)
            {
                // Если пользователь потерял соединение, пишем событие в массив
                // Как пользователь переподключится, он увидит все пропущенные события
                $user->UnreadEvents[] = $data;
            }
        }

        if (!$save)
            return;

        $this->history[] = $data;

        if ($this->WindowLogOpened)
            Console::Write($this->FormatRow($data));
    }

    public function GetHistory() : array
    {
        return $this->history;
    }

    public function OpenWindowLog() : void
    {
        $this->WindowLogOpened = true;
        $reversedHistory = array_reverse($this->history);
        $lastElements = [];

        $historyLimit = 25;
        $k = 0;
        foreach ($reversedHistory as $row)
        {
            $k++;
            $lastElements[] = $row;

            if ($k == $historyLimit)
            {
                break;
            }
        }
        if ($k < $historyLimit)
        {
            $lastElements[] = array(
                "type" => "start",
                "date" => ""
            );
        }

        $history = array_reverse($lastElements);

        $output = "";
        foreach ($history as $row)
        {
            $output .= $this->FormatRow($row);
        }
        Console::ClearLine($output);
        Console::ReadLine();
        $this->WindowLogOpened = false;
        Console::ClearWindow();
    }

    private function FormatRow(array $row) : string
    {
        $output = "";
        $date = ColoredString::Get($row["date"] . " ", ForegroundColors::DARK_GRAY);
        switch ($row["type"])
        {
            case "start":
                $output .= ColoredString::Get("Начало чата!", ForegroundColors::GREEN);
                break;

            case "connected":
                $output .= $date . ColoredString::Get($row["username"], ForegroundColors::PURPLE) . " " . ColoredString::Get("вошёл!", ForegroundColors::GREEN);
                break;

            case "disconnected":
                $output .= $date . ColoredString::Get($row["username"], ForegroundColors::PURPLE) . " " . ColoredString::Get("покинул чат", ForegroundColors::YELLOW);
                break;

            case "kicked":
                $output .= $date . ColoredString::Get($row["username"], ForegroundColors::PURPLE) . " " . ColoredString::Get("исключён (" . $row["reason"] . ")", ForegroundColors::RED);
                break;

            case "timed out":
                $output .= $date . ColoredString::Get($row["username"], ForegroundColors::PURPLE) . " " . ColoredString::Get("потерял соединение с сервером", ForegroundColors::DARK_RED);
                break;

            case "message":
                $output .= $date . ColoredString::Get($row["sender"], ForegroundColors::PURPLE) . ": " . ColoredString::Get($row["message"], ForegroundColors::WHITE);
                break;
        }
        $output .= "\n";
        return $output;
    }
}