<?php

namespace Navigation;

use Nette\Application\UI\Form,
	\Nette\Utils\Validators;

/**
 * Creates hieratical data structure. Front end and back end as well
 */
class MyNavigationBuilder extends MyNavigation {

	/** disbles CMS data when inserting and editing items - leaves only Tree important data */
	private $onlyTree = false;

	/** persistent */
	protected $renderedArgs;

	/** If it is possible to edit menu items at all */
	private $editable = true;

	/** If it is possible to edit Root item - usually needed for CMS interaction - f.e. HP redirect */
	private $homepageEditable = false;

	/** Disables all interactive buttons. Makes only a list of items. */
	private $disableButtons = false;

	/** url to Redirect when clicking on item (title) - usefull for list only as a redirect (f.e.gallery list) */
	private $urlOnClick = "this";

	/** CMS specific. Setting up different types of content, with their db tables, presenters etc. */
	private $typesOfContent = array();

	/** Sets navigation as builder - admin part with buttons and possibility of changing data. Should be constructor, depends on Nette version. */
	public function setAsBuilder() {
		$this->setFrontEnd(false);
		$this->RenderWhole = true;
		$this->setMenuTemplate(__DIR__ . '/myNavigationBuilder.latte');
	}

	/** CMS specific. Enables all functionality to connect with CMS */
	public function setForCMS() {
		$this->setContentTypes();
		$this->setEditable(true);
		$this->setHomepageEditable(true);
		$this->setDisableButtons(false);
		$this->setOnlyTree(false);
	}

	public function setupBeforeRender($template) {
		$template->args = $this->renderedArgs;
		$template->urlOnClick = $this->urlOnClick;
		$template->homepageEditable = $this->homepageEditable;
		$template->editable = $this->editable;
		$template->disableButtons = $this->disableButtons;
		return $template;
	}

	/* FORMS FOR BASIC BUILDER FUNCTIONALITY */

	/** For moving branches and items
	 * @return \Nette\Application\UI\Form
	 */
	protected function createComponentMoveForm() {
		$form = new Form;
		$options = $this->closureModel->getOptions();
		$form->addSelect("parentId", "Branch Parent", $options);
		$form->addHidden("id", $this->edit_id);
		$form->addSubmit('save', 'Save')
				->onClick[] = callback($this, 'moveFormSubmitted');
		return $form;
	}

	public function moveFormSubmitted($btn) {
		$data = $btn->form->getValues();
		$result = $this->closureModel->moveSubtree($data->parentId, $data->id);
		$this->invalidateAll($result);
	}

//<editor-fold defaultstate="collapsed" desc="forms and submit functions - mostly project or admin CMS specific">
	/* FORMS FOR MORE PROJECT-SPECIFIC FUNCTIONALITY */

