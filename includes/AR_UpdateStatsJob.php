<?php

/**
 * Class ARUpdateStatsJob
 */
class ARUpdateStatsJob extends Job{

	/**
	 * ARUpdateStatsJob constructor.
	 *
	 * @param $title
	 * @param $params
	 */
	public function __construct( $title, $params ) {
		parent::__construct('ar_updatestats', $title, $params );
		$this->removeDuplicates = true;
	}

	/**
	 * @return bool
	 * @throws MWException
	 * @throws Exception
	 */
	public function run() {

		// Fetch recorded stats from database
		$oldStats = ApprovedRevs::getStats();
		if(!$oldStats) {
			return false;
		}

		// Calculate new stats
		$not_latest = ApprovedRevs::countPagesByType();
		$unapproved = ApprovedRevs::countPagesByType('unapproved');
		$invalid = ApprovedRevs::countPagesByType('invalid');
		$total = $not_latest + $unapproved + $invalid;

		// Update stats
		ApprovedRevs::updateStats(array(
			'total' => $total,
			'not_latest' => $not_latest,
			'unapproved' => $unapproved,
			'invalid' => $invalid,
			'time_updated' => time()
		));

		// Check if total amount of pages in target queues has increased
		if( $oldStats->total == 0 && $total > 0 ) {
			ApprovedRevs::notifyStatsChange();
		}

		return true;
	}

}
