<?php

declare(strict_types=1);

namespace ChatPerms;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\player\Player;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\permission\Permission;
use pocketmine\permission\PermissionManager;
use pocketmine\utils\Config;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\utils\TextFormat;
use pocketmine\scheduler\ClosureTask;
use pocketmine\event\entity\EntityDamageEvent;

class Main extends PluginBase implements Listener {
    private $config;
    private $groups;
    private $playerGroups;
    private $playerAttachments = [];
    private $factionPlugin = null;
    private $messages;
    
    private $lastChatTime = [];
    private $chatStats;
    private $tempGroups;
    /** @var array<string, array{message:string,label:string,type:string,expires:float}> */
    private array $chatBubbles = [];

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveDefaultConfig();
        $this->config = $this->getConfig();
        $this->migrateDisplayConfig();
        $this->groups = $this->config->get("groups", []);
        $this->playerGroups = new Config($this->getDataFolder() . "players.yml", Config::YAML);
        $this->chatStats = new Config($this->getDataFolder() . "chat_stats.yml", Config::YAML);
        $this->tempGroups = new Config($this->getDataFolder() . "temp_groups.yml", Config::YAML);
        
        if (!$this->config->exists("messages")) {
            $this->config->set("messages", [
                "no_permission" => "§cYou don't have permission to use this command.",
                "player_not_found" => "§cPlayer not found.",
                "group_not_found" => "§cGroup not found.",
                "group_already_exists" => "§cGroup already exists.",
                "permission_already_exists" => "§cPermission already exists in the group.",
                "permission_not_found" => "§cPermission not found in the group.",
                "group_created" => "§aGroup §6{GROUP} §ahas been created.",
                "group_removed" => "§aGroup §6{GROUP} §ahas been removed.",
                "player_group_set" => "§aPlayer §b{PLAYER} §ahas been set to group §6{GROUP}",
                "permission_added" => "§aPermission §b{PERMISSION} §ahas been added to group §6{GROUP}",
                "permission_removed" => "§aPermission §b{PERMISSION} §ahas been removed from group §6{GROUP}",
                "faction_detected" => "§aDetected faction plugin: {PLUGIN}",
                "no_faction_detected" => "§cNo compatible faction plugin detected.",
                "unknown_command" => "§cUnknown command: {COMMAND}. Use /cp help for help.",
                "chat_cooldown" => "§cPlease wait {SECONDS} more seconds before sending another message.",
                "invalid_duration" => "§cInvalid duration format. Use examples: 1m, 5h, 1d, 1w",
                "tempgroup_expired" => "§aYour temporary group §6{GROUP} §ahas expired. You have been restored to your original group."
            ]);
            $this->config->save();
        }
        
        $this->messages = $this->config->get("messages");

