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
                'constraints' => [
                    new Assert\NotNull(),
                    new Assert\Positive(),
                ],
            ])
            ->add('to', IntegerType::class, [
                'constraints' => [
                    new Assert\NotNull(),
                    new Assert\Positive(),
                    new Assert\LessThanOrEqual([
                        'value' => 1000000,
                        'message' => 'Set number exceeds supported limit',
                    ]),
                ],
            ])
            ->add('save', SubmitType::class)
        ;
    }
}

