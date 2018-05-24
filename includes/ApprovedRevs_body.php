<?php

/**
 * Main class for the Approved Revs extension.
 *
 * @file
 * @ingroup Extensions
 *
 * @author Yaron Koren
 */
class ApprovedRevs {

	// Static arrays to prevent querying the database more than necessary.
	static $mApprovedContentForPage = array();
	static $mApprovedRevIDForPage = array();
	static $mApproverForPage = array();
	static $mUserCanApprove = null;

	/**
	 * Gets the approved revision User for this page, or null if there isn't
	 * one.
	 */
	public static function getRevApprover( $title ) {
		$pageID = $title->getArticleID();
		if ( !isset( self::$mApproverForPage[$pageID] ) && self::pageIsApprovable( $title ) ) {
			$dbr = wfGetDB( DB_SLAVE );
			$approverID = $dbr->selectField( 'approved_revs', 'approver_id',
				array( 'page_id' => $pageID ) );
			$approver = $approverID ? User::newFromID( $approverID ) : null;
			self::$mApproverForPage[$pageID] = $approver;
		}
		return $approver;
	}

	/**
	 * Gets the approved revision ID for this page, or null if there isn't
	 * one.
	 */
	public static function getApprovedRevID( $title ) {
		if ( $title == null ) {
			return null;
		}

		$pageID = $title->getArticleID();
		if ( array_key_exists( $pageID, self::$mApprovedRevIDForPage ) ) {
			return self::$mApprovedRevIDForPage[$pageID];
		}

		if ( ! self::pageIsApprovable( $title ) ) {
			return null;
		}

		$dbr = wfGetDB( DB_SLAVE );
		$revID = $dbr->selectField( 'approved_revs', 'rev_id', array( 'page_id' => $pageID ) );
		self::$mApprovedRevIDForPage[$pageID] = $revID;
		return $revID;
	}

	/**
	 * Returns whether or not this page has a revision ID.
	 */
	public static function hasApprovedRevision( $title ) {
		$revision_id = self::getApprovedRevID( $title );
		return ( ! empty( $revision_id ) );
	}

	/**
	 * Returns the contents of the specified wiki page, at either the
	 * specified revision (if there is one) or the latest revision
	 * (otherwise).
	 */
	public static function getPageText( $title, $revisionID = null ) {
		$revision = Revision::newFromTitle( $title, $revisionID );
		return $revision->getContent()->getNativeData();
	}

	/**
	 * Returns the content of the approved revision of this page, or null
	 * if there isn't one.
	 */
	public static function getApprovedContent( $title ) {
		$pageID = $title->getArticleID();
		if ( array_key_exists( $pageID, self::$mApprovedContentForPage ) ) {
			return self::$mApprovedContentForPage[$pageID];
		}

		$revisionID = self::getApprovedRevID( $title );
		if ( empty( $revisionID ) ) {
			return null;
		}
		$text = self::getPageText( $title, $revisionID );
		self::$mApprovedContentForPage[$pageID] = $text;
		return $text;
	}

	/**
	 * Helper function - returns whether the user is currently requesting
	 * a page via the simple URL for it - not specfying a version number,
	 * not editing the page, etc.
	 */
	public static function isDefaultPageRequest( $request ) {
		if ( $request->getCheck( 'oldid' ) ) {
			return false;
		}
		// Check if it's an action other than viewing.
		if ( $request->getCheck( 'action' ) &&
			$request->getVal( 'action' ) != 'view' &&
			$request->getVal( 'action' ) != 'purge' &&
			$request->getVal( 'action' ) != 'render' ) {
				return false;
		}
		return true;
	}

	/**
	 * Returns whether this page can be approved - either because it's in
	 * a supported namespace, or because it's been specially marked as
	 * approvable. Also stores the boolean answer as a field in the page
	 * object, to speed up processing if it's called more than once.
	 */
	public static function pageIsApprovable( Title $title ) {
		// If this function was already called for this page, the value
		// should have been stored as a field in the $title object.
		if ( isset( $title->isApprovable ) ) {
			return $title->isApprovable;
		}

		if ( !$title->exists() ) {
			$title->isApprovable = false;
			return $title->isApprovable;
		}

		// Allow custom setting of whether the page is approvable.
		if ( !Hooks::run( 'ApprovedRevsPageIsApprovable', array( $title, &$isApprovable ) ) ) {
			$title->isApprovable = $isApprovable;
			return $title->isApprovable;
		}

		// Check the namespace.
		global $egApprovedRevsNamespaces;
		if ( in_array( $title->getNamespace(), $egApprovedRevsNamespaces ) ) {
			$title->isApprovable = true;
			return $title->isApprovable;
		}

		// It's not in an included namespace, so check for the page
		// property - for some reason, calling the standard
		// getProperty() function doesn't work, so we just do a DB
		// query on the page_props table.
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select( 'page_props', 'COUNT(*)',
			array(
				'pp_page' => $title->getArticleID(),
				'pp_propname' => 'approvedrevs',
				'pp_value' => 'y'
			)
		);
		$row = $dbr->fetchRow( $res );
		$isApprovable = ( $row[0] == '1' );
		$title->isApprovable = $isApprovable;
		return $isApprovable;
	}

