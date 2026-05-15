<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('username', options: ['label' => 'Kita Name']);

        $passwordConstraints = [
            new Length([
                'min' => 6,
                'minMessage' => 'Das Passwort sollte mindestens {{ limit }} Zeichen lang sein.',
                'max' => 4096,
            ]),
        ];

        if ($options['is_new']) {
            $passwordConstraints[] = new NotBlank([
                'message' => 'Bitte Passwort eingeben.',
            ]);
        }

        $builder->add('plainPassword', PasswordType::class, [
            'label' => $options['is_new'] ? 'Passwort' : 'Neues Passwort (nur nötig bei Änderung)',
            'mapped' => false,
            'required' => $options['is_new'],
            'attr' => ['autocomplete' => 'new-password'],
            'constraints' => $passwordConstraints,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'is_new' => true, // Default to true for new users
        ]);
    }
}
