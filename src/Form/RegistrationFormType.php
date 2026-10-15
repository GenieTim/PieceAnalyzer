<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class RegistrationFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Email Address',
                'attr' => [
                    'autocomplete' => 'email',
                    'placeholder' => 'you@example.com',
                ],
                'constraints' => [
                    new NotBlank([
                        'message' => 'Please enter an email address',
                    ]),
                    new Email([
                        'message' => 'Please enter a valid email address',
                    ]),
                    new Length([
                        'max' => 180,
                        'maxMessage' => 'Email cannot be longer than {{ limit }} characters',
                    ]),
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'first_options' => [
                    'label' => 'auth.password',
                    'attr' => [
                        'autocomplete' => 'new-password',
                        'placeholder' => 'Minimum 8 characters',
                    ],
                ],
                'second_options' => [
                    'label' => 'auth.password_repeat',
                    'attr' => [
                        'autocomplete' => 'new-password',
                        'placeholder' => 'Repeat your password',
                    ],
                ],
                'invalid_message' => 'auth.password_match_error',
                'mapped' => false,
                'constraints' => [
                    new NotBlank([
                        'message' => 'Please enter a password',
                    ]),
                    new Length([
                        'min' => 8,
                        'minMessage' => 'Your password must be at least {{ limit }} characters',
                        'max' => 4096,
                    ]),
                ],
            ])
            ->add('preferredCurrency', ChoiceType::class, [
                'label' => 'Preferred Currency',
                'choices' => [
                    'CHF (Swiss Franc)' => 'CHF',
                    'EUR (€ Euro)' => 'EUR',
                    'USD ($ Dollar)' => 'USD',
                    'GBP (£ British Pound)' => 'GBP',
                ],
                'data' => 'CHF',
                'required' => true,
            ])
        ;
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_token_id' => 'registration',
        ]);
    }
}
