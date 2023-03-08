<?php

declare(ticks=1);

namespace Program;

use CliForms\Common\RowHeaderType;
use CliForms\Exceptions\NoItemsAddedException;
use CliForms\MenuBox\Events\ItemClickedEvent;
use CliForms\MenuBox\Events\KeyPressEvent;
use CliForms\MenuBox\MenuBox;
use CliForms\MenuBox\MenuBoxItem;
use IO\Console;

class MainMenu
{
    private Main $main;

    public MenuBox $UsersMenuBox;

    public function __construct(Main $main)
    {
        $this->main = $main;
    }

    public function Start() : void
    {
        /**
         * СОЗДАЁМ ГЛАВНОЕ МЕНЮ
         */
        $menu = new MenuBox("============ Чат на xRefCore ============", $this);
        $menu->SetRowsHeaderType(RowHeaderType::STARS);
        $menu->SetRowHeaderItemDelimiter(" ");
        $item1 = new MenuBoxItem("Открыть лог", "Нажмите ENTER, чтобы закрыть лог", function(ItemClickedEvent $event) : void { $this->OpenLog($event); });
        $item2 = new MenuBoxItem("Перезагрузить конфиг", "ВНИМАНИЕ! НЕ ПЕРЕЗАГРУЖАЕТ ВЕБ-СЕРВЕР, ЕСЛИ ПОРТ ИЗМЕНЁН!", function(ItemClickedEvent $event) : void { $this->ReloadConfig($event); });
        $item3 = new MenuBoxItem("Пользователи", "Чтобы выгнать пользователя, выберите его и нажмите Enter", function(ItemClickedEvent $event) : void { $this->ShowUsers($event); });
        $close = new MenuBoxItem("Закрыть", "Выключает чат и закрывает программу", function(ItemClickedEvent $event) : void { $this->Exit($event); });

        $menu->
            AddItem($item1)->
            AddItem($item2)->
            AddItem($item3)->
            SetZeroItem($close);

        /**
         * СОЗДАЁМ МЕНЮ "ПОЛЬЗОВАТЕЛИ"
         */
        $this->UsersMenuBox = new MenuBox("Список пользователей", $this);
        $this->UsersMenuBox->SetRowsHeaderType(RowHeaderType::NUMERIC);
        $this->UsersMenuBox->SetRowHeaderItemDelimiter(" ");
        $this->UsersMenuBox->SetDescription("Выберите пользователя и нажмите ENTER, чтобы исключить. Либо выберите пользователя и нажмите BACKSPACE, чтобы сразу выгнать его, не указывая причину");
        $this->UsersMenuBox->KeyPressEvent = function(KeyPressEvent $event) : void
        {
            if ($event->Key != "backspace")
                return;

            $username = $event->MenuBox->GetSelectedItem()->Name();
            $this->main->chat->Kick($username, "Kicked by Console");
        };
        $this->UsersMenuBox->SetZeroItem(new MenuBoxItem("Назад", "", function(ItemClickedEvent $event) : void
        {
            $event->MenuBox->Close();
        }));

        /**
         * ЗАПУСКАЕМ ГЛАВНОЕ МЕНЮ
         */
        $menu->Render();
    }

    public function OpenLog(ItemClickedEvent $event) : void
    {
        $this->main->chat->OpenWindowLog();
    }

    public function ReloadConfig(ItemClickedEvent $event) : void
    {
        $this->main->config->Load();
        $event->MenuBox->ResultOutputLine("Конфиг перезагружен!");
    }

    public function ShowUsers(ItemClickedEvent $event) : void
    {
        try
        {
            $this->UsersMenuBox->Render();
        }
        catch(NoItemsAddedException $e)
        {
            $event->MenuBox->ResultOutputLine("Нет подключённых пользователей");
        }
    }

    public function KickUser(ItemClickedEvent $event) : void
    {
        $username = $event->Item->Name();
        Console::ClearWindow("Укажите причину, по которой выгоняете " . $username . ": ");
        $reason = Console::ReadLine();

        $this->main->chat->Kick($username, "Kicked by Console" . (strlen($reason) > 0 ? ": " . $reason : ""));
        Console::ClearWindow();
    }

    public function Exit(ItemClickedEvent $event) : void
    {
        $this->main->chat->Shutdown();
        $event->MenuBox->Close();
    }
}