	public static function checkPermission( User $user, Title $title, $permission ) {
		return ( $title->userCan( $permission, $user ) || $user->isAllowed( $permission ) );
	}

	public static function userCanApprove( User $user, Title $title ) {
		global $egApprovedRevsSelfOwnedNamespaces;
		$permission = 'approverevisions';

		// $mUserCanApprove is a static variable used for
		// "caching" the result of this function, so that
		// it only has to be called once.
		if ( self::$mUserCanApprove ) {
			return true;
		} elseif ( self::$mUserCanApprove === false ) {
			return false;
		} elseif ( ApprovedRevs::checkPermission( $user, $title, $permission ) ) {
			self::$mUserCanApprove = true;
			return true;
		} else {
			// If the user doesn't have the 'approverevisions'
			// permission, they still might be able to approve
			// revisions - it depends on whether the current
			// namespace is within the admin-defined
			// $egApprovedRevsSelfOwnedNamespaces array.
			$namespace = $title->getNamespace();
			if ( in_array( $namespace, $egApprovedRevsSelfOwnedNamespaces ) ) {
				if ( $namespace == NS_USER ) {
					// If the page is in the 'User:'
					// namespace, this user can approve
					// revisions if it's their user page.
					if ( $title->getText() == $user->getName() ) {
						self::$mUserCanApprove = true;
						return true;
					}
				} else {
					// Otherwise, they can approve revisions
					// if they created the page.
					// We get that information via a SQL
					// query - is there an easier way?
					$dbr = wfGetDB( DB_SLAVE );
					$row = $dbr->selectRow(
						array( 'r' => 'revision', 'p' => 'page' ),
						'r.rev_user_text',
						array( 'p.page_title' => $title->getDBkey() ),
						null,
						array( 'ORDER BY' => 'r.rev_id ASC' ),
						array( 'revision' => array( 'JOIN', 'r.rev_page = p.page_id' ) )
					);
					if ( $row->rev_user_text == $user->getName() ) {
						self::$mUserCanApprove = true;
						return true;
					}
				}
			}
		}
		self::$mUserCanApprove = false;
		return false;
	}

	public static function saveApprovedRevIDInDB( $title, $rev_id, $isAutoApprove = true ) {
		global $wgUser;
		$userBit = array();

		if ( !$isAutoApprove ) {
			$userBit = array( 'approver_id' => $wgUser->getID() );
		}

		$dbr = wfGetDB( DB_MASTER );
		$page_id = $title->getArticleID();
		$old_rev_id = $dbr->selectField( 'approved_revs', 'rev_id', array( 'page_id' => $page_id ) );
		if ( $old_rev_id ) {
			$dbr->update( 'approved_revs',
				array_merge( array( 'rev_id' => $rev_id ), $userBit ),
				array( 'page_id' => $page_id ) );
		} else {
			$dbr->insert( 'approved_revs',
				array_merge( array( 'page_id' => $page_id, 'rev_id' => $rev_id ), $userBit ) );
		}
		// Update "cache" in memory
		self::$mApprovedRevIDForPage[$page_id] = $rev_id;
		self::$mApproverForPage[$page_id] = $wgUser;
	}

	static function setPageSearchText( $title, $text ) {
		DeferredUpdates::addUpdate( new SearchUpdate( $title->getArticleID(), $title->getText(), $text ) );
	}

