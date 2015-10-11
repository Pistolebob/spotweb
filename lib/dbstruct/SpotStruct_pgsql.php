<?php
class SpotStruct_pgsql extends SpotStruct_abs {

	/*
	 * Optimize / analyze (database specific) a number of hightraffic
	 * tables.
	 * This function does not modify any schema or data
	 */
	function analyze() { 
		$this->_dbcon->rawExec("VACUUM ANALYZE spots");
		$this->_dbcon->rawExec("VACUUM ANALYZE spotsfull");
		$this->_dbcon->rawExec("VACUUM ANALYZE commentsxover");
		$this->_dbcon->rawExec("VACUUM ANALYZE commentsfull");
		$this->_dbcon->rawExec("VACUUM ANALYZE sessions");
		$this->_dbcon->rawExec("VACUUM ANALYZE filters");
		$this->_dbcon->rawExec("VACUUM ANALYZE spotteridblacklist");
		$this->_dbcon->rawExec("VACUUM ANALYZE filtercounts");
		$this->_dbcon->rawExec("VACUUM ANALYZE spotstatelist");
		$this->_dbcon->rawExec("VACUUM ANALYZE users");
		$this->_dbcon->rawExec("VACUUM ANALYZE cache");
	} # analyze
	
	/*
	 * Converts a 'spotweb' internal datatype to a 
	 * database specific datatype
	 */
	function swDtToNative($colType) {
		switch(strtoupper($colType)) {
			case 'INTEGER'				: $colType = 'integer'; break;
			case 'UNSIGNED INTEGER'		: $colType = 'bigint'; break;
			case 'BIGINTEGER'			: $colType = 'bigint'; break;
			case 'UNSIGNED BIGINTEGER'	: $colType = 'bigint'; break;
			case 'BOOLEAN'				: $colType = 'boolean'; break;
			case 'MEDIUMBLOB'			: $colType = 'bytea'; break;
		} # switch
		
		return $colType;
	} # swDtToNative 

	/*
	 * Converts a database native datatype to a spotweb native
	 * datatype
	 */
	function nativeDtToSw($colInfo) {
		switch(strtolower($colInfo)) {
			case 'integer'				: $colInfo = 'INTEGER'; break;
			case 'bigint'				: $colInfo = 'BIGINTEGER'; break;
			case 'boolean'				: $colInfo = 'BOOLEAN'; break;
			case 'bytea'				: $colInfo = 'MEDIUMBLOB'; break;
		} # switch
		
		return $colInfo;
	} # nativeDtToSw 
	
	/* checks if an index exists */
	function indexExists($idxname, $tablename) {
		$q = $this->_dbcon->arrayQuery("SELECT indexname FROM pg_indexes WHERE schemaname = CURRENT_SCHEMA() AND tablename = '%s' AND indexname = '%s'",
				Array($tablename, $idxname));
		return !empty($q);
	} # indexExists

	/* checks if a column exists */
	function columnExists($tablename, $colname) {
		$q = $this->_dbcon->arrayQuery("SELECT column_name FROM information_schema.columns 
											WHERE table_schema = CURRENT_SCHEMA() AND table_name = '%s' AND column_name = '%s'",
									Array($tablename, $colname));
		return !empty($q);
	} # columnExists

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

