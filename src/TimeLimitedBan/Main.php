<?php
namespace TimeLimitedBan;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\Player;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerPreLoginEvent;

class Main extends PluginBase implements Listener {
    
    /** @var Config */
    private $config;
    
    /** @var Config */
    private $bans;
    
    /** @var  */
    private $lang = [];
    
    public function onEnable() {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getLogger()->info(TextFormat::GREEN . "\nTimeLimitedBan 插件已启动!\nWindows1145极致优化+重写!必是精品!\n");
        $this->initConfig();
        $this->saveDefaultConfig();
    }
    
    private function loadLanguage($lang) {
        $langFile = $this->getDataFolder() . "lang_" . $lang . ".yml";
        if(!file_exists($langFile)) {
            $this->saveResource("lang_" . $lang . ".yml");
        }
        $this->lang = (new Config($langFile, Config::YAML))->getAll();
    }

    public function initConfig(){
        // 确保数据文件夹存在
        if (!file_exists($this->getDataFolder())) {
            mkdir($this->getDataFolder(), 0755, true);
        }

        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML,[
            "lang-tips" => "多语言 目前仅支持zho和eng",
            "default-lang" => "zho",
            "time-format" => "Y-m-d H:i:s"
        ]);
        $this->bans = new Config($this->getDataFolder() . "bans.yml", Config::YAML, [
            "players" => [], 
            "ips" => [], 
            "clientids" => []
        ]);

        // 加载语言文件
        $this->loadLanguage($this->config->get("default-lang"));
        $this->config->save();
        $this->bans->save();
    }
    
    private function translate($key,  $replace = []){
        $keys = explode(".", $key);
        $value = $this->lang;
        
        foreach($keys as $k) {
            if(!isset($value[$k])) {
                return $key;
            }
            $value = $value[$k];
        }
        
        if(!empty($replace)) {
            foreach($replace as $k => $v) {
                $value = str_replace("{" . strtoupper($k) . "}", $v, $value);
            }
        }
        
        return $value;
    }
    
    public function onCommand(CommandSender $sender, Command $command, $label, array $args){ //TODO: 简化用法
        switch(strtolower($command->getName())) {
            case "tban":
                return $this->banCommand($sender, $args); //TODO: 时间格式判断
            case "tunban":
                return $this->unbanCommand($sender, $args);
            case "tbaninfo":
                return $this->banInfoCommand($sender, $args);
        }
        return false;
    }
    
    private function banCommand(CommandSender $sender, array $args){
        if(count($args) < 2) {
            $sender->sendMessage($this->translate("command.usage-ban"));
            return false;
        }
        
        $type = strtolower($args[0]);
        $identifier = $args[1];
        $time = isset($args[2]) ? $this->parseTime($args[2]) : null;
        $reason = isset($args[3]) ? $args[3]: $this->translate("command.default-reason");
        
        $banData = [
            "banned_by" => $sender->getName(),
            "reason" => $reason,
            "time" => $time !== null ? date($this->config->get("time-format"), $time) : $this->translate("ban-info.permanent"),
            "timestamp" => time(),
            "expire" => $time
        ];
        
        switch($type) {
            case "player":
            case "p";
                $this->bans->setNested("players.$identifier", $banData);
                $player = $this->getServer()->getPlayerExact($identifier);
                if($player instanceof Player) {
                    $player->kick($this->formatMessage("messages.player-banned", $banData), false);
                }
                $this->getServer()->broadcastMessage($this->formatMessage("messages.ban-broadcast", $banData, ["PLAYER" => $identifier]));
                break;
                
            case "ip":
                $this->bans->setNested("ips.$identifier", $banData);
                foreach($this->getServer()->getOnlinePlayers() as $onlinePlayer) {
                    if($onlinePlayer->getAddress() === $identifier) {
                        $onlinePlayer->kick($this->formatMessage("messages.ip-banned", $banData), false);
                    }
                }
                break;
                
            case "cid":
            case "clientid":
                $this->bans->setNested("clientids.$identifier", $banData);
                foreach($this->getServer()->getOnlinePlayers() as $onlinePlayer) {
                    if($onlinePlayer->getClientId() === (int)$identifier) {
                        $onlinePlayer->kick($this->formatMessage("messages.clientid-banned", $banData), false);
                    }
                }
                break;
                
            default:
                $sender->sendMessage($this->translate("command.invalid-type"));
                return false;
        }
        
        $this->bans->save();
        $sender->sendMessage($this->translate("command.ban-success", [
            "TYPE" => $type,
            "IDENTIFIER" => $identifier
        ]));
        return true;
    }
    
    private function unbanCommand(CommandSender $sender, array $args){ //FIXME: bans.yml没有完全清除
        if(count($args) < 2) {
            $sender->sendMessage($this->translate("command.usage-unban"));
            return false;
        }
        
        $type = strtolower($args[0]);
        $identifier = $args[1];
        
        switch($type) {
            case "player":
            case "p":
                if(!$this->bans->getNested("players.$identifier")) {
                    $sender->sendMessage($this->translate("command.not-banned", [
                        "TYPE" => "玩家",
                        "IDENTIFIER" => $identifier
                    ]));
                    return false;
                }
                $this->bans->setNested("players.$identifier", []);
                break;
                
            case "ip":
                if(!$this->bans->getNested("ips.$identifier")) {
                    $sender->sendMessage($this->translate("command.not-banned", [
                        "TYPE" => "IP",
                        "IDENTIFIER" => $identifier
                    ]));
                    return false;
                }
                $this->bans->setNested("ips.$identifier", []);
                break;
                
            case "clientid":
            case "cid":
                if(!$this->bans->getNested("clientids.$identifier")) {
                    $sender->sendMessage($this->translate("command.not-banned", [
                        "TYPE" => "ClientID",
                        "IDENTIFIER" => $identifier
                    ]));
                    return false;
                }
                $this->bans->setNested("clientids.$identifier", []);
                break;
                
            default:
                $sender->sendMessage($this->translate("command.invalid-type"));
                return false;
        }
        
        $this->bans->save();
        $sender->sendMessage($this->translate("command.unban-success", [
            "TYPE" => $type,
            "IDENTIFIER" => $identifier
        ]));
        return true;
    }
    
    private function banInfoCommand(CommandSender $sender, array $args){
        if(count($args) < 2) {
            $sender->sendMessage($this->translate("command.usage-baninfo"));
            return false;
        }
        
        $type = strtolower($args[0]);
        $identifier = $args[1];
        
        switch($type) {
            case "player":
            case "p":
                if(!$this->bans->getNested("players.$identifier")) {
                    $sender->sendMessage($this->translate("command.not-banned", [
                        "TYPE" => "玩家",
                        "IDENTIFIER" => $identifier
                    ]));
                    return false;
                }
                $banData = $this->bans->getNested("players.$identifier");
                break;
                
            case "ip":
                if(!$this->bans->getNested("ips.$identifier")) {
                    $sender->sendMessage($this->translate("command.not-banned", [
                        "TYPE" => "IP",
                        "IDENTIFIER" => $identifier
                    ]));
                    return false;
                }
                $banData = $this->bans->getNested("ips.$identifier");
                break;
                
            case "clientid":
            case "cid":
                if(!$this->bans->getNested("clientids.$identifier")) {
                    $sender->sendMessage($this->translate("command.not-banned", [
                        "TYPE" => "ClientID",
                        "IDENTIFIER" => $identifier
                    ]));
                    return false;
                }
                $banData = $this->bans->getNested("clientids.$identifier");
                break;
                
            default:
                $sender->sendMessage($this->translate("command.invalid-type"));
                return false;
        }
        
        $sender->sendMessage($this->translate("ban-info.title"));
        $sender->sendMessage($this->translate("ban-info.type", ["TYPE" => ucfirst($type)]));
        $sender->sendMessage($this->translate("ban-info.identifier", ["IDENTIFIER" => $identifier]));
        $sender->sendMessage($this->translate("ban-info.banned-by", ["BANNED_BY" => $banData["banned_by"]]));
        $sender->sendMessage($this->translate("ban-info.reason", ["REASON" => $banData["reason"]]));
        $sender->sendMessage($this->translate("ban-info.ban-time", [
            "TIME" => date($this->config->get("time-format"), $banData["timestamp"])
        ]));
        $sender->sendMessage($this->translate("ban-info.expire-time", ["EXPIRE" => $banData["time"]]));
        
        return true;
    }
    