	/** Project-specific: basic example of functionality for some kind of light weight CMS. Editing items. */
	protected function createComponentEditForm() {
		$form = new Form;
		$form->getElementPrototype()->class("formWide");
		$options = array(0 => "Default", 1 => "Odkaz na obsah", 3 => "Odkaz na položku menu");
		// easy way how to create url slug
		$form->addText("title", "Název:")
				->setAttribute('onchange', '
							var nodiac = { "á": "a", "č": "c", "ď": "d", "é": "e", "ě": "e", "í": "i", "ň": "n", "ó": "o", "ř": "r", "š": "s", "ť": "t", "ú": "u", "ů": "u", "ý": "y", "ž": "z" };
							s = $("#frmeditForm-title").val().toLowerCase();
							var s2 = "";
							for (var i=0; i < s.length; i++) {
								s2 += (typeof nodiac[s.charAt(i)] != "undefined" ? nodiac[s.charAt(i)] : s.charAt(i));
							}
							result=s2.replace(/[^a-z0-9_]+/g, "-").replace(/^-|-$/g, "");
							$("#frmeditForm-url").val(result);
						');
		$form->addText("url", "url:")->setAttribute("readonly", "readonly");
		if (!$this->onlyTree) {
			$form->addSelect("target_type", "Cíl:", $options);
			$content = $this->prepareContentSelectBox();
			$menuContent = $this->prepareMenuContentSelectBox();
			$form->addSelect("target_id", "Odkazovaný obsah:", $content)->setPrompt("Pouze při zvolení výše");
			$form->addSelect("target_menu", "Odk. položka menu:", $menuContent)->setPrompt("Pouze při zvolení výše");
		}
		$form->addHidden("parent_id", $this->parent_id);
		$form->addHidden("edit_id", $this->edit_id);
		//filling form
		if ($this->edit_id or $this->edit_id === "0") {
			$defFromDb = $this->closureModel->getItemById($this->edit_id);
			$defaults = new \Nette\ArrayHash;
			foreach ($defFromDb as $key => $value) {
				$defaults->$key = $value;
			}
			if ($this->edit_id === "0") {
				$form["title"]->setDisabled();
			}
			if (!$this->onlyTree) {
				$t = $defaults->target;
				if (!Validators::isNumericInt($t)) {
					$menuItem = $this->checkTargetMenuItemExists($t);
					if ($menuItem) {
						$defaults->target_type = 3;
						$defaults->target_menu = $menuItem;
					} else {
						$contentId = $this->checkTargetContentExists($t);
						$defaults->target_type = 1;
						$defaults->target_id = $contentId;
					}
					$form["target_menu"]->getControlPrototype()->addClass("formFieldInvisible");
				} elseif ($t <= 0) {
					$defaults->target_type = 0;
					$form["target_menu"]->getControlPrototype()->addClass("formFieldInvisible");
					$form["target_id"]->getControlPrototype()->addClass("formFieldInvisible");
				}
			}
			$defaults->target = null;
			$form->setDefaults($defaults);
		}

		$form->addSubmit('save', 'Save')
						->setAttribute('onclick', '$("#frmeditForm-title").trigger("change")')
				->onClick[] = callback($this, 'editFormSubmitted');
		return $form;
	}

	public function editFormSubmitted($btn) {
		$data = $btn->form->getValues();
		if ($data["parent_id"] === "0") {
			\Nette\Diagnostics\FireLogger::log(true);
		}
		if (!$this->onlyTree) {
			//CMS menu for showing
			$t = $data["target_type"];
			if ($t == 3) {
				$target = $this->prepareTargetFromChildItem($data["target_menu"]);
			} elseif ($t == 2) {
				$target = $data["target_url"];
			} elseif ($t <= 0) {
				$target = 0;
			} elseif ($t == 1) {
				$target = $this->prepareFieldFromContentSelectBox($data["target_id"]); //$data["target_id"];
			}
		} else {
			$target = 0;
		}
		//protection from error when trying to save parameter as a column
		$dataForSaving = array("title" => $data["title"], "url" => $data["url"], "target" => $target);
		if ($dataForSaving["title"] == "ROOT" or !$dataForSaving["title"]) {
			unset($dataForSaving["title"]);
			unset($dataForSaving["url"]);
		}
		if ($data["parent_id"] || $data["parent_id"] === "0") {
			\Nette\Diagnostics\FireLogger::log("parent");
			$parent_id = $data["parent_id"];
			$result = $this->closureModel->createChild($dataForSaving, $parent_id);
		} elseif ($data["edit_id"] or $data["edit_id"] === "0") {
			$edit_id = $data["edit_id"];
			$result = $this->closureModel->editItem($dataForSaving, $edit_id);
		} else {
			$result = false;
		}
		$this->invalidateAll($result);
	}

	/**
	 * CMS-specific example
	 * Checks target from DB if its set URL or if its existing menu Item 
	 */
	private function checkTargetMenuItemExists($target) {
		$query = explode(",", $target);
		$args = explode(";", $query[1]);
		foreach ($args as $value) {
			$items = explode("=>", $value);
			$arguments[$items[0]] = $items[1];
		}
		if (!isset($arguments["url"]) or !isset($arguments["menu_id"])) {
			return false;
		}
		$conditions["url"] = $arguments["url"];
		$conditions["id"] = $arguments["menu_id"];
		$count = $this->db->table($this->closureModel->getTreeItems())->where($conditions)->count();
		if ($count === 0) {
			return false;
		} elseif ($count === 1) {
			return $arguments["menu_id"];
		}
	}

	/** Checks whether exists target content_id	
	 * CMS-specific example
	 * @param type $target
	 * @return string table:content_id for selectBox. False if not exists.
	 */
	private function checkTargetContentExists($target) {
		$query = explode(",", $target);
		$args = explode(";", $query[1]);
		foreach ($args as $value) {
			$items = explode("=>", $value);
			$arguments[$items[0]] = $items[1];
		}
		if (!isset($arguments["content_id"])) {
			return false;
		}
		foreach ($this->typesOfContent as $type) {
			if ($type["target"] == $query[0]) {
				$data = $type;
			}
		}
		$exists = $this->db->table($data["table"])->get($arguments["content_id"]);
		if ($exists) {
			return $data["table"] . ":" . $arguments["content_id"];
		}
	}

	/** Prepares array to use in Nette\SelectBox imitate UNION for each Content types */
	private function prepareContentSelectBox() {
		$content = array();
		foreach ($this->typesOfContent as $type) {
			$data = $this->db->table($type["table"])->fetchPairs("id", "title");
			foreach ($data as $key => $value) {
				if ($value == "ROOT") {
					continue;
				}
				$content["$type[table]:$key"] = "$type[title] - $value";
			}
		}
		return $content;
	}

	/** Prepares array of menuItems to use in selectBox */
	private function prepareMenuContentSelectBox() {
		$query = $this->closureModel->getStructureAsBreadcrumbsPath()->fetchPairs("id", "path");
		return $query;
	}

	/** Prepares target as a string to DB from SelectBox value */
	private function prepareFieldFromContentSelectBox($target) {
		$data = explode(":", $target);
		foreach ($this->typesOfContent as $type) {
			if ($type["table"] == $data[0]) {
				$result = $type["target"] . "," . $type["argument"] . "=>" . $data[1];
				return $result;
			}
		}
		return false;
	}

	/** Canonize url if we need the same target as children item */
	private function prepareTargetFromChildItem($targetMenuId) {
		$targetRow = $this->db->table($this->closureModel->getTreeItems())->get($targetMenuId);
		$parent = $this->closureModel->getImmediateParent($targetRow->id, true);
		if (!$targetRow->target) {
			$mainTarget = $this->linkedPresenter . ",";
		} else {
			$mainTarget = $targetRow->target . ";";
		}
		$newTarget = $mainTarget . "menu_id=>" . $targetRow->id . ";url=>" . $targetRow->url . (($parent) ? ";parent_url=>" . $parent : "");
		return $newTarget;
	}

//</editor-fold>
//<editor-fold defaultstate="collapsed" desc="handlers">
	public function handleDelete($id) {
		$result = $this->closureModel->delete($id);
		$this->redAndFlash($result);
	}

	public function handleMoveUp($id) {
		$result = $this->closureModel->changeSequence($id, -1);
		$this->redAndFlash($result);
	}

	public function handleMoveDown($id) {
		$result = $this->closureModel->changeSequence($id, +1);
		$this->redAndFlash($result);
	}

	/**
	 * takes care of ajax request and calls the right form
	 * @param type $name
	 * @param type $parent_id 
	 */
	public function handleGetForm($name, $parent_id = null, $edit_id = null) {
		$this->parent_id = $parent_id;
		$this->edit_id = $edit_id;
		$var = "show" . ucfirst($name) . "Form";
		$this->renderedArgs[$var] = true;
		$this->invalidateControl($name . "Form");
	}

	/*
	 * shortcut for flashing result, invalidating and redirecting at once
	 */

	public function invalidateAll($result) {
		if (!$result) {
			$this->presenter->flashMessage("Operation failed. Please repeat in a while", "danger");
		} else {
			$this->presenter->flashMessage("Your request was proceeded smoothly");
		}
		//$this->invalidateControl('simpleForm');
		$this->presenter->invalidateControl('flashes');
		//$this->invalidateControl('nav');
		$this->redirect('this');
	}

	/*
	 * shortcut for flashing result and redirecting at once. For project-specific commands is different to invalidateAll
	 */

	public function redAndFlash($result) {
		if (!$result) {
			$this->presenter->flashMessage("Operation failed. Please repeat in a while", "danger");
		} else {
			$this->presenter->flashMessage("Your request was proceeded smoothly");
		}
		$this->redirect("this");
	}

//</editor-fold>
//<editor-fold defaultstate="collapsed" desc="setters">
	public function setEditable($value) {
		$this->editable = $value;
	}

	public function setHomepageEditable($value) {
		$this->homepageEditable = $value;
	}

	public function setDisableButtons($value) {
		$this->disableButtons = $value;
	}

	public function setOnlyTree($value) {
		$this->onlyTree = $value;
	}

	/** sets up url where to redirect after click in navigation builder on title
	 * @param string $url as Nette address
	 */
	public function setUrlOnClick($url) {
		$this->urlOnClick = $url;
	}

	public function setContentTypes() {
		$this->typesOfContent[] = array("title" => "článek", "table" => "content", "target" => "Homepage:default", "argument" => "content_id");
		//$this->typesOfContent[] = array("title" => "galerie", "table" => "galleryItems", "target" => "Homepage:gallery", "argument" => "content_id");
	}

//</editor-fold>
}