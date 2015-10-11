<?php
class SpotStruct_mysql extends SpotStruct_abs {

	/*
	 * Optimize / analyze (database specific) a number of hightraffic
	 * tables.
	 * This function does not modify any schema or data
	 */
	function analyze() { 
		$this->_dbcon->rawExec("ANALYZE TABLE spots");
		$this->_dbcon->rawExec("ANALYZE TABLE spotsfull");
		$this->_dbcon->rawExec("ANALYZE TABLE commentsxover");
		$this->_dbcon->rawExec("ANALYZE TABLE commentsfull");
		$this->_dbcon->rawExec("ANALYZE TABLE spotstatelist");
		$this->_dbcon->rawExec("ANALYZE TABLE sessions");
		$this->_dbcon->rawExec("ANALYZE TABLE filters");
		$this->_dbcon->rawExec("ANALYZE TABLE spotteridblacklist");
		$this->_dbcon->rawExec("ANALYZE TABLE filtercounts");
		$this->_dbcon->rawExec("ANALYZE TABLE users");
		$this->_dbcon->rawExec("ANALYZE TABLE cache");
	} # analyze
	
	/*
	 * Converts a 'spotweb' internal datatype to a 
	 * database specific datatype
	 */
	function swDtToNative($colType) {
		switch(strtoupper($colType)) {
			case 'INTEGER'				: $colType = 'int(11)'; break;
			case 'UNSIGNED INTEGER'		: $colType = 'int(10) unsigned'; break;
			case 'BIGINTEGER'			: $colType = 'bigint(20)'; break;
			case 'UNSIGNED BIGINTEGER'	: $colType = 'bigint(20) unsigned'; break;
			case 'BOOLEAN'				: $colType = 'tinyint(1)'; break;
			case 'MEDIUMBLOB'			: $colType = 'mediumblob'; break;
		} # switch
		
		return $colType;
	} # swDtToNative 

	/*
	 * Converts a database native datatype to a spotweb native
	 * datatype
	 */
	function nativeDtToSw($colInfo) {
		switch(strtolower($colInfo)) {
			case 'int(11)'				: $colInfo = 'INTEGER'; break;
			case 'int(10) unsigned'		: $colInfo = 'UNSIGNED INTEGER'; break;
			case 'bigint(20)'			: $colInfo = 'BIGINTEGER'; break;
			case 'bigint(20) unsigned'	: $colInfo = 'UNSIGNED BIGINTEGER'; break;
			case 'tinyint(1)'			: $colInfo = 'BOOLEAN'; break;
			case 'mediumblob'			: $colInfo = 'MEDIUMBLOB'; break;
		} # switch
		
		return $colInfo;
	} # nativeDtToSw 
	
	/* checks if an index exists */
	function indexExists($idxname, $tablename) {
		$q = $this->_dbcon->arrayQuery("SHOW INDEXES FROM " . $tablename . " WHERE key_name = '%s'", Array($idxname));
		return !empty($q);
	} # indexExists

	/* checks if a column exists */
	function columnExists($tablename, $colname) {
		$q = $this->_dbcon->arrayQuery("SHOW COLUMNS FROM " . $tablename . " WHERE Field = '%s'", Array($colname));
		return !empty($q);
	} # columnExists

	/*
	 * Adds an index, but first checks if the index doesn't
	 * exist already.
	 *
	 * $idxType can be either 'UNIQUE', '' or 'FULLTEXT'
	 */
	function addIndex($idxname, $idxType, $tablename, $colList) {
		if (!$this->indexExists($idxname, $tablename)) {
			if ($idxType == "UNIQUE") {
				$this->_dbcon->rawExec("ALTER IGNORE TABLE " . $tablename . " ADD " . $idxType . " INDEX " . $idxname . "(" . implode(",", $colList) . ");");
			} else {
				$this->_dbcon->rawExec("ALTER TABLE " . $tablename . " ADD " . $idxType . " INDEX " . $idxname . "(" . implode(",", $colList) . ");");
			} # else
		} # if
	} # addIndex

