<?php

class SpotPosting {
	private $_db;
	private $_settings;
	private $_nntp_post;

	function __construct(SpotDb $db, SpotSettings $settings) {
		$this->_db = $db;
		$this->_settings = $settings;
		
		$this->_nntp_post = new SpotNntp($settings->get('nntp_post'));
	} # ctor

	/*
	 * Post een comment op een spot naar de newsserver, als dit lukt komt er
	 * een 'true' terug, anders een foutmelding
	 */
	public function postComment($user, $comment) {
		$errorList = array();

		# haal de spot op waar dit een reply op is
		$spotsOverview = new SpotsOverview($this->_db, $this->_settings);
		$fullSpot = $spotsOverview->getFullSpot($comment['inreplyto'], $user['userid'], $this->_nntp_post);

		# als de hashcash al niet klopt, doen we verder geen moeite
		if (substr(sha1('<' . $comment['newmessageid'] . '>'), 0, 4) != '0000') {
			$errorList[] = _('Hash was not calculated properly');
		} # if

		# Body mag niet leeg zijn of heel kort
		$comment['body'] = trim($comment['body']);
		if (strlen($comment['body']) < 2) {
			$errorList[] = _('Please enter a comment');
		} # if
		if (strlen($comment['body']) > 9000) {
			$errorList[] = _('Comment is too long');
		} # if
		
		# Rating mag niet uit de range vallen
		if (($comment['rating'] > 10) || ($comment['rating'] < 0)) {
			$errorList[] = _('Invalid rating');
		} # if
		
		# controleer dat de messageid waarop we replyen overeenkomt
		# met het newMessageid om replay-attacks te voorkomen.
		$replyToPart = substr($comment['inreplyto'], 0, strpos($comment['inreplyto'], '@'));

		if (substr($comment['newmessageid'], 0, strlen($replyToPart)) != $replyToPart) { 
			$errorList[] = _('Replay attack!?');
		} # if
		
		# controleer dat het random getal niet recentelijk ook al gebruikt
		# is voor deze messageid (hiermee voorkomen we dat de hashcash niet
		# steeds herberekend wordt voor het volspammen van 1 spot).
		if (!$this->_db->isCommentMessageIdUnique($comment['newmessageid'])) {
			$errorList[] = _('Replay attack!?');
		} # if

		# Make sure a newmessageid contains a certain length
		if (strlen($comment['newmessageid']) < 10) {
			$errorList[] = _('MessageID too short!?');
		} # if

		# Add the title as a comment property
		$comment['title'] = 'Re: ' . $fullSpot['title'];
		
		# Body komt vanuit het form als UTF-8, maar moet verzonden worden als ISO-8859-1
		# De database wil echter alleen UTF-8, dus moeten we dat even opsplitsen
		$dbComment = $comment;
		$comment['body'] = utf8_decode($comment['body']);
		
		# en post daadwerkelijk de comment
		if (empty($errorList)) {
			$this->_nntp_post->postComment($user,
										   $this->_settings->get('privatekey'),  # Server private key
										   $this->_settings->get('comment_group'),
										   $comment);
			$this->_db->addPostedComment($user['userid'], $dbComment);
		} # if
		
		return $errorList;
	} # postComment

