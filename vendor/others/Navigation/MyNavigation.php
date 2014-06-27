<?php

namespace Navigation;

use Nette\Utils\Validators,
	ClosureTreeHelper\ClosureTreeHelper;

class MyNavigation extends Navigation {
// <editor-fold defaultstate="collapsed" desc="variables">

	/** ClosureTreeHelper\ClosureTreeHelper */
	protected $closureModel;

	/** persistent */
	public $parent_id;

	/** persistent */
	public $edit_id;

	/** Nette\Database\Connection */
	protected $db;

	/** @var bool */
	private $renderWhole = false;

	/** @var string */
	protected $linkedPresenter = "Default";

	/** Whether its needed to test Current link - in real, used only in Front end menu */
	private $frontEnd = true;

//</editor-fold>

	public function render() {
		if ($this->renderWhole) {
			$this->renderWholeMenu();
		} else {
			$this->renderMenu();
		}
	}

	/**
	 * Add navigation node as a child
	 * Overriding parent function to be able to pass primary id
	 * @param string $label
	 * @param string $url
	 * @return NavigationNode
	 */
	public function add($label, $url, $id = null) {
		return $this->getComponent('homepage')->add($label, $url, $id);
	}

	public function setRenderWhole($option) {
		$this->renderWhole = $option;
	}

	public function create($empty = false) {
		$this->db = $this->presenter->getService("nette.database.default");
		$data = $this->closureModel->getDataForNavigation();
		$this->buildMenu(0, $data, $this, null, $empty);
	}

	/**
	 * Render complete menu - no hidden items
	 * @param bool $renderChildren
	 * @param NavigationNode $base
	 * @param bool $renderHomepage
	 */
	public function renderWholeMenu($renderChildren = TRUE, $base = NULL, $renderHomepage = TRUE) {
		$template = $this->createTemplate()
				->setFile($this->menuTemplate ? : __DIR__ . '/menu.latte');
		$template->args = null;
		$template = $this->setupBeforeRender($template);
		$template->opened = array();
		$template->homepage = $base ? $base : $this->getComponent('homepage');
		$template->renderChildren = $renderChildren;
		$template->children = $this->getComponent('homepage')->getComponents();
		$template->render();
	}

	/**
	 * For easy setting up parameters during render. Overridden by myNavigationBuilder. 
	 * @param type $template
	 * @return type
	 */
	public function setupBeforeRender($template) {
		$template->renderWhole = $this->renderWhole;
		return $template;
	}

	/**
	 * Render menu - classic menu - only highest level and active sublevels
	 */
	public function renderMenu($renderChildren = TRUE, $base = NULL, $renderHomepage = TRUE) {
		$items = array();
		$node = $this->currentNode;
		while ($node instanceof NavigationNode) {
			$parent = $node->getParent();
			if (!($parent instanceof NavigationNode)) {
				break;
			}
			array_unshift($items, $node);
			$node = $parent;
		}

		//Main items - 1st level - always visible
		$mainNodes = array();
		foreach ($this->getComponent('homepage')->getComponents(FALSE) as $component) {
			$mainNodes[] = $component;
		}

		$template = $this->createTemplate()
				->setFile($this->menuTemplate ? : __DIR__ . '/menu.latte');
		$template->homepage = $base ? $base : $this->getComponent('homepage');
		$template->renderChildren = $renderChildren;
		$template->opened = $items;
		$template->children = $mainNodes;
		$template = $this->setupBeforeRender($template);
		$template->render();
	}

// <editor-fold defaultstate="collapsed" desc="setters">

	public function setFrontEnd($value) {
		$this->frontEnd = $value;
	}

	/** Sets presenter to which all menu items will link */
	public function setPresenter($presenterName) {
		$this->linkedPresenter = $presenterName;
	}

	/**
	 * Sets up model and tables for closure tree
	 *
	 * Connects db through model
	 * @param string $items 
	 * @param string $structure
	 */
	public function useClosureTree($items, $structure) {
		//TODO: touching context - recommended to get DB otherwise, depending on Nette version (inject, construct)
		$this->closureModel = new ClosureTreeHelper($this->presenter->context->nette->database->default, $items, $structure);
	}

//</editor-fold>
// <editor-fold defaultstate="collapsed" desc="coreBuildingFunctions">

