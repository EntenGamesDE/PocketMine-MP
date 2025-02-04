<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe\handler;

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\cache\CraftingDataCache;
use pocketmine\network\mcpe\cache\StaticPacketCache;
use pocketmine\network\mcpe\convert\GlobalItemTypeDictionary;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\InventoryManager;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\RequestChunkRadiusPacket;
use pocketmine\network\mcpe\protocol\StartGamePacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\BoolGameRule;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\protocol\types\Experiments;
use pocketmine\network\mcpe\protocol\types\LevelSettings;
use pocketmine\network\mcpe\protocol\types\PlayerMovementSettings;
use pocketmine\network\mcpe\protocol\types\PlayerMovementType;
use pocketmine\network\mcpe\protocol\types\SpawnSettings;
use pocketmine\player\Player;
use pocketmine\Server;
use Ramsey\Uuid\Uuid;

/**
 * Handler used for the pre-spawn phase of the session.
 */
class PreSpawnPacketHandler extends ChunkRequestPacketHandler{
	public function __construct(
		private Server $server,
		private Player $player,
		NetworkSession $session,
		private InventoryManager $inventoryManager
	){
		parent::__construct($session);
	}

	public function setUp() : void{
		$dictionaryProtocol = GlobalItemTypeDictionary::getDictionaryProtocol($this->session->getProtocolId());
		$location = $this->player->getLocation();

		$levelSettings = new LevelSettings();
		$levelSettings->seed = -1;
		$levelSettings->spawnSettings = new SpawnSettings(SpawnSettings::BIOME_TYPE_DEFAULT, "", DimensionIds::OVERWORLD); //TODO: implement this properly
		$levelSettings->worldGamemode = TypeConverter::getInstance()->coreGameModeToProtocol($this->server->getGamemode());
		$levelSettings->difficulty = $location->getWorld()->getDifficulty();
		$levelSettings->spawnPosition = BlockPosition::fromVector3($location->getWorld()->getSpawnLocation());
		$levelSettings->hasAchievementsDisabled = true;
		$levelSettings->time = $location->getWorld()->getTime();
		$levelSettings->eduEditionOffer = 0;
		$levelSettings->rainLevel = 0; //TODO: implement these properly
		$levelSettings->lightningLevel = 0;
		$levelSettings->commandsEnabled = true;
		$levelSettings->gameRules = [
			"naturalregeneration" => new BoolGameRule(false, false) //Hack for client side regeneration
		];
		$levelSettings->experiments = new Experiments([], false);

		$this->session->sendDataPacket(StartGamePacket::create(
			$this->player->getId(),
			$this->player->getId(),
			TypeConverter::getInstance()->coreGameModeToProtocol($this->player->getGamemode()),
			$this->player->getOffsetPosition($location),
			$location->pitch,
			$location->yaw,
			new CacheableNbt(CompoundTag::create()), //TODO: we don't care about this right now
			$levelSettings,
			"",
			$this->server->getMotd(),
			"",
			false,
			new PlayerMovementSettings(PlayerMovementType::SERVER_AUTHORITATIVE_V1, 0, false),
			0,
			0,
			"",
			false,
			 "NetherGames v4.0",
			Uuid::fromString(Uuid::NIL),
			[],
			0,
			GlobalItemTypeDictionary::getInstance()->getDictionary($dictionaryProtocol)->getEntries()
		));

		$this->session->sendDataPacket(StaticPacketCache::getInstance()->getAvailableActorIdentifiers());
		$this->session->sendDataPacket(StaticPacketCache::getInstance()->getBiomeDefs());
		$this->session->syncAttributes($this->player, $this->player->getAttributeMap()->getAll());
		$this->session->syncAvailableCommands();
		$this->session->syncAbilities($this->player);
		$this->session->syncAdventureSettings();
		foreach($this->player->getEffects()->all() as $effect){
			$this->session->onEntityEffectAdded($this->player, $effect, false);
		}
		$this->player->sendData([$this->player]);

		$this->inventoryManager->syncAll();
		$this->inventoryManager->syncCreative();
		$this->inventoryManager->syncSelectedHotbarSlot();
		$this->session->sendDataPacket(CraftingDataCache::getInstance()->getCache($dictionaryProtocol, $this->server->getCraftingManager()));

		$this->session->syncPlayerList($this->server->getOnlinePlayers());
	}

	public function handleRequestChunkRadius(RequestChunkRadiusPacket $packet) : bool{
		$this->player->setViewDistance($packet->radius);

		return true;
	}
}
