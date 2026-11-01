<?php
/**
 * One event read from an iCalendar feed.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Feeds;

use DateTimeImmutable;

/**
 * A VEVENT, normalised: times in the event's own timezone, text unescaped,
 * all-day end dates inclusive (iCal's DTEND is the day after).
 */
final class Item {

	/**
	 * @param string[] $categories CATEGORIES values.
	 * @param string[] $rdates     Extra start times, "Y-m-d H:i:s" in the event timezone.
	 * @param string[] $exdates    Skipped dates, "Y-m-d" in the event timezone.
	 */
	public function __construct(
		public readonly string $uid,
		public readonly DateTimeImmutable $start,
		public readonly DateTimeImmutable $end,
		public readonly bool $all_day,
		public readonly string $summary = '',
		public readonly string $description = '',
		public readonly string $location = '',
		public readonly string $url = '',
		public readonly array $categories = array(),
		public readonly string $status = '',
		public readonly string $class = 'PUBLIC',
		public readonly string $rrule = '',
		public readonly array $rdates = array(),
		public readonly array $exdates = array(),
		public readonly ?DateTimeImmutable $recurrence_id = null,
		public readonly ?float $lat = null,
		public readonly ?float $lng = null,
	) {}

	public function timezone(): string {
		return $this->start->getTimezone()->getName();
	}

	/**
	 * Private and confidential events (in a calendar shared by its secret
	 * address) are never published.
	 */
	public function is_private(): bool {
		return in_array( $this->class, array( 'PRIVATE', 'CONFIDENTIAL' ), true );
	}

	public function is_cancelled(): bool {
		return 'CANCELLED' === $this->status;
	}
}
