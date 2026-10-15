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
                'label' => 'import.start_set_number',
                'attr' => ['placeholder' => 'import.start_set_placeholder', 'min' => 1],
                'help' => 'import.start_set_help',
                'constraints' => [
                    new Assert\NotNull(),
                    new Assert\Positive(),
                ],
            ])
            ->add('to', IntegerType::class, [
                'label' => 'import.end_set_number',
                'attr' => ['placeholder' => 'import.end_set_placeholder', 'min' => 1, 'max' => 1000000],
                'help' => 'import.end_set_help',
                'constraints' => [
                    new Assert\NotNull(),
                    new Assert\Positive(),
                    new Assert\LessThanOrEqual([
                        'value' => 1000000,
                        'message' => 'import.limit_exceeded',
                    ]),
                ],
            ])
            ->add('save', SubmitType::class, [
                'label' => 'import.submit',
                'attr' => ['class' => 'btn-primary w-100'],
            ])
        ;
    }
}