	/* checks if a fts text index exists */
	function ftsExists($ftsname, $tablename, $colList) {
		foreach($colList as $num => $col) {
			$indexInfo = $this->getIndexInfo($ftsname . '_' . $num, $tablename);
			
			if ((empty($indexInfo)) || (strtolower($indexInfo[0]['column_name']) != strtolower($col))) {
				return false;
			} # if
		} # foreach
		
		return true;
	} # ftsExists
			
	/* creates a full text index */
	function createFts($ftsname, $tablename, $colList) {
		foreach($colList as $num => $col) {
			$indexInfo = $this->getIndexInfo($ftsname . '_' . $num, $tablename);
			
			if ((empty($indexInfo)) || (strtolower($indexInfo[0]['column_name']) != strtolower($col))) {
				$this->dropIndex($ftsname . '_' . $num, $tablename);
				$this->addIndex($ftsname . '_' . $num, 'FULLTEXT', $tablename, array($col));
			} # if
		} # foreach
	} # createFts
	
	/* drops a fulltext index */
	function dropFts($ftsname, $tablename, $colList) {
		foreach($colList as $num => $col) {
			$this->dropIndex($ftsname . '_' . $num, $tablename);
		} # foreach
	} # dropFts
	
	/* returns FTS info  */
	function getFtsInfo($ftsname, $tablename, $colList) {
		$ftsList = array();
		
		foreach($colList as $num => $col) {
			$tmpIndex = $this->getIndexInfo($ftsname . '_' . $num, $tablename);
			
			if (!empty($tmpIndex)) {
				$ftsList[] = $tmpIndex[0];
			} # if
		} # foreach
		
		return $ftsList;
	} # getFtsInfo
	
	/* drops an index if it exists */
	function dropIndex($idxname, $tablename) {
		/*
		 * Make sure the table exists, else this will return an error
		 * and return a fatal
		 */
		if (!$this->tableExists($tablename)) {
			return ;
		} # if
		
		if ($this->indexExists($idxname, $tablename)) {
			$this->_dbcon->rawExec("DROP INDEX " . $idxname . " ON " . $tablename);
		} # if
	} # dropIndex
	
	/* adds a column if the column doesn't exist yet */
	function addColumn($colName, $tablename, $colType, $colDefault, $notNull, $collation) {
		if (!$this->columnExists($tablename, $colName)) {
			# set the DEFAULT value
			if (strlen($colDefault) != 0) {
				$colDefault = 'DEFAULT ' . $colDefault;
			} # if

			# Convert the column type to a type we use in MySQL
			$colType = $this->swDtToNative($colType);

			# change the collation to a MySQL type
			switch(strtolower($collation)) {
				case 'utf8'			: $colSetting = 'CHARACTER SET utf8 COLLATE utf8_unicode_ci'; break;
				case 'ascii'		: $colSetting = 'CHARACTER SET ascii'; break;
				case 'ascii_bin'	: $colSetting = 'CHARACTER SET ascii COLLATE ascii_bin'; break;
				case ''				: $colSetting = ''; break;
				default				: throw new Exception("Invalid collation setting");
			} # switch
			
			# and define the 'NOT NULL' part
			switch($notNull) {
				case true		: $nullStr = 'NOT NULL'; break;
				default			: $nullStr = '';
			} # switch
			
			$this->_dbcon->rawExec("ALTER TABLE " . $tablename . 
						" ADD COLUMN(" . $colName . " " . $colType . " " . $colSetting . " " . $colDefault . " " . $nullStr . ")");
		} # if
	} # addColumn
	
