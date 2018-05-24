<?php

/**
 * Special page that displays various lists of pages that either do or do
 * not have an approved revision.
 *
 * @author Yaron Koren
 */
class SpecialApprovedRevsPage extends QueryPage {

	protected $mMode;

	public function __construct( $mode ) {
		if ( $this instanceof SpecialPage ) {
			parent::__construct( 'ApprovedRevs' );
		}
		$this->mMode = $mode;
	}

	function getName() {
		return 'ApprovedRevs';
	}

	function isExpensive() { return false; }

	function isSyndicated() { return false; }

	function getPageHeader() {
		// show the names of the three lists of pages, with the one
		// corresponding to the current "mode" not being linked
		$approvedPagesTitle = SpecialPage::getTitleFor( 'ApprovedRevs' );
		$navLine = wfMessage( 'approvedrevs-view' )->parse() . ' ';

		if ( $this->mMode == '' ) {
			$navLine .= Xml::element( 'strong',
				null,
				wfMessage( 'approvedrevs-notlatestpages' )->text()
			);
		} else {
			$navLine .= Xml::element( 'a',
				array( 'href' => $approvedPagesTitle->getLocalURL() ),
				wfMessage( 'approvedrevs-notlatestpages' )->text()
			);
		}

		$navLine .= ' | ';

		if ( $this->mMode == 'all' ) {
			$navLine .= Xml::element( 'strong',
				null,
				wfMessage( 'approvedrevs-approvedpages' )->text()
			);
		} else {
			$navLine .= Xml::element( 'a',
				array( 'href' => $approvedPagesTitle->getLocalURL( array( 'show' => 'all' ) ) ),
				wfMessage( 'approvedrevs-approvedpages' )->text()
			);
		}

		$navLine .= ' | ';

		if ( $this->mMode == 'unapproved' ) {
			$navLine .= Xml::element( 'strong',
				null,
				wfMessage( 'approvedrevs-unapprovedpages' )->text()
			);
		} else {
			$navLine .= Xml::element( 'a',
				array( 'href' => $approvedPagesTitle->getLocalURL( array( 'show' => 'unapproved' ) ) ),
				wfMessage( 'approvedrevs-unapprovedpages' )->text()
			);
		}

		$navLine .= ' | ';

		if ( $this->mMode == 'invalid' ) {
			$navLine .= Xml::element( 'strong',
				null,
				wfMessage( 'approvedrevs-invalidpages' )->text()
			);
		} else {
			$navLine .= Xml::element( 'a',
				array( 'href' => $approvedPagesTitle->getLocalURL( array( 'show' => 'invalid' ) ) ),
				wfMessage( 'approvedrevs-invalidpages' )->text()
			);
		}

		$navLine .= "\n";

		return Xml::tags( 'p', null, $navLine ) . "\n";
	}

	/**
	 * Set parameters for standard navigation links.
	 */
	function linkParameters() {
		$params = array();

		if ( $this->mMode == 'all' ) {
			$params['show'] = 'all';
		} elseif ( $this->mMode == 'unapproved' ) {
			$params['show'] = 'unapproved';
		} elseif ( $this->mMode == 'invalid' ) {
			$params['show'] = 'invalid';
		} else { // 'approved revision not the latest' pages
		}

		return $params;
	}

	function getPageFooter() {
	}

	public static function getNsConditionPart( $ns ) {
		return 'p.page_namespace = ' . $ns;
	}

	/**
	 * (non-PHPdoc)
	 * @see QueryPage::getSQL()
	 */
	function getQueryInfo() {
		return ApprovedRevs::getQueryByType( $this->mMode );
	}

	function getOrder() {
		return ' ORDER BY p.page_namespace, p.page_title ASC';
	}

	function getOrderFields() {
		return array( 'p.page_namespace', 'p.page_title' );
	}

	function sortDescending() {
		return false;
	}

	function formatResult( $skin, $result ) {
		$title = Title::newFromId( $result->id );

		if( !ApprovedRevs::pageIsApprovable( $title ) && $this->mMode !== 'invalid' ) {
			return false;
		}
          
		$context = $skin->getContext();
		$user = $context->getUser();
		$out = $context->getOutput();
		$lang = $context->getLanguage();

		if ( method_exists( $this, 'getLinkRenderer' ) ) {
			$linkRenderer = $this->getLinkRenderer();
		} else {
			$linkRenderer = null;
		}

		// Create page link - special handling for redirects.
		$params = array();
		if ( $title->isRedirect() ) {
			$params['redirect'] = 'no';
		}
		$pageLink = ApprovedRevs::makeLink( $linkRenderer, $title, null, array(), $params );
		if ( $title->isRedirect() ) {
			$pageLink = "<em>$pageLink</em>";
		}

		if ( $this->mMode == 'all' ) {
			$additionalInfo = Xml::element( 'span',
				array (
					'class' => $result->rev_id == $result->latest_id ? 'approvedRevIsLatest' : 'approvedRevNotLatest'
				),
				wfMessage( 'approvedrevs-revisionnumber', $result->rev_id )->text()
			);

			// Get data on the most recent approval from the
			// 'approval' log, and display it if it's there.
			$loglist = new LogEventsList( $out->getSkin(), $out );
			$pager = new LogPager( $loglist, 'approval', '', $title->getText() );
			$pager->mLimit = 1;
			$pager->doQuery();
			$row = $pager->mResult->fetchObject();

			if ( !empty( $row ) ) {
				$timestamp = $lang->timeanddate( wfTimestamp( TS_MW, $row->log_timestamp ), true );
				$date = $lang->date( wfTimestamp( TS_MW, $row->log_timestamp ), true );
				$time = $lang->time( wfTimestamp( TS_MW, $row->log_timestamp ), true );
				$userLink = Linker::userLink( $row->log_user, $row->user_name );
				$additionalInfo .= ', ' . wfMessage(
					'approvedrevs-approvedby',
					$userLink,
					$timestamp,
					$row->user_name,
					$date,
					$time
				)->text();
			}

			return "$pageLink ($additionalInfo)";
		} elseif ( $this->mMode == 'unapproved' ) {
			global $egApprovedRevsShowApproveLatest;

			$line = $pageLink;
			if ( $egApprovedRevsShowApproveLatest &&
				ApprovedRevs::checkPermission( $user, $title, 'approverevisions' ) ) {
				$line .= ' (' . Xml::element( 'a',
					array( 'href' => $title->getLocalUrl(
						array(
							'action' => 'approve',
							'oldid' => $result->latest_id
						)
					) ),
					wfMessage( 'approvedrevs-approvelatest' )->text()
				) . ')';
			}

			return $line;
		} elseif ( $this->mMode == 'invalid' ) {
			return $pageLink;
		} else { // approved revision is not latest
			$diffLink = Xml::element( 'a',
				array( 'href' => $title->getLocalUrl(
					array(
						'diff' => $result->latest_id,
						'oldid' => $result->rev_id
					)
				) ),
				wfMessage( 'approvedrevs-difffromlatest' )->text()
			);

			return "$pageLink ($diffLink)";
		}
	}

}