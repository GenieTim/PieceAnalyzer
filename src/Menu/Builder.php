<?php

namespace App\Menu;

use Knp\Menu\FactoryInterface;

class Builder {

    public function mainMenu(FactoryInterface $factory, array $options) {
        $menu = $factory->createItem('root');

        $menu->addChild('Home', array('route' => 'index'));
        $menu->addChild('List Sets', array('route' => 'list_all'));
        $menu->addChild('Suggest Set', array('route' => 'load_range'));

        return $menu;
    }

}
