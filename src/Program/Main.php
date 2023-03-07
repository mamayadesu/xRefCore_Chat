<?php

declare(ticks=1);

namespace Program;

use Chat\Chat;
use Data\String\ForegroundColors;
use HttpServer\Exceptions\ServerStartException;
use HttpServer\Request;
use HttpServer\Response;
use HttpServer\Server;
use IO\Console;
use Scheduler\AsyncTask;

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
         * Загружаем конфиг
         */
        $this->config = new Config();

        /**
         * Создаём инстанцию чата
         */
        $this->chat = new Chat($this);

        /**
         * Создаём инстанцию маршрутизатора
         */
        $this->router = new Router($this);
        if (!$this->config->Load())
        {
            Console::WriteLine("Пожалуйста, настройте конфиг и запустите это приложение снова.");
            return;
        }

        /**
         * Добавляем обработчик Ctrl+C
         */

        if (IS_WINDOWS)
        {
            sapi_windows_set_ctrl_handler(function(int $event) : void
            {
                // Выключаем чат при Ctrl+C
                if ($event == PHP_WINDOWS_EVENT_CTRL_C)
                    $this->chat->Shutdown();
            }, true);
        }
        else
        {
            pcntl_signal(SIGINT, function() : void
            {
                // Выключаем чат при Ctrl+C
                $this->chat->Shutdown();
            });
        }

        /**
         * Запускаем веб-сервер
         */
        $this->StartServer();

        /**
         * Загружаем главное меню
         */
        $this->mainMenu = new MainMenu($this);
        $this->mainMenu->Start();
    }

    /**
     * #################
     * ПОЛУЧАЕМ СОДЕРЖИМОЕ ПАПКИ
     * #################
     */
    private function GetDirContentPage(string $requestUri, string $prevDirectory, string $target) : string
    {
        /**
         * Добавляем слэш в конец URI
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
         * Сканируем текущую директорию
         */
        foreach (scandir($target) as $name)
        {
            $nameEncoded = iconv("UTF-8", "WINDOWS-1251", $name);
            /**
             * Получаем полный путь к целевой папке для получения размера содержимого
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
             * Убираем "."
             */
            if ($name == ".")
            {
                continue;
            }

            /**
             * Добавляем эту папку или файл в <table>
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

    public function StartServer() : void
    {
        if ($this->server !== null)
        {
            $this->server->Shutdown();
        }

        $this->server = new Server("0.0.0.0", $this->config->Port);

        /**
         * #################
         * ОБРАБОТЧИК HTTP-ЗАПРОСОВ
         * #################
         */
        $this->server->On("request", function(Request $request, Response $response)
        {
            /**
             * #################
             * ПОДДЕРЖКА CLOUDFLARE
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
             * ПРЕЖДЕ ВСЕГО ОБРАЩАЕМСЯ К МАРШРУТИЗАТОРУ
             * #################
             */
            if ($this->router->HandleRequest($request, $response))
            {
                return;
            }

            /**
             * *****************
             * СЛЕДУЮЩИЙ КОД БЫЛ ВЗЯТ ИЗ РЕПОЗИТОРИЯ "xRefCore_HttpServerExample"
             * *****************
             */

            /**
             * #################
             * РЕДИРЕКТ НА index.html
             * #################
             */
            if ($request->PathInfo == "/" && file_exists($this->config->DocumentRoot . "index.html"))
            {
                $request->PathInfo .= "index.html";
            }
            $path = $request->PathInfo;

            /**
             * #################
             * УДАЛЯЕМ "." И ".." И "//" "///" (и т.п.) ИЗ URI
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
             * ПОЛУЧАЕМ РОДИТЕЛЬСКУЮ ПАПКУ
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
             * ПУТЬ К ЦЕЛЕВОМУ ФАЙЛУ ИЛИ ПАПКЕ НА ЛОКАЛЬНОЙ МАШИНЕ
             * #################
             */
            $target = $this->config->DocumentRoot . rawurldecode($newPath);
            if (is_dir($target))
            {
                $dirContentPage = $this->GetDirContentPage($request->RequestUri, $prevDirectory, $target);
                $response->End($dirContentPage); // Выводим содержимое папки
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
             * ПОЛУЧАЕМ ТИП ФАЙЛА
             * #################
             */
            $extension = strtolower(pathinfo($target, PATHINFO_EXTENSION));
            $mime = $this->GetMimeByExtension($extension);

            /**
             * УСТАНАВЛИВАЕМ НЕБЛОКИРУЮЩИЙ РЕЖИМ ДЛЯ АУДИО ИЛИ ВИДЕО
             *
             * * ПОЧЕМУ НЕ ДЛЯ КАЖДОГО ТИПА?
             * ЕСЛИ БУДЕМ СТАВИТЬ НЕБЛОКИРУЮЩИЙ РЕЖИМ ДЛЯ БОЛЬШИХ ФАЙЛОВ, ЭТО МОЖЕТ ПРИВЕСТИ К ПОТЕРЕ ДАННЫХ ПРИ СКАЧИВАНИИ ФАЙЛА
             *
             * * ЛАДНО. ПОЧЕМУ СТАВИМ НЕБЛОКИРУЮЩИЙ РЕЖИМ ИМЕННО ДЛЯ АУДИО ИЛИ ВИДЕО?
             * ПОТОМУ ЧТО КОГДА БРАУЗЕР ВОСПРОИЗВОДИТ МЕДИА-КОНТЕНТ, А МЫ В ЭТО ВРЕМЯ ШЛЁМ ЕМУ ДАННЫЕ,
             * БРАУЗЕР МОЖЕТ НЕ ОТВЕЧАТЬ СЕРВЕРУ, ПОКА ОН ВОСПРОИЗВОДИТ УЖЕ ЗАГРУЖЕННЫЙ КОНТЕНТ И ПРИЛОЖЕНИЕ МОЖЕТ ПОВИСНУТЬ НА НЕСКОЛЬКО СЕКУНД
             */
            if (in_array(explode('/', $mime)[0], ["video", "audio"]))
            {
                $response->ClientNonBlockMode = true;
            }

            /**
             * #################
             * УСТАНАВЛИВАЕМ СТАТУС HTTP 200, РАЗМЕР ФАЙЛА И MIME
             * #################
             */
            $filesize = filesize($target);
            $response->Status(200);
            $response->Header("Content-Type", $mime);
            $response->Header("Content-Length", $filesize);

            /**
             * #################
             * ОТКРЫВАЕМ ФАЙЛ
             * #################
             */
            if ($filesize <= 1024 * 1024)
            {
                $response->End(@file_get_contents($target));
                return;
            }

            /**
             * Чтобы не нагружать приложение обработкой большого объёма информации,
             * будем запрос обрабатывать в асинхронной задаче
             */
            $file = @fopen($target, "r");
            if (!$file)
            {
                Console::WriteLine("Не удалось открыть файл " . $target, ForegroundColors::RED);

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

        $this->server->On("shutdown", function(Server $server)
        {
            exit(0);
        });

        try
        {
            $this->server->Start(true);
        }
        catch (ServerStartException $e)
        {
            Console::WriteLine("Ошибка запуска веб-сервера. " . $e->getMessage(), ForegroundColors::RED);
            exit;
        }
    }
}
