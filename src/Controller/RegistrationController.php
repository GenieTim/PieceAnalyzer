<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class RegistrationController extends AbstractController
{
    public function __construct(private readonly RateLimiterFactory $registrationLimiter)
    {
    }

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        LoggerInterface $logger
    ): Response {
        if ($this->getUser() instanceof \Symfony\Component\Security\Core\User\UserInterface) {
            return $this->redirectToRoute('index');
        }

        $form = $this->createForm(RegistrationFormType::class);

        if ($request->isMethod('POST')) {
            $limiter = $this->registrationLimiter->create($request->getClientIp() ?? '127.0.0.1');
            if (!$limiter->consume()->isAccepted()) {
                $this->addFlash('danger', 'Too many registration attempts. Please try again later.');

                return $this->render('registration/register.html.twig', [
                    'registrationForm' => $form->createView(),
                ], new Response(status: Response::HTTP_TOO_MANY_REQUESTS));
            }
        }

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = strtolower(trim((string) $form->get('email')->getData()));
            $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

            if ($existingUser !== null) {
                $form->get('email')->addError(new FormError('An account with this email address already exists.'));
                return $this->render('registration/register.html.twig', [
                    'registrationForm' => $form->createView(),
                ]);
            }

            $plainPassword = (string) $form->get('plainPassword')->getData();
            $currency = (string) $form->get('preferredCurrency')->getData();
            $username = trim((string) ($form->get('username')->getData() ?? ''));

            $user = new User($email, '', false);
            if ($username !== '') {
                $user->setUsername($username);
            }
            $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));
            $user->setPreferredCurrency($currency);
            $token = $user->generateEmailVerificationToken(24);

            $entityManager->persist($user);
            $entityManager->flush();

            // Send confirmation email
            $verifyUrl = $this->generateUrl('app_verify_email', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
            $fromAddress = (string) ($_ENV['ADMIN_EMAIL'] ?? 'noreply@piece-analyzer.local');

            try {
                $emailMessage = (new TemplatedEmail())
                    ->from(new Address($fromAddress, 'Piece Analyzer'))
                    ->to($user->getEmail())
                    ->subject('Please Confirm Your Email Address — Piece Analyzer')
                    ->htmlTemplate('emails/registration_verification.html.twig')
                    ->textTemplate('emails/registration_verification.txt.twig')
                    ->context([
                        'verifyUrl' => $verifyUrl,
                        'user' => $user,
                    ]);

                $mailer->send($emailMessage);
            } catch (\Throwable $e) {
                $logger->warning('Failed to dispatch verification email', [
                    'email' => $user->getEmail(),
                    'error' => $e->getMessage(),
                ]);
            }

            $this->addFlash('success', 'Registration successful! A verification email has been sent. Please confirm your email address before logging in.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form->createView(),
        ]);
    }

    #[Route('/verify-email/{token}', name: 'app_verify_email', methods: ['GET'])]
    public function verifyEmail(string $token, EntityManagerInterface $entityManager): Response
    {
        $user = $entityManager->getRepository(User::class)->findOneBy(['emailVerificationToken' => $token]);

        if (!$user instanceof User) {
            $this->addFlash('danger', 'This verification link is invalid or has already been used.');
            return $this->redirectToRoute('app_login');
        }

        if ($user->isEmailVerificationTokenExpired()) {
            $this->addFlash('warning', 'This verification link has expired. Please request a new verification email.');
            return $this->redirectToRoute('app_resend_verification');
        }

        $user->setIsEmailVerified(true);
        $user->setEmailVerificationToken(null);
        $user->setEmailVerificationTokenExpiresAt(null);
        $entityManager->flush();

        $this->addFlash('success', 'Your email address has been successfully verified! You can now log in.');

        return $this->redirectToRoute('app_login');
    }

    #[Route('/resend-verification', name: 'app_resend_verification', methods: ['GET', 'POST'])]
    public function resendVerification(
        Request $request,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        LoggerInterface $logger
    ): Response {
        if ($request->isMethod('POST')) {
            $limiter = $this->registrationLimiter->create($request->getClientIp() ?? '127.0.0.1');
            if (!$limiter->consume()->isAccepted()) {
                $this->addFlash('danger', 'Too many verification requests. Please try again later.');

                return $this->redirectToRoute('app_resend_verification');
            }

            if (!$this->isCsrfTokenValid('resend_verification', (string) $request->request->get('_token'))) {
                $this->addFlash('danger', 'Invalid security token. Please try again.');
                return $this->redirectToRoute('app_resend_verification');
            }

            $email = strtolower(trim((string) $request->request->get('email')));
            $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

            if ($user instanceof User && !$user->isEmailVerified()) {
                $token = $user->generateEmailVerificationToken(24);
                $entityManager->flush();

                $verifyUrl = $this->generateUrl('app_verify_email', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
                $fromAddress = (string) ($_ENV['ADMIN_EMAIL'] ?? 'noreply@piece-analyzer.local');

                try {
                    $emailMessage = (new TemplatedEmail())
                        ->from(new Address($fromAddress, 'Piece Analyzer'))
                        ->to($user->getEmail())
                        ->subject('Confirm Your Email Address — Piece Analyzer')
                        ->htmlTemplate('emails/resend_verification.html.twig')
                        ->textTemplate('emails/resend_verification.txt.twig')
                        ->context([
                            'verifyUrl' => $verifyUrl,
                            'user' => $user,
                        ]);

                    $mailer->send($emailMessage);
                } catch (\Throwable $e) {
                    $logger->warning('Failed to resend verification email', ['error' => $e->getMessage()]);
                }
            }

            $this->addFlash('info', 'If an unverified account exists for that email, a new confirmation link has been sent.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('registration/resend_verification.html.twig');
    }
}
