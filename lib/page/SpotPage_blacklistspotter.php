<?php
class SpotPage_blacklistspotter extends SpotPage_Abs {
	private $_blForm;
	
	function __construct(SpotDb $db, SpotSettings $settings, $currentSession, $params) {
		parent::__construct($db, $settings, $currentSession);
		$this->_blForm = $params['blform'];
	} # ctor

	function render() {
		$formMessages = array('errors' => array(),
							  'info' => array());
							  
		# Controleer de users' rechten
		$this->_spotSec->fatalPermCheck(SpotSecurity::spotsec_blacklist_spotter, '');
				
		# creeer een default blacklist
		$blackList = array('spotterid' => '',
						   'origin' => '');
		
		# blacklist is standaard niet geprobeerd
		$postResult = array();
		
		# zet de page title
		$this->_pageTitle = "report: blacklist spotter";

		/* 
		 * bring the forms' action into the local scope for 
		 * easier access
		 */
		$formAction = $this->_blForm['action'];

		# Make sure the anonymous user and reserved usernames cannot post content
		$spotUserSystem = new SpotUserSystem($this->_db, $this->_settings);
		if (!$spotUserSystem->allowedToPost($this->_currentSession['user'])) {
			$postResult = array('result' => 'notloggedin');

			$formAction = '';
		} # if
		
		if (!empty($formAction)) {
			# zorg er voor dat alle variables ingevuld zijn
			$blackList = array_merge($blackList, $this->_blForm);

			switch($formAction) {
				case 'addspotterid'		: {
					$spotUserSystem->addSpotterToList($this->_currentSession['user']['userid'], $blackList['spotterid'], $blackList['origin'], $blackList['idtype']);
					break;
				} # case addspotterid
				
				case 'removespotterid'	: {
					$idtyPe = $blackList['idtype'];
					$spotUserSystem->removeSpotterFromList($this->_currentSession['user']['userid'], $blackList['spotterid']);
					break;
				} # case removespotterid
			} # switch
			
			$postResult = array('result' => 'success');
		} # if
		
		#- display stuff -#
		$this->template('blacklistspotter', array('blacklistspotter' => $blackList,
											 'formmessages' => $formMessages,
											 'postresult' => $postResult));
	} # render	
} # class SpotPage_blacklistspotter