	/**
	 * Sets a certain revision as the approved one for this page in the
	 * approved_revs DB table; calls a "links update" on this revision
	 * so that category information can be stored correctly, as well as
	 * info for extensions such as Semantic MediaWiki; and logs the action.
	 */
	public static function setApprovedRevID( $title, $rev_id, $is_latest = false ) {
		self::saveApprovedRevIDInDB( $title, $rev_id, false );
		$parser = new Parser();

		// If the revision being approved is definitely the latest
		// one, there's no need to call the parser on it.
		if ( !$is_latest ) {
			$parser->setTitle( $title );
			$text = self::getPageText( $title, $rev_id );
			$options = new ParserOptions();
			$parser->parse( $text, $title, $options, true, true, $rev_id );
			$u = new LinksUpdate( $title, $parser->getOutput() );
			$u->doUpdate();
			self::setPageSearchText( $title, $text );
		}

		$log = new LogPage( 'approval' );
		$rev_url = $title->getFullURL( array( 'oldid' => $rev_id ) );
		$rev_link = Xml::element(
			'a',
			array( 'href' => $rev_url ),
			$rev_id
		);
		$logParams = array( $rev_link );
		$log->addEntry(
			'approve',
			$title,
			'',
			$logParams
		);

		Hooks::run( 'ApprovedRevsRevisionApproved', array( $parser, $title, $rev_id ) );
	}

	public static function deleteRevisionApproval( $title ) {
		$dbr = wfGetDB( DB_MASTER );
		$page_id = $title->getArticleID();
		$dbr->delete( 'approved_revs', array( 'page_id' => $page_id ) );
	}

	/**
	 * Unsets the approved revision for this page in the approved_revs DB
	 * table; calls a "links update" on this page so that category
	 * information can be stored correctly, as well as info for
	 * extensions such as Semantic MediaWiki; and logs the action.
	 */
	public static function unsetApproval( $title ) {
		global $egApprovedRevsBlankIfUnapproved;

		self::deleteRevisionApproval( $title );

		$parser = new Parser();
		$parser->setTitle( $title );
		if ( $egApprovedRevsBlankIfUnapproved ) {
			$text = '';
		} else {
			$text = self::getPageText( $title );
		}
		$options = new ParserOptions();
		$parser->parse( $text, $title, $options );
		$u = new LinksUpdate( $title, $parser->getOutput() );
		$u->doUpdate();
		self::setPageSearchText( $title, $text );

		$log = new LogPage( 'approval' );
		$log->addEntry(
			'unapprove',
			$title,
			''
		);

		Hooks::run( 'ApprovedRevsRevisionUnapproved', array( $parser, $title ) );
	}

	public static function addCSS() {
		global $wgOut;
		$wgOut->addModuleStyles( 'ext.ApprovedRevs' );
	}

	/**
	 * Helper function for backward compatibility.
	 */
	public static function makeLink( $linkRenderer, $title, $msg = null, $attrs = array(), $params = array() ) {
		if ( !is_null( $linkRenderer ) ) {
			// MW 1.28+
			return $linkRenderer->makeLink( $title, $msg, $attrs, $params );
		} else {
			return Linker::linkKnown( $title, $msg, $attrs, $params );
		}
	}

	/**
	 * Produces a db query array for specified type of request
	 *
	 * @param null|string $type allowed values are:
	 *  null (default)  - Pages whose approved revision is not their latest
	 *  'all'           - All pages with an approved revision
	 *  'invalid'       - Pages with invalid approvals
	 *  'unapproved'    - Unapproved pages
	 *
	 * @return array
	 */
	public static function getQueryByType( $type = null ) {
		global $egApprovedRevsNamespaces;

		$mainCondsString = "( pp_propname = 'approvedrevs' AND pp_value = 'y' )";
		if ( $type == 'invalid' ) {
			$mainCondsString = "( pp_propname IS NULL OR NOT $mainCondsString )";
		}

		if ( count( $egApprovedRevsNamespaces ) > 0 ) {
			if ( $type == 'invalid' ) {
				$mainCondsString .= " AND ( p.page_namespace NOT IN ( " . implode( ',', $egApprovedRevsNamespaces ) . " ) )";
			} else {
				$mainCondsString .= " OR ( p.page_namespace IN ( " . implode( ',', $egApprovedRevsNamespaces ) . " ) )";
			}
		}

		$query = array(
			'tables' => array(
				'ar' => 'approved_revs',
				'p' => 'page',
				'pp' => 'page_props',
			)
		);

		switch ($type) {

			// All pages with an approved revision
			case 'all':

				$query['fields'] = array(
					'p.page_id AS id',
					'ar.rev_id AS rev_id',
					'p.page_latest AS latest_id'
				);
				$query['join_conds'] = array(
					'p' => array(
						'JOIN', 'ar.page_id=p.page_id'
					),
					'pp' => array(
						'LEFT OUTER JOIN', 'ar.page_id=pp_page'
					)
				);
				$query['conds'] = $mainCondsString;
				break;

			// Unapproved pages
			case 'unapproved':

				$query['fields'] = array(
					'p.page_id AS id',
					'p.page_latest AS latest_id'
				);
				$query['join_conds'] = array(
					'ar' => array(
						'LEFT OUTER JOIN', 'p.page_id=ar.page_id'
					),
					'pp' => array(
						'LEFT OUTER JOIN', 'ar.page_id=pp_page'
					)
				);
				$query['conds'] = "ar.page_id IS NULL AND ( $mainCondsString )";
				break;

			// Pages with invalid approvals
			case 'invalid':

				$query['fields'] = array(
					'p.page_id AS id',
					'p.page_latest AS latest_id'
				);
				$query['join_conds'] = array(
					'p' => array(
						'LEFT OUTER JOIN', 'p.page_id=ar.page_id'
					),
					'pp' => array(
						'LEFT OUTER JOIN', 'ar.page_id=pp_page'
					),
				);
				$query['conds'] = $mainCondsString;
				break;

			// Pages whose approved revision is not their latest
			default:

				$query['fields'] = array(
					'p.page_id AS id',
					'ar.rev_id AS rev_id',
					'p.page_latest AS latest_id',
				);
				$query['join_conds'] = array(
					'p' => array(
						'JOIN', 'ar.page_id=p.page_id'
					),
					'pp' => array(
						'LEFT OUTER JOIN', 'ar.page_id=pp_page'
					)
				);
				$query['conds'] = "p.page_latest != ar.rev_id AND ( $mainCondsString )";
				break;
		}

		return $query;

	}

