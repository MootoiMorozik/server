<?php

namespace PvPMode;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\CommandEvent;
use pocketmine\player\Player;
use pocketmine\scheduler\Task;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use BossBarAPI\API;

class Main extends PluginBase implements Listener {

    private $pvpPlayers = [];
    private $pvpTime = 30;
    private $allowedCommands = ["/tell", "/say", "/me", "/msg", "/w"];
    private $bossBarIds = [];

    public function onEnable(): void {
        // Проверяем наличие BossBarAPI
        if (!class_exists('BossBarAPI\API')) {
            $this->getLogger()->error("BossBarAPI не найден! Установите плагин BossBar для работы PvP режима!");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }
        
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveDefaultConfig();
        $this->reloadConfig();
        
        $this->pvpTime = $this->getConfig()->get("pvp-time", 30);
        $this->allowedCommands = $this->getConfig()->get("allowed-commands", $this->allowedCommands);
        
        $this->getScheduler()->scheduleRepeatingTask(new class($this) extends Task {
            private $plugin;
            
            public function __construct(Main $plugin) {
                $this->plugin = $plugin;
            }
            
            public function onRun(): void {
                $this->plugin->updatePvPTimers();
            }
        }, 20);
        
        $this->getLogger()->info("PvPMode включен с поддержкой BossBar!");
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if ($command->getName() === "pvpmode") {
            if (!$sender->hasPermission("pvpmode.bypass")) {
                $sender->sendMessage(TextFormat::RED . "У вас нет прав для использования этой команды!");
                return true;
            }
            
            if (count($args) === 0) {
                $sender->sendMessage(TextFormat::YELLOW . "Использование: /pvpmode [info|reload]");
                return true;
            }
            
            switch (strtolower($args[0])) {
                case "info":
                    $sender->sendMessage(TextFormat::GREEN . "PvP режим: " . count($this->pvpPlayers) . " игроков в режиме");
                    foreach ($this->pvpPlayers as $player => $time) {
                        $sender->sendMessage(TextFormat::YELLOW . "- " . $player . ": " . $time . " сек");
                    }
                    break;
                    
                case "reload":
                    $this->reloadConfig();
                    $this->pvpTime = $this->getConfig()->get("pvp-time", 30);
                    $this->allowedCommands = $this->getConfig()->get("allowed-commands", $this->allowedCommands);
                    $sender->sendMessage(TextFormat::GREEN . "Конфиг перезагружен!");
                    break;
                    
                default:
                    $sender->sendMessage(TextFormat::RED . "Неизвестная подкоманда. Используйте: info, reload");
                    break;
            }
            
            return true;
        }
        return false;
    }

    public function onDamage(EntityDamageEvent $event): void {
        if ($event->isCancelled()) return;
        
        if ($event instanceof EntityDamageByEntityEvent) {
            $damager = $event->getDamager();
            $victim = $event->getEntity();
            
            if ($damager instanceof Player && $victim instanceof Player) {
                if (!$damager->hasPermission("pvpmode.bypass")) {
                    $this->activatePvPMode($damager);
                }
                if (!$victim->hasPermission("pvpmode.bypass")) {
                    $this->activatePvPMode($victim);
                }
            }
        }
    }

    private function activatePvPMode(Player $player): void {
        $playerName = $player->getName();
        
        if (!isset($this->pvpPlayers[$playerName])) {
            $player->sendMessage(TextFormat::RED . "Вы вошли в PvP режим!");
            $this->getServer()->broadcastMessage(TextFormat::YELLOW . $playerName . " вошел в PvP режим!");
            
            // Создаем BossBar для игрока
            $this->createBossBar($player);
        }
        
        $this->pvpPlayers[$playerName] = $this->pvpTime;
        $this->updateBossBar($player);
    }

    private function deactivatePvPMode(Player $player): void {
        $playerName = $player->getName();
        
        if (isset($this->pvpPlayers[$playerName])) {
            unset($this->pvpPlayers[$playerName]);
            $player->sendMessage(TextFormat::GREEN . "Вы вышли из PvP режима!");
            $this->getServer()->broadcastMessage(TextFormat::YELLOW . $playerName . " вышел из PvP режима!");
            
            // Убираем BossBar
            $this->removeBossBar($player);
        }
    }

