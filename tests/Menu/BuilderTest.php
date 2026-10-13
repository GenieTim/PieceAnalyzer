<?php

namespace App\Tests\Menu;

use App\Menu\Builder;
use Knp\Menu\FactoryInterface;
use Knp\Menu\MenuItem;
use PHPUnit\Framework\TestCase;

class BuilderTest extends TestCase
{
    public function testCreateMainMenu(): void
    {
        $factory = $this->createMock(FactoryInterface::class);
        $rootItem = $this->createMock(MenuItem::class);

        $factory->method('createItem')->with('root')->willReturn($rootItem);
        $rootItem->expects($this->once())
            ->method('setChildrenAttribute')
            ->with('class', 'navbar-nav ms-auto mb-2 mb-lg-0');

        $rootItem->expects($this->exactly(3))
            ->method('addChild')
            ->willReturnCallback(fn(string $name, array $options): \PHPUnit\Framework\MockObject\MockObject => $rootItem);

        $builder = new Builder($factory);
        $menu = $builder->createMainMenu([]);

        $this->assertSame($rootItem, $menu);
    }
}
