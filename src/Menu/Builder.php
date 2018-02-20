<?php

namespace App\Menu;

use Knp\Menu\FactoryInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;

class Builder {
    
    private $factory;

    public function __construct(FactoryInterface $factory) {
        $this->factory = $factory;
    }
    
    public function createMainMenu(array $options) {
        $menu = $this->factory->createItem('root');
        $menu->setChildrenAttribute('class', 'nav navbar-nav navbar-right');

        $menu->addChild('Home', array('route' => 'index'));
        $menu->addChild('List Sets', array('route' => 'list_all'));
        $menu->addChild('Refresh Set Data', array('route' => 'load_files'));

        return $menu;
    }

}
