<?php

/**
 * Closure Tree Helper
 * @author Jakub Havranek
 * @license MIT
 * 2012
 */

namespace ClosureTreeHelper;

/* SQL query For creating tables. Could be deleted after creating.
 * It is recommend to rename them for specific needs. 
  CREATE TABLE `items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(127) NOT NULL,
  `url` varchar(127) NOT NULL,
  `sequence` int(11) NOT NULL DEFAULT '0',
  `target` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8;


  CREATE TABLE `structure` (
  `ancestor` smallint(5) unsigned NOT NULL,
  `descendant` smallint(5) unsigned NOT NULL,
  `depth` tinyint(3) unsigned NOT NULL,
  KEY `descendant` (`descendant`),
  KEY `ancestor` (`ancestor`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
  INSERT INTO `items` (`id`, `title`, `url`, `sequence`, `target`) VALUES
  (0,	'ROOT',	'ROOT',	0,	'0');
  INSERT INTO `structure` (`ancestor`, `descendant`, `depth`) VALUES
  (0,	0,	0);
 */

/**
 * helps with all basic operation in db stored data according to Closure Tree structure
 * it expects two tables a] item tables with data	b] table with structure and hierarchy
 * for better protection it uses TRANSACTION so use INNODB not MyISAM
 */
class ClosureTreeHelper extends \Nette\Object {

	/** @var string 
	 * name of table with items - menu/url/order
	 */
	private $treeItems;

	/** @var string 
	 * name of table with hieratical structure according to Closure Tree ancestor/descendant/depth
	 */
	private $treeStructure;

	/**
	 * @var \Nette\Database\Connection
	 * instance of db connection
	 */
	private $db;

	/**
	 *
	 * @param \Nette\Database\Connection $db
	 * @param type $treeItems
	 * @param type $treeStructure 
	 */
	public function __construct(\Nette\Database\Connection $db, $treeItems, $treeStructure) {
		$this->db = $db;
		$this->treeItems = $treeItems;
		$this->treeStructure = $treeStructure;
	}

	/* Shortcut to execute two core functions to get associative array for navigation
	 * @return type array
	 */

	public function getDataForNavigation() {
		$tree = $this->constructTree();
		$array = $this->prepareArray($tree);
		return $array;
	}

