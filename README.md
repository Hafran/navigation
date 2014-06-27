navigation
==========
Addon for Nette for easy working, building and presenting hieratical data stored in DB. Useful for creating basic navigation or for easier working with Closure Tree system.

Best Compatible with:
Nette 2.0.15
Bootstrap 2.3.2 or 3
jQuery and jQuery UI

ClosureTreeHelper.php handles all interaction with Closure Tree hieratical data in DB and could be used alone for easy DB managment.

MyNavigation.php is layer between ClosureTreeHelper and lite-weight Navigation addon

MyNavigationBuilder.php is admin layer for operating and creating menu items.

Basic interaction with Nette is done by well-known Navigation addon by Jan Marek under license MIT for easier use of Nette users.

useClosureTree() expects two table names of common Closure Tree structure. SQL for creating those tables is included

in template just : {control tree} 

FrontEnd in Presenter:
``` PHP
    protected function createComponentTree($name) {
		$tree = new Navigation\MyNavigation($this, $name);
		$tree->useClosureTree("treeItems", "treeStructure");
		$tree->setPresenter("Homepage:default");
		$tree->create();
		}
```
For easier showing all data items at once you can set:
``` PHP
    $tree->renderWhole = true;
``` 
In template: 
``` PHP
    {control tree}
``` 

For easier dividing menu navigation into two block use in template:
``` PHP
    {control tree:mainLocation}
    {control tree:subLocation}
``` 

BackEnd in Presenter (most common setup) :
``` PHP
    protected function createComponentTree($name) {
		$tree = new Navigation\MyNavigationBuilder($this, $name);
		$tree->useClosureTree("treeItems", "treeStructure");
		$tree->setPresenter("Homepage:default");
		$tree->setMenuTemplate('../vendor/others/Navigation/' . "myNavigationBuilder.latte");
		$tree->setAsBuilder();
  	$tree->onlyTree = true;
		$tree->create(true);
		}
```
Backend could cause problems if you rename the component otherwise - because of dynamically added info modal windows. 

For Connecting with Hafran CMS add also:
``` PHP
    $tree->setForCMS();
```


