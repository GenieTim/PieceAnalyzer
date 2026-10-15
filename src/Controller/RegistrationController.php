<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
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

            $user = new User($email, '', false);
            $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));
            $user->setPreferredCurrency($currency);
            $token = $user->generateEmailVerificationToken(24);

            $entityManager->persist($user);
            $entityManager->flush();

            // Send confirmation email
            $verifyUrl = $this->generateUrl('app_verify_email', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
            $fromAddress = (string) ($_ENV['ADMIN_EMAIL'] ?? 'noreply@piece-analyzer.local');

            try {
                $emailMessage = (new Email())
                    ->from(new Address($fromAddress, 'Piece Analyzer'))
                    ->to($user->getEmail())
                    ->subject('Please Confirm Your Email Address — Piece Analyzer')
                    ->html(sprintf(
                        '<div style="font-family: sans-serif; max-width: 600px; margin: auto; padding: 20px;">' .
                        '<h2 style="color: #212529;">Welcome to Piece Analyzer!</h2>' .
                        '<p>Thank you for creating an account. Please click the button below to confirm your email address and activate your account:</p>' .
                        '<p style="margin: 24px 0;"><a href="%s" style="background-color: #0d6efd; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; font-weight: bold; display: inline-block;">Confirm Email Address</a></p>' .
                        '<p style="color: #6c757d; font-size: 0.9em;">This link will expire in 24 hours. If you did not create an account, you can safely ignore this email.</p>' .
                        '<hr style="border: 0; border-top: 1px solid #dee2e6; margin: 24px 0;">' .
                        '<p style="color: #adb5bd; font-size: 0.8em;">Piece Analyzer</p>' .
                        '</div>',
                        htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8')
                    ))
                    ->text(sprintf(
                        "Welcome to Piece Analyzer!\n\n" .
                        "Please verify your email address by visiting the link below:\n%s\n\n" .
                        "This link will expire in 24 hours.\n",
                        $verifyUrl
                    ));

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
                    $emailMessage = (new Email())
                        ->from(new Address($fromAddress, 'Piece Analyzer'))
                        ->to($user->getEmail())
                        ->subject('Confirm Your Email Address — Piece Analyzer')
                        ->html(sprintf(
                            '<div style="font-family: sans-serif; max-width: 600px; margin: auto; padding: 20px;">' .
                            '<h2>Email Verification Request</h2>' .
                            '<p>Please confirm your email address by clicking the link below:</p>' .
                            '<p><a href="%s" style="background-color: #0d6efd; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; font-weight: bold; display: inline-block;">Confirm Email Address</a></p>' .
                            '<p style="color: #6c757d; font-size: 0.9em;">This link will expire in 24 hours.</p>' .
                            '</div>',
                            htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8')
                        ));

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
