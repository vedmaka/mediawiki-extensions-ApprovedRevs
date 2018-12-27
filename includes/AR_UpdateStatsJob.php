<?php

/**
 * Class ARUpdateStatsJob
 */
class ARUpdateStatsJob extends Job {

	/**
	 * ARUpdateStatsJob constructor.
	 *
	 * @param $title
	 * @param $params
	 */
	public function __construct( $title, $params ) {
		parent::__construct( 'ar_updatestats', $title, $params );
		$this->removeDuplicates = true;
	}

	/**
	 * Entry point
	 * @return bool
	 * @throws MWException
	 */
	public function run() {

		$cache = wfGetCache( CACHE_ANYTHING );
		$key = $cache->makeKey( 'approvedrevs_stats' );

		// Fetch cached previous value
		$oldStatsTotal = $cache->get( $key );

		// Fetch current value
		$newStatsTotal = $this->getStatsTotal();

		// Update cached value with current one
		$cache->set( $key, $newStatsTotal );

		if ( $oldStatsTotal === false ) {
			// Do no do comparison and just set cache to current value
			return true;
		}

		// Check if total amount of pages in target queues has increased
		if ( $oldStatsTotal == 0 && $newStatsTotal > 0 ) {
			ApprovedRevs::notifyStatsChange();
		}

		return true;

	}

	/**
	 * Fetches total number of not approved, invalid or not latest pages in queue
	 * @return int
	 */
	private function getStatsTotal() {
		global $egApprovedRevsNotifyFiles;

		$total = ApprovedRevs::countPagesByTypes([
			null,
			'unapproved',
			'invalid'
		]);

		if( $egApprovedRevsNotifyFiles ) {
			$total += ApprovedRevs::countFilesByTypes([
				'notlatestfiles',
				'unapprovedfiles',
				'invalidfiles'
			]);
		}

		return $total;
	}

}
