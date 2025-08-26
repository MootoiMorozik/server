<?php

namespace CheatCheck;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\Config;

class Main extends PluginBase implements Listener {

    private $checks = [];
    private $messageTask;

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        
        $this->messageTask = $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function(): void {
            foreach($this->checks as $playerName => $data) {
                $player = $this->getServer()->getPlayerExact($playerName);
                if($player instanceof Player) {
                    $timeLeft = $data['duration'] - (time() - $data['start_time']);
                    
                    if($timeLeft <= 0) {
                        $this->finishCheck($player, "срок проверки вышел");
                    } else {
                        $minutes = floor($timeLeft / 60);
                        $seconds = $timeLeft % 60;
                        $timeFormatted = sprintf("%d:%02d", $minutes, $seconds);
                        
                        $player->sendTitle("§cПроверка на читы", "§eОсталось: §c{$timeFormatted}", 0, 40, 0);
                        $player->sendMessage("§5§lПроверка на читы!§f Осталось: {$timeFormatted}");
                        $player->sendMessage("§c§lВыход с игры - бан, пишите свой дискорд");
                    }
                }
            }
        }), 60); // 3 секунды (60 тиков)
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if(strtolower($command->getName()) === "check") {
            if(!$sender->hasPermission("check.command")) {
                $sender->sendMessage("§cNo permission!");
                return true;
            }

            if(count($args) === 0) {
                $sender->sendMessage("§cUsage: /check <start|stop|add> [player] [time]");
                return true;
            }

            $subCommand = strtolower($args[0]);

            switch($subCommand) {
                case "start":
                    if(count($args) < 2) {
                        $sender->sendMessage("§cUsage: /check start <player>");
                        return true;
                    }
                    $this->startCheck($sender, $args[1]);
                    break;

                case "stop":
                    if(count($args) < 2) {
                        $sender->sendMessage("§cUsage: /check stop <player>");
                        return true;
                    }
                    $this->stopCheck($sender, $args[1]);
                    break;

                case "add":
                    if(count($args) < 3) {
                        $sender->sendMessage("§cUsage: /check add <player> <time>");
                        return true;
                    }
                    $this->addTime($sender, $args[1], $args[2]);
                    break;

                default:
                    $sender->sendMessage("§cUnknown subcommand");
                    break;
            }
            return true;
        }
        return false;
    }

    private function startCheck(CommandSender $sender, string $playerName): void {
        $player = $this->getServer()->getPlayerExact($playerName);
        if(!$player instanceof Player) {
            $sender->sendMessage("§cPlayer not found!");
            return;
        }

        if(isset($this->checks[$player->getName()])) {
            $sender->sendMessage("§cPlayer is already being checked!");
            return;
        }

        $this->checks[$player->getName()] = [
            'start_time' => time(),
            'duration' => 480, // 8 минут
            'added_time' => 0
        ];

        $sender->sendMessage("§aCheck started for " . $player->getName());
        $player->sendMessage("§cВы под проверкой на читы! Не двигайтесь и следуйте инструкциям.");
        $player->sendTitle("§cПРОВЕРКА НА ЧИТЫ", "§eНе двигайтесь!", 0, 40, 0);
    }

    private function stopCheck(CommandSender $sender, string $playerName): void {
        $player = $this->getServer()->getPlayerExact($playerName);
        if(!$player instanceof Player || !isset($this->checks[$player->getName()])) {
            $sender->sendMessage("§cPlayer is not being checked!");
            return;
        }

        unset($this->checks[$player->getName()]);
        $sender->sendMessage("§aCheck stopped for " . $player->getName());
        $player->sendMessage("§aПроверка завершена. Вы свободны.");
        $player->sendTitle("§aПРОВЕРКА ЗАВЕРШЕНА", "§eВы свободны", 0, 40, 0);
    }

    private function addTime(CommandSender $sender, string $playerName, string $time): void {
        $player = $this->getServer()->getPlayerExact($playerName);
        if(!$player instanceof Player || !isset($this->checks[$player->getName()])) {
            $sender->sendMessage("§cPlayer is not being checked!");
            return;
        }

        // Парсим время (5m, 10s и т.д.)
        $timeValue = $this->parseTime($time);
        if($timeValue === null) {
            $sender->sendMessage("§cInvalid time format! Use like: 5m, 10s, 2m30s");
            return;
        }

        $this->checks[$player->getName()]['duration'] += $timeValue;
        $this->checks[$player->getName()]['added_time'] += $timeValue;
        
        $sender->sendMessage("§aAdded {$timeValue} seconds to check for " . $player->getName());
        $player->sendMessage("§cК вашей проверке добавлено {$timeValue} секунд!");
    }

    private function parseTime(string $time): ?int {
        $seconds = 0;
        $pattern = '/(\d+)([smh])/';
        preg_match_all($pattern, $time, $matches, PREG_SET_ORDER);
        
        foreach($matches as $match) {
            $value = (int)$match[1];
            $unit = $match[2];
            
            switch($unit) {
                case 's': $seconds += $value; break;
                case 'm': $seconds += $value * 60; break;
                case 'h': $seconds += $value * 3600; break;
                default: return null;
            }
        }
        
        return $seconds > 0 ? $seconds : null;
    }

    public function onPlayerMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();
        if(isset($this->checks[$player->getName()])) {
            $event->cancel();
        }
    }

    public function onPlayerQuit(PlayerQuitEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        
        if(isset($this->checks[$playerName])) {
            $this->getServer()->getNameBans()->addBan($playerName, "Проверка на читы - выход во время проверки", null, "CheatCheck");
            unset($this->checks[$playerName]);
        }
    }

    public function onPlayerChat(PlayerChatEvent $event): void {
        $player = $event->getPlayer();
        $message = $event->getMessage();
        $playerName = $player->getName();

        if(isset($this->checks[$playerName]) && strtolower($message) === "я чит") {
            // Просто игнорируем сообщение "я чит" - модер сам забанит
            $event->cancel();
        }
    }

    private function finishCheck(Player $player, string $reason): void {
        $playerName = $player->getName();
        $this->getServer()->getNameBans()->addBan($playerName, $reason, null, "CheatCheck");
        unset($this->checks[$playerName]);
        $player->kick("§c{$reason}");
    }

    public function onDisable(): void {
        foreach($this->checks as $playerName => $data) {
            $player = $this->getServer()->getPlayerExact($playerName);
            if($player instanceof Player) {
                $this->getServer()->getNameBans()->addBan($playerName, "Проверка на читы - сервер выключен", null, "CheatCheck");
            }
        }
    }
}