<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PriceSourceProvider;
use App\Enum\PriceVerificationStatus;
use App\Enum\Retailer;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Money\Currency;
use Money\Money;

#[ORM\Entity]
#[ORM\Table(name: 'set_price')]
#[ORM\Index(name: 'idx_set_price_set', columns: ['set_id'])]
#[ORM\Index(name: 'idx_set_price_retailer', columns: ['retailer'])]
#[ORM\Index(name: 'idx_set_price_recorded_at', columns: ['recorded_at'])]
class SetPrice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'submitted_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $submittedBy = null;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: PriceVerificationStatus::class)]
    private PriceVerificationStatus $status = PriceVerificationStatus::VERIFIED;

    #[ORM\Column(type: Types::STRING, length: 3)]
    private string $currency = 'CHF';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $url = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Set::class, inversedBy: 'prices')]
        #[ORM\JoinColumn(name: 'set_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        private ?Set $set = null,
        /**
         * Stored in minor currency units (e.g. cents/rappen: 1000 = 10.00).
         */
        #[ORM\Column(type: Types::INTEGER)]
        private int $amount = 0,
        string $currency = 'CHF',
        #[ORM\Column(type: Types::STRING, length: 32, enumType: Retailer::class)]
        private Retailer $retailer = Retailer::OTHER,
        #[ORM\Column(type: Types::STRING, length: 32, enumType: PriceSourceProvider::class)]
        private PriceSourceProvider $provider = PriceSourceProvider::MANUAL,
        #[ORM\Column(name: 'recorded_at', type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $recordedAt = new \DateTimeImmutable()
    ) {
        $this->currency = strtoupper(trim($currency));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSet(): ?Set
    {
        return $this->set;
    }

    public function setSet(?Set $set): static
    {
        $this->set = $set;

        return $this;
    }

    public function getSubmittedBy(): ?User
    {
        return $this->submittedBy;
    }

    public function setSubmittedBy(?User $user): static
    {
        $this->submittedBy = $user;

        return $this;
    }

    public function getProvider(): PriceSourceProvider
    {
        return $this->provider;
    }

    public function setProvider(PriceSourceProvider $provider): static
    {
        $this->provider = $provider;

        return $this;
    }

    public function getRetailer(): Retailer
    {
        return $this->retailer;
    }

    public function setRetailer(Retailer $retailer): static
    {
        $this->retailer = $retailer;

        return $this;
    }

    public function getStatus(): PriceVerificationStatus
    {
        return $this->status;
    }

    public function setStatus(PriceVerificationStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = strtoupper(trim($currency));

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function setRecordedAt(\DateTimeImmutable $recordedAt): static
    {
        $this->recordedAt = $recordedAt;

        return $this;
    }

    public function getMoney(): Money
    {
        $curr = $this->currency !== '' ? $this->currency : 'CHF';
        assert($curr !== '');

        return new Money($this->amount, new Currency($curr));
    }

    public function setMoney(Money $money): static
    {
        $this->amount = (int) $money->getAmount();
        $this->currency = $money->getCurrency()->getCode();

        return $this;
    }

    public function getDecimalPrice(): float
    {
        return $this->amount / 100;
    }

    public function setDecimalPrice(float $decimal, ?string $currency = null): static
    {
        $this->amount = (int) \round($decimal * 100);
        if ($currency !== null) {
            $this->currency = strtoupper(trim($currency));
        }

        return $this;
    }

    public function getFormattedPrice(): string
    {
        return sprintf('%s %.2f', $this->currency, $this->getDecimalPrice());
    }
}
