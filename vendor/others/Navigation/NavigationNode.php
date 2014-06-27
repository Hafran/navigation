<?php

/**
 * Navigation node
 *
 * @author Jan Marek
 * @license MIT
 * @edited to pass primary id as well for easier operation
 */

namespace Navigation;

use Nette\ComponentModel\Container;

class NavigationNode extends Container
{

	/** @var string */
	public $label;

	/** @var string */
	public $url;

	/** @var int */
	public $id;
	
	/** @var bool */
	public $isCurrent = false;

	/**
	 * Add navigation node as a child
	 * @staticvar int $counter
	 * @param string $label
	 * @param string $url
	 * @return NavigationNode
	 */
	public function add($label, $url, $id) {
		$navigationNode = new self;
		$navigationNode->label = $label;
		$navigationNode->url = $url;
		$navigationNode->id = $id;
		static $counter;
		$this->addComponent($navigationNode, ++$counter);

		return $navigationNode;
	}

	/**
	 * Set node as current
	 * @param bool $current
	 * @return \Navigation\NavigationNode
	 */
	public function setCurrent($current)
	{
		$this->isCurrent = $current;

		if ($current) {
			$this->lookup('Navigation\Navigation')->setCurrentNode($this);
		}

		return $this;
	}

}
