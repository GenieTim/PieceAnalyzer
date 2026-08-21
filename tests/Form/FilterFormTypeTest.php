<?php

namespace App\Tests\Form;

use App\Form\FilterFormType;
use App\Repository\PieceRepository;
use App\Service\CsvLegoLoaderService;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\TypeTestCase;

class FilterFormTypeTest extends TypeTestCase
{
    private FilterFormType $filterFormType;

    protected function setUp(): void
    {
        $pieceRepo = $this->createMock(PieceRepository::class);
        $csvLoader = $this->createMock(CsvLegoLoaderService::class);

        $csvLoader->method('getColors')->willReturn([
            ['id' => '1', 'name' => 'Blue'],
            ['id' => '2', 'name' => 'Green'],
        ]);

        $csvLoader->method('getCategories')->willReturn([
            ['id' => '1', 'name' => 'Classic'],
            ['id' => '2', 'name' => 'Technic'],
        ]);

        $this->filterFormType = new FilterFormType($pieceRepo, $csvLoader);

        parent::setUp();
    }

    protected function getExtensions(): array
    {
        return [
            new PreloadedExtension([$this->filterFormType], []),
        ];
    }

    public function testSubmitValidData(): void
    {
        $form = $this->factory->create(FilterFormType::class);

        $formData = [
            'color' => 1,
            'category' => 2,
        ];

        $form->submit($formData);

        $this->assertTrue($form->isSynchronized());
        $this->assertSame(1, $form->get('color')->getData());
        $this->assertSame(2, $form->get('category')->getData());
    }
}
