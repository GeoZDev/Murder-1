<?php

namespace TheNewHEROBRINE\Murder;

use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\item\Item;
use pocketmine\level\Level;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\Player;
use pocketmine\utils\TextFormat;
use TheNewHEROBRINE\Murder\entity\MurderPlayer;
use TheNewHEROBRINE\Murder\entity\projectile\MurderGunProjectile;

class MurderArena {

    const GAME_IDLE = 0;
    const GAME_STARTING = 1;
    const GAME_RUNNING = 2;

    /** @var MurderMain $plugin */
    private $plugin;

    /** @var string $name */
    private $name;

    /** @var int $countdown */
    private $countdown;

 // /** @var int $maxTime */
 // private $maxTime;

    /** @var int $status */
    private $state = self::GAME_IDLE;

    /** @var MurderPlayer[] $players */
    private $players = [];

    /** @var array $skins */
    private $skins = [];

    /** @var MurderPlayer $murderer */
    private $murderer;

    /** @var MurderPlayer[] $bystanders */
    private $bystanders;

    /** @var array $spawns */
    private $spawns;

    /** @var array $espawns */
    private $espawns;

    /** @var Level $world */
    private $world;

    /** @var int $spawnEmerald */
    private $spawnEmerald = 10;

    /**
     * @param MurderMain $plugin
     * @param string $name
     * @param array $spawns
     * @param array $espawns
     */
    public function __construct(MurderMain $plugin, string $name, array $spawns, array $espawns) {
        $this->spawns = $spawns;
        $this->espawns = $espawns;
        $this->plugin = $plugin;
        $this->name = $name;
        $this->world = $this->getPlugin()->getServer()->getLevelByName($name);
        $this->countdown = $this->getPlugin()->getCountdown();
    }

    public function tick() {
        if ($this->isStarting()){
            if ($this->countdown == 0){
                $this->start();
                $this->broadcastMessage("La partita è iniziata!");
            }
            else {
            $this->broadcastPopup(TextFormat::YELLOW . "Inizio tra " . TextFormat::WHITE . $this->countdown . TextFormat::YELLOW . "s");
            $this->countdown--;
            }
        }

        elseif ($this->isRunning()){
            $padding = str_repeat(" ", 55);
            foreach ($this->getPlayers() as $player){
                $player->sendPopup(
                    $padding . MurderMain::MESSAGE_PREFIX . "\n" .
                    $padding . TextFormat::AQUA . "Ruolo: " . TextFormat::GREEN . $this->getRole($player) . "\n" .
                    $padding . TextFormat::AQUA . "Smeraldi: " . TextFormat::YELLOW . $player->getItemCount() . "/5\n" .
                    $padding . TextFormat::AQUA . "Identità: " . "\n$padding" . TextFormat::GREEN . $player->getDisplayName() . str_repeat("\n", 3));
            }
            if ($this->spawnEmerald == 0){
                $this->spawnEmerald($this->espawns[array_rand($this->espawns)]);
                $this->spawnEmerald = 10;
            }
            $this->spawnEmerald--;
        }
    }

    /**
     * @param Player $player
     */
    public function join(Player $player) {
        if (!$this->isRunning()){
            if (!$this->getPlugin()->getArenaByPlayer($player)){
                if (count($this->getPlayers()) < count($this->spawns)){
                    $this->players[] = $player;
                    $player->getInventory()->clearAll();
                    $player->getInventory()->sendContents($player);
                    $player->teleport($this->getPlugin()->getHub()->getSpawnLocation());
                    $this->broadcastMessage(str_replace("{player}", $player->getName(), $this->getPlugin()->getJoinMessage()));
                    if (count($this->getPlayers()) >= 2 && $this->isIdle()){
                        $this->state = self::GAME_STARTING;
                    }
                }else{
                    $player->sendMessage(TextFormat::RED . "Arena piena!");
                }
            }else{
                $player->sendMessage(TextFormat::RED . "Sei già in una partita!");
            }
        } else{
            $player->sendMessage(TextFormat::RED . "Partita in corso!");
        }
    }

