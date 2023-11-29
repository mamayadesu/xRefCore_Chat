<?php

declare(ticks=1);

namespace Program;

use Application\Application;
use Chat\Chat;
use Data\String\ForegroundColors;
use HttpServer\Exceptions\ServerStartException;
use HttpServer\Request;
use HttpServer\Response;
use HttpServer\Server;
use HttpServer\ServerEvents;
use IO\Console;
use Scheduler\AsyncTask;
use Scheduler\IAsyncTaskParameters;

class Main
{
    public ?Server $server = null;
    public Config $config;
    public Chat $chat;
    public MainMenu $mainMenu;
    public Router $router;

    public function __construct(array $args)
    {
        /**
         * Loading config
         */
        $this->config = new Config();

        /**
         * Creating chat instance
         */
        $this->chat = new Chat($this);

        /**
         * Creating router instance
         */
        $this->router = new Router($this);
        if (!$this->config->Load())
        {
            Console::WriteLine("Please setup config and start this program again");
            return;
        }

        /**
         * Adding Ctrl+C handler
         */

        if (IS_WINDOWS)
        {
            sapi_windows_set_ctrl_handler(function(int $event) : void
            {
                // Shutting down chat on CTRL+C
                if ($event == PHP_WINDOWS_EVENT_CTRL_C)
                    $this->mainMenu->MainMenuBox->Close();
            }, true);
        }
        else
        {
            pcntl_signal(SIGINT, function() : void
            {
                // Shutting down chat on CTRL+C
                $this->mainMenu->MainMenuBox->Close();
            });
        }

        /**
         * Starting Web-server
         */
        if (!$this->StartServer())
        {
            return;
        }

        /**
         * Load main menu
         */
        $this->mainMenu = new MainMenu($this);
        $this->mainMenu->Start();
    }

    /**
     * #################
     * GETTING DIRECTORY CONTENT PAGE
     * #################
     */
    private function GetDirContentPage(string $requestUri, string $prevDirectory, string $target) : string
    {
        /**
         * Adding slash to end of URI
         */
        $ru = str_split($requestUri);
        if ($ru[count($ru) - 1] != "/")
            $requestUri .= "/";

        $result = "
<html>
    <head>
        <title>Content of " . $requestUri . "</title>
    </head>
    <body>
        <table border>
            <tr>
                <th>Name</th>
                <th>Type</th>
                <th>Size</th>
                <th>Modified</th>
            </tr>
";

        /**
         * Scanning current directory
         */
        foreach (scandir($target) as $name)
        {
            $nameEncoded = iconv("UTF-8", "WINDOWS-1251", $name);
            /**
             * Getting full path to target directory on local machine to get file size
             */
            $fullPathToTarget = $target . DIRECTORY_SEPARATOR . $name;

            $targetType = "File";
            if (is_dir($fullPathToTarget))
                $targetType = "Directory";

            $size = "";
            if ($targetType == "File")
            {
                $st = "B";
                $filesize = filesize($fullPathToTarget);
                while ($st != "GB")
                {
                    if ($filesize >= 1024)
                    {
                        $filesize = round($filesize / 1024, 1);
                        switch ($st)
                        {
                            case "B":
                                $st = "KB";
                                break;

                            case "KB":
                                $st = "MB";
                                break;

                            case "MB":
                                $st = "GB";
                                break;
                        }
                    }
                    else
                    {
                        break;
                    }
                }
                $size = $filesize . " " . $st;
            }
            if ($name == "..")
            {
                $url = "/" . $prevDirectory;
            }

            /**
             * Removing "."
             */
            if ($name == ".")
            {
                continue;
            }

            /**
             * Adding this directory or file to <table>
             */
            $result .= "<tr><td><a href='" . $requestUri . $nameEncoded . "'>" . $nameEncoded . "</a></td><td>" . $targetType . "</td><td>" . $size . "</td><td>" . date("d.m.Y H:i:s", filemtime($fullPathToTarget)) . "</td></tr>\n";
        }

        $result .= "
        </table>
    </body>
</html>";
        return $result;
    }

    public function RequestAsyncHandler(AsyncTask $task, RequestAsyncHandlerParams $params) : void
    {
        $tile = $params->GetNext();

        if ($tile == "")
        {
            $params->Response->End();
            $task->Cancel();
            return;
        }
        $params->Response->PrintBody($tile);
    }

    public function GetMimeByExtension(string $ext) : string
    {
        $mime = "octet/stream";
        switch ($ext)
        {
            case "css":
                $mime = "text/css";
                break;

            case "js":
                $mime = "application/javascript";
                break;

            case "txt":
                $mime = "text/plain";
                break;

            case "jpg":
            case "jpeg":
                $mime = "image/jpeg";
                break;

            case "gif":
                $mime = "image/gif";
                break;

            case "png":
                $mime = "image/png";
                break;

            case "htm":
            case "html":
                $mime = "text/html";
                break;

            case "doc":
            case "dot":
                $mime = "application/msword";
                break;

            case "pdf":
                $mime = "application/pdf";
                break;

            case "mp3":
                $mime = "audio/mpeg";
                break;

            case "wav":
                $mime = "audio/x-wav";
                break;

            case "bmp":
                $mime = "image/bmp";
                break;

            case "ico":
                $mime = "image/x-icon";
                break;

            case "mp4":
                $mime = "video/mp4";
                break;

            case "avi":
                $mime = "video/x-msvideo";
                break;
        }
        return $mime;
    }

