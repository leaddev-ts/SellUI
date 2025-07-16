<?php

namespace Lead\SellUI;

use pocketmine\plugin\PluginBase;
use pocketmine\player\Player;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\scheduler\ClosureTask;
use onebone\economyapi\EconomyAPI;
use jojoe77777\FormAPI\SimpleForm;

class SellUI extends PluginBase {

    private array $autoSell = [];
    private array $sellData = [];
    private string $logPath;
    private string $autosellPath;
    private array $lastInventories = [];

    public function onEnable(): void {
        @mkdir($this->getDataFolder());

        $sellFile = $this->getDataFolder() . "sell.yml";
        if (!file_exists($sellFile)) {
            file_put_contents($sellFile, yaml_emit([
                "minecraft:coal" => 120,
                "minecraft:raw_iron" => 130,
                "minecraft:iron_ingot" => 130,
                "minecraft:raw_gold" => 130,
                "minecraft:gold_ingot" => 130,
                "minecraft:raw_copper" => 135,
                "minecraft:copper_ingot" => 135,
                "minecraft:redstone" => 140,
                "minecraft:nether_quartz" => 145,
                "minecraft:lapis_lazuli" => 150,
                "minecraft:diamond" => 160,
                "minecraft:emerald" => 175,
                "minecraft:netherite_scrap" => 195,
                "minecraft:netherite_ingot" => 250,
                "minecraft:apple" => 75,
                "minecraft:wheat" => 150,
                "minecraft:carrot" => 250,
                "minecraft:potato" => 250,
                "minecraft:sugar_cane" => 250,
                "minecraft:beetroot" => 270,
                "minecraft:melon" => 320,
                "minecraft:pumpkin" => 2880,
                "minecraft:cocoa_beans" => 320,
                "minecraft:stone" => 75,
                "minecraft:cobblestone" => 75,
            ]));
        }

        $this->sellData = yaml_parse_file($sellFile) ?? [];
        $this->logPath = $this->getDataFolder() . "log.yml";
        if (!file_exists($this->logPath)) file_put_contents($this->logPath, yaml_emit([]));

        $this->autosellPath = $this->getDataFolder() . "autosell.yml";
        if (file_exists($this->autosellPath)) {
            $this->autoSell = yaml_parse_file($this->autosellPath);
        }

        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function(): void {
            foreach ($this->getServer()->getOnlinePlayers() as $player) {
                if (!empty($this->autoSell[$player->getName()])) {
                    $this->sellAutoItems($player);
                }
            }
        }), 20);
    }

    public function onDisable(): void {
        file_put_contents($this->autosellPath, yaml_emit($this->autoSell));
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if (!$sender instanceof Player) {
            $sender->sendMessage("§cGunakan perintah ini di dalam game.");
            return true;
        }

        if (!isset($args[0])) {
            if (!$sender->hasPermission("sellui.command.sell")) {
                $sender->sendMessage("§cKamu tidak punya izin untuk membuka menu jual.");
                return true;
            }
            $this->sendMainForm($sender);
            return true;
        }

        switch ($args[0]) {
            case "hand":
                return $this->hasPerm($sender, "sellui.command.hand") && $this->sellHand($sender);
            case "all":
                return $this->hasPerm($sender, "sellui.command.all") && $this->sellAll($sender);
            case "inv":
                return $this->hasPerm($sender, "sellui.command.inv") && $this->sellInventory($sender);
            case "autosell":
                return $this->hasPerm($sender, "sellui.command.autosell") && $this->toggleAutoSell($sender);
            case "log":
                return $this->hasPerm($sender, "sellui.command.log") && $this->showLog($sender);
            default:
                $this->sendMainForm($sender);
                return true;
        }
    }

    private function hasPerm(Player $player, string $perm): bool {
        if (!$player->hasPermission($perm)) {
            $player->sendMessage("§cKamu tidak punya izin untuk menggunakan perintah ini.");
            return false;
        }
        return true;
    }

    public function sendMainForm(Player $player): void {
        $form = new SimpleForm(function (Player $player, ?int $data): void {
            if ($data === null) return;

            switch ($data) {
                case 0:
                    $this->sellHand($player);
                    break;
                case 1:
                    $this->sellAll($player);
                    break;
                case 2:
                    $this->sellInventory($player);
                    break;
                case 3:
                    $this->toggleAutoSell($player);
                    break;
                case 4:
                    $this->showLog($player);
                    break;
            }
        });

        $form->setTitle("§l§aSell Menu");
        $form->addButton("§l§b• Jual Item di Tangan •");
        $form->addButton("§l§6• Jual Semua Item Serupa •");
        $form->addButton("§l§e• Jual Isi Inventory •");
        $form->addButton("§l§c• Auto Sell: " . (!empty($this->autoSell[$player->getName()]) ? "ON" : "OFF"));
        $form->addButton("§l§d• Lihat Log Penjualan •");
        $player->sendForm($form);
    }

    public function sellHand(Player $player): bool {
        $item = $player->getInventory()->getItemInHand();
        return $this->sellItems($player, [$item], "item di tangan");
    }

    public function sellAll(Player $player): bool {
        $inventory = $player->getInventory();
        $handItem = $inventory->getItemInHand();
        $targetKey = "minecraft:" . strtolower(str_replace(" ", "_", $handItem->getVanillaName()));

        if (!isset($this->sellData[$targetKey])) {
            $player->sendMessage("§cItem di tangan tidak dapat dijual.");
            return false;
        }

        $itemsToSell = [];

        foreach ($inventory->getContents() as $item) {
            $key = "minecraft:" . strtolower(str_replace(" ", "_", $item->getVanillaName()));
            if ($key === $targetKey && $item->getCount() > 0) {
                $itemsToSell[] = $item;
            }
        }

        return $this->sellItems($player, $itemsToSell, "semua item serupa di hotbar dan inventory");
    }

    public function sellInventory(Player $player): bool {
        $items = $player->getInventory()->getContents();
        return $this->sellItems($player, $items, "inventory");
    }

    public function toggleAutoSell(Player $player): bool {
        $name = $player->getName();
        if (!empty($this->autoSell[$name])) {
            unset($this->autoSell[$name]);
            $player->sendMessage("§cAuto Sell dimatikan.");
        } else {
            $this->autoSell[$name] = true;
            $player->sendMessage("§aAuto Sell diaktifkan.");
        }
        file_put_contents($this->autosellPath, yaml_emit($this->autoSell));
        return true;
    }

    public function showLog(Player $player): bool {
        $logs = yaml_parse_file($this->logPath);
        $name = $player->getName();
        $msg = "§eLog Penjualan:\n";
        if (isset($logs[$name])) {
            foreach ($logs[$name] as $entry) {
                $msg .= "- $entry\n";
            }
        } else {
            $msg .= "Belum ada data.";
        }
        $player->sendMessage($msg);
        return true;
    }

    private function sellItems(Player $player, array $items, string $context = "", bool $silentIfEmpty = false): bool {
        $total = 0;
        $inventory = $player->getInventory();

        foreach ($items as $item) {
            $key = "minecraft:" . strtolower(str_replace(" ", "_", $item->getVanillaName()));
            $price = $this->sellData[$key] ?? null;
            if ($price !== null && $item->getCount() > 0) {
                $amount = $item->getCount();
                $total += $price * $amount;
                $inventory->removeItem($item);
            }
        }

        if ($total > 0) {
            EconomyAPI::getInstance()->addMoney($player, $total);
            $player->sendMessage("§aBerhasil menjual $context senilai §6$" . number_format($total));
            $this->logSell($player, $context, $total);
            return true;
        } else {
            if (!$silentIfEmpty) {
                $player->sendMessage("§cTidak ada item yang dapat dijual.");
            }
            return false;
        }
    }

    private function sellAutoItems(Player $player): void {
        $name = $player->getName();
        $inventory = $player->getInventory()->getContents();

        $currentHash = md5(json_encode(array_map(fn($item) => $item->getName() . ":" . $item->getCount(), $inventory)));

        if (!isset($this->lastInventories[$name])) {
            $this->lastInventories[$name] = $currentHash;
            return;
        }

        if ($this->lastInventories[$name] === $currentHash) {
            return;
        }

        $this->lastInventories[$name] = $currentHash;
        $this->sellItems($player, $inventory, "auto-sell", true);
    }

    private function logSell(Player $player, string $context, float $amount): void {
        $logs = yaml_parse_file($this->logPath);
        $name = $player->getName();
        $logs[$name][] = date("d/m/Y H:i") . " menjual $context senilai $" . number_format($amount);
        file_put_contents($this->logPath, yaml_emit($logs));
    }
}
