<?php

namespace App\Menu;

use Knp\Menu\FactoryInterface;
use Knp\Menu\ItemInterface;

class Builder
{
    public function __construct(private readonly FactoryInterface $factory)
    {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function createMainMenu(array $options): ItemInterface
    {
        $menu = $this->factory->createItem('root');
        $menu->setChildrenAttribute('class', 'nav navbar-nav navbar-right');

        $menu->addChild('Home', ['route' => 'index']);
        $menu->addChild('List Sets', ['route' => 'list_all']);

        return $menu;
    }
}
