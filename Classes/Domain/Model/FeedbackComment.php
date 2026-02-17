<?php

declare(strict_types=1);

namespace ByteBuilders\T3Pinpoint\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

class FeedbackComment extends AbstractEntity
{
    protected string $comment = '';
    protected string $authorName = '';
    protected string $authorType = 'editor';

    public function getComment(): string
    {
        return $this->comment;
    }

    public function setComment(string $comment): void
    {
        $this->comment = $comment;
    }

    public function getAuthorName(): string
    {
        return $this->authorName;
    }

    public function setAuthorName(string $authorName): void
    {
        $this->authorName = $authorName;
    }

    public function getAuthorType(): string
    {
        return $this->authorType;
    }

    public function setAuthorType(string $authorType): void
    {
        $this->authorType = $authorType;
    }
}
