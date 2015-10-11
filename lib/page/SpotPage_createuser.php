<?php
class SpotPage_createuser extends SpotPage_Abs {
	private $_createUserForm;
	
	function __construct(SpotDb $db, SpotSettings $settings, $currentSession, $params) {
		parent::__construct($db, $settings, $currentSession);
		$this->_createUserForm = $params['createuserform'];
	} # ctor

	function render() {
		$formMessages = array('errors' => array(),
							  'info' => array());
		
		# Controleer de users' rechten
		$this->_spotSec->fatalPermCheck(SpotSecurity::spotsec_create_new_user, '');

		# creeer een default spotuser zodat het form altijd
		# de waardes van het form kan renderen
		$spotUser = array('username' => '',
						  'firstname' => '',
						  'lastname' => '',
						  'mail' => '');
		
		# createuser resultaat is standaard niet geprobeerd
		$createResult = array();
		
		# Instantieer het Spot user system
		$spotUserSystem = new SpotUserSystem($this->_db, $this->_settings);
		
		# zet de page title
		$this->_pageTitle = "spot: create user";

		/* 
		 * bring the forms' action into the local scope for 
		 * easier access
		 */
		$formAction = $this->_createUserForm['action'];
		
		# Is dit een submit van een form, of nog maar de aanroep?
		if ($formAction == 'create') {
			# userid zetten we altijd op false voor het maken van een
			# nieuwe user, omdat validateUserRecord() anders denkt
			# dat we een bestaande user aan het bewerken zijn en we bv.
			# het mailaddress niet controleren op dubbelen behalve 'zichzelf'
			$this->_createUserForm['userid'] = false;
			
			# creeer een random password voor deze user
			$spotUser['newpassword1'] = substr($spotUserSystem->generateUniqueId(), 1, 9);
			$spotUser['newpassword2'] = $spotUser['newpassword1'];
				
			# valideer de user
			$spotUser = array_merge($spotUser, $this->_createUserForm);
			$formMessages['errors'] = $spotUserSystem->validateUserRecord($spotUser, false);

			# Is er geen andere user met dezelfde username?
			$userIdForName = $this->_db->findUserIdForName($spotUser['username']);
			if (!empty($userIdForName)) {
				$formMessages['errors'][] = sprintf(_("'%s' already exists"), $spotUser['username']);
			} # if
			
			if (empty($formMessages['errors'])) {
				# Creer een private en public key paar voor deze user
				$spotSigning = Services_Signing_Base::newServiceSigning();
				$userKey = $spotSigning->createPrivateKey($this->_settings->get('openssl_cnf_path'));
				$spotUser['publickey'] = $userKey['public'];
				$spotUser['privatekey'] = $userKey['private'];

				# Notificatiesysteem initialiseren
				$spotsNotifications = new SpotNotifications($this->_db, $this->_settings, $this->_currentSession);

				# voeg de user toe
				$spotUserSystem->addUser($spotUser);
				
				# als het toevoegen van de user gelukt is, laat het weten
				$createResult = array('result' => 'success');
				$formMessages['info'][] = sprintf(_("User <strong>&quot;%s&quot;</strong> successfully added"), $spotUser['username']);
				$formMessages['info'][] = sprintf(_("Password: <strong>&quot;%s&quot;</strong>"), $spotUser['newpassword1']);

				# verstuur een e-mail naar de nieuwe gebruiker als daar om is gevraagd
				$sendMail = isset($this->_createUserForm['sendmail']);
				if ($sendMail || $this->_settings->get('sendwelcomemail')) {
					$spotsNotifications->sendNewUserMail($spotUser);
 				} # if 

				# en verstuur een notificatie
				$spotsNotifications->sendUserAdded($spotUser['username'], $spotUser['newpassword1']);
			} else {
				$createResult = array('result' => 'failure');
			} # else
			
		} # if
		
		#- display stuff -#
		$this->template('createuser', array('createuserform' => $spotUser,
										    'formmessages' => $formMessages,
											'createresult' => $createResult));
	} # render
	
} # class SpotPage_createuser