    /**
     * @param Player $player
     * @param bool $silent
     */
    public function quit(Player $player, $silent = false) {
        /** @var MurderPlayer $player */
        if ($this->inArena($player)){
            if (!$silent){
                $this->broadcastMessage(str_replace("{player}", $player->getMurderName(), $this->getPlugin()->getQuitMessage()));
            }
            $this->closePlayer($player);
            if ($this->isRunning()){
                if ($this->isMurderer($player)){
                    $bystanders = [];
                    $event = $this->getMurderer()->getLastDamageCause();
                    $lastDamageCause = new \ReflectionProperty(get_class($player), "lastDamageCause");
                    $lastDamageCause->setAccessible(true);
                    $lastDamageCause->setValue($player, null);
                    foreach ($this->getBystanders() as $bystander) {
                        $name = $bystander->getName();
                        if ($this->inArena($bystander)) {
                            $name = TextFormat::BLUE . $name;
                            if ($event instanceof EntityDamageByChildEntityEvent and $event->getChild() instanceof MurderGunProjectile and $event->getDamager() == $bystander) {
                                $name = TextFormat::BOLD . $name;
                            }
                        }else {
                            $name = TextFormat::RED . $name;
                        }
                        if ($this->getBystanders()[0] == $bystander) {
                            $name = TextFormat::ITALIC . $name;
                        }
                        $bystanders[] = $name;
                    }
                    $this->stop(count($bystanders) == 1 ?
                        "L'innocente " . implode(TextFormat::RESET . ", ", $bystanders) . TextFormat::RESET . " ha vinto la partita su " . $this :
                        "Gli innocenti " . implode(TextFormat::RESET . ", ", $bystanders) . TextFormat::RESET . " hanno vinto la partita su " . $this);
                }
                elseif (count($this->getPlayers()) === 1){
                    $this->stop("L'assassino " . TextFormat::DARK_RED . $this->getMurderer()->getName() .  TextFormat::RESET . " ha vinto la partita su " . $this);
                }
            }
            elseif ($this->isStarting()){
                if (count($this->getPlayers()) < 2){
                    $this->state = self::GAME_IDLE;
                }
            }
        }
    }

    public function start() {
        $this->state = self::GAME_RUNNING;
        $skins = [];
        foreach ($this->getPlayers() as $player) {
            $skins[$player->getName()] = $player->getSkinData();
        }
        $this->skins = $skins;
        if (count(array_unique($this->getSkins())) > 1){
            do {
                shuffle($skins);
            } while (array_values($this->getSkins()) == $skins);
        }
        $players = $this->getPlayers();
        do {
            shuffle($players);
        } while ($this->getPlayers() == $players);
        foreach ($this->getPlayers() as $player) {
            $player->setSkin(array_shift($skins), $player->getSkinId());
            $name = array_shift($players)->getName();
            $player->setDisplayName($name);
            $player->setNameTag($name);
            $player->setNameTagAlwaysVisible(false);
        }
        $random = array_rand($this->getPlayers(), 2);
        shuffle($random);
        $this->murderer = $this->getPlayers()[$random[0]];
        $this->bystanders[] = $this->getPlayers()[$random[1]];
        foreach ($random as $key) {
            $player = $this->getPlayers()[$key];
            $player->getInventory()->clearAll();
            $player->getInventory()->setHeldItemIndex(0);
            $player->getInventory()->resetHotbar(true);
        }
        $this->getMurderer()->getInventory()->setItemInHand(Item::get(Item::WOODEN_SWORD)->setCustomName("Coltello"));
        $this->getMurderer()->setButtonText("Lancia");
        $this->getMurderer()->setFood($this->murderer->getMaxFood());
        $this->getMurderer()->addTitle(TextFormat::BOLD . TextFormat::RED . "Murderer", TextFormat::RED . "Uccidi tutti");
        $this->getBystanders()[0]->getInventory()->setItemInHand(Item::get(Item::FISHING_ROD)->setCustomName("Pistola"));
        $this->getBystanders()[0]->addTitle(TextFormat::BOLD . TextFormat::AQUA . "Bystander", TextFormat::AQUA . "Con un'arma segreta");
        $spawns = $this->spawns;
        shuffle($spawns);
        foreach ($this->getPlayers() as $player) {
            $player->setGamemode($player::ADVENTURE);
            $player->setHealth($player->getMaxHealth());
            $player->removeAllEffects();
            if ($player !== $this->getMurderer()){
                $player->setButtonText("Spara");
                $player->setFood(6);
                if ($player !== $this->getBystanders()[0]){
                    $player->addTitle(TextFormat::BOLD . TextFormat::AQUA . "Bystander", TextFormat::AQUA . "Uccidi il murderer");
                    $this->bystanders[] = $player;
                }
            }
            $spawn = array_shift($spawns);
            $player->teleport(new Position($spawn[0], $spawn[1], $spawn[2], $this->getWorld()));
        }
        foreach ($this->espawns as $espawn) {
            $this->spawnEmerald($espawn);
        }
    }

