<?php

namespace App\Controller;

use App\Entity\Listing;
use App\Entity\User;
use App\Service\ListingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Psr\Log\LoggerInterface;

#[Route('/api/listing')]
class ListingController extends AbstractController
{
    private $entityManager;
    private $listingService;
    private $logger;

    public function __construct(
        EntityManagerInterface $entityManager,
        ListingService $listingService,
        LoggerInterface $logger
    ) {
        $this->entityManager = $entityManager;
        $this->listingService = $listingService;
        $this->logger = $logger;
    }

    #[Route('/create', name: 'api_listing_create', methods: ['POST'])]
    #[IsGranted("ROLE_USER")]
    public function apiCreate(Request $request): JsonResponse
    {
        $this->logger->info('Début de la création d\'une annonce');

        try {
            /** @var User $user */
            $user = $this->getUser();
            if (!$user instanceof User) {
                $this->logger->warning('Tentative de création d\'annonce par un utilisateur non authentifié');
                return new JsonResponse(['error' => 'Utilisateur non authentifié'], Response::HTTP_UNAUTHORIZED);
            }

            $this->logger->info('Utilisateur authentifié', ['user_id' => $user->getId()]);

            $data = json_decode($request->getContent(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $data = $request->request->all();
            }
            $this->logger->info('Données reçues', ['data' => $data]);

            $listing = new Listing();
            $listing->setTitle($data['title']);
            $listing->setDescription($data['description']);
            $listing->setPrice($data['price']);
            $listing->setState($data['state']);
            $listing->setRegion($data['region']);
            $listing->setDepartment($data['department']);

            // Gérer les catégories
            if (isset($data['categories'])) {
                $categories = is_array($data['categories']) ? $data['categories'] : [$data['categories']];
                foreach ($categories as $categoryId) {
                    $category = $this->entityManager->getReference('App\Entity\Category', $categoryId);
                    $listing->addCategory($category);
                }
            }

            // Gérer les photos
            $photoFiles = $request->files->get('photoFiles');
            if ($photoFiles) {
                $listing->setPhotoFiles($photoFiles);
            }

            $this->listingService->createListing($listing, $user);
            $this->logger->info('Annonce créée avec succès', ['listing_id' => $listing->getId()]);

            return new JsonResponse(['message' => 'Annonce créée avec succès'], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la création de l\'annonce', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return new JsonResponse(['error' => 'Une erreur est survenue lors de la création de l\'annonce.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Retrieve departments for a given region (AJAX endpoint).
     */
    #[Route('/get-departments/{id}', name: 'app_get_departments', methods: ['GET'])]
    public function getDepartments(Region $region, DepartmentRepository $departmentRepository): JsonResponse
    {
        $departments = $departmentRepository->findBy(['region' => $region]);

        $departmentsArray = array_map(function($department) {
            return ['id' => $department->getId(), 'name' => $department->getName()];
        }, $departments);

        return new JsonResponse($departmentsArray);
    }

    /**
     * Display the form to edit an existing listing and handle form submission.
     */
    #[Route('/edit/{slug}', name: 'app_listing_edit')]
    public function edit(Request $request, Listing $listing): Response
    {
        if ($listing->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Vous n\'êtes pas autorisé à modifier cette annonce.');
        }

        $form = $this->createForm(ListingFormType::class, $listing);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->listingService->updateListing($listing, $form->getData());

            $this->addFlash('success', 'Votre annonce a été mise à jour avec succès.');

            return $this->redirectToRoute('app_listing_show', ['slug' => $listing->getSlug()]);
        }

        return $this->render('listing/edit.html.twig', [
            'form' => $form->createView(),
            'listing' => $listing,
        ]);
    }

    /**
     * Handle the deletion of a listing.
     */
    #[Route('/delete/{slug}', name: 'app_listing_delete')]
    public function delete(Request $request, Listing $listing): Response
    {
        if ($listing->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas supprimer cette annonce.');
        }

        if ($this->isCsrfTokenValid('delete'.$listing->getId(), $request->request->get('_token'))) {
            $this->listingService->deleteListing($listing);
            $this->addFlash('success', 'Votre annonce a été supprimée avec succès.');
        }

        return $this->redirectToRoute('app_home');
    }

    /**
     * Display details of a specific listing.
     */
    #[Route('/{slug}', name: 'app_listing_show', methods: ['GET'])]
    public function show(Listing $listing): Response
    {
        return $this->render('listing/show.html.twig', [
            'listing' => $listing,
        ]);
    }

    /**
     * Handle contacting the seller of a listing.
     */
    #[Route('/{slug}/contact', name: 'app_listing_contact')]
    #[IsGranted('ROLE_USER')]
    public function contact(Request $request, Listing $listing): Response
    {
        $sender = $this->getUser();
        if (!$sender instanceof User) {
            throw $this->createAccessDeniedException('Vous devez être connecté pour envoyer un message.');
        }

        $form = $this->createForm(MessageFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $content = $form->get('content')->getData();

            try {
                $this->listingService->contactSeller($sender, $listing, $content);
                $this->addFlash('success', 'Votre message a été envoyé avec succès.');
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }

            return $this->redirectToRoute('app_listing_show', ['slug' => $listing->getSlug()]);
        }

        return $this->render('message/new.html.twig', [
            'listing' => $listing,
            'form' => $form->createView(),
        ]);
    }
}