        $this->registerPermissions();
        $this->startNameTagUpdateTask();
        $this->startTempGroupCheckTask();
        
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(
            function(): void {
                $this->detectFactionPlugin();
            }
        ), 20);

        if (!$this->config->exists("rename_nametag")) {
            $this->config->set("rename_nametag", true);
            $this->config->save();
        }
    }
    private function migrateDisplayConfig(): void {
        $changed = false;

        $defaults = [
            "global_chat_format" => "{PLATFORM} {GROUP} {CLAN} §f{PLAYER} §f §f{MESSAGE}",
            "use_global_chat_format" => true,
            "chat_channels_enabled" => true,
            "local_chat_radius" => 150,
            "global_chat_prefix" => "!",
            "local_chat_label" => "§eⓁ",
            "global_chat_label" => "§aⒼ",
            "chat_label_separator" => " ",
            "nametag_chat_bubble" => [
                "enabled" => true,
                "duration-seconds" => 6,
                "placement" => "below",
                "blank-lines-between" => 1,
                "max-lines" => 2,
                "max-characters-per-line" => 28,
                "max-message-characters" => 80,
                "strip-message-colors" => true,
                "type-names" => ["local" => "Локальный", "global" => "Глобальный"],
                "type-icons" => ["local" => "§eⓁ", "global" => "§aⒼ"],
                "first-line-format" => "{CHAT_ICON} §7{CHAT_TYPE}: §f{MESSAGE}",
                "continuation-line-format" => "§f{MESSAGE}"
            ],
            "global_nametag_format" => "{PLATFORM} {GROUP} {CLAN} §f{PLAYER}",
            "use_global_nametag_format" => true,
            "group_tag_format" => "§f[§{GROUP_COLOR}{GROUP_NAME}§f]",
            "rename_nametag" => true,
            "empty_clan_tag" => "",
            "clan_tag_format" => "§f[{CLAN_NAME}§f]",
            "platform_enabled" => true,
            "platform_icon_format" => "{ICON}",
            "platform_fallback" => "windows",
            "platform_debug" => false,
            "platform_overrides" => [],
            "platform_icons" => [
                "apple" => "U+E2F2",
                "playstation" => "U+E2F3",
                "xbox" => "U+E2F4",
                "windows" => "U+E2F5",
                "android" => "U+E2F6"
            ],
            "platform_device_os_map" => [
                1 => "android", 2 => "apple", 3 => "apple", 4 => "android",
                7 => "windows", 8 => "windows", 10 => "apple",
                11 => "playstation", 13 => "xbox", 14 => "windows"
            ]
        ];

        foreach ($defaults as $key => $value) {
            if (!$this->config->exists($key)) {
                $this->config->set($key, $value);
                $changed = true;
            }
        }

        // v1.5.2: используем исходную страницу InMine glyph_E2.png.
        // Эти пять ячеек уже содержат нужные платформы и стабильно работают на Windows/Android.
        $glyphLayout = (string) $this->config->get("platform_glyph_layout", "");
        if ($glyphLayout !== "inmine-e2-mobile-v5") {
            $this->config->set("platform_icons", [
                "apple" => "U+E2F2",
                "playstation" => "U+E2F3",
                "xbox" => "U+E2F4",
                "windows" => "U+E2F5",
                "android" => "U+E2F6"
            ]);
            $this->config->set("platform_glyph_layout", "inmine-e2-mobile-v5");
            // Последний резерв для неизвестного DeviceOS. На обычных клиентах
            // используется реальный DeviceOS из PlayerInfo->extraData.
            if ((string) $this->config->get("platform_fallback", "") === "") {
                $this->config->set("platform_fallback", "windows");
            }
            $changed = true;
        }

        $groupTagFormat = (string) $this->config->get("group_tag_format", "");
        if ($groupTagFormat === "" || $groupTagFormat === "§{GROUP_COLOR}[{GROUP_NAME}]") {
            $this->config->set("group_tag_format", "§f[§{GROUP_COLOR}{GROUP_NAME}§f]");
            $changed = true;
        }

        if ((string) $this->config->get("empty_clan_tag", "") === "§8[Без клана]") {
            $this->config->set("empty_clan_tag", "");
            $changed = true;
        }
        $clanTagFormat = (string) $this->config->get("clan_tag_format", "");
        if ($clanTagFormat === "§7[§b{CLAN_NAME}§7]" || $clanTagFormat === "§f[{CLAN_NAME}]") {
            // Закрывающая скобка всегда белая, даже если название клана цветное.
            $this->config->set("clan_tag_format", "§f[{CLAN_NAME}§f]");
            $changed = true;
        }
        if ((string) $this->config->get("global_nametag_format", "") === "{PLATFORM} {CLAN} {GROUP} §f{PLAYER}") {
            $this->config->set("global_nametag_format", "{PLATFORM} {GROUP} {CLAN} §f{PLAYER}");
            $changed = true;
        }

        // v1.5.5: заменяем обычный >, двоеточие или » между ником и сообщением
        // на ресурсный символ U+E216 ().
        $chatFormat = (string) $this->config->get("global_chat_format", "");
        $newChatFormat = (string) preg_replace(
            '/\{PLAYER\}\s*(?:§[0-9a-fklmnor]\s*)*(?:>|:|»)(?:\s*§[0-9a-fklmnor])*\s*\{MESSAGE\}/iu',
            '{PLAYER} §f §f{MESSAGE}',
            $chatFormat
        );
        if($newChatFormat === $chatFormat && !str_contains($chatFormat, "")){
            $newChatFormat = "{PLATFORM} {GROUP} {CLAN} §f{PLAYER} §f §f{MESSAGE}";
        }
        if($newChatFormat !== $chatFormat){
            $this->config->set("global_chat_format", $newChatFormat);
            $changed = true;
        }

        $messages = (array) $this->config->get("messages", []);
        if (!isset($messages["empty_chat_message"])) {
            $messages["empty_chat_message"] = "§cПосле знака глобального чата напишите сообщение.";
            $changed = true;
        }
        foreach ([
            "player_muted",
            "player_muted_permanent",
            "mute_success",
            "mute_success_permanent",
            "unmute_success",
            "player_not_muted",
            "already_muted"
        ] as $obsoleteMessage) {
            if (array_key_exists($obsoleteMessage, $messages)) {
                unset($messages[$obsoleteMessage]);
                $changed = true;
            }
        }
        $this->config->set("messages", $messages);

        $groups = (array) $this->config->get("groups", []);
        foreach ($groups as $groupName => &$groupData) {
            $groupData = (array) $groupData;

            if (!isset($groupData["display_name"])) {
                $groupData["display_name"] = ucfirst((string) $groupName);
                $changed = true;
            }
            if (!isset($groupData["color"])) {
                $source = (string) ($groupData["nametag_format"] ?? $groupData["chat_format"] ?? "");
                $groupData["color"] = preg_match('/§([0-9a-f])/i', $source, $matches) === 1 ? strtolower($matches[1]) : "f";
                $changed = true;
            }
            if (!isset($groupData["permissions"]) || !is_array($groupData["permissions"])) {
                $groupData["permissions"] = [];
                $changed = true;
            }
            if (!isset($groupData["chat_cooldown"])) {
                $groupData["chat_cooldown"] = 0;
                $changed = true;
            }

            foreach ($groupData as $key => $value) {
                if ($value === null && !in_array($key, ["inherits"], true)) {
                    unset($groupData[$key]);
                    $changed = true;
                }
            }
        }
        unset($groupData);

        // Исправляет структуру из старого конфига, где elite/legend/... случайно
        // оказались null-полями внутри arxont вместо отдельных групп.
        $rankTemplates = [
            "default" => ["display_name" => "Игрок", "color" => "f", "permissions" => ["chatperms.basic"], "chat_cooldown" => 3],
            "vip" => ["display_name" => "VIP", "color" => "a", "inherits" => "default", "permissions" => ["chatperms.vip"], "chat_cooldown" => 3],
            "premium" => ["display_name" => "Premium", "color" => "b", "inherits" => "vip", "permissions" => ["chatperms.premium"], "chat_cooldown" => 2],
            "delux" => ["display_name" => "Deluxe", "color" => "6", "inherits" => "premium", "permissions" => ["chatperms.delux"], "chat_cooldown" => 1],
            "knight" => ["display_name" => "Рыцарь", "color" => "3", "inherits" => "delux", "permissions" => ["chatperms.knight"], "chat_cooldown" => 1],
            "arxont" => ["display_name" => "Архонт", "color" => "9", "inherits" => "knight", "permissions" => ["chatperms.arxont"], "chat_cooldown" => 0],
            "elite" => ["display_name" => "Элита", "color" => "d", "inherits" => "arxont", "permissions" => ["chatperms.elite"], "chat_cooldown" => 0],
            "legend" => ["display_name" => "Легенда", "color" => "e", "inherits" => "elite", "permissions" => ["chatperms.legend"], "chat_cooldown" => 0],
            "vlastelin" => ["display_name" => "Властелин", "color" => "5", "inherits" => "legend", "permissions" => ["chatperms.vlastelin"], "chat_cooldown" => 0],
            "titan" => ["display_name" => "Титан", "color" => "c", "inherits" => "vlastelin", "permissions" => ["chatperms.titan"], "chat_cooldown" => 0],
            "demiurg" => ["display_name" => "Демиург", "color" => "4", "inherits" => "titan", "permissions" => ["chatperms.demiurg"], "chat_cooldown" => 0],
            "povelitel" => ["display_name" => "Повелитель", "color" => "5", "inherits" => "demiurg", "permissions" => ["chatperms.povelitel"], "chat_cooldown" => 0]
        ];

        if (isset($groups["arxont"]) && is_array($groups["arxont"])) {
            foreach (["elite", "legend", "vlatelend", "titan", "demiyrg", "povelitel"] as $badKey) {
                if (array_key_exists($badKey, $groups["arxont"]) && $groups["arxont"][$badKey] === null) {
                    unset($groups["arxont"][$badKey]);
                    $changed = true;
                }
            }
            if (($groups["arxont"]["inherits"] ?? "") === "demiyrg") {
                $groups["arxont"]["inherits"] = "knight";
                $changed = true;
            }
            if (($groups["arxont"]["permissions"] ?? []) === ["chatperms.povelitel"]) {
                $groups["arxont"]["permissions"] = ["chatperms.arxont"];
                $changed = true;
            }
        }

        foreach ($rankTemplates as $rankId => $template) {
            if (!isset($groups[$rankId])) {
                $groups[$rankId] = $template;
                $changed = true;
            }
        }

        if ($changed) {
            $this->config->set("groups", $groups);
            $this->config->save();
        }
    }

    private function detectFactionPlugin(): void {
        $pluginManager = $this->getServer()->getPluginManager();
        $factionPlugins = ['FactionsPro', 'PiggyFactions', 'SimpleFaction', 'Factions'];
        foreach ($factionPlugins as $pluginName) {
            $plugin = $pluginManager->getPlugin($pluginName);
            if ($plugin !== null && $plugin->isEnabled()) {
                $this->factionPlugin = $plugin;
                $this->getLogger()->info($this->getMessage("faction_detected", ["PLUGIN" => $pluginName]));
                return;
            }
        }
        $this->getLogger()->info($this->getMessage("no_faction_detected"));
    }

    private function startNameTagUpdateTask(): void {
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(
            function(): void {
                foreach ($this->getServer()->getOnlinePlayers() as $player) {
                    $this->updatePlayerNameTag($player);
                }
            }
        ), max(1, (int) $this->config->getNested("nametag_update_period_ticks", 20)));
    }

    private function registerPermissions(): void {
        $permManager = PermissionManager::getInstance();
        foreach ($this->groups as $group => $data) {
            $groupPermName = "chatperms.group." . $group;
            if ($permManager->getPermission($groupPermName) === null) {
                $permManager->addPermission(new Permission($groupPermName));
            }
            foreach ($data['permissions'] as $perm) {
                if ($permManager->getPermission($perm) === null) {
                    $permManager->addPermission(new Permission($perm));
                }
            }
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        $commandName = strtolower($command->getName());
        if ($commandName === "amyxglyph") {
            return $this->handleGlyphTestCommand($sender);
        }
        if ($commandName === "amyxplatform") {
            return $this->handlePlatformCommand($sender, $args);
        }
        if ($commandName !== "cp" && $commandName !== "chatperms") {
            return false;
        }
        if (count($args) === 0) {
            return $this->handleHelpCommand($sender);
        }
        $subCommand = strtolower(array_shift($args));
        switch($subCommand) {
            case "setgroup":
                return $this->handleSetGroupCommand($sender, $args);
            case "creategroup":
                return $this->handleCreateGroupCommand($sender, $args);
            case "removegroup":
                return $this->handleRemoveGroupCommand($sender, $args);
            case "addgroupperm":
                return $this->handleAddGroupPermCommand($sender, $args);
            case "removegroupperm":
                return $this->handleRemoveGroupPermCommand($sender, $args);
            case "listgroups":
                return $this->handleListGroupsCommand($sender, $args);
            case "checkplayer":
                return $this->handleCheckPlayerCommand($sender, $args);
            case "platform":
                return $this->handlePlatformCommand($sender, $args);
            case "glyphtest":
                return $this->handleGlyphTestCommand($sender);
            case "stats":
                return $this->handleStatsCommand($sender, $args);
            case "tempgroup":
                return $this->handleTempGroupCommand($sender, $args);
            case "tempgroupcheck":
                return $this->handleTempGroupCheckCommand($sender, $args);
            case "help":
                return $this->handleHelpCommand($sender);
            default:
                $sender->sendMessage($this->getMessage("unknown_command", ["COMMAND" => $subCommand]));
                return true;
        }
    }
    private function parseDuration(string $duration): int|false {
        if (!preg_match('/^(\d+)([smhdw])$/', $duration, $matches)) {
            return false;
        }

        $value = (int)$matches[1];
        $unit = $matches[2];

        return match($unit) {
            's' => $value,
            'm' => $value * 60,
            'h' => $value * 3600,
            'd' => $value * 86400,
            'w' => $value * 604800,
            default => false
        };
    }

    private function formatTime(int $seconds): string {
        if ($seconds < 60) {
            return $seconds . " seconds";
        } elseif ($seconds < 3600) {
            return round($seconds / 60) . " minutes";
        } elseif ($seconds < 86400) {
            return round($seconds / 3600) . " hours";
        } elseif ($seconds < 604800) {
            return round($seconds / 86400) . " days";
        } else {
            return round($seconds / 604800) . " weeks";
        }
    }

    private function handleSetGroupCommand(CommandSender $sender, array $args): bool {
        if (!$sender->hasPermission("chatperms.command.setgroup")) {
            $sender->sendMessage($this->getMessage("no_permission"));
            return true;
        }
        if (count($args) !== 2) {
            $sender->sendMessage(TextFormat::YELLOW . "Usage: " . TextFormat::WHITE . "/cp setgroup <player> <group>");
            return true;
        }
        $player = $this->getServer()->getPlayerExact($args[0]);
        if ($player === null) {
            $sender->sendMessage($this->getMessage("player_not_found"));
            return true;
        }
        $group = $args[1];
        if (!isset($this->groups[$group])) {
            $sender->sendMessage($this->getMessage("group_not_found"));
            return true;
        }
        $this->setPlayerGroup($player, $group);
        $sender->sendMessage($this->getMessage("player_group_set", [
            "PLAYER" => $player->getName(),
            "GROUP" => $group
        ]));
        return true;
    }

    private function handleCreateGroupCommand(CommandSender $sender, array $args): bool {
        if (!$sender->hasPermission("chatperms.command.creategroup")) {
            $sender->sendMessage($this->getMessage("no_permission"));
            return true;
        }
        if (count($args) < 3) {
            $sender->sendMessage(TextFormat::YELLOW . "Usage: " . TextFormat::WHITE . "/cp creategroup <group> <chat_format> <nametag_format> [permissions...]");
            return true;
        }
        $group = $args[0];
        $chatFormat = $args[1];
        $nametagFormat = $args[2];
        $permissions = array_slice($args, 3);

        if (isset($this->groups[$group])) {
            $sender->sendMessage($this->getMessage("group_already_exists"));
            return true;
        }

        $this->groups[$group] = [
            'chat_format' => $chatFormat,
            'nametag_format' => $nametagFormat,
            'permissions' => $permissions,
            'chat_cooldown' => 0
        ];

        $this->config->set("groups", $this->groups);
        $this->config->save();

        $this->registerPermissions();

        $sender->sendMessage($this->getMessage("group_created", ["GROUP" => $group]));
        return true;
    }

    private function handleRemoveGroupCommand(CommandSender $sender, array $args): bool {
        if (!$sender->hasPermission("chatperms.command.removegroup")) {
            $sender->sendMessage($this->getMessage("no_permission"));
            return true;
        }
        if (count($args) !== 1) {
            $sender->sendMessage(TextFormat::YELLOW . "Usage: " . TextFormat::WHITE . "/cp removegroup <group>");
            return true;
        }
        $group = $args[0];
        if (!isset($this->groups[$group])) {
            $sender->sendMessage($this->getMessage("group_not_found"));
            return true;
        }
        
        $playersMovedToDefault = 0;
        foreach ($this->playerGroups->getAll() as $playerName => $playerGroup) {
            if ($playerGroup === $group) {
                $this->playerGroups->set($playerName, "default");
                $playersMovedToDefault++;
                
                $onlinePlayer = $this->getServer()->getPlayerExact($playerName);
                if ($onlinePlayer !== null) {
                    $this->updatePlayerPermissions($onlinePlayer);
                    $this->updatePlayerNameTag($onlinePlayer);
                }
            }
        }
        $this->playerGroups->save();
        
        unset($this->groups[$group]);
        $this->config->set("groups", $this->groups);
        $this->config->save();
        
        $sender->sendMessage($this->getMessage("group_removed", ["GROUP" => $group]));
        if ($playersMovedToDefault > 0) {
            $sender->sendMessage(TextFormat::YELLOW . "Moved $playersMovedToDefault player(s) to default group.");
        }
        return true;
    }

    private function handleAddGroupPermCommand(CommandSender $sender, array $args): bool {
        if (!$sender->hasPermission("chatperms.command.addgroupperm")) {
            $sender->sendMessage($this->getMessage("no_permission"));
            return true;
        }
        if (count($args) !== 2) {
            $sender->sendMessage(TextFormat::YELLOW . "Usage: " . TextFormat::WHITE . "/cp addgroupperm <group> <permission>");
            return true;
        }
        $group = $args[0];
        $permission = $args[1];
        if (!isset($this->groups[$group])) {
            $sender->sendMessage($this->getMessage("group_not_found"));
            return true;
        }
        if (in_array($permission, $this->groups[$group]['permissions'])) {
            $sender->sendMessage($this->getMessage("permission_already_exists"));
            return true;
        }
        $this->groups[$group]['permissions'][] = $permission;
        $this->config->set("groups", $this->groups);
        $this->config->save();
        $this->registerPermissions();
        
        foreach ($this->getServer()->getOnlinePlayers() as $onlinePlayer) {
            if ($this->getPlayerGroup($onlinePlayer) === $group) {
                $this->updatePlayerPermissions($onlinePlayer);
            }
        }
        
        $sender->sendMessage($this->getMessage("permission_added", [
            "PERMISSION" => $permission,
            "GROUP" => $group
        ]));
        return true;
    }

    private function handleRemoveGroupPermCommand(CommandSender $sender, array $args): bool {
        if (!$sender->hasPermission("chatperms.command.removegroupperm")) {
            $sender->sendMessage($this->getMessage("no_permission"));
            return true;
        }
        if (count($args) !== 2) {
            $sender->sendMessage(TextFormat::YELLOW . "Usage: " . TextFormat::WHITE . "/cp removegroupperm <group> <permission>");
            return true;
        }
        $group = $args[0];
        $permission = $args[1];
        if (!isset($this->groups[$group])) {
            $sender->sendMessage($this->getMessage("group_not_found"));
            return true;
        }
        $key = array_search($permission, $this->groups[$group]['permissions']);
        if ($key === false) {
            $sender->sendMessage($this->getMessage("permission_not_found"));
            return true;
        }
        unset($this->groups[$group]['permissions'][$key]);
        $this->config->set("groups", $this->groups);
        $this->config->save();
        $this->registerPermissions();
        
        foreach ($this->getServer()->getOnlinePlayers() as $onlinePlayer) {
            if ($this->getPlayerGroup($onlinePlayer) === $group) {
                $this->updatePlayerPermissions($onlinePlayer);
            }
        }
        
        $sender->sendMessage($this->getMessage("permission_removed", [
            "PERMISSION" => $permission,
            "GROUP" => $group
        ]));
        return true;
    }

    private function handleListGroupsCommand(CommandSender $sender, array $args): bool {
        if (!$sender->hasPermission("chatperms.command.listgroups")) {
            $sender->sendMessage($this->getMessage("no_permission"));
            return true;
        }
        
        if (empty($this->groups)) {
            $sender->sendMessage(TextFormat::YELLOW . "No groups are currently defined.");
            return true;
        }
        
        $sender->sendMessage(TextFormat::YELLOW . "=== ChatPerms Groups ===");
        
        foreach ($this->groups as $groupName => $groupData) {
            $sender->sendMessage("");
            $sender->sendMessage(TextFormat::GREEN . "Group: " . TextFormat::AQUA . $groupName);
            $sender->sendMessage(TextFormat::GRAY . "Chat Format: " . TextFormat::WHITE . ($groupData['chat_format'] ?? 'Not set'));
            $sender->sendMessage(TextFormat::GRAY . "Nametag Format: " . TextFormat::WHITE . ($groupData['nametag_format'] ?? 'Not set'));
            $sender->sendMessage(TextFormat::GRAY . "Chat Cooldown: " . TextFormat::WHITE . ($groupData['chat_cooldown'] ?? 0) . " seconds");
            
            $permissions = $groupData['permissions'] ?? [];
            if (!empty($permissions)) {
                $sender->sendMessage(TextFormat::GRAY . "Permissions: " . TextFormat::WHITE . implode(", ", $permissions));
            } else {
                $sender->sendMessage(TextFormat::GRAY . "Permissions: " . TextFormat::WHITE . "None");
            }
            
            $playersInGroup = 0;
            foreach ($this->playerGroups->getAll() as $playerName => $playerGroup) {
                if ($playerGroup === $groupName) {
                    $playersInGroup++;
                }
            }
            $sender->sendMessage(TextFormat::GRAY . "Players in group: " . TextFormat::WHITE . $playersInGroup);
        }
        
        return true;
    }

    private function handleCheckPlayerCommand(CommandSender $sender, array $args): bool {
        if (!$sender->hasPermission("chatperms.command.checkplayer")) {
            $sender->sendMessage($this->getMessage("no_permission"));
            return true;
        }
        
        if (count($args) !== 1) {
            $sender->sendMessage(TextFormat::YELLOW . "Usage: " . TextFormat::WHITE . "/cp checkplayer <player>");
            return true;
        }
        
        $player = $this->getServer()->getPlayerExact($args[0]);
        if ($player === null) {
            $sender->sendMessage($this->getMessage("player_not_found"));
            return true;
        }
        
        $group = $this->getPlayerGroup($player);
        $groupData = $this->groups[$group] ?? [];
        $directPermissions = $groupData['permissions'] ?? [];
        $allPermissions = $this->getAllGroupPermissions($group);
        
        $sender->sendMessage(TextFormat::YELLOW . "=== Player Info: " . TextFormat::AQUA . $player->getName() . TextFormat::YELLOW . " ===");
        $sender->sendMessage(TextFormat::GRAY . "Group: " . TextFormat::WHITE . $group);
        
        $inheritanceChain = $this->getInheritanceChain($group);
        if (count($inheritanceChain) > 1) {
            $sender->sendMessage(TextFormat::GRAY . "Inheritance: " . TextFormat::WHITE . implode(" → ", $inheritanceChain));
        }
        
        $sender->sendMessage(TextFormat::GRAY . "Direct Permissions: " . TextFormat::WHITE . (empty($directPermissions) ? "None" : implode(", ", $directPermissions)));
        $sender->sendMessage(TextFormat::GRAY . "All Permissions: " . TextFormat::WHITE . (empty($allPermissions) ? "None" : implode(", ", $allPermissions)));
        
        $hasAttachment = isset($this->playerAttachments[$player->getName()]);
        $sender->sendMessage(TextFormat::GRAY . "Has Permission Attachment: " . TextFormat::WHITE . ($hasAttachment ? "Yes" : "No"));
        
        $testPermissions = array_merge($allPermissions, ["chatperms.basic", "chatperms.vip", "chatperms.admin"]);
        $sender->sendMessage(TextFormat::GRAY . "Permission Tests:");
        foreach (array_unique($testPermissions) as $perm) {
            $hasPermission = $player->hasPermission($perm);
            $color = $hasPermission ? TextFormat::GREEN : TextFormat::RED;
            $status = $hasPermission ? "✓" : "✗";
            $sender->sendMessage(TextFormat::WHITE . "  " . $perm . ": " . $color . $status);
        }
        
        return true;
    }

    private function getInheritanceChain(string $group): array {
        $chain = [];
        $current = $group;
        $visited = [];

        while ($current && !in_array($current, $visited)) {
            $visited[] = $current;
            $chain[] = $current;
            $current = $this->groups[$current]['inherits'] ?? null;
        }

        return array_reverse($chain);
    }
    
    private function handlePlatformCommand(CommandSender $sender, array $args): bool {
        if (!$sender->hasPermission("chatperms.command.platform")) {
            $sender->sendMessage($this->getMessage("no_permission"));
            return true;
        }

        $player = null;
        if (isset($args[0])) {
            $player = $this->getServer()->getPlayerExact($args[0]);
            if ($player === null) {
                $sender->sendMessage($this->getMessage("player_not_found"));
                return true;
            }
        } elseif ($sender instanceof Player) {
            $player = $sender;
        }

        if (!$player instanceof Player) {
            $sender->sendMessage(TextFormat::YELLOW . "Использование: /cp platform <игрок>");
            return true;
        }

        $platform = $this->getPlatformData($player);
        $icon = (string) $platform["icon"];
        $iconCode = "нет";

        $cleanIcon = TextFormat::clean($icon);
        if ($cleanIcon !== "") {
            $first = mb_substr($cleanIcon, 0, 1, "UTF-8");
            if ($first !== "") {
                $iconCode = "U+" . strtoupper(str_pad(dechex(mb_ord($first, "UTF-8")), 4, "0", STR_PAD_LEFT));
            }
        }

        $sender->sendMessage(TextFormat::YELLOW . "=== Платформа " . TextFormat::AQUA . $player->getName() . TextFormat::YELLOW . " ===");
        $sender->sendMessage(TextFormat::GRAY . "DeviceOS: " . TextFormat::WHITE . (string) $platform["device_os"] . " (raw: " . ((string) $platform["device_os_raw"] !== "" ? (string) $platform["device_os_raw"] : "не передано") . ")");
        $sender->sendMessage(TextFormat::GRAY . "DeviceModel: " . TextFormat::WHITE . ((string) $platform["device_model"] !== "" ? (string) $platform["device_model"] : "не передано"));
        $sender->sendMessage(TextFormat::GRAY . "Ключи extraData: " . TextFormat::WHITE . ((string) $platform["extra_keys"] !== "" ? (string) $platform["extra_keys"] : "нет"));
        $sender->sendMessage(TextFormat::GRAY . "Определено: " . TextFormat::WHITE . (string) $platform["name"] . " (" . (string) $platform["key"] . ")");
        $sender->sendMessage(TextFormat::GRAY . "Unicode: " . TextFormat::WHITE . $iconCode);
        $sender->sendMessage(TextFormat::GRAY . "Проверка значка: " . TextFormat::WHITE . ($icon !== "" ? $icon . " отображается здесь" : "значок не выбран"));

        return true;
    }

    private function handleGlyphTestCommand(CommandSender $sender): bool {
        if (!$sender->hasPermission("chatperms.command.glyphtest")) {
            $sender->sendMessage($this->getMessage("no_permission"));
            return true;
        }

        $tests = [
            "E2F2" => "Apple",
            "E2F3" => "PlayStation",
            "E2F4" => "Xbox",
            "E2F5" => "Windows",
            "E2F6" => "Android"
        ];

        $sender->sendMessage(TextFormat::YELLOW . "=== Проверка AmyX glyph_E2.png ===");
        $sender->sendMessage(TextFormat::GRAY . "Должно быть 5 значков. Команда работает без OP: /amyxglyph");
        foreach ($tests as $hex => $name) {
            $char = mb_chr(hexdec($hex), "UTF-8");
            $sender->sendMessage(TextFormat::WHITE . "U+" . $hex . " " . TextFormat::AQUA . $name . TextFormat::WHITE . ": " . $char);
        }
        return true;
    }

    private function handleHelpCommand(CommandSender $sender): bool {
        $sender->sendMessage(TextFormat::YELLOW . "ChatPerms Plugin Commands:");
        $sender->sendMessage(TextFormat::GREEN . "/cp setgroup " . TextFormat::AQUA . "<player> <group>" . TextFormat::WHITE . " - Set a player's group");
        $sender->sendMessage(TextFormat::GREEN . "/cp creategroup " . TextFormat::AQUA . "<group> <chat_format> <nametag_format> [permissions...]" . TextFormat::WHITE . " - Create a new group");
        $sender->sendMessage(TextFormat::GREEN . "/cp removegroup " . TextFormat::AQUA . "<group>" . TextFormat::WHITE . " - Remove a group");
        $sender->sendMessage(TextFormat::GREEN . "/cp addgroupperm " . TextFormat::AQUA . "<group> <permission>" . TextFormat::WHITE . " - Add a permission to a group");
        $sender->sendMessage(TextFormat::GREEN . "/cp removegroupperm " . TextFormat::AQUA . "<group> <permission>" . TextFormat::WHITE . " - Remove a permission from a group");
        $sender->sendMessage(TextFormat::GREEN . "/cp listgroups" . TextFormat::WHITE . " - List all groups");
        $sender->sendMessage(TextFormat::GREEN . "/cp checkplayer " . TextFormat::AQUA . "<player>" . TextFormat::WHITE . " - Check a player's permissions");
        $sender->sendMessage(TextFormat::GREEN . "/cp platform " . TextFormat::AQUA . "[player]" . TextFormat::WHITE . " - Проверить DeviceOS и значок платформы");
        $sender->sendMessage(TextFormat::GREEN . "/cp glyphtest" . TextFormat::WHITE . " - Показать U+E2F2–U+E2F6 для проверки ресурс-пака");
        $sender->sendMessage(TextFormat::GREEN . "/cp stats " . TextFormat::AQUA . "[player]" . TextFormat::WHITE . " - Show chat statistics");
        $sender->sendMessage(TextFormat::GREEN . "/cp tempgroup " . TextFormat::AQUA . "<player> <group> <duration> [reason]" . TextFormat::WHITE . " - Create a temporary group");
        $sender->sendMessage(TextFormat::GREEN . "/cp tempgroupcheck" . TextFormat::WHITE . " - Check temporary group status");
        $sender->sendMessage(TextFormat::GREEN . "/cp help" . TextFormat::WHITE . " - Show this help message");
        return true;
    }

    public function supportsClanChannel() : bool{return true;}

    /** @priority HIGHEST */
    public function onClanChannel(PlayerChatEvent $event) : void{
        if($event->isCancelled() || !str_starts_with($event->getMessage(), '#')) return;
        $clans=$this->getServer()->getPluginManager()->getPlugin('AmyXClans');
        if($clans!==null && $clans->isEnabled() && method_exists($clans,'routeClanChat')) $clans->routeClanChat($event);
    }

    public function onChat(PlayerChatEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        $rawMessage = $event->getMessage();

        // Сообщения вида #канал текст обрабатывает AmyXClans.
        if (str_starts_with($rawMessage, "#")) {
            return;
        }

        $channelsEnabled = (bool) $this->config->get("chat_channels_enabled", true);
        $globalPrefix = (string) $this->config->get("global_chat_prefix", "!");
        $isGlobal = !$channelsEnabled || ($globalPrefix !== "" && str_starts_with($rawMessage, $globalPrefix));

        $message = $rawMessage;
        if ($channelsEnabled && $isGlobal) {
            $message = trim(substr($rawMessage, strlen($globalPrefix)));
            if ($message === "") {
                $event->cancel();
                $player->sendMessage($this->getMessage("empty_chat_message"));
                return;
            }
        }

        $group = $this->getPlayerGroup($player);
        $cooldown = $this->groups[$group]['chat_cooldown'] ?? 0;

        if ($cooldown > 0) {
            $lastTime = $this->lastChatTime[$playerName] ?? 0;
            $currentTime = time();
            $timeSince = $currentTime - $lastTime;

            if ($timeSince < $cooldown) {
                $event->cancel();
                $remaining = $cooldown - $timeSince;
                $player->sendMessage($this->getMessage("chat_cooldown", ["SECONDS" => $remaining]));
                return;
            }

            $this->lastChatTime[$playerName] = $currentTime;
        }

        $format = $this->getGroupChatFormat($group);
        $formattedMessage = $this->replacePlaceholders($format, $player, $message, false);

        $label = $isGlobal
            ? (string) $this->config->get("global_chat_label", "§aⒼ")
            : (string) $this->config->get("local_chat_label", "§eⓁ");
        $labelSeparator = (string) $this->config->get("chat_label_separator", " ");
        $formattedMessage = $label . $labelSeparator . $formattedMessage;

        $event->cancel();

        if ($isGlobal) {
            foreach ($event->getRecipients() as $recipient) {
                $recipient->sendMessage($formattedMessage);
            }
        } else {
            $radius = max(1.0, (float) $this->config->get("local_chat_radius", 150));
            $radiusSquared = $radius * $radius;
            foreach ($event->getRecipients() as $recipient) {
                if (!$recipient instanceof Player) {
                    continue;
                }
                if ($recipient->getWorld() !== $player->getWorld()) {
                    continue;
                }
                if ($recipient->getPosition()->distanceSquared($player->getPosition()) <= $radiusSquared) {
                    $recipient->sendMessage($formattedMessage);
                }
            }
        }

        $this->showChatBubble($player, $message, $isGlobal ? "global" : "local", $label);
        $this->updateChatStats($player);
    }

    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        
        $this->checkPlayerTempGroup($player);
        
        $this->updatePlayerPermissions($player);
        $this->updatePlayerNameTag($player);

        if ((bool) $this->config->get("platform_debug", false)) {
            $platform = $this->getPlatformData($player);
            $this->getLogger()->info(
                "Platform debug: " . $player->getName()
                . " DeviceOS=" . (string) $platform["device_os"]
                . " DeviceModel=" . (string) $platform["device_model"]
                . " detected=" . (string) $platform["key"]
            );
        }
    }

    public function onPlayerQuit(PlayerQuitEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        
        if (isset($this->playerAttachments[$playerName])) {
            $player->removeAttachment($this->playerAttachments[$playerName]);
            unset($this->playerAttachments[$playerName]);
        }
        
        unset($this->lastChatTime[$playerName], $this->chatBubbles[strtolower($playerName)]);
    }

    private function getPlayerGroup(Player $player): string {
        $name = $player->getName();
        return $this->playerGroups->get($name, "default");
    }

    private function getGroupChatFormat(string $group): string {
        if ((bool) $this->config->get("use_global_chat_format", true)) {
            return (string) $this->config->get(
                "global_chat_format",
                "{PLATFORM} {GROUP} {CLAN} §f{PLAYER} §f §f{MESSAGE}"
            );
        }

        return (string) ($this->groups[$group]["chat_format"] ?? "{PLAYER}: {MESSAGE}");
    }

    private function getGroupNameTagFormat(string $group): string {
        if ((bool) $this->config->get("use_global_nametag_format", true)) {
            return (string) $this->config->get(
                "global_nametag_format",
                "{PLATFORM} {GROUP} {CLAN} §f{PLAYER}"
            );
        }

        return (string) ($this->groups[$group]["nametag_format"] ?? "{PLATFORM} {GROUP} {CLAN} §f{PLAYER}");
    }
    private function replacePlaceholders(string $format, Player $player, string $message, bool $isNameTag): string {
        $group = $this->getPlayerGroup($player);
        $clanName = $this->getClanName($player) ?? "";
        $clanTag = $this->getClanTag($player);
        $groupTag = $this->getGroupTag($group);
        $groupName = $this->getGroupDisplayName($group);
        $platform = $this->getPlatformData($player);

        $placeholders = [
            "{PLAYER}" => $player->getName(),
            "{MESSAGE}" => $message,
            "{WORLD}" => $player->getWorld()->getFolderName(),
            "{PLATFORM}" => $platform["icon"],
            "{PLATFORM_ICON}" => $platform["icon"],
            "{PLATFORM_NAME}" => $platform["name"],
            "{PLATFORM_KEY}" => $platform["key"],
            "{PLATFORM_ID}" => (string) $platform["device_os"],
            "{DEVICE_MODEL}" => $platform["device_model"],
            "{CLAN}" => $clanTag,
            "{CLAN_TAG}" => $clanTag,
            "{CLAN_NAME}" => $clanName,
            "{GROUP}" => $groupTag,
            "{GROUP_TAG}" => $groupTag,
            "{GROUP_NAME}" => $groupName,
            "{GROUP_COLOR}" => $this->getGroupColor($group),
            "{GROUP_COLOR_CODE}" => "§" . $this->getGroupColor($group),
            "{PRIVILEGE}" => $groupTag,
            "{PRIVILEGE_TAG}" => $groupTag,
            "{PRIVILEGE_NAME}" => $groupName,
            "{PRIVILEGE_COLOR}" => $this->getGroupColor($group),
            "{PRIVILEGE_COLOR_CODE}" => "§" . $this->getGroupColor($group),
            "{HEALTH}" => $isNameTag ? $this->getColoredHealth($player) : ""
        ];

        if ($this->factionPlugin !== null) {
            $placeholders["{FACTION}"] = $this->getPlayerFaction($player);
        } else {
            $placeholders["{FACTION}"] = $clanTag;
        }

        $result = str_replace(array_keys($placeholders), array_values($placeholders), $format);
        $result = (string) preg_replace('/[ \t]{2,}/u', ' ', $result);
        $result = (string) preg_replace('/ +:/u', ':', $result);

        return trim($result);
    }

    private function getGroupDisplayName(string $group): string {
        return (string) ($this->groups[$group]["display_name"] ?? ucfirst($group));
    }

    private function getGroupColor(string $group): string {
        $color = strtolower((string) (($this->groups[$group] ?? [])["color"] ?? "f"));
        return preg_match('/^[0-9a-f]$/', $color) === 1 ? $color : "f";
    }

    private function getGroupTag(string $group): string {
        $data = (array) ($this->groups[$group] ?? []);
        $displayName = (string) ($data["display_name"] ?? ucfirst($group));
        $color = $this->getGroupColor($group);

        $format = (string) ($data["tag_format"] ?? $this->config->get(
            "group_tag_format",
            "§f[§{GROUP_COLOR}{GROUP_NAME}§f]"
        ));

        return str_replace(
            ["{GROUP_NAME}", "{PRIVILEGE_NAME}", "{GROUP_COLOR}", "{PRIVILEGE_COLOR}"],
            [$displayName, $displayName, $color, $color],
            $format
        );
    }

    private function getClanName(Player $player): ?string {
        $plugin = $this->getServer()->getPluginManager()->getPlugin("AmyXClans");
        if ($plugin === null || !$plugin->isEnabled() || !method_exists($plugin, "getClanNameByPlayer")) {
            return null;
        }

        try {
            $name = $plugin->getClanNameByPlayer($player->getName());
            return is_string($name) && $name !== "" ? $name : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function getClanTag(Player $player): string {
        $clanName = $this->getClanName($player);
        if ($clanName === null) {
            return (string) $this->config->get("empty_clan_tag", "");
        }

        $format = (string) $this->config->get("clan_tag_format", "§f[{CLAN_NAME}§f]");
        return str_replace("{CLAN_NAME}", $clanName, $format);
    }

    /**
     * Находит значение в extraData клиента без зависимости от регистра ключа
     * и от того, лежит ли оно на верхнем уровне или во вложенном массиве.
     */
    private function findClientDataValue(array $data, array $wantedKeys, int $depth = 0): mixed {
        if ($depth > 4) {
            return null;
        }

        $normalizedWanted = array_map(
            static fn(string $key): string => strtolower(str_replace(["_", "-", " "], "", $key)),
            $wantedKeys
        );

        foreach ($data as $key => $value) {
            $normalizedKey = strtolower(str_replace(["_", "-", " "], "", (string) $key));
            if (in_array($normalizedKey, $normalizedWanted, true)) {
                return $value;
            }
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $found = $this->findClientDataValue($value, $wantedKeys, $depth + 1);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function normalizePlatformKey(string $value): string {
        $value = strtolower(trim($value));
        $value = str_replace(["_", "-", " "], "", $value);

        return match (true) {
            $value === "ios", $value === "iphone", $value === "ipad", $value === "macos", $value === "osx", $value === "tvos", $value === "apple" => "apple",
            $value === "playstation", $value === "ps4", $value === "ps5" => "playstation",
            $value === "xbox" => "xbox",
            $value === "windows", $value === "windows10", $value === "win32", $value === "win64", $value === "windowsphone", $value === "pc" => "windows",
            $value === "android", $value === "fireos", $value === "amazon" => "android",
            default => ""
        };
    }

    /**
     * Поддерживает в config.yml как сам символ, так и записи U+E2F5,
     * E2F5, \\uE2F5 и \\u{E2F5}. Это защищает от проблем кодировки YAML.
     */
    private function decodeGlyphString(string $value): string {
        $value = trim($value);
        if ($value === "") {
            return "";
        }

        if (preg_match('/^(?:U\\+|0x)?([0-9a-fA-F]{4,6})$/', $value, $matches) === 1) {
            return mb_chr(hexdec($matches[1]), "UTF-8");
        }
        if (preg_match('/^\\\\u\\{?([0-9a-fA-F]{4,6})\\}?$/', $value, $matches) === 1) {
            return mb_chr(hexdec($matches[1]), "UTF-8");
        }

        return $value;
    }

    private function getPlatformData(Player $player): array {
        $extraData = $player->getPlayerInfo()->getExtraData();

        $rawDeviceOS = $this->findClientDataValue($extraData, [
            "DeviceOS", "DeviceOs", "device_os", "PlatformOS", "OperatingSystem"
        ]);
        $rawDeviceModel = $this->findClientDataValue($extraData, [
            "DeviceModel", "device_model", "Model", "PlatformModel"
        ]);
        $rawPlatformName = $this->findClientDataValue($extraData, [
            "Platform", "PlatformName", "DevicePlatform", "OSName"
        ]);

        $deviceOS = is_numeric($rawDeviceOS) ? (int) $rawDeviceOS : -1;
        $deviceModel = trim(is_scalar($rawDeviceModel) ? (string) $rawDeviceModel : "");

        $platform = "";
        if (is_string($rawDeviceOS) && !is_numeric($rawDeviceOS)) {
            $platform = $this->normalizePlatformKey($rawDeviceOS);
        }
        if ($platform === "" && is_scalar($rawPlatformName)) {
            $platform = $this->normalizePlatformKey((string) $rawPlatformName);
        }

        if ($platform === "") {
            $osMap = (array) $this->config->get("platform_device_os_map", [
                1 => "android", 2 => "apple", 3 => "apple", 4 => "android",
                7 => "windows", 8 => "windows", 10 => "apple",
                11 => "playstation", 13 => "xbox", 14 => "windows"
            ]);
            $platform = $this->normalizePlatformKey((string) ($osMap[$deviceOS] ?? ""));
        }

        if ($platform === "" && $deviceModel !== "") {
            $platform = $this->normalizePlatformKey($deviceModel);
            if ($platform === "") {
                $model = strtolower($deviceModel);
                if (str_contains($model, "playstation") || str_contains($model, "ps4") || str_contains($model, "ps5")) {
                    $platform = "playstation";
                } elseif (str_contains($model, "xbox")) {
                    $platform = "xbox";
                } elseif (str_contains($model, "iphone") || str_contains($model, "ipad") || str_contains($model, "mac") || str_contains($model, "apple")) {
                    $platform = "apple";
                } elseif (str_contains($model, "windows") || str_contains($model, "win32") || str_contains($model, "win64") || str_contains($model, "desktop")) {
                    $platform = "windows";
                } elseif (
                    str_contains($model, "android") || str_contains($model, "samsung") || str_contains($model, "xiaomi")
                    || str_contains($model, "huawei") || str_contains($model, "honor") || str_contains($model, "oppo")
                    || str_contains($model, "vivo") || str_contains($model, "realme") || str_contains($model, "oneplus")
                ) {
                    $platform = "android";
                }
            }
        }

        $overrides = (array) $this->config->get("platform_overrides", []);
        foreach ($overrides as $playerName => $forcedPlatform) {
            if (strcasecmp((string) $playerName, $player->getName()) === 0) {
                $platform = $this->normalizePlatformKey((string) $forcedPlatform);
                break;
            }
        }

        if ($platform === "") {
            $platform = $this->normalizePlatformKey((string) $this->config->get("platform_fallback", ""));
        }

        $names = [
            "apple" => "Apple",
            "playstation" => "PlayStation",
            "xbox" => "Xbox",
            "windows" => "Windows",
            "android" => "Android"
        ];

        $icon = "";
        if ((bool) $this->config->get("platform_enabled", true) && $platform !== "") {
            $icons = (array) $this->config->get("platform_icons", []);
            $rawIcon = $this->decodeGlyphString((string) ($icons[$platform] ?? ""));
            if ($rawIcon !== "") {
                $iconFormat = (string) $this->config->get("platform_icon_format", "{ICON}");
                $icon = str_replace("{ICON}", $rawIcon, $iconFormat);
            }
        }

        return [
            "key" => $platform,
            "name" => $names[$platform] ?? ($platform !== "" ? ucfirst($platform) : "Не определена"),
            "icon" => $icon,
            "device_os" => $deviceOS,
            "device_os_raw" => is_scalar($rawDeviceOS) ? (string) $rawDeviceOS : "",
            "device_model" => $deviceModel,
            "extra_keys" => implode(", ", array_map("strval", array_keys($extraData)))
        ];
    }

    private function getPlatformIcon(Player $player): string {
        return (string) $this->getPlatformData($player)["icon"];
    }

    private function getPlayerFaction(Player $player): string {
        if ($this->factionPlugin === null) {
            return "";
        }

        $faction = "";
        $pluginName = $this->factionPlugin->getName();

        switch ($pluginName) {
            case "FactionsPro":
                $faction = $this->factionPlugin->getPlayerFaction($player->getName());
                break;
            case "PiggyFactions":
                $member = $this->factionPlugin->getPlayerManager()->getPlayer($player);
                if ($member !== null) {
                    $faction = $member->getFaction() !== null ? $member->getFaction()->getName() : "";
                }
                break;
            case "SimpleFaction":
                $faction = $this->factionPlugin->getPlayerFaction($player->getName());
                break;
            case "Factions":
                $factionPlayer = $this->factionPlugin->getPlayerManager()->getPlayer($player);
                if ($factionPlayer !== null) {
                    $faction = $factionPlayer->getFaction() !== null ? $factionPlayer->getFaction()->getName() : "";
                }
                break;
        }

        return $faction !== "" ? "[$faction] " : "";
    }

    private function getColoredHealth(Player $player): string {
        $health = $player->getHealth();
        $maxHealth = $player->getMaxHealth();
        
        if ($health > $maxHealth * 0.75) {
            $color = TextFormat::GREEN;
        } elseif ($health > $maxHealth * 0.5) {
            $color = TextFormat::YELLOW;
        } elseif ($health > $maxHealth * 0.25) {
            $color = TextFormat::GOLD;
        } else {
            $color = TextFormat::RED;
        }
        
        return $color . (int)$health . TextFormat::RESET;
    }

    public function setPlayerGroup(Player $player, string $group): void {
        if (!isset($this->groups[$group])) {
            return;
        }
        $this->playerGroups->set($player->getName(), $group);
        $this->playerGroups->save();
        $this->updatePlayerPermissions($player);
        $this->updatePlayerNameTag($player);
    }

    private function updatePlayerPermissions(Player $player): void {
        $group = $this->getPlayerGroup($player);
        $permissions = $this->getAllGroupPermissions($group);
        
        if (isset($this->playerAttachments[$player->getName()])) {
            $player->removeAttachment($this->playerAttachments[$player->getName()]);
            unset($this->playerAttachments[$player->getName()]);
        }

        $attachment = $player->addAttachment($this);
        $attachment->setPermission("chatperms.group." . $group, true);
        foreach ($permissions as $permission) {
            $attachment->setPermission($permission, true);
        }
        
        $this->playerAttachments[$player->getName()] = $attachment;
    }

    private function getAllGroupPermissions(string $group): array {
        if (!isset($this->groups[$group])) {
            return [];
        }

        $allPermissions = [];
        $visited = [];
        $this->collectInheritedPermissions($group, $allPermissions, $visited);
        
        return array_unique($allPermissions);
    }

    private function collectInheritedPermissions(string $group, array &$permissions, array &$visited): void {
        if (in_array($group, $visited) || !isset($this->groups[$group])) {
            return;
        }

        $visited[] = $group;
        $groupData = $this->groups[$group];

        if (isset($groupData['inherits'])) {
            $parentGroup = $groupData['inherits'];
            $this->collectInheritedPermissions($parentGroup, $permissions, $visited);
        }

        if (isset($groupData['permissions'])) {
            foreach ($groupData['permissions'] as $permission) {
                $permissions[] = $permission;
            }
        }
    }

    public function onEntityDamage(EntityDamageEvent $event): void {
        $entity = $event->getEntity();
        if ($entity instanceof Player) {
            $this->updatePlayerNameTag($entity);
        }
    }

    private function updatePlayerNameTag(Player $player): void {
        if (!(bool) $this->config->get("rename_nametag", true)) {
            return;
        }
        $group = $this->getPlayerGroup($player);
        $format = $this->getGroupNameTagFormat($group);
        $baseNameTag = $this->replacePlaceholders($format, $player, "", true);
        $bubbleLines = $this->getChatBubbleLines($player);
        if ($bubbleLines === []) {
            $player->setNameTag($baseNameTag);
            return;
        }

        $blankLines = max(0, (int) $this->config->getNested("nametag_chat_bubble.blank-lines-between", 1));
        $separator = str_repeat("\n", $blankLines + 1);
        $bubbleText = implode("\n", $bubbleLines);
        $placement = strtolower((string) $this->config->getNested("nametag_chat_bubble.placement", "below"));
        $player->setNameTag($placement === "above" ? $bubbleText . $separator . $baseNameTag : $baseNameTag . $separator . $bubbleText);
    }

    private function showChatBubble(Player $player, string $message, string $type, string $label): void {
        if (!(bool) $this->config->getNested("nametag_chat_bubble.enabled", true)) {
            return;
        }
        if ((bool) $this->config->getNested("nametag_chat_bubble.strip-message-colors", true)) {
            $message = TextFormat::clean($message);
        }
        $message = trim((string) preg_replace('/[\r\n\t]+/u', ' ', $message));
        $maxCharacters = max(1, (int) $this->config->getNested("nametag_chat_bubble.max-message-characters", 80));
        if (mb_strlen($message, "UTF-8") > $maxCharacters) {
            $message = mb_substr($message, 0, max(1, $maxCharacters - 1), "UTF-8") . "…";
        }
        $duration = max(0.5, (float) $this->config->getNested("nametag_chat_bubble.duration-seconds", 6));
        $this->chatBubbles[strtolower($player->getName())] = [
            "message" => $message,
            "label" => $label,
            "type" => $type,
            "expires" => microtime(true) + $duration
        ];
        $this->updatePlayerNameTag($player);
    }

    /** @return string[] */
    private function getChatBubbleLines(Player $player): array {
        if (!(bool) $this->config->getNested("nametag_chat_bubble.enabled", true)) {
            return [];
        }
        $key = strtolower($player->getName());
        $bubble = $this->chatBubbles[$key] ?? null;
        if (!is_array($bubble)) {
            return [];
        }
        if ((float) ($bubble["expires"] ?? 0.0) <= microtime(true)) {
            unset($this->chatBubbles[$key]);
            return [];
        }

        $maxLines = max(1, min(6, (int) $this->config->getNested("nametag_chat_bubble.max-lines", 2)));
        $width = max(4, (int) $this->config->getNested("nametag_chat_bubble.max-characters-per-line", 28));
        $chunks = $this->wrapBubbleMessage((string) ($bubble["message"] ?? ""), $width, $maxLines);
        $type = (string) ($bubble["type"] ?? "local");
        $typeName = (string) $this->config->getNested("nametag_chat_bubble.type-names." . $type, $type === "global" ? "Глобальный" : "Локальный");
        $icon = (string) $this->config->getNested("nametag_chat_bubble.type-icons." . $type, (string) ($bubble["label"] ?? ""));
        $firstFormat = (string) $this->config->getNested("nametag_chat_bubble.first-line-format", "{CHAT_ICON} §7{CHAT_TYPE}: §f{MESSAGE}");
        $continuationFormat = (string) $this->config->getNested("nametag_chat_bubble.continuation-line-format", "§f{MESSAGE}");
        $lines = [];
        foreach ($chunks as $index => $chunk) {
            $template = $index === 0 ? $firstFormat : $continuationFormat;
            $template = str_replace(
                ["{CHAT_TYPE}", "{CHAT_LABEL}", "{CHAT_ICON}", "{CHAT_MESSAGE}", "{LINE}", "{LINE_NUMBER}"],
                [$typeName, (string) ($bubble["label"] ?? $icon), $icon, $chunk, $chunk, (string) ($index + 1)],
                $template
            );
            $lines[] = $this->replacePlaceholders($template, $player, $chunk, true);
        }
        return $lines;
    }

    /** @return string[] */
    private function wrapBubbleMessage(string $message, int $width, int $maxLines): array {
        $words = preg_split('/\s+/u', trim($message)) ?: [];
        $lines = [];
        $current = "";
        foreach ($words as $word) {
            while (mb_strlen($word, "UTF-8") > $width) {
                if ($current !== "") {
                    $lines[] = $current;
                    $current = "";
                    if (count($lines) >= $maxLines) {
                        break 2;
                    }
                }
                $lines[] = mb_substr($word, 0, $width, "UTF-8");
                $word = mb_substr($word, $width, null, "UTF-8");
                if (count($lines) >= $maxLines) {
                    break 2;
                }
            }
            $candidate = $current === "" ? $word : $current . " " . $word;
            if (mb_strlen($candidate, "UTF-8") <= $width) {
                $current = $candidate;
            } else {
                $lines[] = $current;
                $current = $word;
                if (count($lines) >= $maxLines) {
                    break;
                }
            }
        }
        if ($current !== "" && count($lines) < $maxLines) {
            $lines[] = $current;
        }
        if ($lines === []) {
            $lines[] = "";
        }
        return array_slice($lines, 0, $maxLines);
    }
    
    /** Публичный API для других плагинов. */
    public function getPrivilegeNameByPlayer(string $playerName): string {
        $player = $this->getServer()->getPlayerExact($playerName);
        if (!$player instanceof Player) {
            return "";
        }
        return $this->getGroupDisplayName($this->getPlayerGroup($player));
    }

    public function getPrivilegeTagByPlayer(string $playerName): string {
        $player = $this->getServer()->getPlayerExact($playerName);
        if (!$player instanceof Player) {
            return "";
        }
        return $this->getGroupTag($this->getPlayerGroup($player));
    }

    public function getClanTagByPlayer(string $playerName): string {
        $player = $this->getServer()->getPlayerExact($playerName);
        return $player instanceof Player ? $this->getClanTag($player) : "";
    }

    public function parsePlayerPlaceholders(Player $player, string $format, string $message = ""): string {
        return $this->replacePlaceholders($format, $player, $message, false);
    }

    private function getMessage(string $key, array $placeholders = []): string {
        $message = $this->messages[$key] ?? "Message '$key' not found";
        
        foreach ($placeholders as $placeholder => $value) {
            $message = str_replace("{{$placeholder}}", $value, $message);
        }
        
        return $message;
    }

    private function handleStatsCommand(CommandSender $sender, array $args): bool {
        if (!$sender->hasPermission("chatperms.command.stats")) {
            $sender->sendMessage($this->getMessage("no_permission"));
            return true;
        }

        if (count($args) === 0) {
            $this->showServerStats($sender);
        } else {
            $playerName = $args[0];
            $this->showPlayerStats($sender, $playerName);
        }

        return true;
    }

    private function showServerStats(CommandSender $sender): void {
        $allStats = $this->chatStats->getAll();
        if (empty($allStats)) {
            $sender->sendMessage(TextFormat::YELLOW . "No chat statistics available yet.");
            return;
        }

        $totalMessages = 0;
        $totalPlayers = count($allStats);
        $mostActivePlayer = "";
        $mostMessages = 0;

        foreach ($allStats as $playerName => $stats) {
            $messageCount = $stats['total_messages'] ?? 0;
            $totalMessages += $messageCount;
            
            if ($messageCount > $mostMessages) {
                $mostMessages = $messageCount;
                $mostActivePlayer = $playerName;
            }
        }

        $sender->sendMessage(TextFormat::YELLOW . "=== Server Chat Statistics ===");
        $sender->sendMessage(TextFormat::GREEN . "Total Messages: " . TextFormat::WHITE . number_format($totalMessages));
        $sender->sendMessage(TextFormat::GREEN . "Active Players: " . TextFormat::WHITE . $totalPlayers);
        $sender->sendMessage(TextFormat::GREEN . "Most Active Player: " . TextFormat::WHITE . $mostActivePlayer . TextFormat::GRAY . " (" . number_format($mostMessages) . " messages)");
        
        if ($totalMessages > 0) {
            $avgMessages = round($totalMessages / $totalPlayers, 1);
            $sender->sendMessage(TextFormat::GREEN . "Average per Player: " . TextFormat::WHITE . $avgMessages . " messages");
        }
    }

    private function showPlayerStats(CommandSender $sender, string $playerName): void {
        if (!$this->chatStats->exists($playerName)) {
            $sender->sendMessage(TextFormat::YELLOW . "No statistics found for player " . TextFormat::WHITE . $playerName);
            return;
        }

        $stats = $this->chatStats->get($playerName);
        $totalMessages = $stats['total_messages'] ?? 0;
        $firstMessage = $stats['first_message'] ?? 0;
        $lastMessage = $stats['last_message'] ?? 0;
        $hourlyStats = $stats['hourly_stats'] ?? [];

        $sender->sendMessage(TextFormat::YELLOW . "=== Chat Stats: " . TextFormat::AQUA . $playerName . TextFormat::YELLOW . " ===");
        $sender->sendMessage(TextFormat::GREEN . "Total Messages: " . TextFormat::WHITE . number_format($totalMessages));
        
        if ($firstMessage > 0) {
            $sender->sendMessage(TextFormat::GREEN . "First Message: " . TextFormat::WHITE . date("Y-m-d H:i:s", $firstMessage));
        }
        
        if ($lastMessage > 0) {
            $sender->sendMessage(TextFormat::GREEN . "Last Message: " . TextFormat::WHITE . date("Y-m-d H:i:s", $lastMessage));
        }

        if (!empty($hourlyStats)) {
            $maxHour = array_keys($hourlyStats, max($hourlyStats))[0];
            $maxMessages = $hourlyStats[$maxHour];
            $sender->sendMessage(TextFormat::GREEN . "Most Active Hour: " . TextFormat::WHITE . $maxHour . ":00 - " . ($maxHour + 1) . ":00 " . TextFormat::GRAY . "(" . $maxMessages . " messages)");
        }

        if ($totalMessages > 0) {
            $daysSinceFirst = max(1, (time() - $firstMessage) / 86400);
            $messagesPerDay = round($totalMessages / $daysSinceFirst, 1);
            $sender->sendMessage(TextFormat::GREEN . "Average per Day: " . TextFormat::WHITE . $messagesPerDay . " messages");
        }
    }

    private function updateChatStats(Player $player): void {
        $playerName = $player->getName();
        $currentTime = time();
        $currentHour = (int)date("H", $currentTime);

        $stats = $this->chatStats->get($playerName, [
            'total_messages' => 0,
            'first_message' => $currentTime,
            'last_message' => 0,
            'hourly_stats' => []
        ]);

        $stats['total_messages']++;
        $stats['last_message'] = $currentTime;
        
        if (!isset($stats['hourly_stats'][$currentHour])) {
            $stats['hourly_stats'][$currentHour] = 0;
        }
        $stats['hourly_stats'][$currentHour]++;

        $this->chatStats->set($playerName, $stats);
        
        if ($stats['total_messages'] % 10 === 0) {
            $this->chatStats->save();
        }
    }

    private function handleTempGroupCommand(CommandSender $sender, array $args): bool {
        if (!$sender->hasPermission("chatperms.command.tempgroup")) {
            $sender->sendMessage($this->getMessage("no_permission"));
            return true;
        }

        if (count($args) < 3) {
            $sender->sendMessage(TextFormat::YELLOW . "Usage: " . TextFormat::WHITE . "/cp tempgroup <player> <group> <duration> [reason]");
            return true;
        }

        $playerName = $args[0];
        $group = $args[1];
        $duration = $args[2];
        $reason = isset($args[3]) ? implode(" ", array_slice($args, 3)) : "No reason specified";

        $player = $this->getServer()->getPlayerExact($playerName);
        if ($player === null) {
            $sender->sendMessage($this->getMessage("player_not_found"));
            return true;
        }

        if (!isset($this->groups[$group])) {
            $sender->sendMessage($this->getMessage("group_not_found"));
            return true;
        }

        $expireTime = $this->parseDuration($duration);
        if ($expireTime === false) {
            $sender->sendMessage($this->getMessage("invalid_duration"));
            return true;
        }
        $expireTime += time();

        $originalGroup = $this->getPlayerGroup($player);

        $this->tempGroups->set($playerName, [
            'temp_group' => $group,
            'original_group' => $originalGroup,
            'expires' => $expireTime,
            'reason' => $reason,
            'assigned_by' => $sender->getName(),
            'assigned_at' => time()
        ]);
        $this->tempGroups->save();

        $this->setPlayerGroup($player, $group);

        $sender->sendMessage("§aAssigned temporary group §6$group §ato §b$playerName §afor $duration. Reason: $reason");
        $player->sendMessage("§aYou have been assigned to temporary group §6$group §afor $duration. Reason: $reason");

        return true;
    }

    private function startTempGroupCheckTask(): void {
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(
            function(): void {
                $this->checkExpiredTempGroups();
            }
        ), 200);
    }

    private function checkExpiredTempGroups(): void {
        $currentTime = time();
        $tempGroups = $this->tempGroups->getAll();
        
        if (empty($tempGroups)) {
            return;
        }
        
        $updated = false;

        foreach ($tempGroups as $playerName => $tempData) {
            if (!is_array($tempData) || !isset($tempData['expires'])) {
                $this->tempGroups->remove($playerName);
                $updated = true;
                continue;
            }
            
            if ($tempData['expires'] <= $currentTime) {
                $originalGroup = $tempData['original_group'] ?? 'default';
                $tempGroup = $tempData['temp_group'] ?? 'unknown';
                
                $this->tempGroups->remove($playerName);
                $updated = true;

                $player = $this->getServer()->getPlayerExact($playerName);
                if ($player !== null) {
                    $this->setPlayerGroup($player, $originalGroup);
                    $player->sendMessage($this->getMessage("tempgroup_expired", [
                        "GROUP" => $tempGroup,
                        "PLAYER" => $playerName
                    ]));
                } else {
                    $this->playerGroups->set($playerName, $originalGroup);
                    $this->playerGroups->save();
                }
            }
        }

        if ($updated) {
            $this->tempGroups->save();
        }
    }

    private function checkPlayerTempGroup(Player $player): void {
        $playerName = $player->getName();
        $tempData = $this->tempGroups->get($playerName, null);
        if ($tempData !== null && is_array($tempData) && isset($tempData['expires']) && $tempData['expires'] <= time()) {
            $originalGroup = $tempData['original_group'] ?? 'default';
            $tempGroup = $tempData['temp_group'] ?? 'unknown';
            $this->tempGroups->remove($playerName);
            $this->tempGroups->save();
            
            $this->setPlayerGroup($player, $originalGroup);
            
            $player->sendMessage($this->getMessage("tempgroup_expired", [
                "GROUP" => $tempGroup,
                "PLAYER" => $playerName
            ]));
            
            $this->getLogger()->info("Restored player $playerName from temp group $tempGroup to $originalGroup");
        }
    }

    private function handleTempGroupCheckCommand(CommandSender $sender, array $args): bool {
        if (!$sender->hasPermission("chatperms.command.tempgroupcheck")) {
            $sender->sendMessage($this->getMessage("no_permission"));
            return true;
        }

        if (count($args) !== 1) {
            $sender->sendMessage(TextFormat::YELLOW . "Usage: " . TextFormat::WHITE . "/cp tempgroupcheck <player>");
            return true;
        }

        $playerName = $args[0];
        $player = $this->getServer()->getPlayerExact($playerName);
        if ($player === null) {
            $sender->sendMessage($this->getMessage("player_not_found"));
            return true;
        }

        $tempData = $this->tempGroups->get($playerName, null);
        if ($tempData === null || !is_array($tempData)) {
            $sender->sendMessage(TextFormat::YELLOW . "Player " . TextFormat::WHITE . $playerName . TextFormat::YELLOW . " is not in a temporary group.");
            return true;
        }

        $group = $tempData['temp_group'] ?? "unknown";
        $reason = $tempData['reason'] ?? "No reason specified";
        $remainingTime = $tempData['expires'] - time();
        $duration = $remainingTime > 0 ? $this->formatTime($remainingTime) : "EXPIRED";

        $sender->sendMessage(TextFormat::YELLOW . "=== Temporary Group Info: " . TextFormat::AQUA . $playerName . TextFormat::YELLOW . " ===");
        $sender->sendMessage(TextFormat::GREEN . "Group: " . TextFormat::WHITE . $group);
        $sender->sendMessage(TextFormat::GREEN . "Reason: " . TextFormat::WHITE . $reason);
        $sender->sendMessage(TextFormat::GREEN . "Time Remaining: " . TextFormat::WHITE . $duration);
        
        if ($remainingTime <= 0) {
            $sender->sendMessage(TextFormat::RED . "⚠ This temp group has expired and should be removed on next check!");
        }

        return true;
    }
}
