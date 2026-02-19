<?php

declare(strict_types=1);

namespace ByteBuilders\T3Pinpoint\Domain\Model;

use TYPO3\CMS\Extbase\Annotation as Extbase;
use TYPO3\CMS\Extbase\Domain\Model\FileReference;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

class Feedback extends AbstractEntity
{
    protected int $crdate = 0;

    protected string $title = '';
    protected string $description = '';
    protected string $status = 'open';
    protected string $priority = 'medium';
    protected string $category = 'change_request';

    // TYPO3 content context
    protected int $pageUid = 0;
    protected int $contentUid = 0;
    protected string $contentType = '';
    protected string $pageUrl = '';
    protected string $backendEditLink = '';

    // Browser/client metadata
    protected string $browserInfo = '';
    protected string $viewport = '';
    protected string $cssSelector = '';

    // Screenshots (FAL)
    protected ?FileReference $screenshot = null;
    protected ?FileReference $annotatedScreenshot = null;

    // TYPO3 backend user
    protected int $backendUser = 0;
    protected string $backendUsername = '';

    // External sync
    protected string $externalId = '';
    protected string $externalUrl = '';
    protected int $syncedAt = 0;

    /** @var ObjectStorage<FeedbackComment> */
    #[Extbase\ORM\Cascade(['value' => 'remove'])]
    protected ObjectStorage $comments;

    public function __construct()
    {
        $this->comments = new ObjectStorage();
    }

    public function getCrdate(): int
    {
        return $this->crdate;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getPriority(): string
    {
        return $this->priority;
    }

    public function setPriority(string $priority): void
    {
        $this->priority = $priority;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): void
    {
        $this->category = $category;
    }

    public function getPageUid(): int
    {
        return $this->pageUid;
    }

    public function setPageUid(int $pageUid): void
    {
        $this->pageUid = $pageUid;
    }

    public function getContentUid(): int
    {
        return $this->contentUid;
    }

    public function setContentUid(int $contentUid): void
    {
        $this->contentUid = $contentUid;
    }

    public function getContentType(): string
    {
        return $this->contentType;
    }

    public function setContentType(string $contentType): void
    {
        $this->contentType = $contentType;
    }

    public function getPageUrl(): string
    {
        return $this->pageUrl;
    }

    public function setPageUrl(string $pageUrl): void
    {
        $this->pageUrl = $pageUrl;
    }

    public function getBackendEditLink(): string
    {
        return $this->backendEditLink;
    }

    public function setBackendEditLink(string $backendEditLink): void
    {
        $this->backendEditLink = $backendEditLink;
    }

    public function getBrowserInfo(): string
    {
        return $this->browserInfo;
    }

    public function setBrowserInfo(string $browserInfo): void
    {
        $this->browserInfo = $browserInfo;
    }

    public function getViewport(): string
    {
        return $this->viewport;
    }

    public function setViewport(string $viewport): void
    {
        $this->viewport = $viewport;
    }

    public function getCssSelector(): string
    {
        return $this->cssSelector;
    }

    public function setCssSelector(string $cssSelector): void
    {
        $this->cssSelector = $cssSelector;
    }

    public function getScreenshot(): ?FileReference
    {
        return $this->screenshot;
    }

    public function setScreenshot(?FileReference $screenshot): void
    {
        $this->screenshot = $screenshot;
    }

    public function getAnnotatedScreenshot(): ?FileReference
    {
        return $this->annotatedScreenshot;
    }

    public function setAnnotatedScreenshot(?FileReference $annotatedScreenshot): void
    {
        $this->annotatedScreenshot = $annotatedScreenshot;
    }

    public function getBackendUser(): int
    {
        return $this->backendUser;
    }

    public function setBackendUser(int $backendUser): void
    {
        $this->backendUser = $backendUser;
    }

    public function getBackendUsername(): string
    {
        return $this->backendUsername;
    }

    public function setBackendUsername(string $backendUsername): void
    {
        $this->backendUsername = $backendUsername;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function setExternalId(string $externalId): void
    {
        $this->externalId = $externalId;
    }

    public function getExternalUrl(): string
    {
        return $this->externalUrl;
    }

    public function setExternalUrl(string $externalUrl): void
    {
        $this->externalUrl = $externalUrl;
    }

    public function getSyncedAt(): int
    {
        return $this->syncedAt;
    }

    public function setSyncedAt(int $syncedAt): void
    {
        $this->syncedAt = $syncedAt;
    }

    /** @return ObjectStorage<FeedbackComment> */
    public function getComments(): ObjectStorage
    {
        return $this->comments;
    }

    /** @param ObjectStorage<FeedbackComment> $comments */
    public function setComments(ObjectStorage $comments): void
    {
        $this->comments = $comments;
    }

    public function addComment(FeedbackComment $comment): void
    {
        $this->comments->attach($comment);
    }
}
