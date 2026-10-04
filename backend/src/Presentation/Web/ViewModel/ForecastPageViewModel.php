<?php

declare(strict_types=1);

namespace App\Presentation\Web\ViewModel;

/**
 * 予報ページの Twig に渡す唯一のオブジェクト.
 *
 * @phpstan-type Form array{latitude: string, longitude: string, latitudeError: string|null, longitudeError: string|null}
 * @phpstan-type Notice array{type: 'stale'|'unavailable'|'rate_limited', message: string}
 */
final readonly class ForecastPageViewModel
{
    /**
     * @param Form         $form
     * @param Notice|null  $notice
     * @param list<string> $groupMessages
     */
    public function __construct(
        public array $form,
        public ?array $notice,
        public ?string $location,
        public ?string $lastUpdated,
        public array $groupMessages,
        public ?ForecastTable $table,
    ) {
    }
}
