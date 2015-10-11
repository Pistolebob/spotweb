<?php
# Deze klasse proxied feitelijk requests voor meerdere resources, dit is voornamelijk handig
# als er meerdere JS files e.d. geinclude moeten worden. 
# 
# Normaal kan je dit ook met mod_expires (van apache) en gelijkaardigen oplossen, maar dit vereist
# server configuratie en dit kunnen we op deze manier vrij makkelijk in de webapp oplossen.
#
class SpotPage_statics extends SpotPage_Abs {
	private $_params;
	private $_currentCssFile;

	function __construct(SpotDb $db, SpotSettings $settings, $currentSession, $params) {
		parent::__construct($db, $settings, $currentSession);
		
		$this->_params = $params;
	} # ctor

	function cbFixCssUrl($needle) {
		return 'URL(' . dirname($this->_currentCssFile) . '/' . trim($needle[1], '"\'') . ')';
	} # cbFixCssUrl
	
	function cbGetText($s) {
		return _($s[1]);
	} # cbGetText

	function mergeFiles($files) {
		$tmp = '';

		foreach($files as $file) {
			$fc = file_get_contents($file) . PHP_EOL;
			$fc = str_replace(
				Array('$HTTP_S',
					  '$COOKIE_EXPIRES',
					  '$COOKIE_HOST'),
				Array((@$_SERVER['HTTPS'] == 'on' ? 'https' : 'http'),
				       $this->_settings->get('cookie_expires'),
					   $this->_settings->get('cookie_host')),
				$fc);

			# ik ben geen fan van regexpen maar in dit scheelt het
			# het volledig parsen van de content van de CSS file dus
			# is het het overwegen waard.
			$this->_currentCssFile = $file;
			$fc = preg_replace_callback('/url\(([^)]+)\)/i', array($this, 'cbFixCssUrl'), $fc);
			
			# also replace any internationalisation strings in JS. 
			# Code copied from:
			#	http://stackoverflow.com/questions/5069321/preg-replace-and-gettext-problem
			$fc = preg_replace_callback("%\<t\>([a-zA-Z0-9',\.\\s\(\))]*)\</t\>%is", array($this, 'cbGetText'), $fc); 
			
			$tmp .= $fc;
		} # foreach

		# en geef de body terug
		return array('body' => $tmp);
	} # mergeFiles
	
	function render() {
		$tplHelper = $this->_tplHelper;

		# Controleer de users' rechten
		$this->_spotSec->fatalPermCheck(SpotSecurity::spotsec_view_statics, '');
		
		# vraag de content op
		$mergedInfo = $this->mergeFiles($tplHelper->getStaticFiles($this->_params['type']));

		# Er is een bug met mod_deflate en mod_fastcgi welke ervoor zorgt dat de content-length
		# header niet juist geupdate wordt. Als we dus mod_fastcgi detecteren, dan sturen we
		# content-length header niet mee
		if (isset($_SERVER['REDIRECT_HANDLER']) && ($_SERVER['REDIRECT_HANDLER'] != 'php-fastcgi')) {
			Header("Content-Length: " . strlen($mergedInfo['body']));
		} # if

		# en stuur de versie specifieke content
		switch($this->_params['type']) {
			case 'css'		: $this->sendContentTypeHeader('css');
							  Header('Vary: Accept-Encoding'); // sta toe dat proxy servers dit cachen
							  break;
			case 'js'		: $this->sendContentTypeHeader('js'); break;
			case 'ico'		: $this->sendContentTypeHeader('ico'); break;
		} # switch
		
		# stuur de expiration headers
		$this->sendExpireHeaders(false);
		
		# stuur de last-modified header
		Header("Last-Modified: " . gmdate("D, d M Y H:i:s", $tplHelper->getStaticModTime($this->_params['type'])) . " GMT"); 

		echo $mergedInfo['body'];
	} # render

} # class SpotPage_statics