	/**
	 * basic query which joins our two tables and creates basic infrastructure
	 * @return Nette\Database\Statement
	 */
	public function constructTree() {
		return $this->db->query("SELECT c.*, cc.* FROM $this->treeItems c
								LEFT JOIN $this->treeStructure cc
								ON (c.id = cc.descendant) where cc.depth<2 ORDER BY c.sequence ASC");
	}

	/**
	 * From basic infrastructure is made associative array which can be more easily used afterwards
	 * @param Nette\Database\Statement $result
	 * @return associative array
	 */
	public function prepareArray($tree) {
		$menu = array(
			"items" => array(),
			"parents" => array()
		);
		// Builds the array lists with data from the menu table
		foreach ($tree as $items) {
			// Creates entry into items array with current menu item id ie. $menu["items"][1]
			$menu["items"][$items["id"]] = $items;
			// Creates entry into parents array. Parents array contains a list of all items with children
			if ($items["ancestor"] != $items["descendant"]) {
				$menu["parents"][$items["ancestor"]][] = $items["descendant"];
			}
		}
		if (empty($menu["parents"])) {
			$menu["parents"][0][0] = 0;
		}
		return $menu;
	}

//*********************** BASIC TREE OPERATIONS **********************************

	/**
	 * Creates new descendant
	 * @param array $dataToInsert
	 * @param type $parent
	 * @return type boolean
	 */
	public function createChild($dataToInsert, $parent) {
		try {
			$this->db->beginTransaction();
			$sequence = $this->db->query("select max($this->treeItems.sequence)+1 as seq from $this->treeItems
			join $this->treeStructure on $this->treeStructure.descendant = $this->treeItems.id 
			where $this->treeStructure.ancestor= $parent  and depth = 1")->fetch()->seq;
			$dataToInsert["sequence"] = ($sequence) ? $sequence : 0;
			$result = $this->db->table($this->treeItems)->insert($dataToInsert);
			$lastId = $result["id"];
			$this->db->table($this->treeStructure)->insert(array("ancestor" => $lastId, "descendant" => $lastId, "depth" => 0));
			$this->db->exec("INSERT INTO $this->treeStructure (ancestor,descendant,depth) SELECT ancestor,$lastId, depth+1 FROM $this->treeStructure where descendant = $parent");
			$this->db->commit();
			return true;
		} catch (Exception $e) {
			$this->db->rollback();
			return false;
		}
	}

	/**
	 * Moves whole branch (including all descendants) to a new position
	 * @param type $parentId id of a NEW parent
	 * @param type $branchId id of HIGHEST item in moved branch
	 * @return type boolean
	 */
	public function moveSubtree($parentId, $branchId) {
		try {
			$this->db->beginTransaction();
			//we get immediate parent of moving branch and its curent sequence so we can then update sequence of siblings
			//TODO: $this->getImmediateParent();
			$closestParent = $this->db->table($this->treeStructure)->where(array("descendant" => $branchId, "depth" => 1))->fetch()->ancestor;
			$oldSequence = $this->db->table($this->treeItems)->get($branchId)->sequence;

			// this will delete all connection above the subtree. It keeps connection within the subtree and self-connection
			$this->db->exec("DELETE a FROM $this->treeStructure AS a
			JOIN $this->treeStructure AS d ON a.descendant = d.descendant
			LEFT JOIN $this->treeStructure AS x
			ON x.ancestor = d.ancestor AND x.descendant = a.ancestor
			WHERE d.ancestor = $branchId AND x.ancestor IS NULL;");

			//we find a highest sequence and we make new branch last item in order
			$seq = $this->db->query("select max($this->treeItems.sequence)+1 as seq from $this->treeItems
			join $this->treeStructure on $this->treeStructure.descendant = $this->treeItems.id 
			where $this->treeStructure.ancestor = $parentId  and depth = 1")->fetch()->seq;
			$sequence = ($seq) ? $seq : 0;

			// this will move the subtree (and all below) to supertree
			$this->db->exec("INSERT INTO $this->treeStructure (ancestor, descendant, depth)
			SELECT supertree.ancestor, subtree.descendant,
			supertree.depth+subtree.depth+1
			FROM $this->treeStructure AS supertree JOIN $this->treeStructure AS subtree
			WHERE subtree.ancestor = $branchId
			AND supertree.descendant = $parentId;");
			$this->db->table($this->treeItems)->get($branchId)->update(array("sequence" => $sequence));

			//we find all ids and sequence of next siblings of moved branch which need to update
			$siblings = $this->db->query("select $this->treeItems.id, $this->treeItems.sequence from $this->treeItems
			join $this->treeStructure on $this->treeStructure.descendant = $this->treeItems.id 
			where $this->treeStructure.ancestor=" . $closestParent . " and depth = 1 and sequence > " . $oldSequence . " order by $this->treeItems.sequence")->fetchPairs("id");
			$idsToChange = array();
			foreach ($siblings as $SeqId => $value) {
				$idsToChange[] = $SeqId;
			}

			// lower sequence for all sibling with higher sequence than moved branch, because of the hole after removed (moved) branch
			$this->db->table($this->treeItems)->where("id", $idsToChange)->update(array("sequence" => new \Nette\Database\SqlLiteral("sequence-1")));
			$this->db->commit();
			return true;
		} catch (Exception $e) {
			$this->db->rollback();
			return false;
		}
	}

	public function delete($id) {
		// better safe than sorry
		if ($id == 0)
			return false;
		try {
			$this->db->beginTransaction();
			// PROJECT-SPECIFIC check attached content whehter it is safe to delete this tree item
			$empty = $this->checkContent($id);
			if ($empty) {
				$sequence = $this->db->table($this->treeItems)->get($id)->sequence;
				//TODO $this->getImmediateParent();
				$closestParent = $this->db->table($this->treeStructure)->where(array("descendant" => $id, "depth" => 1))->fetch()->ancestor;
				//TODO here is the same sequence as in Move Branch - for more specific projects its more easier. Of course the cleaner way is to move it to separate function.
				$siblings = $this->db->query("select $this->treeItems.id, $this->treeItems.sequence from $this->treeItems
				join $this->treeStructure on $this->treeStructure.descendant = $this->treeItems.id 
				where $this->treeStructure.ancestor=" . $closestParent . " and depth = 1 and sequence > " . $sequence . " order by $this->treeItems.sequence")->fetchPairs("id");
				$idsToChange = array();
				foreach ($siblings as $SeqId => $value) {
					$idsToChange[] = $SeqId;
				}

				$this->db->table($this->treeItems)->where("id", $idsToChange)->update(array("sequence" => new \Nette\Database\SqlLiteral("sequence-1")));
				$this->db->exec("DELETE cc_a FROM $this->treeStructure cc_a JOIN $this->treeStructure cc_d USING (descendant) WHERE cc_d.ancestor = $id");
				$this->db->exec("DELETE FROM $this->treeItems WHERE id = $id");
				$this->db->commit();
				return true;
			} else {
				return false;
			}
		} catch (Exception $e) {
			$this->db->rollback();
			return false;
		}
	}

	/**
	 * moves one item up or down in the list- changes sequence
	 * @param type $what
	 * @param type $direction +1 = down; -1 = up;
	 * @return type boolean
	 */
	public function changeSequence($what, $direction) {
		$step = $direction;
		try {
			$this->db->beginTransaction();
			$closestParent = $this->db->table($this->treeStructure)->where(array("descendant" => $what, "depth" => 1))->fetch()->ancestor;
			$oldPosition = $this->db->table($this->treeItems)->get($what)->sequence;
			$newPosition = $oldPosition + $step;
			$targetItem = $this->db->query("select $this->treeItems.id, $this->treeItems.sequence from $this->treeItems
			join $this->treeStructure on $this->treeStructure.descendant = $this->treeItems.id 
			where $this->treeStructure.ancestor=" . $closestParent . " and depth = 1 and $this->treeItems.sequence = " . $newPosition . " order by $this->treeItems.sequence");
			$exists = $targetItem->rowCount();
			if ($exists) {
				$targetItemId = $targetItem->fetch()->id;
				$this->db->query("update $this->treeItems set $this->treeItems.sequence = $this->treeItems.sequence - $step where id = $targetItemId");
				$this->db->query("update $this->treeItems set $this->treeItems.sequence = $this->treeItems.sequence + $step where id = $what");
			} else {
				return false;
			}
			$this->db->commit();
			return true;
		} catch (Exception $e) {
			$this->db->rollback();
			return false;
		}
	}

//*********************** SELECTION AND ITEM-SPECIFIC OPERATIONS **********************************

	/**
	 * Selects siblings - all items with same depth and parent. Useful in classic menus
	 * @param type $id
	 * @return type array  id => sequence; list of All siblings INCLUDING the asked one
	 */
	public function selectSiblings($id) {
		//TODO maybe $this->getImmediateParent($id)?
		$closestParent = $this->db->table($this->treeStructure)->where(array("descendant" => $id, "depth" => 1))->fetch()->ancestor;
		$targetItems = $this->db->query("select $this->treeItems.id, $this->treeItems.sequence from $this->treeItems
			join $this->treeStructure on $this->treeStructure.descendant = $this->treeItems.id 
			where $this->treeStructure.ancestor=" . $closestParent . " and depth = 1 order by $this->treeItems.sequence");
		return $targetItems;
	}

	/**
	 * Gets human readable path as in Breadcrumbs. Useful in selecting correct items where it is needed to see the whole path.
	 * @return type array id => breadcrumbs like string separated by ->
	 */
	public function getStructureAsBreadcrumbsPath() {
		$query = $this->db->query("select n.id, group_concat(n.title order by n.id separator ' -> ') as path
			from $this->treeStructure d
			join $this->treeStructure a on (a.descendant = d.descendant)
			join $this->treeItems n on (n.id = a.ancestor)
			where d.ancestor = 0 and d.descendant != d.ancestor
			group by d.descendant;");
		return $query;
	}

	public function editItem($data, $id) {
		return $this->db->table($this->treeItems)->get($id)->update($data);
	}

	/**
	 * @return type ActiveRow or FALSE
	 */
	public function getItemById($id) {
		return $this->db->table($this->treeItems)->get($id);
	}

	/**
	 * Get all Items in array with id and title. Usable for select boxes and other choices.
	 * @return type array id=>title
	 */
	public function getOptions() {
		return $this->db->table($this->treeItems)->select("id,title")->order("id")->fetchPairs("id", "title");
	}

	/**
	 * Gets immediate (closest) parent of item. DOESNT return ROOT
	 * @param type $id id of searched item
	 * @param type $url whether to return url(slug like string used in url creating), otherwise returns just id
	 * @return id or url of parent item, or false on failure
	 */
	public function getImmediateParent($id, $url = false) {
		$parentId = $this->db->table($this->treeStructure)->where(array("descendant" => $id, "depth" => 1))->fetch()->ancestor;
		//Nezobrazujeme ROOT
		if ($parentId === 0) {
			return false;
		}
		if (!$url) {
			return $parentId;
		} else {
			return $this->db->table($this->treeItems)->get($parentId)->url;
		}
	}

	/**
	 * Check whether menu item has any content attached to it or if its possible to delete it.
	 * It is project-specific content so in basic distribution it always return TRUE
	 * @param type $id 
	 * @return type boolean
	 */
	public function checkContent($id) {
		return true;
	}

	public function getTreeItems() {
		return $this->treeItems;
	}

	public function getTreeStructure() {
		return $this->treeStructure;
	}

}