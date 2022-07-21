<?php

namespace Program;

use Application\Application;

class Config
{
    public string $DocumentRoot = "/var/www/";
    public int $Port = 80;
    public int $MaxUsers = -1;
    public int $MaxUsersWithSameIp = 1;

    /**
     * Load config
     *
     * @return bool Returns FALSE if config.json doesn't exist
     */
    public function Load() : bool
    {
        $cfgFile = self::cfgFile();
        $configExist = true;
        if (!file_exists($cfgFile))
        {
            $configExist = false;
            $this->Save();
        }

        $text = file_get_contents($cfgFile);
        $data = @json_decode($text, true);

        if (!$data)
        {
            $configExist = false;
            $this->Save();
        }

        $this->DocumentRoot = $data["documentRoot"];
        $this->Port = $data["port"];
        $this->MaxUsers = $data["maxUsers"];
        $this->MaxUsersWithSameIp = $data["maxUsersWithSameIp"];

        return $configExist;
    }

    public function Save() : void
    {
        $cfgFile = self::cfgFile();
        $f = fopen($cfgFile, "w+");
        $data = json_encode(array(
            "documentRoot" => $this->DocumentRoot,
            "port" => $this->Port,
            "maxUsers" => $this->MaxUsers,
            "maxUsersWithSameIp" => $this->MaxUsersWithSameIp
        ), JSON_PRETTY_PRINT);

        fwrite($f, $data);
        fclose($f);
    }

    private static function cfgFile() : string
    {
        return Application::GetExecutableDirectory() . "config.json";
    }
}