	/* alters a column - does not check if the column doesn't adhere to the given definition */
	function modifyColumn($colName, $tablename, $colType, $colDefault, $notNull, $collation, $what) {
		# set the DEFAULT value
		if (strlen($colDefault) != 0) {
			$colDefault = 'DEFAULT ' . $colDefault;
		} # if

		# Convert the column type to a type we use in MySQL
		$colType = $this->swDtToNative($colType);

		# change the collation to a MySQL type
		switch(strtolower($collation)) {
			case 'utf8'			: $colSetting = 'CHARACTER SET utf8 COLLATE utf8_unicode_ci'; break;
			case 'ascii'		: $colSetting = 'CHARACTER SET ascii'; break;
			case 'ascii_bin'	: $colSetting = 'CHARACTER SET ascii COLLATE ascii_bin'; break;
			case ''				: $colSetting = ''; break;
			default				: throw new Exception("Invalid collation setting");
		} # switch
		
		# and define the 'NOT NULL' part
		switch($notNull) {
			case true		: $nullStr = 'NOT NULL'; break;
			default			: $nullStr = '';
		} # switch
		
		$this->_dbcon->rawExec("ALTER TABLE " . $tablename . 
					" MODIFY COLUMN " . $colName . " " . $colType . " " . $colSetting . " " . $colDefault . " " . $nullStr);
	} # modifyColumn


	/* drops a column */
	function dropColumn($colName, $tablename) {
		if ($this->columnExists($tablename, $colName)) {
			$this->_dbcon->rawExec("ALTER TABLE " . $tablename . " DROP COLUMN " . $colName);
		} # if
	} # dropColumn

	/* checks if a table exists */
	function tableExists($tablename) {
		$q = $this->_dbcon->arrayQuery("SHOW TABLES LIKE '" . $tablename . "'");
		return !empty($q);
	} # tableExists

	/* creates an empty table with only an ID field. Collation should be either UTF8 or ASCII */
	function createTable($tablename, $collation) {
		if (!$this->tableExists($tablename)) {
			switch(strtolower($collation)) {
				case 'utf8'		: $colSetting = 'CHARSET=utf8 COLLATE=utf8_unicode_ci'; break;
				case 'ascii'	: $colSetting = 'CHARSET=ascii'; break;
				default			: throw new Exception("Invalid collation setting");
			} # switch
		
			$this->_dbcon->rawExec("CREATE TABLE " . $tablename . " (id INTEGER PRIMARY KEY AUTO_INCREMENT) " . $colSetting);
		} # if
	} # createTable
	
	/* drop a table */
	function dropTable($tablename) {
		if ($this->tableExists($tablename)) {
			$this->_dbcon->rawExec("DROP TABLE " . $tablename);
		} # if
	} # dropTable
	
	/* alters a storage engine (only mysql knows something about store engines, but well  :P ) */
	function alterStorageEngine($tablename, $engine) {
		$q = $this->_dbcon->singleQuery("SELECT ENGINE 
										FROM information_schema.TABLES 
										WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $tablename . "'");

		if (strtolower($q) != strtolower($engine)) {
			$this->_dbcon->rawExec("ALTER TABLE " . $tablename . " ENGINE=" . $engine);
		} # if
	} # alterStorageEngine

	/* rename a table */
	function renameTable($tablename, $newTableName) {
		$this->_dbcon->rawExec("RENAME TABLE " . $tablename . " TO " . $newTableName);
	} # renameTable

