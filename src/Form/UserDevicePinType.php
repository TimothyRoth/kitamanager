<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Regex;

class UserDevicePinType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('devicePin', TextType::class, [
                'label' => 'Fernseher-PIN (4 Ziffern)',
                'required' => false,
                'constraints' => [
                    new Regex([
                        'pattern' => '/^\d{4}$/',
                        'message' => 'Die PIN muss aus genau 4 Ziffern bestehen.',
                    ]),
                ],
                'attr' => [
                    'placeholder' => 'z. B. 4711',
                    'maxlength' => 4,
                    'inputmode' => 'numeric',
                    'autocomplete' => 'off',
                ],
                'help' => 'Diese PIN geben Sie einmalig auf dem Fernseher unter /slider/display ein. Wird die PIN geändert oder entfernt, verlieren alle damit verbundenen Fernseher die Zuordnung. Feld leer lassen, um die PIN zu entfernen.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
