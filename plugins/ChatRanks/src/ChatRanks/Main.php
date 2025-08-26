<?php

namespace ChatRanks;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\Config;
use pocketmine\player\chat\ChatFormatter;
use pocketmine\lang\Translatable;

class Main extends PluginBase implements Listener {

    private $ranks;
    private $config;

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveResource("config.yml");
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        $this->ranks = $this->config->getAll();
    }

    public function onDisable(): void {
        $this->config->setAll($this->ranks);
        $this->config->save();
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if(strtolower($command->getName()) === "ranks") {
            if(!$sender->hasPermission("ranks.command")) {
                $sender->sendMessage("§cNo permission!");
                return true;
            }

            if(count($args) < 2) {
                $sender->sendMessage("§cUsage: /ranks <add|remove> <player> [rank]");
                return true;
            }

            $action = strtolower($args[0]);
            $playerName = strtolower($args[1]);

            if($action === "add") {
                if(count($args) < 3) {
                    $sender->sendMessage("§cUsage: /ranks add <player> <rank>");
                    return true;
                }
                $rank = strtolower($args[2]);
                $this->ranks[$playerName] = $rank;
                $sender->sendMessage("§aRank {$rank} added to {$playerName}");
            } elseif($action === "remove") {
                if(isset($this->ranks[$playerName])) {
                    unset($this->ranks[$playerName]);
                    $sender->sendMessage("§aRank removed from {$playerName}");
                } else {
                    $sender->sendMessage("§cPlayer {$playerName} has no rank");
                }
            }
            return true;
        }
        return false;
    }

    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $this->updateDisplayName($player);
    }

    public function onPlayerChat(PlayerChatEvent $event): void {
        $player = $event->getPlayer();
        $rank = $this->getPlayerRank($player);
        $rankFormat = $this->getRankFormat($rank);
        
        // Создаем кастомный форматтер
        $formatter = new class($rankFormat, $player) implements ChatFormatter {
            private $rankFormat;
            private $player;
            
            public function __construct(string $rankFormat, Player $player) {
                $this->rankFormat = $rankFormat;
                $this->player = $player;
            }
            
            public function format(string $username, string $message): string {
                return $this->rankFormat . $this->player->getName() . "§f: " . $message;
            }
        };
        
        $event->setFormatter($formatter);
    }

    private function updateDisplayName(Player $player): void {
        $rank = $this->getPlayerRank($player);
        $rankFormat = $this->getRankFormat($rank);
        $player->setDisplayName($rankFormat . $player->getName());
    }

    private function getPlayerRank(Player $player): string {
        $playerName = strtolower($player->getName());
        return $this->ranks[$playerName] ?? "player";
    }

    private function getRankFormat(string $rank): string {
        $formats = [
            "создатель" => "§c[Создатель] ",
            "модер" => "§b[Модер] ",
            "игрок" => "§a[Игрок] ",
            "player" => "§a[Игрок] "
        ];
        return $formats[$rank] ?? "§a[Игрок] ";
    }
}