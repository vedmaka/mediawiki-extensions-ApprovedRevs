<?php

/**
 * Jumps into database update script and initializes approved_revs_stats table with default nil values
 * Note: this class is based on LoggedUpdateMaintenance hence it'll run each time when update script is executed
 * but it'll not do anything if table already contains a row
 *
 * Class ApprovedRevsDatabaseMaintenance
 */
class ApprovedRevsDatabaseMaintenance extends LoggedUpdateMaintenance {

	public function getUpdateKey() {
		return self::class;
	}

	/**
	 * @return bool
	 */
	public function doDBUpdates() {

		$db = $this->getDB( DB_MASTER );

		if ( !$db->tableExists( 'approved_revs_stats' ) ) {
			return false;
		}

		if ( $db->selectRowCount( 'approved_revs_stats' ) ) {
			return true;
		}

		$not_latest = ApprovedRevs::countPagesByType();
		$unapproved = ApprovedRevs::countPagesByType( 'unapproved' );
		$invalid = ApprovedRevs::countPagesByType( 'invalid' );
		$total = $not_latest + $unapproved + $invalid;

		$values = array(
			'row_id' => 1,
			'total' => $total,
			'not_latest' => $not_latest,
			'unapproved' => $unapproved,
			'invalid' => $invalid,
			'time_updated' => time()
		);

		try {
			$result = $db->insert( 'approved_revs_stats', $values );
		}
		catch ( Exception $e ) {
			$result = false;
		}

		if ( $result ) {
			return true;
		}

		return false;

	}

	/**
	 * @throws Exception
	 */
	public function execute() {
		$this->doDBUpdates();
	}


}
