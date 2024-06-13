<?php

declare(strict_types=1);

namespace DavidGlitch04\VanillaEC;

use DavidGlitch04\VanillaEC\Enchantment\{BaneOfArthropodsEnchantment,
    FortuneEnchantment,
    LootingEnchantment,
    SmiteEnchantment};
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\EnchantmentIdMap;
use pocketmine\entity\Living;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\entity\{EntityDamageByEntityEvent, EntityShootBowEvent};
use pocketmine\event\Listener;
use pocketmine\item\{enchantment\StringToEnchantmentParser, Item, LegacyStringToItemParser, VanillaItems};
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;

class Main extends PluginBase implements Listener
{

    public const UNDEAD = [
        EntityIds::ZOMBIE,
        EntityIds::HUSK,
        EntityIds::WITHER,
        EntityIds::SKELETON,
        EntityIds::STRAY,
        EntityIds::WITHER_SKELETON,
        EntityIds::ZOMBIE_PIGMAN,
        EntityIds::ZOMBIE_VILLAGER
    ];

    public const ARTHROPODS = [
        EntityIds::SPIDER,
        EntityIds::CAVE_SPIDER,
        EntityIds::SILVERFISH,
        EntityIds::ENDERMITE
    ];

    public function onLoad(): void
    {
        $this->saveDefaultConfig();
        $enchants = [
            new LootingEnchantment(),
            new SmiteEnchantment(),
            new BaneOfArthropodsEnchantment()
        ];
        foreach ($enchants as $enchant) {
            EnchantmentIdMap::getInstance()->register($enchant->getMcpeId(), $enchant);
            StringToEnchantmentParser::getInstance()->register($enchant->getId(), fn() => $enchant);
        }
    }

    public function onEnable(): void
    {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    /**
     * @param BlockBreakEvent $event
     */
    public function onBreak(BlockBreakEvent $event): void
    {
        $block = $event->getBlock();
        $item = $event->getItem();
        $enchantment = new FortuneEnchantment();

        if ($block->isSameState(VanillaBlocks::OAK_LEAVES())) {
            if (mt_rand(1, 99) <= 10) {
                $event->setDrops([VanillaItems::APPLE()]);
            }
        }

        if (($level = $item->getEnchantmentLevel(EnchantmentIdMap::getInstance()->fromId($enchantment->getMcpeId()))) > 0) {
            $add = mt_rand(0, $level + 1);

            if ($block->isSameState(VanillaBlocks::OAK_LEAVES())) {
                if (mt_rand(1, 99) <= 10) {
                    $event->setDrops([VanillaItems::APPLE()]);
                }
            }

            foreach ($this->getConfig()->get("fortune.blocks", []) as $str) {
                $itemFortune = LegacyStringToItemParser::getInstance()->parse($str);

                if ($block->asItem()->equals($itemFortune)) {
                    if (mt_rand(1, 99) <= 10 * $level) {
                        if (!empty($event->getDrops())) {
                            $event->setDrops(array_map(function (Item $drop) use ($add) {
                                $drop->setCount($drop->getCount() + $add);
                                return $drop;
                            }, $event->getDrops()));
                        }
                    }
                    break;
                }
            }
        }
    }

    /**
     * @param EntityDamageByEntityEvent $event
     */
    public function onDamage(EntityDamageByEntityEvent $event): void
    {
        $player = $event->getEntity();
        $killer = $event->getDamager();
        if ($killer instanceof Player) {
            $item = $killer->getInventory()->getItemInHand();
            $smiteEnchantment = new SmiteEnchantment();
            $arthropodsEnchantment = new BaneOfArthropodsEnchantment();
            $lootingEnchantment = new LootingEnchantment();
            if ($item->hasEnchantment(EnchantmentIdMap::getInstance()->fromId($smiteEnchantment->getMcpeId()))) {
                if (in_array($player::getNetworkTypeId(), self::UNDEAD)) {
                    $event->setBaseDamage($event->getBaseDamage() + (2.5 * $item->getEnchantmentLevel(EnchantmentIdMap::getInstance()->fromId($smiteEnchantment->getMcpeId()))));
                }
            }
            if ($item->hasEnchantment(EnchantmentIdMap::getInstance()->fromId($arthropodsEnchantment->getMcpeId()))) {
                if (in_array($player::getNetworkTypeId(), self::ARTHROPODS)) {
                    $event->setBaseDamage($event->getBaseDamage() + (2.5 * $item->getEnchantmentLevel(EnchantmentIdMap::getInstance()->fromId($arthropodsEnchantment->getMcpeId()))));
                }
            }
            if (($level = $killer->getInventory()->getItemInHand()->getEnchantmentLevel(EnchantmentIdMap::getInstance()->fromId($lootingEnchantment->getMcpeId()))) > 0) {
                if (
                    !$player instanceof Player and
                    $player instanceof Living and
                    $event->getFinalDamage() >= $player->getHealth()
                ) {
                    $add = mt_rand(0, $level + 1);
                    if (is_bool($this->getConfig()->get("looting.entities"))) {
                        $this->getLogger()->debug("There is an error (looting) in the config of vanillaEC");
                        return;
                    }
                    foreach ($this->getConfig()->get("looting.entities", []) as $items) {
                        $drops = $this->getLootingDrops($player->getDrops(), $items, $add);
                        foreach ($drops as $drop) {
                            $killer->getWorld()->dropItem($player->getPosition()->asVector3(), $drop);
                        }
                        $player->flagForDespawn();
                    }
                }
            }
        }
    }

    /**
     * @param array $drops
     * @param array $items
     * @param int   $add
     * @return array
     */
    public function getLootingDrops(array $drops, array $items, int $add): array
    {
        $lootingDrops = [];

        foreach ($items as $ite) {
            $item = LegacyStringToItemParser::getInstance()->parse($ite);
            /** @var Item $drop */
            foreach ($drops as $drop) {
                if ($drop->equals($item)) {
                    $drop->setCount($drop->getCount() + $add);
                }
                $lootingDrops[] = $drop;
                break;
            }
        }
        return $lootingDrops;
    }

    /**
     * @param EntityShootBowEvent $event
     */
    public function onShoot(EntityShootBowEvent $event): void
    {
        $arrow = $event->getProjectile();

        if ($arrow::getNetworkTypeId() == EntityIds::ARROW) {
            $event->setForce($event->getForce() + 0.95);
        }
    }
}
