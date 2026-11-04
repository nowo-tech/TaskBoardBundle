<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

final class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $demo = new User();
        $demo
            ->setEmail('demo@example.com')
            ->setPassword('')
            ->setRoles(['ROLE_USER']);

        $manager->persist($demo);
        // Since 1.23: two more people, so the demo shows member pictures (and one broken picture) on shared tasks.
        foreach (['ada@example.com', 'grace@example.com'] as $email) {
            $manager->persist((new User())->setEmail($email)->setPassword('')->setRoles(['ROLE_USER']));
        }
        $manager->flush();
    }
}