    /**
     * @param string $message
     */
    public function stop(string $message = "") {
        if ($this->isRunning()){
            foreach ($this->getWorld()->getPlayers() as $player) {
                if ($this->inArena($player)){
                    $this->closePlayer($player);
                }
                else{
                    $player->teleport($this->getPlugin()->getServer()->getDefaultLevel()->getSpawnLocation());
                }
            }
            $this->getPlugin()->broadcastMessage($message);
            $this->players = [];
            $this->skins = [];
            $this->countdown = $this->getPlugin()->getCountdown();
            $this->bystanders = [];
            $this->murderer = null;
            $this->spawnEmerald = 10;
            $this->state = self::GAME_IDLE;
            foreach ($this->getWorld()->getEntities() as $entity) {
                $entity->close();
            }
        }
    }

    /**
     * @param Player $player
     */
    public function closePlayer(Player $player){
        /** @var MurderPlayer $player */
        if ($this->inArena($player)){
            $player->setNameTagAlwaysVisible(true);
            $player->setNameTag($player->getName());
            $player->setDisplayName($player->getName());
            if (isset($this->getSkins()[$player->getName()])){
                $player->setSkin($this->getSkins()[$player->getName()], $player->getSkinId());
            }
            $player->getInventory()->clearAll();
            $player->getInventory()->sendContents($player);
            $player->setButtonText("");
            $player->setGamemode($this->getPlugin()->getServer()->getDefaultGamemode());
            $player->setHealth($player->getMaxHealth());
            $player->setFood($player->getMaxFood());
            $player->removeAllEffects();
            unset($this->players[array_search($player, $this->getPlayers())]);
            $player->teleport($this->getPlugin()->getServer()->getDefaultLevel()->getSpawnLocation());
        }
    }

    /**
     * @param array $espawn
     */
    public function spawnEmerald(array $espawn) {
        $this->getWorld()->dropItem(new Vector3($espawn[0], $espawn[1], $espawn[2]), Item::get(Item::EMERALD));
    }

    /**
     * @param Player $player
     * @return bool
     */
    public function inArena(Player $player): bool {
        return in_array($player, $this->getPlayers());
    }

    /**
     * @param Player $player
     * @return string|null
     */
    public function getRole(Player $player): string {
        if ($this->inArena($player)){
            return $this->isMurderer($player) ? "Murderer" : "Bystander";
        }
        return null;
    }

    /**
     * @param string $msg
     */
    public function broadcastMessage(string $msg) {
        $this->getPlugin()->broadcastMessage($msg, $this->getPlayers());
    }

    /**
     * @param string $msg
     */
    public function broadcastPopup(string $msg) {
        $this->getPlugin()->broadcastPopup($msg, $this->getPlayers());
    }

    /**
     * @param Player $player
     * @return bool
     */
    public function isMurderer(Player $player): bool {
        return $this->getMurderer() === $player;
    }

    /**
     * @param Player $player
     * @return bool
     */
    public function isBystander(Player $player): bool {
        return in_array($player, $this->getBystanders());
    }

    /**
     * @return int
     */
    public function isIdle(): int {
        return $this->state == self::GAME_IDLE;
    }

    /**
     * @return int
     */
    public function isStarting(): int {
        return $this->state == self::GAME_STARTING;
    }

    /**
     * @return bool
     */
    public function isRunning(): bool {
        return $this->state == self::GAME_RUNNING;
    }

    /**
     * @return MurderPlayer
     */
    public function getMurderer(): MurderPlayer {
        return $this->murderer;
    }

    /**
     * @return MurderPlayer[]
     */
    public function getBystanders(): array {
        return $this->bystanders;
    }

    /**
     * @return MurderPlayer[]
     */
    public function getPlayers(): array {
        return $this->players;
    }

    /**
     * @return Level
     */
    public function getWorld(): Level {
        return $this->world;
    }

    /**
     * @return array
     */
    public function getSkins(): array {
        return $this->skins;
    }

    /**
     * @return MurderMain
     */
    public function getPlugin(): MurderMain {
        return $this->plugin;
    }

    /**
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * @return string
     */

    public function __toString(): string {
        return $this->getName();
    }
}
