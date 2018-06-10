<?php

namespace App\Menu;

use Knp\Menu\FactoryInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;

class Builder
{

    private $factory;

    public function __construct(FactoryInterface $factory)
    {
        $this->factory = $factory;
    }

    public function createMainMenu(array $options)
    {
        $menu = $this->factory->createItem('root');
        $menu->setChildrenAttribute('class', 'nav navbar-nav navbar-right');

        $menu->addChild('Home', array('route' => 'index'));
        $menu->addChild('List Sets', array('route' => 'list_all'));
//        $menu->addChild('Admin', array('uri' => '#',
//            'attributes' => array('dropdown' => TRUE))
//        );
//        $menu['Admin']->addChild('Refresh Set Data', array('route' => 'load_files', 'routeParameters' => array('index' => 1)));
//        $menu['Admin']->addChild('Refresh Prices', array('route' => 'load_prices_brickpicker'));

        return $menu;
    }
}