	/*
	 * Post a spot to the usenet server. 
	 */
	public function postSpot($user, $spot, $imageFilename, $nzbFilename) {
		$errorList = array();
		$hdr_newsgroup = $this->_settings->get('hdr_group');
		$bin_newsgroup = $this->_settings->get('nzb_group');

/*
		$hdr_newsgroup = 'alt.test';
		$bin_newsgroup = 'alt.test';
*/

		# If the hashcash doesn't match, we will never post it
		if (substr(sha1('<' . $spot['newmessageid'] . '>'), 0, 4) != '0000') {
			$errorList[] = _('Hash was not calculated properly');
		} # if

		# Read the contents of image so we can check it
		$imageContents = file_get_contents($imageFilename);

		# the image should be below 1MB
		if (strlen($imageContents) > 1024*1024) {
			$errorList[] = _('Uploaded image is too large (maximum 1MB)');
		} # if

		/*
		 * Get some image information, if it fails, this is an
		 * error as well
		 */
		$tmpGdImageSize = getimagesize($imageFilename);
		if ($tmpGdImageSize === false) {
			$errorList[] = _('Uploaded image was not recognized as an image');
		} else {
			$imageInfo = array('width' => $tmpGdImageSize[0],
					  	       'height' => $tmpGdImageSize[1]);
		} # if

		# Body cannot be empty, very short or too long
		$spot['body'] = trim($spot['body']);
		if (strlen($spot['body']) < 30) {
			$errorList[] = _('Please enter an description');
		} # if
		if (strlen($spot['body']) > 9000) {
			$errorList[] = _('Entered description is too long');
		} # if

		# Title cannot be empty or very short
		$spot['title'] = trim($spot['title']);
		if (strlen($spot['title']) < 5) {
			$errorList[] = _('Enter a title');
		} # if
		
		# Subcategory should be valid
		if (($spot['category'] < 0) || ($spot['category'] > count(SpotCategories::$_head_categories))) {
			$errorList[] = sprintf(_('Incorrect headcategory (%s)'), $spot['category']);
		} # if
		
		/*
		 * Load the NZB file as an XML file so we can make sure 
		 * it's a valid XML and NZB file and we can determine the
		 * filesize
		 */
		$nzbFileContents = file_get_contents($nzbFilename);
		$nzbXml = simplexml_load_string($nzbFileContents);

		# Do some basic sanity checking for some required NZB elements
		if (empty($nzbXml->file)) {
			$errorList[] = _('Incorrect NZB file');
		} # if
		
		# and determine the total filesize
		$spot['filesize'] = 0;
		foreach($nzbXml->file as $file) {
			foreach($file->segments->segment as $seg) {
				$spot['filesize'] += (int) $seg['bytes'];
			} # foreach
		} # foreach
		
		/*
		 * Make sure we didn't use this messageid recently or at all, this
		 * prevents people from not recalculating the hashcash in order to spam
		 * the system
		 */
		if (!$this->_db->isNewSpotMessageIdUnique($spot['newmessageid'])) {
			$errorList[] = _('Replay attack!?');
		} # if

		# Make sure a newmessageid contains a certain length
		if (strlen($spot['newmessageid']) < 10) {
			$errorList[] = _('MessageID too short!?');
		} # if

		# We require the keyid 7 because it is selfsigned
		$spot['key'] = 7;
		
		# Poster's  username
		$spot['poster'] = $user['username'];
		
		# Fix up some overly long spot properties and other minor issues
		$spot['tag'] = substr(trim($spot['tag'], " |;\r\n\t"), 0, 99);
		$spot['http'] = substr(trim($spot['website']), 0, 449);
		
		/**
		 * If the post's character do not fit into ISO-8859-1, we HTML
		 * encode the UTF-8 characters so we can properly post the spots
		 */
		if (mb_detect_encoding($spot['title'], 'UTF-8, ISO-8859-1', true) == 'UTF-8') {
			$spot['title'] = mb_convert_encoding($spot['title'], 'HTML-ENTITIES', 'UTF-8');
		} # if

		/*
		 * Loop through all subcategories and check if they are valid in
		 * our list of subcategories
		 */
		$subCatSplitted = array('a' => array(), 'b' => array(), 'c' => array(), 'd' => array(), 'z' => array());

		foreach($spot['subcatlist'] as $subCat) {
			$subcats = explode('_', $subCat);
			# If not in our format
			if (count($subcats) != 3) {
				$errorList[] = sprintf(_('Incorrect subcategories (%s)'), $subCat);
			} else {
				$subCatLetter = substr($subcats[2], 0, 1);
				
				$subCatSplitted[$subCatLetter][] = $subCat;
				
				if (!isset(SpotCategories::$_categories[$spot['category']][$subCatLetter][substr($subcats[2], 1)])) {
					$errorList[] = sprintf(_('Incorrect subcategories (%s)'), $subCat . ' !! ' . $subCatLetter . ' !! ' . substr($subcats[2], 1));
				} # if
			} # else
		} # foreach	

		/*
		 * Make sure all subcategories are in the format we expect, for
		 * example we strip the 'cat' part and strip the z-subcat
		 */
		$subcatCount = count($spot['subcatlist']);
		for($i = 0; $i < $subcatCount; $i++) {
			$subcats = explode('_', $spot['subcatlist'][$i]);
			
			# If not in our format
			if (count($subcats) != 3) {
				$errorList[] = sprintf(_('Incorrect subcateories (%s)'), $spot['subcatlist'][$i]);
			} else {
				$spot['subcatlist'][$i] = substr($subcats[2], 0, 1) . str_pad(substr($subcats[2], 1), 2, '0', STR_PAD_LEFT);
				
				# Explicitly add the 'z'-category - we derive it from the full categorynames we already have
				$zcatStr = substr($subcats[1], 0, 1) . str_pad(substr($subcats[1], 1), 2, '0', STR_PAD_LEFT);
				if ((is_numeric(substr($subcats[1], 1))) && (array_search($zcatStr, $spot['subcatlist']) === false)) {
					$spot['subcatlist'][] = $zcatStr;
				} # if
			} # else			
		} # for

		# Make sure the spot isn't being posted in many categories
		if (count($subCatSplitted['a']) > 1) {
			$errorList[] = _('You can only specify one format for a spot');
		} # if

		# Make sure the spot has at least a format
		if (count($subCatSplitted['a']) < 1) {
			$errorList[] = _('You need to specify a format for a spot');
		} # if
		
		# Make sure the spot isn't being posted for too many categories
		if (count($spot['subcatlist']) > 10) {
			$errorList[] = _('Too many categories');
		} # if

		# Make sure the spot isn't being posted for too many categories
		if (count($spot['subcatlist']) < 2) {
			$errorList[] = _('At least one category need to be selected');
		} # if

		# en post daadwerkelijk de spot
		if (empty($errorList)) {
			/*
			 * Retrieve the image information and post the image to 
			 * the appropriate newsgroup so we have the messageid list of 
			 * images
			 */
			$imgSegmentList = $this->_nntp_post->postBinaryMessage($user, $bin_newsgroup, $imageContents, '');
			$imageInfo['segments'] = $imgSegmentList;
				
			# Post the NZB file to the appropriate newsgroups
			$nzbSegmentList = $this->_nntp_post->postBinaryMessage($user, $bin_newsgroup, gzdeflate($nzbFileContents), '');
			
			# Convert the current Spotnet info, to an XML structure
			$spotParser = new SpotParser();
			$spotXml = $spotParser->convertSpotToXml($spot, $imageInfo, $nzbSegmentList);
			$spot['spotxml'] = $spotXml;
			
			# And actually post to the newsgroups
			$this->_nntp_post->postFullSpot($user,
										   $this->_settings->get('privatekey'),  # Server private key
										   $hdr_newsgroup,
										   $spot);
			$this->_db->addPostedSpot($user['userid'], $spot, $spotXml);
		} # if

		return $errorList;
	} # postSpot
	
