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
         * CREATING MAIN MENU
         */
        $menu = new MenuBox("============ Chat built on xRefCore ============", $this);
        $menu->SetRowsHeaderType(RowHeaderType::STARS);
        $menu->SetRowHeaderItemDelimiter(" ");
        $item1 = new MenuBoxItem("Open log", "Press ENTER to close log", function(ItemClickedEvent $event) : void { $this->OpenLog($event); });
        $item2 = new MenuBoxItem("Reload config", "WARNING! IT DOES NOT RESTARTS HTTP-SERVER!", function(ItemClickedEvent $event) : void { $this->ReloadConfig($event); });
        $item3 = new MenuBoxItem("Show users", "Select user and kick him", function(ItemClickedEvent $event) : void { $this->ShowUsers($event); });
        $close = new MenuBoxItem("Exit", "Shutdown chat and close program", function(ItemClickedEvent $event) : void { $this->Exit($event); });

        $menu->
            AddItem($item1)->
            AddItem($item2)->
            AddItem($item3)->
            SetZeroItem($close);

        /**
         * CREATING "SHOW USERS" MENU
         */
        $this->UsersMenuBox = new MenuBox("Users list", $this);
        $this->UsersMenuBox->SetRowsHeaderType(RowHeaderType::NUMERIC);
        $this->UsersMenuBox->SetRowHeaderItemDelimiter(" ");
        $this->UsersMenuBox->SetDescription("Select user and press ENTER to kick him. Or select user and press BACKSPACE to kick him without reason");
        $this->UsersMenuBox->KeyPressEvent = function(KeyPressEvent $event) : void
        {
            if ($event->Key != "backspace")
                return;

            $username = $event->MenuBox->GetSelectedItem()->Name();
            $this->main->chat->Kick($username, "Kicked by Console");
        };
        $this->UsersMenuBox->SetZeroItem(new MenuBoxItem("Back", "", function(ItemClickedEvent $event) : void
        {
            $event->MenuBox->Close();
        }));

        /**
         * LOADING MAIN MENU
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
        $event->MenuBox->ResultOutputLine("Config reloaded!");
    }

    public function ShowUsers(ItemClickedEvent $event) : void
    {
        try
        {
            $this->UsersMenuBox->Render();
        }
        catch(NoItemsAddedException $e)
        {
            $event->MenuBox->ResultOutputLine("No users connected");
        }
    }

    public function KickUser(ItemClickedEvent $event) : void
    {
        $username = $event->Item->Name();
        Console::ClearWindow("Input reason to kick " . $username . ": ");
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