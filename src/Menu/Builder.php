<?php

declare(strict_types=1);

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
        $menu->setChildrenAttribute('class', 'navbar-nav ms-auto mb-2 mb-lg-0');

        $menu->addChild('Home', ['route' => 'index']);
        $menu->addChild('Explore Sets', [
            'route' => 'filter_items',
            'extras' => [
                'routes' => [
                    ['route' => 'filter_items'],
                    ['route' => 'list_all'],
                ],
            ],
        ]);
        $menu->addChild('Bookmarklet', ['route' => 'bookmarklet']);

        return $menu;
    }
}