	/*
	 * Post een spam report van een spot naar de newsserver, als dit lukt komt er
	 * een 'true' terug, anders een foutmelding
	 */
	public function reportSpotAsSpam($user, $report) {
		$errorList = array();

		# Controleer eerst of de user al een report heeft aangemaakt, dan kunnen we gelijk stoppen.
		if ($this->_db->isReportPlaced($report['inreplyto'], $user['userid'])) {
			$errorList[] = _('This spot has already been marked as spam');
		} # if
		
		# haal de spot op waar dit een reply op is
		$spotsOverview = new SpotsOverview($this->_db, $this->_settings);
		$fullSpot = $spotsOverview->getFullSpot($report['inreplyto'], $user['userid'], $this->_nntp_post);

		# als de hashcash al niet klopt, doen we verder geen moeite
		if (substr(sha1('<' . $report['newmessageid'] . '>'), 0, 4) != '0000') {
			$errorList[] = _('Hash was not calculated properly');
		} # if

		# Body mag niet leeg zijn of heel kort
		$report['body'] = trim($report['body']);
		if (strlen($report['body']) < 2) {
			$errorList[] = _('Please provide a comment');
		} # if
		
		# controleer dat de messageid waarop we replyen overeenkomt
		# met het newMessageid om replay-attacks te voorkomen.
		$replyToPart = substr($report['inreplyto'], 0, strpos($report['inreplyto'], '@'));

		if (substr($report['newmessageid'], 0, strlen($replyToPart)) != $replyToPart) { 
			$errorList[] = _('Replay attack!?');
		} # if
		
		# controleer dat het random getal niet recentelijk ook al gebruikt
		# is voor deze messageid (hiermee voorkomen we dat de hashcash niet
		# steeds herberekend wordt voor het volspammen van 1 spot).
		if (!$this->_db->isReportMessageIdUnique($report['newmessageid'])) {
			$errorList[] = _('Replay attack!?');
		} # if

		# Make sure a newmessageid contains a certain length
		if (strlen($report['newmessageid']) < 10) {
			$errorList[] = _('MessageID too short!?');
		} # if

		# Body komt vanuit het form als UTF-8, maar moet verzonden worden als ISO-8859-1
		# De database wil echter alleen UTF-8, dus moeten we dat even opsplitsen
		$dbReport = $report;
		$report['body'] = utf8_decode($report['body']);
		$report['title'] = 'REPORT <' . $report['inreplyto'] . '> ' . $fullSpot['title'];

		# en post daadwerkelijk de report
		if (empty($errorList)) {
			$this->_nntp_post->reportSpotAsSpam($user,
										   $this->_settings->get('privatekey'),  # Server private key
										   $this->_settings->get('report_group'),
										   $report);
			$this->_db->addPostedReport($user['userid'], $dbReport);
		} # if
		
		return $errorList;
	} # reportSpotAsSpam
	
} # SpotPosting