	/**
	 * Recursive function building menu on base of parents
	 * @param type $parent primary id of parent
	 * @param type $menu associative array from DB
	 * @param type Navigation\MyNavigation $nav navigation
	 * @param type $section sending section for recursion
	 * @param type $root if we would like to count root or not.
	 */
	function buildMenu($parent, $menu, MyNavigation $nav, $section = NULL, $root = true) {
		if ($parent === 0 and $root and $menu['parents'][0][0] == 0) {
			$this->createLink($parent, $menu, $nav, $section);
			return;
		}
		//If we dont want to count the main ROOT item. Title could be changed by homepage or in template
		if ($parent === 0 and isset($menu['parents'][$parent]) and !$root) {
			foreach ($menu['parents'][$parent] as $itemId) {
				if (!isset($menu['parents'][$itemId])) {
					$node = $this->createLink($itemId, $menu, $nav);
				}
				if (isset($menu['parents'][$itemId])) {
					$this->buildMenu($itemId, $menu, $nav);
				}
			}
		} else {
			//All other items
			if (isset($menu['parents'][$parent])) {
				//If there is not defined a section we create it
				if (!$section) {
					$sec = $this->createLink($parent, $menu, $nav);
				} else {
					$sec = $this->createLink($parent, $menu, $nav, $section);
				}

				foreach ($menu['parents'][$parent] as $itemId) {
					if (!isset($menu['parents'][$itemId])) {
						$this->createLink($itemId, $menu, $nav, $sec);
					}
					if (isset($menu['parents'][$itemId])) {
						$this->buildMenu($itemId, $menu, $nav, $sec);
					}
				}
			}
		}
	}

	/**
	 * Creates object in navigation, link and check whether its current
	 * @param type $id id Polozky z menu
	 * @param type $menu upravena data z databaze
	 * @param type $navigation Instance MyNavigation
	 * @param type $section Pokud jde o podsekci $section z MyNavigation
	 */
	function createLink($id, $menu, $navigation, $section = null) {
		$current = false;
		$target = $menu['items'][$id]['target'];
		$linkedArray = false;
		/* CMS specific. For other types of links (where is not sufficient to pass id) we use non numeric values, URL string and so on. */
		if (!Validators::isNumericInt($target) && !Validators::isUrl($target)) {
			$parent = $this->findImmediateParent($menu, $id, true);
			$request = $this->parseUrlFromTarget($target);
			$address = implode(":", $request["address"]);
			$link = $this->presenter->link($address, $request["parameters"]);
			if ($this->frontEnd) {
				if ($this->presenter->isLinkCurrent($address, $request["parameters"])) {
					$current = true;
				}
			}
		} elseif (Validators::isUrl($target)) {
			//we pass real url as is
			$link = $target;
		} else {
			$parent = $this->findImmediateParent($menu, $id, true);
			$url = $menu['items'][$id]['url'];
			$linkedArray = array_filter(array("parent_url" => $parent, "menu_id" => $id, "url" => $url));
			$link = $this->presenter->link($this->linkedPresenter, $linkedArray);
			if ($this->frontEnd) {
				if ($this->presenter->isLinkCurrent($this->linkedPresenter, $linkedArray)) {
					$current = true;
				}
			}
		}

		if ($section) {
			$node = $section->add($menu['items'][$id]['title'], $link, $id);
		} else {
			$node = $navigation->add($menu['items'][$id]['title'], $link, $id);
		}
		if ($current) {//$this->presenter->isLinkCurrent($this->linkedPresenter, $linkedArray)) {
			$navigation->setCurrentNode($node);
		}
		return $node;
	}

	/**
	 * Finds immediate parent of item of sent $id. Its NOT recursive: works only for 2-level menus
	 * @param type $menu 
	 * @param type $id of item which we need to know a parent
	 * @param type $url if set to true it returns string url (title) instead of int id
	 */
	public function findImmediateParent($menu, $id, $url = null) {
		$parent = null;
		foreach ($menu["parents"] as $key => $value) {
			foreach ($value as $k => $v) {
				if ($v == $id) {
					$parent = $key;
				}
			}
		}
		if (!$parent)
			return false;
		if (!$url) {
			return $parent;
		} else {
			return isset($menu["items"][$parent]["url"]) ? $menu["items"][$parent]["url"] : false;
		}
	}

	/*	 * CMS specific - parses string to NETTE link */

	public function parseUrlFromTarget($target) {
		$req = explode(",", $target);
		$addr = explode(":", $req[0]);
		$address["presenter"] = $addr[0];
		$address["action"] = $addr[1];
		if (count($address) == 3) {
			$address["module"] = $addr[2];
		}
		if (isset($req[1])) {
			$par = explode(";", $req[1]);
			$parameters = array();
			foreach ($par as $p) {
				$a = explode("=>", $p);
				$parameters[trim($a[0])] = trim($a[1]);
			}
		} else {
			$parameters = null;
		}
		return array("address" => $address, "parameters" => $parameters);
	}

// </editor-fold>

	/* If its needed to split menu template in two, but keep logical structure f.e. one menu horizontal, one vertical on different places */
	public function renderMainLocation() {
		$this->setMenuTemplate(__DIR__ . "/mainLocation.latte");
		$this->renderMenu($renderChildren = FALSE);
	}

	public function renderSubLocation() {
		$this->setMenuTemplate(__DIR__ . "/subLocation.latte");
		$this->renderMenu();
	}

}