    public function StartServer() : bool
    {
        if ($this->server !== null)
        {
            $this->server->Shutdown();
        }

        $this->server = new Server("0.0.0.0", $this->config->Port);

        /**
         * #################
         * REQUEST HANDLER
         * #################
         */
        $this->server->On(ServerEvents::Request, function(Request $request, Response $response)
        {
            /**
             * #################
             * CLOUDFLARE SUPPORT
             * #################
             */
            if (isset($request->Headers["CF-Connecting-IP"]))
            {
                try
                {
                    $request->RemoteAddress = $request->Headers["CF-Connecting-IP"];
                }
                catch (\Throwable $e)
                {}
            }
            /**
             * #################
             * CHECKING ROUTER
             * #################
             */
            if ($this->router->HandleRequest($request, $response))
            {
                return;
            }

            /**
             * *****************
             * THE NEXT CODE WAS TAKEN FROM "xRefCore_HttpServerExample" REPOSITORY
             * *****************
             */

            /**
             * #################
             * REDIRECT TO index.html
             * #################
             */
            if ($request->PathInfo == "/" && file_exists($this->config->DocumentRoot . "index.html"))
            {
                $request->PathInfo .= "index.html";
            }
            $path = $request->PathInfo;

            /**
             * #################
             * REMOVING "." AND ".." AND "//" "///" (etc.) FROM URI
             * #################
             */
            $pathSplit = explode('/', $path);
            $newPathSplit = [];
            foreach ($pathSplit as $element)
            {
                if ($element != "" && $element != ".." && $element != ".")
                {
                    $newPathSplit[] = $element;
                }
            }
            $newPath = implode(DIRECTORY_SEPARATOR, $newPathSplit);

            /**
             * #################
             * GETTING PREVIOUS PATH
             * #################
             */
            $prevPathSplit = $newPathSplit;
            if (count($prevPathSplit) > 0)
            {
                array_pop($prevPathSplit);
            }
            $prevDirectory = implode('/', $prevPathSplit);

            /**
             * #################
             * PATH TO TARGET FILE OR DIRECTORY ON LOCAL MACHINE
             * #################
             */
            $target = $this->config->DocumentRoot . rawurldecode($newPath);
            if (is_dir($target))
            {
                $dirContentPage = $this->GetDirContentPage($request->RequestUri, $prevDirectory, $target);
                $response->End($dirContentPage); // Print directory content
                return;
            }

            /**
             * #################
             * 404 NOT FOUND
             * #################
             */
            if (!file_exists($target))
            {
                $response->Status(404);
                $_404 = <<<HTML

<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">
<html><head>
<title>404 Not Found</title>
</head><body>
<h1>Not Found</h1>
<p>The requested URL was not found on this server.</p>
<hr>
<address>xRefCore Web Server</address>
</body></html>

HTML;

                $response->End($_404);
                return;
            }

            /**
             * #################
             * GETTING MIME TYPE
             * #################
             */
            $extension = strtolower(pathinfo($target, PATHINFO_EXTENSION));
            $mime = $this->GetMimeByExtension($extension);

            /**
             * SETTING NON BLOCK MODE FOR VIDEO AND AUDIO
             *
             * * WHY NOT FOR ANY TYPE?
             * IF WE ARE USING NON BLOCK MODE FOR LARGE FILES, IT MAY RESULT IN DATA LOSING
             *
             * * OKAY. WHY WE SET NON BLOCK MODE FOR AUDIO AND VIDEO?
             * BECAUSE WHEN BROWSER IS LOADING PLAYABLE MEDIA CONTENT AND WE'RE SENDING DATA,
             * BROWSER MAY NOT RESPOND WHILE IT IS LOADING ALREADY LOADED DATA AND YOUR APPLICATION WILL GET STUCK FOR SEVERAL SECONDS
             */
            if (in_array(explode('/', $mime)[0], ["video", "audio"]))
            {
                $response->ClientNonBlockMode = true;
            }

            /**
             * #################
             * SETTING HTTP 200, FILE SIZE AND MIME TYPE
             * #################
             */
            $filesize = filesize($target);
            $response->Status(200);
            $response->Header("Content-Type", $mime);
            $response->Header("Content-Length", $filesize);

            /**
             * #################
             * OPENING FILE
             * #################
             */
            if ($filesize <= 1024 * 1024)
            {
                $response->End(@file_get_contents($target));
                return;
            }

            /**
             * We'll send content using async task to do not block whole process,
             * because file content may be large
             */
            $file = @fopen($target, "r");
            if (!$file)
            {
                Console::WriteLine("Opening file " . $target . " failed.", ForegroundColors::RED);

                $_500 = <<<HTML

<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">
<html><head>
<title>500 Internal Server Error</title>
</head><body>
<h1>Internal Server Error</h1>
<hr>
<address>xRefCore Web Server</address>
</body></html>

HTML;

                $response->Status(500);
                $response->End($_500);
                return;
            }
            $params = new RequestAsyncHandlerParams($file, $response);
            new AsyncTask($this, 1, false, [$this, "RequestAsyncHandler"], $params);
        });

        try
        {
            $this->server->Start(true);
        }
        catch (ServerStartException $e)
        {
            Console::WriteLine("Failed to start server. " . $e->getMessage(), ForegroundColors::RED);
            return false;
        }
        return true;
    }
}
