<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

class SelectLoadFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('from', IntegerType::class, [
                'label' => 'Start Set Number',
                'attr' => ['placeholder' => 'e.g. 10000', 'min' => 1],
                'help' => 'First LEGO set number in the range to import.',
                'constraints' => [
                    new Assert\NotNull(),
                    new Assert\Positive(),
                ],
            ])
            ->add('to', IntegerType::class, [
                'label' => 'End Set Number',
                'attr' => ['placeholder' => 'e.g. 10050', 'min' => 1, 'max' => 1000000],
                'help' => 'Last LEGO set number in the range (up to 1,000,000).',
                'constraints' => [
                    new Assert\NotNull(),
                    new Assert\Positive(),
                    new Assert\LessThanOrEqual([
                        'value' => 1000000,
                        'message' => 'Set number exceeds supported limit',
                    ]),
                ],
            ])
            ->add('save', SubmitType::class, [
                'label' => 'Import Set Range',
                'attr' => ['class' => 'btn-primary w-100'],
            ])
        ;
    }
}
