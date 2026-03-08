<?php

declare(strict_types=1);

namespace imlucky\FlySpeed;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;

class FlySpeed extends PluginBase
{
    public function onEnable(): void
    {
        $this->getServer()->getCommandMap()->register("flyspeed", new FlySpeedCommand());
    }
}

class FlySpeedCommand extends Command
{
    public function __construct()
    {
        parent::__construct("flyspeed", "Set your fly speed", "/flyspeed [speed]", ["fspeed", "fs"]);
        $this->setPermission("flyspeed.command");
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool
    {
        if (!$this->testPermission($sender)) {
            return false;
        }

        if (!$sender instanceof Player) {
            $sender->sendMessage(TextFormat::RED . "This is a player only command");
            return false;
        }

        if (empty($args)) {
            $sender->sendMessage(TextFormat::RED . "Usage: /flyspeed [speed]");
            return false;
        }

        $input = strtolower($args[0]);

        if (in_array($input, ["off", "false", "0", "reset"], true)) {
            $speed = 0.05;
        } else {
            $speed = filter_var($args[0], FILTER_VALIDATE_FLOAT);
            if ($speed === false || $speed < 0 || $speed > 3) {
                $sender->sendMessage(TextFormat::RED . "Speed must be a number between 0 and 3, or 'reset' to reset to default");
                return false;
            }
        }

        $sender->setFlightSpeedMultiplier($speed);
        $sender->sendMessage(TextFormat::GREEN . "Your fly speed has been set to " . $speed);
        return true;
    }
}
