<?php

// Allow people to have different layouts.
if ( !isset( $IP ) ) {
	$IP = __DIR__ . '/../../../';
	if ( getenv( "MW_INSTALL_PATH" ) ) {
		$IP = getenv( "MW_INSTALL_PATH" );
	}
}

require_once( "$IP/maintenance/Maintenance.php" );

/**
 * Adds a stats update job into job queue to avoid extra load on regular page loads
 * Should be used in combination with $egApprovedRevsDisableStatsUpdates = true;
 *
 * Class ApprovedRevsUpdateStats
 */
class ApprovedRevsUpdateStats extends Maintenance {

	/**
	 * ApprovedRevsUpdateStats constructor.
	 */
	public function __construct() {
		parent::__construct();

		$this->mDescription = "Updates stats related to ApprovedRevs";

		if ( method_exists( $this, 'requireExtension' ) ) {
			$this->requireExtension( 'Approved Revs' );
		}
	}

	/**
	 * @throws MWException
	 */
	public function execute() {
		global $egApprovedRevsDisableStatsUpdates;

		if ( !$egApprovedRevsDisableStatsUpdates ) {
			$this->output( 'Please disable stats update by adding $egApprovedRevsDisableStatsUpdates = true; ' .
			               'to LocalSettings.php' );
			return;
		}

		$job = new ARUpdateStatsJob( Title::newMainPage(), array() );
		$job->run();

		$this->output( "\nStats were updated." );
	}

}

$maintClass = "ApprovedRevsUpdateStats";
require_once RUN_MAINTENANCE_IF_MAIN;