	/*
	 * Adds an index, but first checks if the index doesn't
	 * exist already.
	 *
	 * $idxType can be either 'UNIQUE', '' or 'FULLTEXT'
	 */
	function addIndex($idxname, $idxType, $tablename, $colList) {
		if (!$this->indexExists($idxname, $tablename)) {
			switch($idxType) {
				case 'UNIQUE': {
					$this->_dbcon->rawExec("CREATE UNIQUE INDEX " . $idxname . " ON " . $tablename . "(" . implode(",", $colList) . ")");
					break;
				} # case
				
				case 'FULLTEXT' : {
					$this->_dbcon->rawExec("CREATE INDEX " . $idxname . " ON " . $tablename . " USING gin(to_tsvector('dutch', " . implode(",", $colList) . "))");
					break;
				} # case
				
				default	: {
					$this->_dbcon->rawExec("CREATE INDEX " . $idxname . " ON " . $tablename . "(" . implode(",", $colList) . ")");
				} # default
			} # switch
		} # if
	} # addIndex

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
			$this->_dbcon->rawExec("DROP INDEX " . $idxname);
		} # if
	} # dropIndex
	
	/* adds a column if the column doesn't exist yet */
	function addColumn($colName, $tablename, $colType, $colDefault, $notNull, $collation) {
		if (!$this->columnExists($tablename, $colName)) {
			# set the DEFAULT value
			if (strlen($colDefault) != 0) {
				$colDefault = 'DEFAULT ' . $colDefault;
			} # if

			# Convert the column type to a type we use in PostgreSQL
			$colType = $this->swDtToNative($colType);

			/*
			 * Only pgsql 9.1 (only just released) supports per-column collation, so for now
			 * we ignore this 
			 */
			switch(strtolower($collation)) {
				case 'utf8'			: 
				case 'ascii'		: 
				case 'ascii_bin'	: 
				case ''			: $colSetting = ''; break;
				default			: throw new Exception("Invalid collation setting");
			} # switch
			
			# and define the 'NOT NULL' part
			switch($notNull) {
				case true		: $nullStr = 'NOT NULL'; break;
				default			: $nullStr = '';
			} # switch
			
			$this->_dbcon->rawExec("ALTER TABLE " . $tablename . 
						" ADD COLUMN " . $colName . " " . $colType . " " . $colSetting . " " . $colDefault . " " . $nullStr);
		} # if
	} # addColumn
	
	/* alters a column - does not check if the column doesn't adhere to the given definition */
	function modifyColumn($colName, $tablename, $colType, $colDefault, $notNull, $collation, $what) {
		# set the DEFAULT value
		if (strlen($colDefault) != 0) {
			$colDefault = 'DEFAULT ' . $colDefault;
		} # if

		# Convert the column type to a type we use in PostgreSQL
		$colType = $this->swDtToNative($colType);

		/*
		 * Only pgsql 9.1 (only just released) supports per-column collation, so for now
		 * we ignore this 
		 */
		switch(strtolower($collation)) {
			case 'utf8'			: 
			case 'ascii'		: 
			case 'ascii_bin'	: 
			case ''			: $colSetting = ''; break;
			default			: throw new Exception("Invalid collation setting");
		} # switch
		
		# and define the 'NOT NULL' part
		switch($notNull) {
			case true		: $nullStr = 'NOT NULL'; break;
			default			: $nullStr = '';
		} # switch
		
		# Alter the column type
		$this->_dbcon->rawExec("ALTER TABLE " . $tablename . " ALTER COLUMN " . $colName . " TYPE " . $colType);
		
		# Change the default value (if one set, else drop it)
		if (strlen($colDefault) > 0) {
			$this->_dbcon->rawExec("ALTER TABLE " . $tablename . " ALTER COLUMN " . $colName . " SET " . $colDefault);
		} else {
			$this->_dbcon->rawExec("ALTER TABLE " . $tablename . " ALTER COLUMN " . $colName . " DROP DEFAULT");
		} # if
		
		# and changes the null/not-null constraint
		if (strlen($notNull) > 0) {
			$this->_dbcon->rawExec("ALTER TABLE " . $tablename . " ALTER COLUMN " . $colName . " SET NOT NULL");
		} else {
			$this->_dbcon->rawExec("ALTER TABLE " . $tablename . " ALTER COLUMN " . $colName . " DROP NOT NULL");
		} # if
	} # modifyColumn

	/* drops a column */
	function dropColumn($colName, $tablename) {
		if ($this->columnExists($tablename, $colName)) {
			$this->_dbcon->rawExec("ALTER TABLE " . $tablename . " DROP COLUMN " . $colName);
		} # if
	} # dropColumn

	/* checks if a table exists */
	function tableExists($tablename) {
		$q = $this->_dbcon->arrayQuery("SELECT tablename FROM pg_tables WHERE schemaname = CURRENT_SCHEMA() AND (tablename = '%s')", array($tablename));
		return !empty($q);
	} # tableExists

	/* creates an empty table with only an ID field. Collation should be either UTF8 or ASCII */
	function createTable($tablename, $collation) {
		if (!$this->tableExists($tablename)) {
			/*
			 * Only pgsql 9.1 (only just released) supports per-column collation, so for now
			 * we ignore this 
			 */
			switch(strtolower($collation)) {
				case 'utf8'		: 
				case 'ascii'	: 
				case ''			: $colSetting = ''; break;
				default			: throw new Exception("Invalid collation setting");
			} # switch
		
			$this->_dbcon->rawExec("CREATE TABLE " . $tablename . " (id SERIAL PRIMARY KEY) " . $colSetting);
		} # if
	} # createTable
	
	/* drop a table */
	function dropTable($tablename) {
		if ($this->tableExists($tablename)) {
			$this->_dbcon->rawExec("DROP TABLE " . $tablename);
		} # if
	} # dropTable
	
	/* dummy - postgresql doesn't know storage engines of course */
	function alterStorageEngine($tablename, $engine) {
		return false;
	} # alterStorageEngine
	
	/* rename a table */
	function renameTable($tablename, $newTableName) {
		$this->_dbcon->rawExec("ALTER TABLE " . $tablename . " RENAME TO " . $newTableName);
	} # renameTable

	/* drop a foreign key constraint */
	function dropForeignKey($tablename, $colname, $reftable, $refcolumn, $action) {
		/* SQL from http://stackoverflow.com/questions/1152260/postgres-sql-to-list-table-foreign-keys */
		$q = $this->_dbcon->arrayQuery("SELECT
											tc.constraint_name AS CONSTRAINT_NAME,
											tc.table_name AS TABLE_NAME,
											tc.constraint_schema AS TABLE_SCHEMA,
											kcu.column_name AS COLUMN_NAME,
											ccu.table_name AS REFERENCED_TABLE_NAME,
											ccu.column_name AS REFERENCED_COLUMN_NAME
										FROM
											information_schema.table_constraints AS tc
											JOIN information_schema.key_column_usage AS kcu ON tc.constraint_name = kcu.constraint_name
											JOIN information_schema.constraint_column_usage AS ccu ON ccu.constraint_name = tc.constraint_name
										WHERE constraint_type = 'FOREIGN KEY' 
										  AND tc.TABLE_SCHEMA = CURRENT_SCHEMA()
										  AND tc.TABLE_NAME = '%s'
										  AND kcu.COLUMN_NAME = '%s'
										  AND ccu.table_name = '%s'
										  AND ccu.column_name = '%s'",
								Array($tablename, $colname, $reftable, $refcolumn));
		if (!empty($q)) {
			foreach($q as $res) {
				$this->_dbcon->rawExec("ALTER TABLE " . $tablename . " DROP CONSTRAINT " . $res['constraint_name']);
			} # foreach
		} # if
	} # dropForeignKey

	/* create a foreign key constraint */
	function addForeignKey($tablename, $colname, $reftable, $refcolumn, $action) {
		/* SQL from http://stackoverflow.com/questions/1152260/postgres-sql-to-list-table-foreign-keys */
		$q = $this->_dbcon->arrayQuery("SELECT
											tc.constraint_name AS CONSTRAINT_NAME,
											tc.table_name AS TABLE_NAME,
											tc.constraint_schema AS TABLE_SCHEMA,
											kcu.column_name AS COLUMN_NAME,
											ccu.table_name AS REFERENCED_TABLE_NAME,
											ccu.column_name AS REFERENCED_COLUMN_NAME
										FROM
											information_schema.table_constraints AS tc
											JOIN information_schema.key_column_usage AS kcu ON tc.constraint_name = kcu.constraint_name
											JOIN information_schema.constraint_column_usage AS ccu ON ccu.constraint_name = tc.constraint_name
										WHERE constraint_type = 'FOREIGN KEY' 
										  AND tc.TABLE_SCHEMA = CURRENT_SCHEMA()
										  AND tc.TABLE_NAME = '%s'
										  AND kcu.COLUMN_NAME = '%s'
										  AND ccu.table_name = '%s'
										  AND ccu.column_name = '%s'",
								Array($tablename, $colname, $reftable, $refcolumn));
		if (empty($q)) {
			$this->_dbcon->rawExec("ALTER TABLE " . $tablename . " ADD FOREIGN KEY (" . $colname . ") 
										REFERENCES " . $reftable . " (" . $refcolumn . ") " . $action);
		} # if
	} # addForeignKey

	/* Returns in a fixed format, column information */
	function getColumnInfo($tablename, $colname) {
		$q = $this->_dbcon->arrayQuery("SELECT column_name AS \"COLUMN_NAME\",
											   column_default AS \"COLUMN_DEFAULT\", 
											   is_nullable AS \"IS_NULLABLE\", 
											   data_type AS \"DATA_TYPE\", 
											   numeric_precision AS \"NUMERIC_PRECISION\", 
											   CASE 
													WHEN (data_type = 'character varying') THEN 'varchar(' || character_maximum_length || ')' 
													WHEN (data_type = 'integer') THEN 'integer' 
													WHEN (data_type = 'bigint') THEN 'bigint' 
													WHEN (data_type = 'boolean') THEN 'boolean' 
													WHEN (data_type = 'text') THEN 'text'
													WHEN (data_type = 'bytea') THEN 'bytea'
											   END as \"COLUMN_TYPE\",
   											   character_set_name AS \"CHARACTER_SET_NAME\", 
											   collation_name AS \"COLLATION_NAME\"
										FROM information_schema.COLUMNS 
										WHERE TABLE_SCHEMA = CURRENT_SCHEMA() 
										  AND TABLE_NAME = '%s'
										  AND COLUMN_NAME = '%s'",
							Array($tablename, $colname));
		if (!empty($q)) {
			$q = $q[0];

			$q['NOTNULL'] = ($q['IS_NULLABLE'] != 'YES');
			
			# a default value has to given, so make it compareable to what we define
			if ((strlen($q['COLUMN_DEFAULT']) == 0) && (is_string($q['COLUMN_DEFAULT']))) {	
				$q['COLUMN_DEFAULT'] = "''";
			} # if

			/*
			 * PostgreSQL per default explicitly typecasts the value, but
			 * we cannot do this, so we strip the default value of its typecast
			 */
			if (strpos($q['COLUMN_DEFAULT'], ':') !== false) {
				$elems = explode(':', $q['COLUMN_DEFAULT']);
				
				$q['COLUMN_DEFAULT'] = $elems[0];
			} # if
		} # if
		
		return $q;
	} # getColumnInfo
	
	/* Returns in a fixed format, index information */
	function getIndexInfo($idxname, $tablename) {
		$q = $this->_dbcon->arrayQuery("SELECT * 
										FROM pg_indexes 
										WHERE schemaname = CURRENT_SCHEMA()
										  AND tablename = '%s'
										  AND indexname = '%s'", Array($tablename, $idxname));
		if (empty($q)) {
			return array();
		} # if
		
		# a index name has to be unique
		$q = $q[0];
											
		# is the index marked as unique
		$tmpAr = explode(" ", $q['indexdef']);
		$isNotUnique = (strtolower($tmpAr[1]) != 'unique');

		# retrieve the column list and seperate the column definition per comma
		preg_match_all("/\((.*)\)/", $q['indexdef'], $tmpAr);
		
		$colList = explode(",", $tmpAr[1][0]);
		$colList = array_map('trim', $colList);
		
		# gin indexes (fulltext search) only have 1 column, so we excempt them
		$idxInfo = array();
		if (stripos($tmpAr[1][0], 'to_tsvector') === false) {
			for($i = 0; $i < count($colList); $i++) {
				$idxInfo[] = array('column_name' => $colList[$i],
								   'non_unique' => (int) $isNotUnique,
								   'index_type' => 'BTREE'
							);
			} # foreach
		} else {
			# extract the column name
			preg_match_all("/\((.*)\)/U", $colList[1], $tmpAr);
			
			# and create the index info
			$idxInfo[] = array('column_name' => $tmpAr[1][0],
							   'non_unique' => (int) $isNotUnique,
							   'index_type' => 'FULLTEXT');
		} # else

		return $idxInfo;
	} # getIndexInfo
	
} # class
