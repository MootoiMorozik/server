<?php

namespace FlyPlugin;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;

class Main extends PluginBase {

    public function onEnable(): void {
        $this->getLogger()->info("FlyPlugin enabled!");
    }

    public function onDisable(): void {
        $this->getLogger()->info("FlyPlugin disabled!");
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if ($command->getName() === "fly") {
            
            // Проверяем, является ли отправитель оператором
            if (!$sender->hasPermission("fly.command")) {
                $sender->sendMessage(TextFormat::RED . "У вас нет прав на использование этой команды!");
                return true;
            }

            // Если команда вызвана из консоли и не указан игрок
            if (!$sender instanceof Player && count($args) === 0) {
                $sender->sendMessage(TextFormat::RED . "Используйте: /fly <player>");
                return true;
            }

            $target = $sender;
            
            // Если указан целевой игрок
            if (count($args) > 0) {
                $target = $this->getServer()->getPlayerByPrefix($args[0]);
                if ($target === null) {
                    $sender->sendMessage(TextFormat::RED . "Игрок " . $args[0] . " не найден или не в сети!");
                    return true;
                }
            }

            // Переключаем режим полета
            $this->toggleFlight($target, $sender);
            return true;
        }
        return false;
    }

    private function toggleFlight(Player $target, CommandSender $executor): void {
        $newState = !$target->getAllowFlight();
        $target->setAllowFlight($newState);
        
        // Для Linux/Windows совместимости - устанавливаем также flying если разрешено
        if ($newState) {
            $target->setFlying(true);
        } else {
            $target->setFlying(false);
        }

        $status = $newState ? TextFormat::GREEN . "включен" : TextFormat::RED . "выключен";
        
        // Сообщение цели
        $target->sendMessage(TextFormat::GOLD . "Режим полета " . $status . TextFormat::GOLD . "!");
        
        // Сообщение исполнителю (если это не сам игрок)
        if ($target !== $executor) {
            $executor->sendMessage(TextFormat::GOLD . "Режим полета для " . $target->getName() . " " . $status . TextFormat::GOLD . "!");
        }
    }
}