	/**
	 * Counts amount of pages matches given type criteria
	 *
	 * @param null|string $type allowed values are:
	 *  null (default)  - Pages whose approved revision is not their latest
	 *  'all'           - All pages with an approved revision
	 *  'invalid'       - Pages with invalid approvals
	 *  'unapproved'    - Unapproved pages
	 *
	 * @return int
	 */
	public static function countPagesByType( $type = null ) {
		$queryInfo = self::getQueryByType( $type );
		$dbr = wfGetDB( DB_SLAVE );
		$result = $dbr->selectRowCount(
				$queryInfo['tables'],
				'1',
				$queryInfo['conds'],
				__METHOD__,
				array(),
				$queryInfo['join_conds']
		);
		return $result;
	}

	/**
	 * Fetching total amount of pages match given queries types
	 * @param $types
	 *
	 * @return int
	 */
	public static function countPagesByTypes( $types ) {
		$total = 0;
		foreach ($types as $type) {
			$total += self::countPagesByType( $type );
		}
		return $total;
	}

	/**
	 * Pushes stats update job into a jobs queue
	 *
	 * @param $title
	 *
	 * @return bool
	 */
	public static function enqueueStatsUpdate( $title ) {
		global $egApprovedRevsDisableStatsUpdates;

		// If stats updates are disabled - do nothing since we expect
		// maintenance script to be added into crontab for scheduled stats update
		if( $egApprovedRevsDisableStatsUpdates ) {
			return true;
		}

		$jobParams = array();
		$job = new ARUpdateStatsJob( $title, $jobParams );
		JobQueueGroup::singleton()->lazyPush($job);
	}

	/**
	 * @return bool|stdClass
	 */
	public static function getStats() {
		$dbr = wfGetDB(DB_SLAVE);
		$row = $dbr->selectRow('approved_revs_stats', '*', array('row_id' => 1));
		return $row;
	}

	/**
	 * @param $stats
	 *
	 * @return bool
	 * @throws Exception
	 */
	public static function updateStats( $stats ) {
		$dbw = wfGetDB(DB_MASTER);
		return $dbw->update('approved_revs_stats', $stats, array('row_id' => 1));
	}

	/**
	 * Sends notification emails to users listed in $egApprovedRevsNotify
	 *
	 * @throws MWException
	 */
	public static function notifyStatsChange() {
		global $egApprovedRevsNotify, $wgPasswordSender, $wgPasswordSenderName;
		$emailSubject = wfMessage('approvedrevs-notify-email-subject')->text();
		foreach ($egApprovedRevsNotify as $userName ) {
			$user = User::newFromName( $userName );
			$email = $user->getEmail();
			if( !$user || empty($email) ) {
				continue;
			}
			$emailBody = wfMessage('approvedrevs-notify-email-body')->params($userName)->text();
			UserMailer::send(
				new MailAddress($email, $user->getName()),
				new MailAddress($wgPasswordSender, $wgPasswordSenderName),
				$emailSubject,
				$emailBody
			);
		}
	}

}
