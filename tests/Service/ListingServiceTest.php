<?php

namespace App\Tests\Service;

use App\Entity\Listing;
use App\Entity\User;
use App\Service\ListingService;
use App\Service\MessageService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\String\AbstractUnicodeString;
use Symfony\Component\String\UnicodeString;

class ListingServiceTest extends TestCase
{
    private $entityManager;
    private $slugger;
    private $messageService;
    private $listingService;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->slugger = $this->createMock(SluggerInterface::class);
        $this->messageService = $this->createMock(MessageService::class);

        $this->listingService = new ListingService(
            $this->entityManager,
            $this->slugger,
            '/tmp/listings_photos',
            $this->messageService
        );
    }

    public function testCreateListing()
    {
        $user = new User();
        $user->setEmail('test@example.com');

        $listing = new Listing();
        $listing->setTitle('Test Listing');
        $listing->setDescription('This is a test listing');
        $listing->setPrice(100.00);
        $listing->setState('New');

        // Simuler un fichier uploadé
        $photoFile = $this->createMock(UploadedFile::class);
        $photoFile->method('guessExtension')->willReturn('jpg');
        $photoFile->method('move')->willReturn(new File(__FILE__));

        $listing->setPhotoFiles([$photoFile]);

        // Configuration du slugger mock
        $this->slugger->method('slug')
            ->willReturn(new UnicodeString('test-listing'));

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(Listing::class));

        $this->entityManager->expects($this->once())
            ->method('flush');

        $createdListing = $this->listingService->createListing($listing, $user);

        $this->assertInstanceOf(Listing::class, $createdListing);
        $this->assertEquals('test-listing', $createdListing->getSlug());
        $this->assertCount(1, $createdListing->getListingPhotos());
    }

    public function testCreateListingWithoutPhotos()
    {
        $user = new User();
        $user->setEmail('test@example.com');

        $listing = new Listing();
        $listing->setTitle('Test Listing No Photo');
        $listing->setDescription('This is a test listing without photos');
        $listing->setPrice(50.00);
        $listing->setState('Used');

        // Configuration du slugger mock
        $this->slugger->method('slug')
            ->willReturn(new UnicodeString('test-listing-no-photo'));

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(Listing::class));

        $this->entityManager->expects($this->once())
            ->method('flush');

        $createdListing = $this->listingService->createListing($listing, $user);

        $this->assertInstanceOf(Listing::class, $createdListing);
        $this->assertEquals('test-listing-no-photo', $createdListing->getSlug());
        $this->assertCount(0, $createdListing->getListingPhotos());
    }

    public function testContactSeller()
    {
        $sender = new User();
        $seller = new User();
        $listing = new Listing();
        $listing->setUser($seller);
        $content = "Test message";

        $this->messageService->expects($this->once())
            ->method('sendMessage')
            ->with($sender, $seller, $content);

        $this->listingService->contactSeller($sender, $listing, $content);
    }

    public function testContactSellerThrowsExceptionWhenSenderIsSeller()
    {
        $user = new User();
        $listing = new Listing();
        $listing->setUser($user);
        $content = "Test message";

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Vous ne pouvez pas vous envoyer un message à vous-même.');

        $this->listingService->contactSeller($user, $listing, $content);
    }
}