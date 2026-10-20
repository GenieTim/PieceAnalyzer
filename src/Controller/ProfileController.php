<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SetPrice;
use App\Entity\User;
use App\Enum\Retailer;
use App\Service\UserPreferencesService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class ProfileController extends AbstractController
{
    #[Route(path: '/profile', name: 'app_profile', methods: ['GET'])]
    public function index(EntityManagerInterface $em, UserPreferencesService $preferences): Response
    {
        $user = $this->getUser();
        assert($user instanceof User);

        $submissionCount = $em->getRepository(SetPrice::class)->count(['submittedBy' => $user]);

        return $this->render('profile/index.html.twig', [
            'user' => $user,
            'submissionCount' => $submissionCount,
            'currentCurrency' => $preferences->getCurrency(),
            'preferredRetailers' => $preferences->getPreferredRetailers(),
            'excludeUsedPrices' => $preferences->shouldExcludeUsedPrices(),
            'allRetailers' => Retailer::cases(),
        ]);
    }

    #[Route(path: '/profile/preferences', name: 'app_profile_preferences', methods: ['POST'])]
    public function updatePreferences(Request $request, UserPreferencesService $preferences, EntityManagerInterface $em): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('update_preferences', (string) $request->request->get('_token'))) {
            $this->addFlash('alert', 'Invalid security token.');
            return $this->redirectToRoute('app_profile');
        }

        $user = $this->getUser();
        assert($user instanceof User);

        $username = trim((string) $request->request->get('username', ''));
        $user->setUsername($username !== '' ? $username : null);
        $em->persist($user);
        $em->flush();

        $currency = (string) $request->request->get('currency', 'CHF');
        /** @var list<string> $retailers */
        $retailers = $request->request->all('retailers');
        $excludeUsed = $request->request->getBoolean('exclude_used_prices');

        $preferences->setCurrency($currency);
        $preferences->setPreferredRetailers($retailers);
        $preferences->setExcludeUsedPrices($excludeUsed);

        $this->addFlash('success', 'Your profile preferences have been saved.');
        return $this->redirectToRoute('app_profile');
    }

    #[Route(path: '/profile/password', name: 'app_profile_password', methods: ['POST'])]
    public function changePassword(
        Request $request,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('change_password', (string) $request->request->get('_token'))) {
            $this->addFlash('alert', 'Invalid security token.');
            return $this->redirectToRoute('app_profile');
        }

        $user = $this->getUser();
        assert($user instanceof User);

        $currentPassword = (string) $request->request->get('current_password', '');
        $newPassword = (string) $request->request->get('new_password', '');
        $confirmPassword = (string) $request->request->get('confirm_password', '');

        if (!$hasher->isPasswordValid($user, $currentPassword)) {
            $this->addFlash('alert', 'Current password is not correct.');
            return $this->redirectToRoute('app_profile');
        }

        if (strlen($newPassword) < 8) {
            $this->addFlash('alert', 'New password must be at least 8 characters long.');
            return $this->redirectToRoute('app_profile');
        }

        if ($newPassword !== $confirmPassword) {
            $this->addFlash('alert', 'New password and confirmation do not match.');
            return $this->redirectToRoute('app_profile');
        }

        $user->setPassword($hasher->hashPassword($user, $newPassword));
        $em->persist($user);
        $em->flush();

        $this->addFlash('success', 'Your password has been changed successfully.');
        return $this->redirectToRoute('app_profile');
    }

    #[Route(path: '/profile/regenerate-token', name: 'app_profile_regenerate_token', methods: ['POST'])]
    public function regenerateToken(Request $request, EntityManagerInterface $em): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('regenerate_token', (string) $request->request->get('_token'))) {
            $this->addFlash('alert', 'Invalid security token.');
            return $this->redirectToRoute('app_profile');
        }

        $user = $this->getUser();
        assert($user instanceof User);

        $user->regenerateApiToken();
        $em->persist($user);
        $em->flush();

        $this->addFlash('success', 'Your API Contributor token has been regenerated.');
        return $this->redirectToRoute('app_profile');
    }
}