    private function createBossBar(Player $player): void {
        $playerName = $player->getName();
        $title = TextFormat::RED . "PvP режим: " . TextFormat::WHITE . $this->pvpTime . " сек";
        
        // Создаем BossBar
        $eid = API::addBossBar([$player], $title);
        $this->bossBarIds[$playerName] = $eid;
        
        // Устанавливаем полную шкалу
        API::setPercentage(100, $eid);
    }

    private function updateBossBar(Player $player): void {
        $playerName = $player->getName();
        $timeLeft = $this->pvpPlayers[$playerName];
        
        if (isset($this->bossBarIds[$playerName])) {
            $eid = $this->bossBarIds[$playerName];
            $title = TextFormat::RED . "PvP режим: " . TextFormat::WHITE . $timeLeft . " сек";
            $percentage = ($timeLeft / $this->pvpTime) * 100;
            
            API::setTitle($title, $eid);
            API::setPercentage($percentage, $eid);
        }
    }

    private function removeBossBar(Player $player): void {
        $playerName = $player->getName();
        
        if (isset($this->bossBarIds[$playerName])) {
            $eid = $this->bossBarIds[$playerName];
            API::removeBossBar([$player], $eid);
            unset($this->bossBarIds[$playerName]);
        }
    }

    public function updatePvPTimers(): void {
        foreach ($this->pvpPlayers as $playerName => $timeLeft) {
            $player = $this->getServer()->getPlayerExact($playerName);
            
            if ($player instanceof Player && $player->isConnected()) {
                $this->pvpPlayers[$playerName]--;
                
                if ($this->pvpPlayers[$playerName] <= 0) {
                    $this->deactivatePvPMode($player);
                } else {
                    $this->updateBossBar($player);
                }
            } else {
                unset($this->pvpPlayers[$playerName]);
                if (isset($this->bossBarIds[$playerName])) {
                    unset($this->bossBarIds[$playerName]);
                }
            }
        }
    }

    public function onQuit(PlayerQuitEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        
        if (isset($this->pvpPlayers[$playerName]) && !$player->hasPermission("pvpmode.bypass")) {
            $this->getServer()->broadcastMessage(TextFormat::RED . $playerName . " вышел во время PvP режима и был убит!");
            
            $player->kill();
            $player->getInventory()->dropContents($player->getPosition());
        }
        
        // Убираем BossBar при выходе
        if (isset($this->bossBarIds[$playerName])) {
            unset($this->bossBarIds[$playerName]);
        }
    }

    public function onCommandProcess(CommandEvent $event): void {
        $sender = $event->getSender();
        
        if ($sender instanceof Player) {
            $player = $sender;
            $command = $event->getCommand();
            
            if (isset($this->pvpPlayers[$player->getName()]) && !$player->hasPermission("pvpmode.bypass")) {
                $commandName = strtolower(explode(" ", $command)[0]);
                
                $allowed = false;
                foreach ($this->allowedCommands as $allowedCmd) {
                    if ($commandName === $allowedCmd) {
                        $allowed = true;
                        break;
                    }
                }
                
                if (!$allowed && strpos($command, "/") === 0) {
                    $player->sendMessage(TextFormat::RED . "Вы не можете использовать команды в PvP режиме!");
                    $event->cancel();
                }
            }
        }
    }

    public function isInPvPMode(Player $player): bool {
        return isset($this->pvpPlayers[$player->getName()]);
    }
    
    public function onDisable(): void {
        // Очищаем все BossBars при отключении плагина
        foreach ($this->bossBarIds as $playerName => $eid) {
            $player = $this->getServer()->getPlayerExact($playerName);
            if ($player instanceof Player && $player->isConnected()) {
                API::removeBossBar([$player], $eid);
            }
        }
        $this->bossBarIds = [];
        
        $this->getLogger()->info("PvPMode выключен!");
    }
}