	/* drop a foreign key constraint */
	function dropForeignKey($tablename, $colname, $reftable, $refcolumn, $action) {
		$q = $this->_dbcon->arrayQuery("SELECT CONSTRAINT_NAME FROM information_schema.key_column_usage 
										WHERE TABLE_SCHEMA = DATABASE() 
										  AND TABLE_NAME = '" . $tablename . "' 
										  AND COLUMN_NAME = '" . $colname . "'
										  AND REFERENCED_TABLE_NAME = '" . $reftable . "' 
										  AND REFERENCED_COLUMN_NAME = '" . $refcolumn . "'");
		if (!empty($q)) {
			foreach($q as $res) {
				$this->_dbcon->rawExec("ALTER TABLE " . $tablename . " DROP FOREIGN KEY " . $res['CONSTRAINT_NAME']);
			} # foreach
		} # if
	} # dropForeignKey

	/* creates a foreign key constraint */
	function addForeignKey($tablename, $colname, $reftable, $refcolumn, $action) {
		$q = $this->_dbcon->arrayQuery("SELECT * FROM information_schema.key_column_usage 
										WHERE TABLE_SCHEMA = DATABASE() 
										  AND TABLE_NAME = '" . $tablename . "' 
										  AND COLUMN_NAME = '" . $colname . "'
										  AND REFERENCED_TABLE_NAME = '" . $reftable . "' 
										  AND REFERENCED_COLUMN_NAME = '" . $refcolumn . "'");
		if (empty($q)) {
			$this->_dbcon->rawExec("ALTER TABLE " . $tablename . " ADD FOREIGN KEY (" . $colname . ") 
										REFERENCES " . $reftable . " (" . $refcolumn . ") " . $action);
		} # if
	} # addForeignKey

	/* Returns in a fixed format, column information */
	function getColumnInfo($tablename, $colname) {
		$q = $this->_dbcon->arrayQuery("SELECT COLUMN_NAME, 
											   COLUMN_DEFAULT, 
											   IS_NULLABLE, 
											   COLUMN_TYPE, 
											   CHARACTER_SET_NAME, 
											   COLLATION_NAME 
										FROM information_schema.COLUMNS 
										WHERE TABLE_NAME = '" . $tablename . "'
										  AND COLUMN_NAME = '" . $colname . "'
										  AND TABLE_SCHEMA = DATABASE()");
		if (!empty($q)) {
			$q = $q[0];
			$q['NOTNULL'] = ($q['IS_NULLABLE'] != 'YES');

			/* 
			 * MySQL's boolean type secretly is a tinyint, but in Spotweb we
			 * use an actual boolean type. We secretly convert all tinyint(1)'s
			 * to boolean types.
			 */
			if (strtolower($q['COLUMN_TYPE']) == 'tinyint(1)') {
				if (is_numeric($q['COLUMN_DEFAULT'])) {
					if ($q['COLUMN_DEFAULT']) {
						$q['COLUMN_DEFAULT'] = '1';
					} else {
						$q['COLUMN_DEFAULT'] = '0';
					} # if
				} # if
			} # if

			/*
			 * We do not properly distinguish between character sets and
			 * collations in the spotweb system, so we mangle them a bit
			 */
			if (is_string($q['COLLATION_NAME'])) {
				switch($q['COLLATION_NAME']) {
					case 'ascii_general_ci'		: $q['COLLATION_NAME'] = 'ascii'; break;
					case 'ascii_bin'			: $q['COLLATION_NAME'] = 'ascii_bin'; break;
					case 'utf8_unicode_ci'		: $q['COLLATION_NAME'] = 'utf8'; break;
					case 'utf8_general_ci'		: $q['COLLATION_NAME'] = 'utf8'; break;

					default 					: throw new Exception("Invalid collation setting for varchar: " . $q['COLLATION_NAME']);
				} # switch
			} # if

			# a default value has to given, so make it compareable to what we define
			if ((strlen($q['COLUMN_DEFAULT']) == 0) && (is_string($q['COLUMN_DEFAULT']))) {	
				$q['COLUMN_DEFAULT'] = "''";
			} # if
		} # if
		
		return $q;
	} # getColumnInfo
	
	/* Returns in a fixed format, index information */
	function getIndexInfo($idxname, $tablename) {
		$q = $this->_dbcon->arrayQuery("SELECT 
											column_name, 
											non_unique, 
											lower(index_type) as index_type
										FROM information_schema.STATISTICS 
										WHERE TABLE_SCHEMA = DATABASE() 
										  AND table_name = '" . $tablename . "' 
										  AND index_name = '" . $idxname . "' 
										ORDER BY seq_in_index");
		return $q;
	} # getIndexInfo
	
} # class