public function onPlayerPreLogin(PlayerPreLoginEvent $event) { //FIXME: bans.yml没有完全清除
    $player = $event->getPlayer();
    $playerName = strtolower($player->getName());
    $ip = $player->getAddress();
    $clientId = $player->getClientId();
    
    // 检查玩家名封禁
    $playerBanData = $this->bans->getNested("players.$playerName", null);
    if($playerBanData !== null) {
        if($playerBanData["expire"] === null || $playerBanData["expire"] > time()) {
            $event->setKickMessage($this->formatMessage("messages.player-banned", $playerBanData));
            
            $event->setCancelled();
            return;
        } else {
            $this->bans->setNested("players.$playerName", []);
            $this->bans->save();
        }
    }
    
    // 检查IP封禁
    $ipBanData = $this->bans->getNested("ips.$ip", null);
    if($ipBanData !== null) {
        if($ipBanData["expire"] === null || $ipBanData["expire"] > time()) {
            $event->setKickMessage($this->formatMessage("messages.ip-banned", $ipBanData));
            
            $event->setCancelled();
            return;
        } else {
            $this->bans->setNested("ips.$ip", []);
            $this->bans->save();
        }
    }
    
    // 检查ClientID封禁
    $clientIdBanData = $this->bans->getNested("clientids.$clientId", null);
    if($clientIdBanData !== null) {
        if($clientIdBanData["expire"] === null || $clientIdBanData["expire"] > time()) {
            $event->setKickMessage($this->formatMessage("messages.clientid-banned", $clientIdBanData));
            
            $event->setCancelled();
            return;
        } else {
            $this->bans->setNested("clientids.$clientId", []);
            $this->bans->save();
        }
    }
}

    private function parseTime($time){ //TODO: 太复杂了建议简化
        if(strtolower($time) === "permanent" && strtolower($time) === "永久") {
            return null;
        }
        
        $units = [
            "s" => 1,
            "m" => 60,
            "h" => 3600,
            "d" => 86400,
            "w" => 604800,
            "mo" => 2592000,
            "y" => 31536000
        ];
        
        if(preg_match("/^(\d+)([a-z]+)$/", $time, $matches)) {
            $value = (int)$matches[1];
            $unit = strtolower($matches[2]);
            
            if(isset($units[$unit])) {
                return time() + ($value * $units[$unit]);
            }
        }
        
        return null;
    }
    
    private function formatMessage($key,  $banData,  $extra = []){
        $replacements = [
            "REASON" => $banData["reason"],
            "TIME" => $banData["time"],
            "BANNED_BY" => $banData["banned_by"]
        ];
        
        foreach($extra as $k => $v) {
            $replacements[strtoupper($k)] = $v;
        }
        
        return $this->translate($key, $replacements);
    }

    public function onDisable(){
        $this->config->save();
        $this->bans->save();
        $this->getServer()->getLogger()->info(TextFormat::RED . "TimeLimitedBan插件已卸载");
    }

}
