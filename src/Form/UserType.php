<?php

namespace App\Form;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('username', options: ['label' => 'Benutzername']);

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

        $builder->add('publishToAll', CheckboxType::class, [
            'label' => 'Alle Benutzer zuweisen',
            'required' => false,
            'help' => 'Inhalte dieses Benutzers können an alle (auch zukünftige) Benutzer ausgespielt werden.',
        ]);

        $currentUserId = $options['current_user_id'];
        $builder->add('publishTargets', EntityType::class, [
            'class' => User::class,
            'label' => 'Zugewiesene Benutzer',
            'multiple' => true,
            'expanded' => true,
            'by_reference' => false,
            'required' => false,
            'choice_label' => 'username',
            'query_builder' => function (UserRepository $repo) use ($currentUserId) {
                $qb = $repo->createQueryBuilder('u')
                    ->andWhere('u.roles LIKE :role')
                    ->setParameter('role', '%"ROLE_USER"%')
                    ->orderBy('u.username', 'ASC');

                if ($currentUserId) {
                    $qb->andWhere('u.id != :self')->setParameter('self', $currentUserId);
                }

                return $qb;
            },
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'is_new' => true, // Default to true for new users
            'current_user_id' => null,
        ]);
    }
}
